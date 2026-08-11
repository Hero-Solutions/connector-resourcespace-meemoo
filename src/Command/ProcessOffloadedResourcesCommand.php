<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use App\Util\OaiPmhApiUtil;
use App\Util\RestApi;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\HttpAdapter\CurlAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProcessOffloadedResourcesCommand extends Command
{
    private const OAI_CLOCK_SKEW_SECONDS = 7200;

    private ParameterBagInterface $params;
    private EntityManagerInterface $entityManager;
    private bool $dryRun;
    private bool $verbose;
    private ResourceSpace $resourceSpace;
    private array $offloadStatusField;
    private array $resourceSpaceMetadataFields;
    private bool $deleteOriginals;
    private string $connectorUrl;
    private bool $processError = false;
    private bool $coverageError = false;
    private ?int $resourceIdFilter = null;
    private ?DateTime $fromFilter = null;
    private ?DateTime $untilFilter = null;

    private RestApi $restApi;
    private array $resourcesProcessed;
    private array $resourcesSeen;
    private array $resourceArchiveStatuses;
    private int $pendingProcessingGraceSeconds;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager, $dryRun = false)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->dryRun = $dryRun;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:process-offloaded-resources')
            ->setDescription('Checks the status of offloaded images and deletes originals if successful.')
            ->addOption('resource-id', null, InputOption::VALUE_REQUIRED, 'Only repair this numeric ResourceSpace resource ID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the targeted OAI-PMH window (YYYY-MM-DD).')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'End of the targeted OAI-PMH window (YYYY-MM-DD, inclusive).');
    }

    public function setVerbose($verbose): void
    {
        $this->verbose = $verbose;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configureTargetOptions(
            $input->getOption('resource-id'),
            $input->getOption('from'),
            $input->getOption('until'),
            $output
        )) {
            return Command::INVALID;
        }
        $this->verbose = $input->getOption('verbose');
        return $this->process();
    }

    public function configureTargetOptions($resourceId, $from, $until, OutputInterface $output): bool
    {
        if ($resourceId === null && $from === null && $until === null) {
            return true;
        }
        if (!is_string($resourceId) || !ctype_digit($resourceId) || (int) $resourceId < 1) {
            $output->writeln('<error>--resource-id must be a positive numeric ResourceSpace ID.</error>');
            return false;
        }
        if (!is_string($from) || !is_string($until)) {
            $output->writeln('<error>A targeted process requires both --from and --until in YYYY-MM-DD format.</error>');
            return false;
        }

        $utc = new DateTimeZone('UTC');
        $fromDate = DateTime::createFromFormat('!Y-m-d', $from, $utc);
        $untilDate = DateTime::createFromFormat('!Y-m-d', $until, $utc);
        if ($fromDate === false || $untilDate === false
            || $fromDate->format('Y-m-d') !== $from
            || $untilDate->format('Y-m-d') !== $until) {
            $output->writeln('<error>--from and --until must be valid dates in YYYY-MM-DD format.</error>');
            return false;
        }
        $untilDate->setTime(23, 59, 59);
        if ($fromDate > $untilDate) {
            $output->writeln('<error>--from must not be later than --until.</error>');
            return false;
        }

        $this->resourceIdFilter = (int) $resourceId;
        $this->fromFilter = $fromDate;
        $this->untilFilter = $untilDate;
        return true;
    }

    // Returns a non-zero exit code when OAI-PMH or ResourceSpace calls failed, so cron notices
    public function process(): int
    {
        $this->resourceSpace = new ResourceSpace($this->params);
        $this->restApi = new RestApi($this->params);

        $lastProcessedTimestampFile = $this->params->get('last_processed_timestamp_file');
        $this->deleteOriginals = $this->params->get('delete_originals');
        $this->connectorUrl = $this->params->get('connector_url');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $collections = $this->params->get('collections');
        $collectionKey = $collections['key'];
        $this->resourcesProcessed = array();
        $this->resourcesSeen = array();
        $this->resourceArchiveStatuses = array();
        $pendingProcessingGraceHours = $this->params->has('pending_processing_grace_hours')
            ? $this->params->get('pending_processing_grace_hours')
            : 48;
        if (!is_numeric($pendingProcessingGraceHours) || (int) $pendingProcessingGraceHours < 1) {
            echo 'ERROR: pending_processing_grace_hours must be a positive number.' . PHP_EOL;
            return Command::INVALID;
        }
        $this->pendingProcessingGraceSeconds = (int) $pendingProcessingGraceHours * 3600;

        if ($this->resourceIdFilter !== null) {
            $resourceReadFailed = false;
            $rawResourceData = $this->resourceSpace->getRawResourceFieldData($this->resourceIdFilter, $resourceReadFailed);
            if ($resourceReadFailed || $rawResourceData === null) {
                echo 'ERROR: Could not retrieve ResourceSpace metadata for resource ' . $this->resourceIdFilter . '.' . PHP_EOL;
                return 1;
            }
            $resourceMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
            $collection = $resourceMetadata[$collectionKey] ?? '';
            if (!in_array($collection, $collections['values'], true)) {
                echo 'ERROR: Resource ' . $this->resourceIdFilter . ' has no configured collection.' . PHP_EOL;
                return 1;
            }

            $this->verboseLog('Searching OAI-PMH collection ' . $collection . ' for ResourceSpace resource '
                . $this->resourceIdFilter . ' from ' . $this->fromFilter->format(DATE_ATOM)
                . ' until ' . $this->untilFilter->format(DATE_ATOM) . '.');
            $this->processOaiPmhApi([$collection], $this->fromFilter, $this->untilFilter);
            if (!in_array((string) $this->resourceIdFilter, $this->resourcesProcessed, true)) {
                echo 'ERROR: No completed meemoo record for ResourceSpace resource ' . $this->resourceIdFilter
                    . ' was found in the selected OAI-PMH window.' . PHP_EOL;
                $this->processError = true;
            }
        } else {
            $lastOffloadTimestampFile = $this->params->get('last_offload_timestamp_file');
            if (file_exists($lastOffloadTimestampFile)) {
                $file = fopen($lastOffloadTimestampFile, "r") or die("ERROR: Unable to open file containing last offload timestamp ('" . $lastOffloadTimestampFile . "').");
                $lastOffloadTimestamp = intval(fgets($file));
                fclose($file);
            } else {
                die("ERROR: Unable to locate file containing last offload timestamp ('" . $lastOffloadTimestampFile . "').");
            }

            // Also grab the last processed timestamp in case the OAI-PMH API was having issues
            if (file_exists($lastProcessedTimestampFile)) {
                $file = fopen($lastProcessedTimestampFile, "r") or die("ERROR: Unable to open file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
                $lastProcessedTimestamp = intval(fgets($file));
                fclose($file);
            } else {
                die("ERROR: Unable to locate file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
            }

            // Keep the cursor safety behavior after incomplete runs, but always revisit at least
            // the complete pending grace period. The extra two hours compensate for possible UTC
            // differences in meemoo's OAI-PMH datestamps.
            $cursorTimestamp = min($lastOffloadTimestamp, $lastProcessedTimestamp) - self::OAI_CLOCK_SKEW_SECONDS;
            $rollingLookbackTimestamp = time()
                - $this->pendingProcessingGraceSeconds
                - self::OAI_CLOCK_SKEW_SECONDS;
            $oaiFromTimestamp = min($cursorTimestamp, $rollingLookbackTimestamp);

            $oaiFromDateTime = new DateTime(DateTimeUtil::formatTimestampWithTimezone($oaiFromTimestamp));
            $this->verboseLog('Processing OAI-PMH records since ' . $oaiFromDateTime->format(DATE_ATOM) . '.');
            $this->processOaiPmhApi($collections['values'], $oaiFromDateTime);
            if ($this->coverageError) {
                echo 'WARNING: Pending ResourceSpace resources were not marked as missing because the meemoo check was incomplete.' . PHP_EOL;
            } else {
                $this->verboseLog('Checking ResourceSpace resources that are still pending.');
                $this->processMissingResources($collections['values'], $collectionKey);
            }

            if ($this->shouldAdvanceLastProcessedTimestamp()) {
                $timestamp = time();
                $file = fopen($lastProcessedTimestampFile, "w") or die("Unable to open file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
                fwrite($file, $timestamp);
                fclose($file);
            }
        }

        return $this->processError ? 1 : 0;
    }

    private function shouldAdvanceLastProcessedTimestamp(): bool
    {
        return !$this->dryRun
            && !$this->coverageError
            && $this->resourceIdFilter === null;
    }

    private function processOaiPmhApi($collections, DateTime $from, ?DateTime $until = null): void
    {
        $overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $sslCertificateAuthorityFile = $this->params->get('ssl_certificate_authority_file');
        $oaiPmhApi = $this->params->get('oai_pmh_api');

        foreach($collections as $collection) {
            $recordCount = 0;

            try {
                $this->verboseLog('Starting OAI-PMH collection ' . $collection . '.');
                $oaiPmhEndpoint = OaiPmhApiUtil::connect($this->restApi, $oaiPmhApi, $collection, $overrideCertificateAuthorityFile, $sslCertificateAuthorityFile);
                if ($oaiPmhEndpoint === null) {
                    throw new Exception('Could not connect to the OAI-PMH endpoint.');
                }
                $this->verboseLog('Requesting OAI-PMH records for ' . $collection . '.');
                $records = $oaiPmhEndpoint->listRecords($oaiPmhApi['metadata_prefix'], $from, $until);

                foreach($records as $record) {
                    $recordCount++;
                    if($recordCount === 1 || $recordCount % 100 === 0) {
                        $this->verboseLog('Processing OAI-PMH record ' . $recordCount . ' for ' . $collection . '.');
                    }

                    $this->processRecord($collection, $record->header->identifier, $record->metadata->children($oaiPmhApi['namespace'], true),
                        $oaiPmhApi['resource_data_xpath'] . '/' . $oaiPmhApi['resourcespace_id'], $oaiPmhApi['media_id_xpath'], $oaiPmhApi['archive_status_xpath'],
                        $oaiPmhApi['resource_data_xpath'] . '/md5',
                        $oaiPmhApi['completed_status']);

                    // A targeted repair needs only the first completed record containing the
                    // exact ResourceSpace ID. Stop before traversing the rest of the date window.
                    if ($this->resourceIdFilter !== null
                        && in_array((string) $this->resourceIdFilter, $this->resourcesProcessed, true)) {
                        break;
                    }
                }

                $this->verboseLog('Finished OAI-PMH collection ' . $collection . ' (' . $recordCount . ' records).');
            }
            catch(OaipmhException $e) {
                if($e->getOaiErrorCode() == 'noRecordsMatch') {
                    echo 'No records to process for ' . $collection . '.' . PHP_EOL;
                    $this->verboseLog('Finished OAI-PMH collection ' . $collection . ' (0 records).');
                } else {
                    echo 'OAI-PMH error (1) at collection ' . $collection . ': ' . $e . PHP_EOL;
                    $this->processError = true;
                    $this->coverageError = true;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
                }
            }
            catch(HttpException $e) {
                if($this->isNoRecordsHttpException($e)) {
                    echo 'No records to process for ' . $collection . '.' . PHP_EOL;
                    $this->verboseLog('Finished OAI-PMH collection ' . $collection . ' (0 records).');
                } else {
                    echo 'OAI-PMH error (2) at collection ' . $collection . ': ' . $e . PHP_EOL;
                    $this->processError = true;
                    $this->coverageError = true;
                }
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
            catch(Exception $e) {
                echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
                $this->processError = true;
                $this->coverageError = true;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
        }
    }

    private function verboseLog($message): void
    {
        if(!$this->verbose) {
            return;
        }

        echo DateTimeUtil::formatTimestampSimple() . ' - ' . $message . PHP_EOL;
        flush();
    }

    private function isNoRecordsHttpException(HttpException $e): bool
    {
        return (int) $e->getCode() === 404 && trim($e->getBody()) === '';
    }

    private function processRecord($collection, $assetId, $record,
                                   $resourceIdXpath, $mediaIdXpath, $archiveStatusXpath, $archiveMd5Xpath, $completedStatuses): void
    {
        $resourceIds = $record->xpath($resourceIdXpath);
        foreach($resourceIds as $id) {
            $resourceId = strval($id);

            if ($this->resourceIdFilter !== null && (int) $resourceId !== $this->resourceIdFilter) {
                continue;
            }

            //Only process ResourceSpace ID's (maybe we should work out a more robust mechanism to detect which resources were offloaded through ResourceSpace)
            if(preg_match('/^[0-9]+$/', $resourceId)) {

                $archiveStatus = null;
                $archiveStatuses = $record->xpath($archiveStatusXpath);
                foreach($archiveStatuses as $status) {
                    $archiveStatus = trim((string) $status);
                }

                if (!in_array($resourceId, $this->resourcesSeen, true)) {
                    $this->resourcesSeen[] = $resourceId;
                }
                $this->resourceArchiveStatuses[$resourceId] = $archiveStatus;

                if($archiveStatus === null || !in_array($archiveStatus, $completedStatuses)) {
                    echo 'Resource ' . $resourceId . ' is still being processed by meemoo (archive status: '
                        . ($archiveStatus === null || $archiveStatus === '' ? 'unknown' : $archiveStatus)
                        . ').' . PHP_EOL;
                } else {
                    // This resource is present in a completed meemoo record even if one of the
                    // local finalization steps below fails. Do not later mislabel it as missing
                    // and overwrite the specific safety error with a generic one.
                    if (!in_array($resourceId, $this->resourcesProcessed, true)) {
                        $this->resourcesProcessed[] = $resourceId;
                    }

                    $imageUrl = null;
                    $mediaIds = $record->xpath($mediaIdXpath);
                    foreach ($mediaIds as $mediaId) {
                        $imageUrl = $this->connectorUrl . 'download/' . $collection . '/' . $mediaId;
                    }
                    $assetUrl = $this->connectorUrl . 'data/' . $collection . '/' . $assetId;

                    $resourceReadFailed = false;
                    $rawResourceData = $this->resourceSpace->getRawResourceFieldData($resourceId, $resourceReadFailed);
                    if ($resourceReadFailed) {
                        echo 'ERROR: Could not retrieve ResourceSpace metadata for resource ' . $resourceId . '.' . PHP_EOL;
                        $this->processError = true;
                    } else if ($rawResourceData == null) {
                        echo 'ERROR: Resource ' . $resourceId . ' not found in ResourceSpace!' . PHP_EOL;
                    } else if (trim((string) $assetId) === '') {
                        $currentMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
                        $this->failResourceProcessing(
                            $resourceId,
                            'Cannot create the meemoo asset URL from the OAI-PMH record.',
                            $currentMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                        );
                    } else if (empty($imageUrl)) {
                        $currentMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
                        $this->failResourceProcessing(
                            $resourceId,
                            'Cannot create the meemoo original download URL from the OAI-PMH record.',
                            $currentMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                        );
                    } else {
                        $resourceMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
                        $statusKey = $this->offloadStatusField['key'];

                        $updatedAssetUrl = false;
                        $updatedImageUrl = false;
                        $updatedStatus = false;

                        if (!empty($resourceMetadata[$statusKey])) {
                            $existingAssetUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_asset_url']] ?? '';
                            $newAssetUrl = null;
                            if (empty($existingAssetUrl)) {
                                $updatedAssetUrl = true;
                                $newAssetUrl = $assetUrl;
                            } else if (!$this->containsUrl($existingAssetUrl, $assetUrl)) {
                                $updatedAssetUrl = true;
                                $newAssetUrl = $existingAssetUrl . PHP_EOL . PHP_EOL . $assetUrl;
                            }
                            if (!$this->dryRun && $newAssetUrl !== null
                                && !$this->resourceSpace->updateFieldVerified($resourceId, $this->resourceSpaceMetadataFields['meemoo_asset_url'], $newAssetUrl)) {
                                $this->failResourceProcessing(
                                    $resourceId,
                                    'Could not write the meemoo asset URL to ResourceSpace.',
                                    $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                );
                                continue;
                            }

                            $existingOriginalUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_image_url']] ?? '';
                            $newOriginalUrl = null;
                            if (empty($existingOriginalUrl)) {
                                $updatedImageUrl = true;
                                $newOriginalUrl = $imageUrl;
                            } else if (!$this->containsUrl($existingOriginalUrl, $imageUrl)) {
                                $updatedImageUrl = true;
                                $newOriginalUrl = $existingOriginalUrl . PHP_EOL . PHP_EOL . $imageUrl;
                            }
                            if (!$this->dryRun && $newOriginalUrl !== null
                                && !$this->resourceSpace->updateFieldVerified($resourceId, $this->resourceSpaceMetadataFields['meemoo_image_url'], $newOriginalUrl)) {
                                $this->failResourceProcessing(
                                    $resourceId,
                                    'Could not write the meemoo original download URL to ResourceSpace.',
                                    $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                );
                                continue;
                            }

                            if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed']) {
                                $updatedStatus = true;
                                if(!$this->dryRun) {
                                    if ($this->deleteOriginals) {
                                        $originalFilename = $resourceMetadata['originalfilename'] ?? null;
                                        if (!$this->resourceSpace->isReplacementFilename($resourceId, $originalFilename)) {
                                            $replacementSafetyError = $this->getReplacementSafetyError(
                                                $resourceId,
                                                $originalFilename,
                                                $record,
                                                $archiveMd5Xpath
                                            );
                                            if ($replacementSafetyError !== null) {
                                                $this->failResourceProcessing(
                                                    $resourceId,
                                                    $replacementSafetyError,
                                                    $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                                );
                                                continue;
                                            }
                                        }

                                        $result = $this->resourceSpace->replaceOriginal($resourceId, $resourceMetadata['originalfilename'] ?? null, $this->entityManager);
                                        $replaceMessage = is_string($result['message'] ?? null)
                                            ? $result['message']
                                            : json_encode($result['message'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        if (($result['status'] ?? false) !== true) {
                                            $this->failResourceProcessing(
                                                $resourceId,
                                                'Error replacing original: ' . $replaceMessage,
                                                $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                            );
                                            continue;
                                        }
                                        echo 'Replaced resource ' . $resourceId . ' original file: ' . $replaceMessage . PHP_EOL;
                                    }
                                    if (!$this->clearProcessingError(
                                        $resourceId,
                                        $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                    )) {
                                        continue;
                                    }
                                    if (!$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded'])) {
                                        $this->failResourceProcessing(
                                            $resourceId,
                                            'All meemoo data was processed, but the final Offloaded status could not be written to ResourceSpace.',
                                            ''
                                        );
                                        continue;
                                    }
                                }
                            } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed_but_keep_original']) {
                                $updatedStatus = true;
                                if(!$this->dryRun) {
                                    if (!$this->clearProcessingError(
                                        $resourceId,
                                        $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                    )) {
                                        continue;
                                    }
                                    if (!$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded_but_keep_original'])) {
                                        $this->failResourceProcessing(
                                            $resourceId,
                                            'All meemoo data was processed, but the final Offloaded status could not be written to ResourceSpace.',
                                            ''
                                        );
                                        continue;
                                    }
                                }
                            }
                        }
                        if ($this->verbose) {
                            echo ($this->dryRun ? ' DRY RUN - ' : '')
                                . 'Resource ' . $resourceId . ' has been processed by meemoo'
                                . ($updatedStatus ? ' - updated status' : ' - no-op status')
                                . ($updatedAssetUrl ? ' - updated asset URL ' . $assetUrl : ' - no-op asset URL')
                                . ($updatedImageUrl ? ' - updated image URL ' . $imageUrl : ' - no-op image URL')
                                . PHP_EOL;
        /*                    echo 'Resource ' . $resourceId . ' has asset URL: ' . $assetUrl . PHP_EOL;
                            echo 'Resource ' . $resourceId . ' has image URL: ' . $imageUrl . PHP_EOL;
                            echo 'Resource ' . $resourceId . ' already has status ' . $resourceMetadata[$statusKey] . PHP_EOL;
                            $statusKey = $this->offloadStatusField['key'];
                            if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed']) {
                                echo 'Set resource ' . $resourceId . ' status from ' . $resourceMetadata[$statusKey] . ' to ' . $this->offloadStatusField['values']['offloaded'] . PHP_EOL;
                            } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed_but_keep_original']) {
                                echo 'Set resource ' . $resourceId . ' status from ' . $resourceMetadata[$statusKey] . ' to ' . $this->offloadStatusField['values']['offloaded_but_keep_original'] . PHP_EOL;
                            }*/
                        }

                    }
                }
            }
        }
    }

    private function failResourceProcessing($resourceId, string $message, $currentError = ''): void
    {
        echo 'ERROR at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->processError = true;

        if ($this->dryRun) {
            return;
        }

        if (!$this->resourceSpace->updateErrorVerified(
            $resourceId,
            $this->resourceSpaceMetadataFields['offload_error'],
            $message,
            $currentError,
            true
        )) {
            echo 'ERROR at resource ' . $resourceId . ': the processing error could not be written to ResourceSpace.' . PHP_EOL;
        }
    }

    private function getReplacementSafetyError($resourceId, $originalFilename, $record, string $archiveMd5Xpath): ?string
    {
        if (!is_string($originalFilename) || trim($originalFilename) === '') {
            return 'Cannot safely replace the original because its filename is missing.';
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return 'Cannot safely replace the original because its file extension is missing.';
        }

        $archivedMd5 = $this->extractSingleMd5($record, $archiveMd5Xpath);
        if ($archivedMd5 === null) {
            return 'Cannot safely replace the original because the completed meemoo record does not contain one valid MD5 checksum.';
        }

        $currentMd5 = $this->resourceSpace->getOriginalFileMd5($resourceId, $extension);
        if ($currentMd5 === null) {
            return 'Cannot safely replace the original because the current ResourceSpace file could not be downloaded and verified.';
        }

        if (!hash_equals($archivedMd5, $currentMd5)) {
            return 'The current ResourceSpace original does not match the MD5 checksum archived by meemoo; the original was not replaced.';
        }

        return null;
    }

    private function extractSingleMd5($record, string $xpath): ?string
    {
        $matches = $record->xpath($xpath);
        if (!is_array($matches) || $matches === []) {
            return null;
        }

        $checksums = [];
        foreach ($matches as $match) {
            $checksum = strtolower(trim((string) $match));
            if (preg_match('/^[0-9a-f]{32}$/', $checksum) !== 1) {
                return null;
            }
            $checksums[$checksum] = true;
        }

        return count($checksums) === 1 ? array_key_first($checksums) : null;
    }

    private function containsUrl(string $storedUrls, string $candidateUrl): bool
    {
        $urls = preg_split('/\R+/', $storedUrls) ?: [];
        foreach ($urls as $url) {
            if (trim($url) === $candidateUrl) {
                return true;
            }
        }
        return false;
    }

    private function clearProcessingError($resourceId, $currentError = ''): bool
    {
        if ($this->dryRun) {
            return true;
        }

        if ($this->resourceSpace->updateErrorVerified(
            $resourceId,
            $this->resourceSpaceMetadataFields['offload_error'],
            '',
            $currentError
        )) {
            return true;
        }

        echo 'ERROR at resource ' . $resourceId . ': the previous processing error could not be cleared in ResourceSpace.' . PHP_EOL;
        $this->processError = true;
        return false;
    }

    private function processMissingResources($collections, $collectionKey): void
    {
        $offloadStatusFilter = array($this->offloadStatusField['values']['offload_pending'], $this->offloadStatusField['values']['offload_pending_but_keep_original']);
        $statusKey = $this->offloadStatusField['key'];
        $checkedResources = array();

        // Loop through all collections
        foreach($collections as $collection) {
            $this->verboseLog('Checking missing resources for collection ' . $collection . '.');
            $checkedResourceCount = 0;

            foreach($offloadStatusFilter as $statusFilter) {
                $this->verboseLog('Searching ResourceSpace resources for ' . $collection . ' with status "' . $statusFilter . '".');
                $allResources = $this->resourceSpace->getAllResources(urlencode('"' . $collectionKey . ':' . $collection . '" "' . $statusKey . ':' . $statusFilter . '"'));
                if(!is_array($allResources)) {
                    echo 'ERROR: Could not retrieve ResourceSpace resources for ' . $collection . ' with status "' . $statusFilter . '".' . PHP_EOL;
                    $this->processError = true;
                    $this->coverageError = true;
                    continue;
                }

                $this->verboseLog('Fetched ' . count($allResources) . ' ResourceSpace candidate resources for ' . $collection . ' with status "' . $statusFilter . '".');

                // Loop through all resources in this collection/status search
                foreach($allResources as $resourceInfo) {
                    $resourceId = $resourceInfo['ref'];
                    if(in_array($resourceId, $checkedResources)) {
                        continue;
                    }
                    $checkedResources[] = $resourceId;

                    $checkedResourceCount++;
                    if($checkedResourceCount === 1 || $checkedResourceCount % 100 === 0) {
                        $this->verboseLog('Checking ResourceSpace pending candidate ' . $checkedResourceCount . ' for ' . $collection . '.');
                    }

                    if (in_array((string) $resourceId, $this->resourcesProcessed, true)) {
                        continue;
                    }

                    // Get this resource's metadata, but only if it has an appropriate offloadStatus
                    $metadataReadFailed = false;
                    $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains(
                        $resourceId,
                        $statusKey,
                        $offloadStatusFilter,
                        $metadataReadFailed
                    );
                    if ($metadataReadFailed) {
                        echo 'ERROR: Could not retrieve ResourceSpace metadata for pending resource ' . $resourceId . '.' . PHP_EOL;
                        $this->processError = true;
                        continue;
                    }
                    if($resourceMetadata != null) {
                        $pendingAgeSeconds = $this->getPendingAgeSeconds($resourceMetadata);
                        if ($pendingAgeSeconds === null) {
                            $this->recordPendingAgeError($resourceId, $resourceMetadata);
                            continue;
                        }

                        $resourceIdString = (string) $resourceId;
                        $archiveStatus = $this->resourceArchiveStatuses[$resourceIdString] ?? null;
                        if ($pendingAgeSeconds < $this->pendingProcessingGraceSeconds) {
                            if (in_array($resourceIdString, $this->resourcesSeen, true)) {
                                echo 'Resource ' . $resourceId . ' remains pending while meemoo processes it (archive status: '
                                    . ($archiveStatus === null || $archiveStatus === '' ? 'unknown' : $archiveStatus)
                                    . ').' . PHP_EOL;
                            } else {
                                echo 'Resource ' . $resourceId
                                    . ' remains pending while its meemoo record becomes available.' . PHP_EOL;
                            }
                            continue;
                        }

                        $graceHours = (int) ($this->pendingProcessingGraceSeconds / 3600);
                        if (in_array($resourceIdString, $this->resourcesSeen, true)) {
                            $message = 'Meemoo processing did not complete within ' . $graceHours
                                . ' hours (last archive status: '
                                . ($archiveStatus === null || $archiveStatus === '' ? 'unknown' : $archiveStatus)
                                . ').';
                        } else {
                            $message = 'No meemoo record was found within ' . $graceHours . ' hours after offload.';
                        }
                        $this->failPendingResource($resourceId, $resourceMetadata, $statusKey, $message);
                    }
                }
            }

            $this->verboseLog('Finished missing-resource check for ' . $collection . ' (' . $checkedResourceCount . ' resources).');
        }
    }

    private function getPendingAgeSeconds(array $resourceMetadata): ?int
    {
        $offloadTimestampKey = $this->resourceSpaceMetadataFields['offload_timestamp_resource'];
        $offloadTimestamp = trim((string) ($resourceMetadata[$offloadTimestampKey] ?? ''));
        if ($offloadTimestamp === '') {
            return null;
        }

        try {
            $offloadDateTime = new DateTime($offloadTimestamp, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return max(0, time() - $offloadDateTime->getTimestamp());
    }

    private function recordPendingAgeError($resourceId, array $resourceMetadata): void
    {
        $message = 'Cannot determine how long this resource has been pending because its offload time is missing or invalid.';
        echo 'ERROR at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->processError = true;

        if ($this->dryRun) {
            return;
        }

        if (!$this->resourceSpace->updateErrorVerified(
            $resourceId,
            $this->resourceSpaceMetadataFields['offload_error'],
            $message,
            $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? '',
            true
        )) {
            echo 'ERROR at resource ' . $resourceId
                . ': the missing offload-time error could not be written to ResourceSpace.' . PHP_EOL;
        }
    }

    private function failPendingResource($resourceId, array $resourceMetadata, string $statusKey, string $message): void
    {
        echo 'ERROR at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->processError = true;

        if ($this->dryRun) {
            return;
        }

        if (!$this->resourceSpace->updateErrorVerified(
            $resourceId,
            $this->resourceSpaceMetadataFields['offload_error'],
            $message,
            $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? '',
            true
        )) {
            echo 'ERROR at resource ' . $resourceId
                . ': the pending timeout error could not be written to ResourceSpace.' . PHP_EOL;
            return;
        }

        $failedStatus = $resourceMetadata[$statusKey] === $this->offloadStatusField['values']['offload_pending_but_keep_original']
            ? $this->offloadStatusField['values']['offload_failed_but_keep_original']
            : $this->offloadStatusField['values']['offload_failed'];
        if (!$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $failedStatus)) {
            echo 'ERROR at resource ' . $resourceId
                . ': the pending resource could not be moved to status "' . $failedStatus . '".' . PHP_EOL;
        }
    }
}

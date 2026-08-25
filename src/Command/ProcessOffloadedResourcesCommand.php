<?php

namespace App\Command;

use App\Entity\FileChecksum;
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
use Phpoaipmh\Granularity;
use Phpoaipmh\HttpAdapter\CurlAdapter;
use Phpoaipmh\RecordIteratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProcessOffloadedResourcesCommand extends Command
{
    private const OAI_CLOCK_SKEW_SECONDS = 7200;
    private const DEFAULT_ARCHIVE_MD5_XPATH = 'mets:fileSec/mets:fileGrp/mets:file[@USE="PRESERVATION" and @CHECKSUMTYPE="MD5"]/@CHECKSUM';

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
    private array $observedArchiveStatuses;
    private array $md5VerifiedResourceIds;
    private array $resourceChecksumMismatches;
    private ?array $knownOffloadChecksumsByResource = null;
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
        $this->observedArchiveStatuses = array();
        $this->md5VerifiedResourceIds = array();
        $this->resourceChecksumMismatches = array();
        $this->knownOffloadChecksumsByResource = null;
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
            $this->reportTargetedResourceStatus($this->resourceIdFilter);
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
        $archiveMd5Xpath = $oaiPmhApi['archive_md5_xpath'] ?? self::DEFAULT_ARCHIVE_MD5_XPATH;
        if (!is_string($archiveMd5Xpath) || trim($archiveMd5Xpath) === '') {
            echo 'ERROR: oai_pmh_api.archive_md5_xpath must be a non-empty XPath.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return;
        }

        $harvestWindows = $this->createDailyHarvestWindows($from, $until);
        if ($harvestWindows === []) {
            echo 'ERROR: The OAI-PMH harvest start is later than its end.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return;
        }

        foreach($collections as $collection) {
            $recordCount = 0;
            $harvestedRecords = [];
            $collectionHarvestComplete = true;

            try {
                $this->verboseLog('Starting OAI-PMH collection ' . $collection . '.');
                $oaiGranularity = null;
                $oaiPmhEndpoint = OaiPmhApiUtil::connect(
                    $this->restApi,
                    $oaiPmhApi,
                    $collection,
                    $overrideCertificateAuthorityFile,
                    $sslCertificateAuthorityFile,
                    $oaiGranularity
                );
                if ($oaiPmhEndpoint === null) {
                    throw new Exception('Could not connect to the OAI-PMH endpoint.');
                }
            }
            catch(\Throwable $e) {
                echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
                $this->processError = true;
                $this->coverageError = true;
                $collectionHarvestComplete = false;
            }

            if ($collectionHarvestComplete) {
                foreach ($harvestWindows as [$windowFrom, $windowUntil]) {
                    $windowResult = $this->harvestOaiPmhWindow(
                        $oaiPmhEndpoint,
                        $collection,
                        $windowFrom,
                        $windowUntil,
                        $oaiPmhApi,
                        $archiveMd5Xpath,
                        $oaiGranularity
                    );
                    if ($windowResult === null) {
                        $collectionHarvestComplete = false;
                        break;
                    }

                    $recordCount += $windowResult['record_count'];
                    foreach ($windowResult['records'] as $harvestedRecord) {
                        $harvestedRecords[] = $harvestedRecord;
                    }
                }
            }

            if (!$collectionHarvestComplete) {
                if ($recordCount > 0) {
                    echo 'ERROR: Discarding ' . $recordCount . ' partially harvested OAI-PMH records for '
                        . $collection . '; no ResourceSpace resources were changed from this incomplete harvest.' . PHP_EOL;
                }
                continue;
            }

            $this->verboseLog('Finished OAI-PMH harvest for ' . $collection . ' (' . $recordCount
                . ' records across ' . count($harvestWindows) . ' daily windows).');
            if (!$this->loadKnownOffloadChecksums()) {
                return;
            }

            foreach ($harvestedRecords as $harvestedRecord) {
                $this->processHarvestedRecord($harvestedRecord, $oaiPmhApi['completed_status']);

                // A targeted repair needs only the first completed, ID+MD5-verified record.
                if ($this->resourceIdFilter !== null
                    && in_array((string) $this->resourceIdFilter, $this->resourcesProcessed, true)) {
                    break;
                }
            }
        }
    }

    private function createDailyHarvestWindows(DateTime $from, ?DateTime $until): array
    {
        $utc = new DateTimeZone('UTC');
        $windowStart = (clone $from)->setTimezone($utc);
        $harvestUntil = $until === null
            ? new DateTime('now', $utc)
            : (clone $until)->setTimezone($utc);
        $windows = [];

        while ($windowStart <= $harvestUntil) {
            $windowEnd = (clone $windowStart)->setTime(23, 59, 59);
            if ($windowEnd > $harvestUntil) {
                $windowEnd = clone $harvestUntil;
            }

            $windows[] = [clone $windowStart, $windowEnd];
            $windowStart = (clone $windowEnd)->modify('+1 second');
        }

        return $windows;
    }

    private function harvestOaiPmhWindow(
        Endpoint $oaiPmhEndpoint,
        string $collection,
        DateTime $from,
        DateTime $until,
        array $oaiPmhApi,
        string $archiveMd5Xpath,
        string $oaiGranularity
    ): ?array {
        $recordCount = 0;
        $records = null;
        $harvestedRecords = [];
        $harvestComplete = false;
        $windowLabel = $from->format('Y-m-d\TH:i:s\Z') . ' until ' . $until->format('Y-m-d\TH:i:s\Z');

        try {
            $this->verboseLog('Requesting OAI-PMH records for ' . $collection . ' from ' . $windowLabel . '.');
            $records = $oaiPmhEndpoint->listRecords($oaiPmhApi['metadata_prefix'], $from, $until);

            foreach($records as $record) {
                $recordCount++;
                if($recordCount === 1 || $recordCount % 100 === 0) {
                    $this->verboseLog('Harvesting OAI-PMH record ' . $recordCount . ' for ' . $collection
                        . ' in window ' . $windowLabel . '.');
                }

                if (!isset($record->metadata)) {
                    // Deleted OAI-PMH records legitimately have a header without metadata and
                    // cannot be linked to ResourceSpace.
                    if (isset($record->header['status']) && (string) $record->header['status'] === 'deleted') {
                        continue;
                    }
                    throw new \UnexpectedValueException(
                        'OAI-PMH record ' . $recordCount . ' for ' . $collection . ' has no metadata.'
                    );
                }
                if (!isset($record->header->identifier) || trim((string) $record->header->identifier) === '') {
                    throw new \UnexpectedValueException(
                        'OAI-PMH record ' . $recordCount . ' for ' . $collection . ' has no identifier.'
                    );
                }

                $metadata = $record->metadata->children($oaiPmhApi['namespace'], true);
                foreach ($this->extractHarvestedRecords(
                    $collection,
                    (string) $record->header->identifier,
                    $metadata,
                    $oaiPmhApi['resource_data_xpath'] . '/' . $oaiPmhApi['resourcespace_id'],
                    $oaiPmhApi['media_id_xpath'],
                    $oaiPmhApi['archive_status_xpath'],
                    $archiveMd5Xpath
                ) as $harvestedRecord) {
                    $harvestedRecords[] = $harvestedRecord;
                }
            }

            $harvestComplete = $this->validateCompletedHarvest($collection, $records, $recordCount);
        }
        catch(OaipmhException $e) {
            if($e->getOaiErrorCode() == 'noRecordsMatch' && $recordCount === 0) {
                $harvestComplete = true;
            } elseif ($recordCount > 0 && in_array($e->getOaiErrorCode(), ['noRecordsMatch', 'badResumptionToken'], true)) {
                unset($harvestedRecords);
                return $this->retryHarvestAsSmallerWindows(
                    $oaiPmhEndpoint,
                    $collection,
                    $from,
                    $until,
                    $oaiPmhApi,
                    $archiveMd5Xpath,
                    $oaiGranularity,
                    $recordCount,
                    $records
                );
            } else {
                echo 'OAI-PMH error (1) at collection ' . $collection . ' for window '
                    . $windowLabel . ': ' . $e . PHP_EOL;
                $this->processError = true;
                $this->coverageError = true;
            }
        }
        catch(HttpException $e) {
            if($this->isNoRecordsHttpException($e)) {
                unset($harvestedRecords);
                return $this->retryHarvestAsSmallerWindows(
                    $oaiPmhEndpoint,
                    $collection,
                    $from,
                    $until,
                    $oaiPmhApi,
                    $archiveMd5Xpath,
                    $oaiGranularity,
                    $recordCount,
                    $records
                );
            } else {
                echo 'OAI-PMH error (2) at collection ' . $collection . ' for window '
                    . $windowLabel . ': ' . $e . PHP_EOL;
                $this->processError = true;
                $this->coverageError = true;
            }
        }
        catch(\Throwable $e) {
            echo 'OAI-PMH error (3) at collection ' . $collection . ' for window '
                . $windowLabel . ': ' . $e . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
        }

        if (!$harvestComplete) {
            if ($recordCount > 0) {
                echo 'ERROR: Discarding ' . $recordCount . ' partially harvested OAI-PMH records for '
                    . $collection . ' in window ' . $windowLabel . '.' . PHP_EOL;
            }
            return null;
        }

        $this->verboseLog('Finished OAI-PMH window for ' . $collection . ' (' . $recordCount
            . ' records, ' . $windowLabel . ').');

        return [
            'record_count' => $recordCount,
            'records' => $harvestedRecords,
        ];
    }

    private function retryHarvestAsSmallerWindows(
        Endpoint $oaiPmhEndpoint,
        string $collection,
        DateTime $from,
        DateTime $until,
        array $oaiPmhApi,
        string $archiveMd5Xpath,
        string $oaiGranularity,
        int $partialRecordCount,
        ?RecordIteratorInterface $records
    ): ?array {
        if ($oaiGranularity !== Granularity::DATE_AND_TIME || $from >= $until) {
            echo 'ERROR: OAI-PMH could not complete window ' . $from->format('Y-m-d\TH:i:s\Z')
                . ' until ' . $until->format('Y-m-d\TH:i:s\Z') . ' for ' . $collection
                . ' and the window cannot be split any further.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return null;
        }

        // getTotalRecordCount() lazily starts the first request when no record was retrieved.
        // After an initial 404 that would repeat the same failing request outside our catch block.
        $expectedRecordCount = $partialRecordCount === 0 || $records === null
            ? null
            : $records->getTotalRecordCount();
        $progressLabel = $expectedRecordCount === null
            ? $partialRecordCount . ' records (advertised total unknown)'
            : $partialRecordCount . ' of ' . (int) $expectedRecordCount . ' advertised records';
        $middleTimestamp = intdiv($from->getTimestamp() + $until->getTimestamp(), 2);
        $utc = new DateTimeZone('UTC');
        $leftUntil = (new DateTime('@' . $middleTimestamp))->setTimezone($utc);
        $rightFrom = (new DateTime('@' . ($middleTimestamp + 1)))->setTimezone($utc);

        $this->verboseLog('OAI-PMH stopped after ' . $progressLabel . ' for ' . $collection
            . '; retrying the window as two smaller windows.');

        $leftResult = $this->harvestOaiPmhWindow(
            $oaiPmhEndpoint,
            $collection,
            $from,
            $leftUntil,
            $oaiPmhApi,
            $archiveMd5Xpath,
            $oaiGranularity
        );
        if ($leftResult === null) {
            return null;
        }

        $rightResult = $this->harvestOaiPmhWindow(
            $oaiPmhEndpoint,
            $collection,
            $rightFrom,
            $until,
            $oaiPmhApi,
            $archiveMd5Xpath,
            $oaiGranularity
        );
        if ($rightResult === null) {
            return null;
        }

        return [
            'record_count' => $leftResult['record_count'] + $rightResult['record_count'],
            'records' => array_merge($leftResult['records'], $rightResult['records']),
        ];
    }

    private function validateCompletedHarvest(
        string $collection,
        RecordIteratorInterface $records,
        int $recordCount
    ): bool {
        $expectedRecordCount = $records->getTotalRecordCount();
        if ($expectedRecordCount !== null && (int) $expectedRecordCount !== $recordCount) {
            echo 'ERROR: Incomplete OAI-PMH harvest for ' . $collection . ': received ' . $recordCount
                . ' of ' . (int) $expectedRecordCount . ' advertised records.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return false;
        }

        $resumptionToken = trim((string) ($records->getResumptionToken() ?? ''));
        if ($resumptionToken !== '') {
            echo 'ERROR: Incomplete OAI-PMH harvest for ' . $collection
                . ': a resumption token remained after iteration stopped.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return false;
        }

        if ((int) $records->getNumRetrieved() !== $recordCount) {
            echo 'ERROR: Incomplete OAI-PMH harvest for ' . $collection . ': iterator count '
                . (int) $records->getNumRetrieved() . ' differs from processed count ' . $recordCount . '.' . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            return false;
        }

        return true;
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

    private function extractHarvestedRecords(
        string $collection,
        string $assetId,
        $record,
        string $resourceIdXpath,
        string $mediaIdXpath,
        string $archiveStatusXpath,
        string $archiveMd5Xpath
    ): array {
        $archiveStatus = null;
        $archiveStatuses = $record->xpath($archiveStatusXpath);
        if (is_array($archiveStatuses)) {
            foreach ($archiveStatuses as $status) {
                $archiveStatus = trim((string) $status);
            }
        }

        $mediaId = null;
        $mediaIds = $record->xpath($mediaIdXpath);
        if (is_array($mediaIds)) {
            foreach ($mediaIds as $candidateMediaId) {
                $mediaId = trim((string) $candidateMediaId);
            }
        }

        $archivedMd5 = $this->extractSingleMd5($record, $archiveMd5Xpath);
        $resourceIds = $record->xpath($resourceIdXpath);
        if (!is_array($resourceIds)) {
            return [];
        }

        $harvestedRecords = [];
        foreach ($resourceIds as $id) {
            $resourceId = trim((string) $id);
            if (preg_match('/^[0-9]+$/', $resourceId) !== 1) {
                continue;
            }
            if ($this->resourceIdFilter !== null && (int) $resourceId !== $this->resourceIdFilter) {
                continue;
            }

            $harvestedRecords[] = [
                'collection' => $collection,
                'asset_id' => $assetId,
                'resource_id' => $resourceId,
                'media_id' => $mediaId,
                'archive_status' => $archiveStatus,
                'archive_md5' => $archivedMd5,
            ];
        }

        return $harvestedRecords;
    }

    private function loadKnownOffloadChecksums(): bool
    {
        if ($this->knownOffloadChecksumsByResource !== null) {
            return true;
        }

        try {
            $this->knownOffloadChecksumsByResource = [];
            $storedChecksums = $this->entityManager->getRepository(FileChecksum::class)->findAll();
            foreach ($storedChecksums as $storedChecksum) {
                $resourceId = (string) $storedChecksum->getResourceId();
                $checksum = strtolower(trim($storedChecksum->getFileChecksum()));
                if (preg_match('/^[0-9a-f]{32}$/', $checksum) === 1) {
                    $this->knownOffloadChecksumsByResource[$resourceId][$checksum] = true;
                }
            }
        } catch (\Throwable $e) {
            echo 'ERROR: Could not load the checksums of files uploaded by this connector: '
                . $e->getMessage() . PHP_EOL;
            $this->processError = true;
            $this->coverageError = true;
            $this->knownOffloadChecksumsByResource = null;
            return false;
        }

        return true;
    }

    private function processHarvestedRecord(array $harvestedRecord, array $completedStatuses): void
    {
        $collection = $harvestedRecord['collection'];
        $assetId = $harvestedRecord['asset_id'];
        $resourceId = $harvestedRecord['resource_id'];
        $mediaId = $harvestedRecord['media_id'];
        $archiveStatus = $harvestedRecord['archive_status'];
        $archivedMd5 = $harvestedRecord['archive_md5'];

        $statusLabel = $archiveStatus === null || $archiveStatus === '' ? 'unknown' : $archiveStatus;
        $this->observedArchiveStatuses[$resourceId][$statusLabel] = true;

        $knownChecksums = $this->knownOffloadChecksumsByResource[$resourceId] ?? [];
        if ($archivedMd5 === null || !isset($knownChecksums[$archivedMd5])) {
            // A numeric external identifier may coincidentally equal a ResourceSpace ID. Only
            // report it as a connector mismatch when this resource is known to our checksum DB,
            // or when it was explicitly requested by a targeted command.
            if ($knownChecksums !== [] || $this->resourceIdFilter !== null) {
                $message = $archivedMd5 === null
                    ? 'The OAI-PMH record contains ResourceSpace ID ' . $resourceId
                        . ' but does not contain one valid preservation MD5 checksum.'
                    : 'The OAI-PMH record contains ResourceSpace ID ' . $resourceId
                        . ' but preservation MD5 ' . $archivedMd5
                        . ' does not match a checksum uploaded for that resource by this connector.';
                $this->resourceChecksumMismatches[$resourceId] = $message;
                echo 'WARNING: ' . $message . ' The record was not linked to ResourceSpace.' . PHP_EOL;
            }
            return;
        }
        $this->md5VerifiedResourceIds[$resourceId] = true;

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

                    $imageUrl = empty($mediaId)
                        ? null
                        : $this->connectorUrl . 'download/' . $collection . '/' . $mediaId;
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
                                return;
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
                                return;
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
                                                $archivedMd5
                                            );
                                            if ($replacementSafetyError !== null) {
                                                $this->failResourceProcessing(
                                                    $resourceId,
                                                    $replacementSafetyError,
                                                    $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                                );
                                                return;
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
                                            return;
                                        }
                                        echo 'Replaced resource ' . $resourceId . ' original file: ' . $replaceMessage . PHP_EOL;
                                    }
                                    if (!$this->clearProcessingError(
                                        $resourceId,
                                        $resourceMetadata[$this->resourceSpaceMetadataFields['offload_error']] ?? ''
                                    )) {
                                        return;
                                    }
                                    if (!$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded'])) {
                                        $this->failResourceProcessing(
                                            $resourceId,
                                            'All meemoo data was processed, but the final Offloaded status could not be written to ResourceSpace.',
                                            ''
                                        );
                                        return;
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
                                        return;
                                    }
                                    if (!$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded_but_keep_original'])) {
                                        $this->failResourceProcessing(
                                            $resourceId,
                                            'All meemoo data was processed, but the final Offloaded status could not be written to ResourceSpace.',
                                            ''
                                        );
                                        return;
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

    private function reportTargetedResourceStatus(int $resourceId): void
    {
        if ($this->coverageError) {
            echo 'RESULT: The current meemoo status of resource ' . $resourceId
                . ' could not be determined because the OAI-PMH harvest was incomplete.' . PHP_EOL;
            return;
        }

        $resourceIdString = (string) $resourceId;
        $statuses = array_keys($this->observedArchiveStatuses[$resourceIdString] ?? []);
        $statusText = $statuses === [] ? 'none' : implode(', ', $statuses);

        if (in_array($resourceIdString, $this->resourcesProcessed, true)) {
            echo 'RESULT: Resource ' . $resourceId
                . ' has an ID+MD5-verified completed meemoo record (observed archive status: '
                . $statusText . ').' . PHP_EOL;
            return;
        }

        if (isset($this->md5VerifiedResourceIds[$resourceIdString])) {
            echo 'RESULT: Resource ' . $resourceId
                . ' was found with a matching MD5, but has no completed meemoo record '
                . '(observed archive status: ' . $statusText . ').' . PHP_EOL;
            return;
        }

        if ($statuses !== []) {
            echo 'RESULT: OAI-PMH record(s) containing ResourceSpace ID ' . $resourceId
                . ' were found, but none matched the stored upload MD5 '
                . '(observed archive status: ' . $statusText . ').' . PHP_EOL;
            return;
        }

        echo 'RESULT: No OAI-PMH record containing ResourceSpace ID ' . $resourceId
            . ' was found in the selected window.' . PHP_EOL;
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

    private function getReplacementSafetyError($resourceId, $originalFilename, string $archivedMd5): ?string
    {
        if (!is_string($originalFilename) || trim($originalFilename) === '') {
            return 'Cannot safely replace the original because its filename is missing.';
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return 'Cannot safely replace the original because its file extension is missing.';
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
                        $checksumMismatch = $this->resourceChecksumMismatches[$resourceIdString] ?? null;
                        if ($pendingAgeSeconds < $this->pendingProcessingGraceSeconds) {
                            if ($checksumMismatch !== null) {
                                echo 'Resource ' . $resourceId
                                    . ' remains pending because an OAI-PMH record with the same ID did not pass MD5 verification.'
                                    . PHP_EOL;
                            } else if (in_array($resourceIdString, $this->resourcesSeen, true)) {
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
                        if ($checksumMismatch !== null) {
                            $message = $checksumMismatch;
                        } else if (in_array($resourceIdString, $this->resourcesSeen, true)) {
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

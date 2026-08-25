<?php

namespace App\Command;

use App\Entity\FileChecksum;
use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use App\Util\FtpUtil;
use App\Util\OaiPmhApiUtil;
use App\Util\RestApi;
use App\Util\XMLUtil;
use App\Twig\MetadataTemplateExtension;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMXPath;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TemplateWrapper;

class OffloadResourcesCommand extends Command
{
    private const RESOURCE_SELECTION_SAFETY_MARGIN_SECONDS = 2 * 60 * 60;

    private ParameterBagInterface $params;
    private EntityManagerInterface $entityManager;
    private bool $dryRun;
    private bool $forceUpdateMetadata;
    private bool $verbose;
    private bool $showProgress = false;
    private ?int $resourceIdFilter;
    private bool $resourceIdFilterMatched = false;

    private FtpUtil $ftpUtil;
    private ResourceSpace $resourceSpace;
    private array $oaiPmhEndpoints = array();
    private RestApi $restApi;

    private array $mandatoryResourceSpaceFields;
    private array $forbiddenResourceSpaceFields;
    private array $relevantResourceSpaceFields;
    private array $relevantMetadataFields;
    private string $lastTimestampFile;
    private string $outputFolder;
    private ?string $outputSubFolder;
    private string $templateFile;
    private string $templateXsdSchemaFile;
    private array $supportedExtensions;
    private array $collections;
    private array $offloadStatusField;
    private array $resourceSpaceMetadataFields;
    private string $errorField;
    private int $offloadErrorFieldRef;
    private array $conversionTable;
    private array $digitizationPartners;
    private array $descriptionFallbackFields = ['inventorynumber'];
    private string $descriptionFallbackSeparator = ' | ';
    private array $managedNestedMetadataFields = [];
    private string $collectionKey;
    private array $offloadStatusFilter;
    private bool $deleteOriginals;
    private bool $uploadError = false;
    private bool $md5BackfillFailed = false;
    private bool $statusUpdateError = false;
    private bool $metadataUpdateError = false;
    private bool $searchError = false;
    private bool $cursorSafetyError = false;
    private bool $resourceError = false;
    private array $metadataChangeSummary = array();

    private bool $overrideCertificateAuthorityFile;
    private string $sslCertificateAuthorityFile;
    private array $oaiPmhApi;

    private int $lastOffloadTimestamp;
    private int $lastMetadataTemplateChange;
    private ?TemplateWrapper $metadataTemplate = null;

    public function __construct(
        ParameterBagInterface $params,
        EntityManagerInterface $entityManager,
        $forceUpdate = false,
        $dryRun = false,
        ?string $outputSubFolder = null,
        ?int $resourceIdFilter = null
    )
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->forceUpdateMetadata = $forceUpdate;
        $this->dryRun = $dryRun;
        $this->outputSubFolder = $outputSubFolder;
        $this->resourceIdFilter = $resourceIdFilter;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:offload-resources')
            ->setDescription('Lists ResourceSpace resources and offloads images with the appropriate metadata onto an FTP server. Also updates changed metadata of existing resources in meemoo\'s archive.')
            ->addOption(
                'resource-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Only offload the ResourceSpace resource with this numeric ID; never advances the global offload timestamp.'
            )
            ->addOption(
                'progress',
                null,
                InputOption::VALUE_NONE,
                'Show timed progress for searches and selected resources.'
            );
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resourceIdOption = $input->getOption('resource-id');
        if ($resourceIdOption !== null) {
            if (!is_string($resourceIdOption) || !ctype_digit($resourceIdOption) || (int) $resourceIdOption < 1) {
                $output->writeln('<error>--resource-id must be a positive numeric ResourceSpace ID.</error>');
                return Command::INVALID;
            }
            $this->resourceIdFilter = (int) $resourceIdOption;
            echo 'Limiting this offload to ResourceSpace resource ' . $this->resourceIdFilter . '.' . PHP_EOL;
        }

        $this->verbose = $input->getOption('verbose');
        $this->showProgress = (bool) $input->getOption('progress');
        return $this->offloadImages();
    }

    public function offloadImages(): int
    {
        $this->init();
        $this->processCollections();
        // Preserve non-zero reporting for operational failures. Only incomplete ResourceSpace
        // search coverage blocks the cursor; individual resources remain available for manual follow-up.
        return ($this->uploadError
            || $this->statusUpdateError
            || $this->metadataUpdateError
            || $this->searchError
            || $this->resourceError) ? 1 : 0;
    }

    private function init(): void
    {
        $this->deleteOriginals = $this->params->get('delete_originals');
        $this->initDescriptionFallback();

        $this->resourceSpace = new ResourceSpace($this->params);
        $this->ftpUtil = new FtpUtil($this->params);
        $this->restApi = new RestApi($this->params);

        $this->lastOffloadTimestamp = 0;
        $this->lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if (file_exists($this->lastTimestampFile)) {
            $file = fopen($this->lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $this->lastTimestampFile . "').");
            $this->lastOffloadTimestamp = intval(fgets($file));
            fclose($file);
        }

        $configuredOutputFolder = $this->params->get('output_folder');
        // Keep the trailing separator when it is the whole path (e.g. an absolute root like '/')
        $trimmedOutputFolder = rtrim($configuredOutputFolder, '/\\');
        $this->outputFolder = $trimmedOutputFolder !== '' ? $trimmedOutputFolder : $configuredOutputFolder;
        if ($this->outputSubFolder !== null && trim($this->outputSubFolder, '/\\') !== '') {
            $this->outputFolder = rtrim($this->outputFolder, '/\\') . '/' . trim($this->outputSubFolder, '/\\');
        }
        if (!is_dir($this->outputFolder)) {
            mkdir($this->outputFolder, 0777, true);
        }

        $this->templateFile = $this->params->get('template_file');
        if (!file_exists($this->templateFile)) {
            die('Metadata template is missing, please configure the location of your template in connector.yml and make sure it exists.');
        }

        $this->lastMetadataTemplateChange = filemtime($this->templateFile);

        // Automatically determine which ResourceSpace fields are relevant based on the occurrences of 'resource.' in the metadata template.
        $this->relevantResourceSpaceFields = array();
        $metadataTemplate = file_get_contents($this->templateFile);
        if (!is_string($metadataTemplate)) {
            die('Metadata template could not be read - exiting.');
        }
        $this->managedNestedMetadataFields = $this->extractManagedNestedMetadataFields($metadataTemplate);
        preg_match_all('/[^a-zA-Z0-9\-_]resource\.([a-zA-Z0-9\-_]+)[^a-zA-Z0-9\-_]/', $metadataTemplate, $matches);
        foreach ($matches[1] as $match) {
            if (!in_array($match, $this->relevantResourceSpaceFields)) {
                $this->relevantResourceSpaceFields[] = $match;
            }
        }
        // Regular descriptions affect existing assets. Fallback-only fields do not: they are used
        // exclusively during ingest and an ingest already forces metadata generation.
        foreach (['description', 'tmsdescription'] as $field) {
            if (!in_array($field, $this->relevantResourceSpaceFields)) {
                $this->relevantResourceSpaceFields[] = $field;
            }
        }

        $this->templateXsdSchemaFile = $this->params->get('template_xsd_schema_file');
        if (!file_exists($this->templateXsdSchemaFile)) {
            die('XSD schema is missing, please configure the location of your xsd schema in connector.yml and make sure it exists.');
        }

        $this->relevantMetadataFields = array();
        $unmanagedMetadataFields = array();
        // Grab all the relevant top-level metadata fields from the XSD schema
        $domDocument = new DOMDocument();
        $domDocument->loadXML(file_get_contents($this->templateXsdSchemaFile));
        $xpath = new DOMXPath($domDocument);
        // Find the top-level element by searching for <xs:element name="CP"> which is a mandatory field
        $results = $xpath->query('//xs:element[@name="CP"]');
        foreach($results as $result) {
            $parent = $result->parentNode;
            foreach($parent->childNodes as $node) {
                if($node->hasAttributes()) {
                    foreach ($node->attributes as $attribute) {
                        if ($attribute->nodeName == 'name') {
                            // md5 is a special field, we want to exclude this one
                            if($attribute->nodeValue != 'md5' && !in_array($attribute->nodeValue, $this->relevantMetadataFields)) {
                                // Only fields this template can actually produce may be overwritten or
                                // emptied at meemoo. Anything else (populated by meemoo or another source)
                                // must be left alone, otherwise getDifference() would wipe it.
                                if ($this->isFieldManagedByTemplate($attribute->nodeValue, $metadataTemplate)) {
                                    $this->relevantMetadataFields[] = $attribute->nodeValue;
                                } else if (!in_array($attribute->nodeValue, $unmanagedMetadataFields)) {
                                    $unmanagedMetadataFields[] = $attribute->nodeValue;
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }
        if ($this->verbose && !empty($unmanagedMetadataFields)) {
            echo 'INFO: these meemoo fields are not produced by the template and will never be overwritten or emptied: '
                . implode(', ', $unmanagedMetadataFields) . PHP_EOL;
        }

        $this->supportedExtensions = $this->params->get('supported_extensions');
        $this->mandatoryResourceSpaceFields = $this->params->get('mandatory_resourcespace_fields');
        $this->forbiddenResourceSpaceFields = $this->params->get('forbidden_resourcespace_fields');
        $this->collections = $this->params->get('collections');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $this->errorField = $this->resourceSpaceMetadataFields['offload_error'];
        $configuredErrorFieldRef = $this->resourceSpaceMetadataFields['offload_error_ref'] ?? null;
        if ((!is_int($configuredErrorFieldRef) && !is_string($configuredErrorFieldRef))
            || !ctype_digit((string) $configuredErrorFieldRef)
            || (int) $configuredErrorFieldRef < 1) {
            throw new \InvalidArgumentException(
                'resourcespace_metadata_fields.offload_error_ref must be a positive ResourceSpace field ID.'
            );
        }
        $this->offloadErrorFieldRef = (int) $configuredErrorFieldRef;
        $offloadValues = $this->offloadStatusField['values'];
        $this->conversionTable = $this->params->get('conversion_table');
        // Lowercased for case-insensitive matching in the template (e.g. 'D/arch' vs 'D/Arch')
        $this->digitizationPartners = array_map('mb_strtolower', $this->params->get('digitization_partners'));

        $this->collectionKey = $this->collections['key'];

        $this->offloadStatusFilter = [
            $offloadValues['offload'],
            $offloadValues['offload_but_keep_original'],
            $offloadValues['offloaded'],
            $offloadValues['offloaded_but_keep_original'],
            $offloadValues['offload_failed'],
            $offloadValues['offload_failed_but_keep_original'],
            $offloadValues['offloaded_now_delete_original']
        ];

        $this->overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $this->sslCertificateAuthorityFile = $this->params->get('ssl_certificate_authority_file');
        $this->oaiPmhApi = $this->params->get('oai_pmh_api');
    }

    private function initDescriptionFallback(): void
    {
        if (!$this->params->has('description_fallback')) {
            return;
        }

        $config = $this->params->get('description_fallback');
        if (!is_array($config)) {
            throw new \InvalidArgumentException('The "description_fallback" configuration must be a YAML mapping.');
        }

        if (array_key_exists('fields', $config)) {
            if (!is_array($config['fields'])) {
                throw new \InvalidArgumentException('The "description_fallback.fields" configuration must be a YAML list.');
            }

            $fields = [];
            foreach ($config['fields'] as $field) {
                if (!is_string($field) || trim($field) === '') {
                    throw new \InvalidArgumentException('Every entry in "description_fallback.fields" must be a non-empty ResourceSpace field name.');
                }
                $fields[] = trim($field);
            }

            if ($fields === []) {
                throw new \InvalidArgumentException('The "description_fallback.fields" configuration must contain at least one ResourceSpace field name.');
            }
            $this->descriptionFallbackFields = array_values(array_unique($fields));
        }

        if (array_key_exists('separator', $config)) {
            if (!is_string($config['separator'])) {
                throw new \InvalidArgumentException('The "description_fallback.separator" configuration must be a string.');
            }
            $this->descriptionFallbackSeparator = $config['separator'];
        }
    }

    private function processCollections(): void
    {
        // Keep track of resource ID's that are already processed to prevent duplicates (duplicates may emerge through different searches)
        $alreadyProcessed = array();
        $timestamp = time();
        $useFastSelection = $this->shouldUseFastResourceSelection();
        $resourcesWithOffloadErrors = array();

        if ($useFastSelection) {
            $errorSearchStartedAt = microtime(true);
            $this->progressLog('Searching ResourceSpace for resources with a non-empty offload error.');
            $resourcesWithOffloadErrors = $this->getResourcesWithOffloadErrors();
            if ($resourcesWithOffloadErrors === null) {
                echo 'ERROR: Could not retrieve ResourceSpace resources with data in offload error field '
                    . $this->offloadErrorFieldRef . '; the offload was stopped.' . PHP_EOL;
                $this->searchError = true;
                return;
            }
            $this->progressLog(
                'Finished offload-error search: ' . count($resourcesWithOffloadErrors) . ' resources found.',
                $errorSearchStartedAt
            );
            if ($this->verbose) {
                echo 'INFO: Found ' . count($resourcesWithOffloadErrors)
                    . ' resources with a non-empty offload error.' . PHP_EOL;
            }
        }

        // Loop through all collections
        foreach ($this->collections['values'] as $collection) {
            if ($this->resourceIdFilter !== null && $this->resourceIdFilterMatched) {
                break;
            }
            foreach($this->offloadStatusFilter as $filter) {
                if ($this->resourceIdFilter !== null && $this->resourceIdFilterMatched) {
                    break;
                }
                $searchStartedAt = microtime(true);
                $this->progressLog('Searching collection ' . $collection . ' for status "' . $filter . '".');
                $allResources = $this->resourceSpace->getAllResources(urlencode('"' . $this->collectionKey . ':' . $collection . '" "' . $this->offloadStatusField['key'] . ':' . $filter . '"'));
                if (!is_array($allResources)) {
                    // Never advance the timestamp after a failed search: the resources we did not see
                    // would otherwise be considered up to date and never be revisited.
                    echo 'ERROR: Could not retrieve ResourceSpace resources for ' . $collection . ' with status "' . $filter . '".' . PHP_EOL;
                    $this->searchError = true;
                    continue;
                }
                $this->progressLog(
                    'Finished search for ' . $collection . ' / "' . $filter . '": ' . count($allResources) . ' candidates.',
                    $searchStartedAt
                );
                // Loop through all resources in this collection
                foreach ($allResources as $resourceInfo) {
                    if ($this->resourceIdFilter !== null && $this->resourceIdFilterMatched) {
                        break;
                    }
                    $resourceId = $resourceInfo['ref'];
                    if ($this->resourceIdFilter !== null && (int) $resourceId !== $this->resourceIdFilter) {
                        continue;
                    }
                    if ($this->resourceIdFilter !== null) {
                        $this->resourceIdFilterMatched = true;
                    }
                    if (isset($alreadyProcessed[$resourceId])) {
                        continue;
                    }
                    if ($useFastSelection
                        && !$this->resourceNeedsFullMetadata($filter, $resourceInfo, $resourcesWithOffloadErrors)) {
                        continue;
                    }
                    $alreadyProcessed[$resourceId] = true;

                    // Get this resource's metadata, but only if it has an appropriate offloadStatus
                    $metadataReadStartedAt = microtime(true);
                    $this->progressLog(
                        'Resource ' . $resourceId . ' selected from ' . $collection . ' / "' . $filter
                        . '"; fetching full ResourceSpace metadata.'
                    );
                    $metadataReadFailed = false;
                    $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains(
                        $resourceId,
                        $this->offloadStatusField['key'],
                        $this->offloadStatusFilter,
                        $metadataReadFailed
                    );
                    if ($metadataReadFailed) {
                        echo 'ERROR: Could not retrieve ResourceSpace metadata for resource ' . $resourceId . '.' . PHP_EOL;
                        $this->resourceError = true;
                        continue;
                    }
                    $this->progressLog('Fetched full metadata for resource ' . $resourceId . '.', $metadataReadStartedAt);
                    if ($resourceMetadata != null) {
                        if (array_key_exists($this->offloadStatusField['key'], $resourceMetadata)) {
                            if ($resourceMetadata[$this->offloadStatusField['key']] == $this->offloadStatusField['values']['offloaded_now_delete_original']) {
                                $assetUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_asset_url']] ?? '';
                                $imageUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_image_url']] ?? '';
                                if(empty($assetUrl)) {
                                    $this->failResourceFinalization(
                                        $resourceId,
                                        'Cannot replace resource: meemoo asset URL is missing.',
                                        $resourceMetadata[$this->errorField] ?? ''
                                    );
                                    continue;
                                } else if(empty($imageUrl)) {
                                    $this->failResourceFinalization(
                                        $resourceId,
                                        'Cannot replace resource: meemoo original download URL is missing.',
                                        $resourceMetadata[$this->errorField] ?? ''
                                    );
                                    continue;
                                } else {
                                    echo 'Replacing resource ' . $resourceId . ', as it has status \'Offloaded now delete original\'.' . PHP_EOL;
                                    if (!$this->dryRun) {
                                        if ($this->deleteOriginals) {
                                            $originalFilename = $resourceMetadata['originalfilename'] ?? null;
                                            if (!$this->resourceSpace->isReplacementFilename($resourceId, $originalFilename)) {
                                                $replacementSafetyError = $this->getManualReplacementSafetyError(
                                                    $resourceId,
                                                    $originalFilename,
                                                    (string) $assetUrl,
                                                    $collection
                                                );
                                                if ($replacementSafetyError !== null) {
                                                    $this->failResourceFinalization(
                                                        $resourceId,
                                                        $replacementSafetyError,
                                                        $resourceMetadata[$this->errorField] ?? ''
                                                    );
                                                    continue;
                                                }
                                            }

                                            $result = $this->resourceSpace->replaceOriginal($resourceId, $resourceMetadata['originalfilename'] ?? null, $this->entityManager);
                                            $replaceMessage = is_string($result['message'] ?? null)
                                                ? $result['message']
                                                : json_encode($result['message'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                            if (($result['status'] ?? false) !== true) {
                                                $this->failResourceFinalization(
                                                    $resourceId,
                                                    'Error replacing original: ' . $replaceMessage,
                                                    $resourceMetadata[$this->errorField] ?? ''
                                                );
                                                continue;
                                            }
                                        }
                                        if (!$this->clearFinalizationError($resourceId, $resourceMetadata[$this->errorField] ?? '')) {
                                            continue;
                                        }
                                        if (!$this->resourceSpace->updateFieldVerified(
                                            $resourceId,
                                            $this->offloadStatusField['key'],
                                            $this->offloadStatusField['values']['offloaded']
                                        )) {
                                            $this->failResourceFinalization(
                                                $resourceId,
                                                'Original was handled, but the final Offloaded status could not be written to ResourceSpace.',
                                                ''
                                            );
                                            continue;
                                        }
                                    }
                                    continue;
                                }
                            }
                        }

                        $extension = strtolower(pathinfo($resourceMetadata['originalfilename'], PATHINFO_EXTENSION));
                        $failed = false;
                        if (!in_array($extension, $this->supportedExtensions)) {
                            $failed = true;
                            if(!$this->dryRun) {
                                $this->resourceSpace->updateError($resourceId, $this->errorField, 'Extension "' . $extension . '" is not supported.', $resourceMetadata, false, true);
                            }
                            echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has extension "' . $extension . '", which is not supported.' . PHP_EOL;
                        }
                        if(!$failed) {
                            foreach ($this->mandatoryResourceSpaceFields as $mandatoryField) {
                                if (!array_key_exists($mandatoryField, $resourceMetadata)) {
                                    $failed = true;
                                    if(!$this->dryRun) {
                                        $this->resourceSpace->updateError($resourceId, $this->errorField, 'Mandatory field "' . $mandatoryField . '" missing.', $resourceMetadata, false, true);
                                    }
                                    echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') is missing the mandatory metadata field "' . $mandatoryField . '".' . PHP_EOL;
                                } else if (empty($resourceMetadata[$mandatoryField])) {
                                    $failed = true;
                                    if(!$this->dryRun) {
                                        $this->resourceSpace->updateError($resourceId, $this->errorField, 'Mandatory field "' . $mandatoryField . '" is empty.', $resourceMetadata, false, true);
                                    }
                                    echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has an empty mandatory metadata field "' . $mandatoryField . '".' . PHP_EOL;
                                }
                            }
                        }
                        if(!$failed) {
                            foreach ($this->forbiddenResourceSpaceFields as $forbiddenField => $fieldData) {
                                if (array_key_exists($forbiddenField, $resourceMetadata)) {
                                    foreach($fieldData as $entry) {
                                        if ($resourceMetadata[$forbiddenField] == $entry) {
                                            $failed = true;
                                            if(!$this->dryRun) {
                                                $this->resourceSpace->updateError($resourceId, $this->errorField, 'Value "' . $entry . '" in field "' . $forbiddenField . '" is not allowed.', $resourceMetadata, false, true);
                                            }
                                            echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has value "' . $entry . '" for metadata field "' . $forbiddenField . '", which is not allowed.' . PHP_EOL;
                                        }
                                    }
                                }
                            }
                        }

                        $offloadFile = false;
                        $fileModifiedTimestampAsString = '';
                        if(!$this->forceUpdateMetadata) {
                            // Always offload the file and metadata if offloadstatus is set to 'Offload', 'Offload but keep original', 'Offload failed' or 'Failed but keep original'
                            if (array_key_exists($this->offloadStatusField['key'], $resourceMetadata)) {
                                $fieldValue = $resourceMetadata[$this->offloadStatusField['key']];
                                if ($fieldValue == $this->offloadStatusField['values']['offload'] || $fieldValue == $this->offloadStatusField['values']['offload_but_keep_original'] || $fieldValue == $this->offloadStatusField['values']['offload_failed'] || $fieldValue == $this->offloadStatusField['values']['offload_failed_but_keep_original']) {
                                    $offloadFile = true;
                                }
                            }
                            if (!$offloadFile) {
                                // If the file was already offloaded in the past, check when the file was last modified to determine if we need to re-upload it
                                if (array_key_exists('file_modified', $resourceInfo)) {
                                    $fileModifiedTimestampAsString = $resourceInfo['file_modified'];
                                    if (strlen($fileModifiedTimestampAsString) > 0) {
                                        $fileModifiedTimestamp = strtotime($fileModifiedTimestampAsString);
                                        if ($fileModifiedTimestamp > $this->lastOffloadTimestamp) {
                                            $offloadFile = true;
                                        }
                                    }
                                }
                            }
                            if($offloadFile) {
                                if ($this->resourceSpace->isReplacementFilename($resourceId, $resourceMetadata['originalfilename'])) {
                                    $offloadFile = false;
                                    if ($this->verbose) {
                                        echo 'INFO: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has not been offloaded, as it is a replacement of an original.' . PHP_EOL;
                                    }
                                }
                            }
                        }

                        if(!$failed) {
                            $resourceProcessingStartedAt = microtime(true);
                            $this->progressLog('Processing selected resource ' . $resourceId . '.');
                            $this->processResource($resourceId, $resourceInfo, $resourceMetadata, $collection, $extension, $offloadFile, $fileModifiedTimestampAsString);
                            $this->progressLog('Finished selected resource ' . $resourceId . '.', $resourceProcessingStartedAt);
                        } else if($offloadFile) {
                            // Only set status to 'failed' if we actually wanted to offload the file, NOT when we're only updating metadata
                            if(!$this->dryRun) {
                                $statusKey = $this->offloadStatusField['key'];
                                if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload']
                                    || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
                                    || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offloaded']) {
                                    $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed']);
                                } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original']
                                    || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
                                    || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offloaded_but_keep_original']) {
                                    $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed_but_keep_original']);
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($this->resourceIdFilter !== null && !$this->resourceIdFilterMatched) {
            echo 'ERROR: ResourceSpace resource ' . $this->resourceIdFilter
                . ' was not found in the configured collections with a supported offload status.' . PHP_EOL;
            $this->resourceError = true;
        }

        $this->logMetadataChangeSummary();

        // The cursor represents search coverage, not whether every individual resource succeeded.
        // Known resource failures remain visible through their status/error and must not make all
        // later runs rescan an ever-growing time window.
        if ($this->shouldAdvanceGlobalOffloadTimestamp()) {
            $file = fopen($this->lastTimestampFile, "w") or die("Unable to open file containing last offload timestamp ('" . $this->lastTimestampFile . "').");
            fwrite($file, $timestamp);
            fclose($file);
        } else {
            if ($this->searchError) {
                echo 'WARNING: the last offload timestamp was not updated because ResourceSpace could not be searched completely.' . PHP_EOL;
            }
            if ($this->cursorSafetyError) {
                echo 'WARNING: the last offload timestamp was not updated because a blocked metadata update could not be recorded in ResourceSpace.' . PHP_EOL;
            }
            if (!$this->dryRun && !$this->forceUpdateMetadata && $this->resourceIdFilter !== null) {
                echo 'INFO: the global last offload timestamp was not updated because this was a targeted resource offload.' . PHP_EOL;
            }
        }
    }

    private function shouldAdvanceGlobalOffloadTimestamp(): bool
    {
        return !$this->dryRun
            && !$this->forceUpdateMetadata
            && !$this->searchError
            && !$this->cursorSafetyError
            && $this->resourceIdFilter === null;
    }

    private function shouldUseFastResourceSelection(): bool
    {
        return !$this->dryRun
            && !$this->forceUpdateMetadata
            && $this->resourceIdFilter === null
            && $this->lastMetadataTemplateChange <= $this->lastOffloadTimestamp;
    }

    private function getResourcesWithOffloadErrors(): ?array
    {
        $rows = $this->resourceSpace->getAllResources(
            urlencode('!hasdata' . $this->offloadErrorFieldRef)
        );
        if (!is_array($rows)) {
            return null;
        }

        $resourceIds = array();
        foreach ($rows as $row) {
            $resourceId = is_array($row) ? ($row['ref'] ?? null) : null;
            if ((!is_int($resourceId) && !is_string($resourceId))
                || !ctype_digit((string) $resourceId)
                || (int) $resourceId < 1) {
                return null;
            }
            $resourceIds[(int) $resourceId] = true;
        }

        return $resourceIds;
    }

    private function resourceNeedsFullMetadata(string $status, array $resourceInfo, array $resourcesWithOffloadErrors): bool
    {
        $skipEligibleStatuses = [
            $this->offloadStatusField['values']['offloaded'],
            $this->offloadStatusField['values']['offloaded_but_keep_original'],
        ];
        if (!in_array($status, $skipEligibleStatuses, true)) {
            return true;
        }

        $resourceId = $resourceInfo['ref'] ?? null;
        if ((!is_int($resourceId) && !is_string($resourceId))
            || !ctype_digit((string) $resourceId)
            || (int) $resourceId < 1) {
            return true;
        }
        if (isset($resourcesWithOffloadErrors[(int) $resourceId])) {
            return true;
        }

        $selectionTimestamp = $this->lastOffloadTimestamp - self::RESOURCE_SELECTION_SAFETY_MARGIN_SECONDS;
        return $this->searchTimestampRequiresFullMetadata($resourceInfo, 'modified', $selectionTimestamp)
            || $this->searchTimestampRequiresFullMetadata($resourceInfo, 'file_modified', $selectionTimestamp);
    }

    private function searchTimestampRequiresFullMetadata(array $resourceInfo, string $key, int $selectionTimestamp): bool
    {
        $value = trim((string) ($resourceInfo[$key] ?? ''));
        if ($value === '') {
            return true;
        }

        $timestamp = strtotime($value);
        return $timestamp === false || $timestamp > $selectionTimestamp;
    }

    private function processResource($resourceId, $resourceInfo, $resourceMetadata, $collection, $extension, $offloadFile, $fileModifiedTimestampAsString): void
    {
        // For debugging purposes
//        var_dump($resourceMetadata);

        $originalStem = pathinfo($resourceMetadata['originalfilename'], PATHINFO_FILENAME);
        $safeStem = $this->sanitizeMeemooFilenamePart($originalStem);

        $uniqueFilenameWithoutExtension = $resourceId . '_' . $safeStem;
        $uniqueFilename = $uniqueFilenameWithoutExtension . '.' . $extension;

        $this->md5BackfillFailed = false;
        $localFilename = null;
        $xmlFile = $this->outputFolder . '/' . $uniqueFilenameWithoutExtension . '.xml';
        $metadataModifiedDate = $resourceInfo['modified'] ?? '';
        $creationDate = $resourceInfo['creation_date'] ?? '';
        $offloadFileBeforeDuplicateCheck = $offloadFile;
        $metadataDecisionStartedAt = microtime(true);
        $this->progressLog('Resource ' . $resourceId . ': determining whether metadata must be updated.');
        $offloadMetadata = $this->shouldOffloadMetadata($resourceId, $resourceInfo, $resourceMetadata, $offloadFile);
        $this->progressLog('Resource ' . $resourceId . ': metadata decision completed.', $metadataDecisionStartedAt);

        if (!$offloadMetadata) {
            $this->progressLog('Resource ' . $resourceId . ': no relevant change; nothing to offload.');
            return;
        }

        $md5 = $offloadFile ? '' : ($resourceMetadata['md5checksum'] ?? '');
        $md5IsKnown = (bool) preg_match('/^[0-9A-Fa-f]{32}$/', $md5);
        if (!$md5IsKnown) {
            // Placeholder for the pre-validation pass only; the real checksum is calculated below.
            $md5 = str_repeat('0', 32);
        }

        // Validate metadata before downloading the image. The final XML is regenerated with the real MD5 after download.
        $validationStartedAt = microtime(true);
        $this->progressLog('Resource ' . $resourceId . ': generating and validating metadata.');
        $domDoc = $this->generateAndValidateXMLFile(
            $resourceId,
            $resourceMetadata,
            $uniqueFilename,
            $xmlFile,
            $collection,
            $md5,
            $creationDate,
            $offloadFile,
            false
        );
        $this->progressLog('Resource ' . $resourceId . ': initial metadata validation completed.', $validationStartedAt);
        if ($domDoc == null) {
            if ($offloadFile) {
                $this->markResourceOffloadFailed($resourceId, $resourceMetadata);
            }
            return;
        }

        if($offloadFile) {
            $localFilename = $this->outputFolder . '/' . $uniqueFilename;
            $downloadStartedAt = microtime(true);
            $this->progressLog('Resource ' . $resourceId . ': requesting and downloading the ResourceSpace original for offload.');
            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId, $extension);
            if (!is_string($resourceUrl) || $resourceUrl === '' || !$this->resourceSpace->downloadFileVerified($resourceUrl, $localFilename)) {
                $this->failResourceOffload($resourceId, $resourceMetadata, 'Could not download resource file for offload.');
                $offloadMetadata = false;
            } else {
                $md5 = md5_file($localFilename);
                if (!is_string($md5) || !preg_match('/^[0-9A-Fa-f]{32}$/', $md5)) {
                    $this->failResourceOffload($resourceId, $resourceMetadata, 'Could not calculate MD5 checksum for downloaded file.');
                    $offloadMetadata = false;
                } else {
                    if(!$this->dryRun) {
                        $this->resourceSpace->updateField($resourceId, 'md5checksum', $md5);
                    }

                    // Prevent files with duplicate MD5 checksums from being offloaded
                    $existingChecksums = $this->entityManager->createQueryBuilder()
                        ->select('i')
                        ->from(FileChecksum::class, 'i')
                        ->where('i.fileChecksum = :checksum')
                        ->setParameter('checksum', $md5)
                        ->getQuery()
                        ->getResult();
                    foreach ($existingChecksums as $existingChecksum) {
                        $offloadFile = false;
                        // A checksum row only proves these bytes were uploaded at some point, not that
                        // that upload is still awaiting ingest, so this always stays a hard failure.
                        // Only the message differs, to tell the two situations apart.
                        if ((int) $existingChecksum->getResourceId() === (int) $resourceId) {
                            $duplicateMessage = 'This exact file was already offloaded for this same resource. Remove its checksum from the database to force a new offload.';
                        } else {
                            $duplicateMessage = 'This exact file was already offloaded (see resource ' . $existingChecksum->getResourceId() . ').';
                        }
                        echo 'ERROR at resource ' . $resourceId . ': ' . $duplicateMessage . PHP_EOL;
                        if (!$this->dryRun) {
                            $statusKey = $this->offloadStatusField['key'];
                            if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload']) {
                                $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed']);
                                $this->resourceSpace->updateError($resourceId, $this->errorField, $duplicateMessage, $resourceMetadata, false, true);
                            } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original']) {
                                $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed_but_keep_original']);
                                $this->resourceSpace->updateError($resourceId, $this->errorField, $duplicateMessage, $resourceMetadata, false, true);
                            }
                        }
                    }
                }
            }
            $this->progressLog('Resource ' . $resourceId . ': original download step completed.', $downloadStartedAt);
        } else if (!$md5IsKnown) {
            // Metadata-only update of a resource without a stored checksum: download the file to
            // backfill md5checksum in ResourceSpace. If that fails, the XML keeps the placeholder
            // (md5 is mandatory in the XSD but filtered out of the meemoo update), so we flag it in
            // the error field: a non-empty error field makes the next run retry this resource.
            $localFilename = $this->outputFolder . '/' . $uniqueFilename;
            $downloadStartedAt = microtime(true);
            $this->progressLog('Resource ' . $resourceId . ': requesting and downloading the file only to calculate its missing MD5 checksum.');
            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId, $extension);
            if (!is_string($resourceUrl) || $resourceUrl === '' || !$this->resourceSpace->downloadFileVerified($resourceUrl, $localFilename)) {
                $this->warnMissingMd5($resourceId, $resourceMetadata, 'Could not download the file to calculate its missing MD5 checksum.');
            } else {
                $calculatedMd5 = md5_file($localFilename);
                if (!is_string($calculatedMd5) || !preg_match('/^[0-9A-Fa-f]{32}$/', $calculatedMd5)) {
                    $this->warnMissingMd5($resourceId, $resourceMetadata, 'Could not calculate the missing MD5 checksum.');
                } else {
                    $md5 = $calculatedMd5;
                    if (!$this->dryRun) {
                        $this->resourceSpace->updateField($resourceId, 'md5checksum', $md5);
                    }
                }
            }
            $this->progressLog('Resource ' . $resourceId . ': MD5 download step completed.', $downloadStartedAt);
        }

        if ($offloadFile !== $offloadFileBeforeDuplicateCheck) {
            $offloadMetadata = $this->shouldOffloadMetadata($resourceId, $resourceInfo, $resourceMetadata, $offloadFile);
        }

        if ($offloadMetadata) {
            $finalMetadataStartedAt = microtime(true);
            $this->progressLog('Resource ' . $resourceId . ': generating final metadata.');
            $domDoc = $this->generateAndValidateXMLFile(
                $resourceId,
                $resourceMetadata,
                $uniqueFilename,
                $xmlFile,
                $collection,
                $md5,
                $creationDate,
                $offloadFile
            );
            $this->progressLog('Resource ' . $resourceId . ': final metadata generated.', $finalMetadataStartedAt);
            if ($domDoc != null) {
                $offloadStartedAt = microtime(true);
                $this->progressLog(
                    'Resource ' . $resourceId . ': starting '
                    . ($offloadFile ? 'file and metadata upload.' : 'metadata-only synchronization.')
                );
                $offloaded = $this->offloadResource($resourceId, $resourceMetadata, $md5, $domDoc, $xmlFile, $offloadFile, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection);
                $this->progressLog('Resource ' . $resourceId . ': upload/synchronization step completed.', $offloadStartedAt);

                if ($this->verbose) {
                    if ($offloaded && $offloadFile) {
                        echo 'Resource file ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $fileModifiedTimestampAsString . ') has been offloaded' . PHP_EOL;
                    }
                    if ($offloaded) {
                        echo 'Metadata ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') has been offloaded' . PHP_EOL;
                    }
                }
            } else if ($offloadFile) {
                $this->markResourceOffloadFailed($resourceId, $resourceMetadata);
            }
        }

        if($localFilename !== null && file_exists($localFilename)) {
            unlink($localFilename);
        }
    }

    private function progressLog(string $message, ?float $startedAt = null): void
    {
        if (!$this->showProgress) {
            return;
        }

        $duration = $startedAt === null
            ? ''
            : ' (' . number_format(microtime(true) - $startedAt, 2, '.', '') . ' s)';
        echo date('Y-m-d H:i:s') . ' - PROGRESS: ' . $message . $duration . PHP_EOL;
    }

    private function shouldOffloadMetadata($resourceId, array $resourceInfo, array $resourceMetadata, bool $offloadFile): bool
    {
        $offloadMetadata = $this->forceUpdateMetadata;
        // Always offload the metadata if the file is to be offloaded or if the metadata template has changed since the last offload or if there is an offload error
        if ($offloadFile || $this->lastMetadataTemplateChange > $this->lastOffloadTimestamp) {
            // Always update the metadata if the file was modified
            $offloadMetadata = true;
        }
        // Always update the metadata if the 'offloaderror' field is not empty, this means a previous metadata update or file offload had failed
        if (array_key_exists($this->errorField, $resourceMetadata)) {
            if (!empty($resourceMetadata[$this->errorField])) {
                $offloadMetadata = true;
            }
        }

        $metadataModifiedDate = $resourceInfo['modified'] ?? '';
        if (!$offloadMetadata && strlen($metadataModifiedDate) > 0) {
            // Check if the resource was modified since the last offload
            if (strtotime($metadataModifiedDate) > $this->lastOffloadTimestamp) {
                // Check if any of the relevant ResourceSpace fields has changed since the last offload
                $logReadFailed = false;
                $offloadMetadata = $this->resourceSpace->didRelevantMetadataChange(
                    $resourceId,
                    DateTimeUtil::formatTimestampSimple($this->lastOffloadTimestamp),
                    $this->relevantResourceSpaceFields,
                    $logReadFailed
                );
                if ($logReadFailed) {
                    echo 'ERROR: Could not retrieve the ResourceSpace change log for resource ' . $resourceId . '.' . PHP_EOL;
                    $this->resourceError = true;
                    return false;
                }
            }
        }

        return $offloadMetadata;
    }

    private function markResourceOffloadFailed($resourceId, array $resourceMetadata): void
    {
        // Only set status to 'failed' if we actually wanted to offload the file, NOT when we're only updating metadata
        if ($this->dryRun) {
            return;
        }

        $statusKey = $this->offloadStatusField['key'];
        if (!array_key_exists($statusKey, $resourceMetadata)) {
            return;
        }

        if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload']
            || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
            || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offloaded']) {
            $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed']);
        } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original']
            || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
            || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offloaded_but_keep_original']) {
            $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed_but_keep_original']);
        }
    }

    private function failResourceFinalization($resourceId, string $message, $currentError = ''): void
    {
        echo 'ERROR at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->statusUpdateError = true;

        if ($this->dryRun) {
            return;
        }

        if (!$this->resourceSpace->updateErrorVerified(
            $resourceId,
            $this->errorField,
            $message,
            $currentError,
            true
        )) {
            echo 'ERROR at resource ' . $resourceId . ': the finalization error could not be written to ResourceSpace.' . PHP_EOL;
        }
    }

    private function clearFinalizationError($resourceId, $currentError = ''): bool
    {
        if ($this->dryRun) {
            return true;
        }

        if ($this->resourceSpace->updateErrorVerified($resourceId, $this->errorField, '', $currentError)) {
            return true;
        }

        echo 'ERROR at resource ' . $resourceId . ': the previous finalization error could not be cleared in ResourceSpace.' . PHP_EOL;
        $this->statusUpdateError = true;
        return false;
    }

    private function getManualReplacementSafetyError($resourceId, $originalFilename, string $storedAssetUrls, string $collection): ?string
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

        $validArchivedMd5Found = false;
        foreach (array_reverse($this->splitStoredUrls($storedAssetUrls)) as $assetUrl) {
            $identifierPosition = strrpos($assetUrl, '/');
            if ($identifierPosition === false || $identifierPosition === strlen($assetUrl) - 1) {
                continue;
            }

            $identifier = rawurldecode(substr($assetUrl, $identifierPosition + 1));
            $meemooUrl = $this->oaiPmhApi['url']
                . '?verb=GetRecord&metadataPrefix=' . urlencode($this->oaiPmhApi['metadata_prefix'])
                . '&identifier=' . urlencode($identifier);
            $archivedMetadata = $this->getCurrentMeemooMetadata($meemooUrl, $collection);
            $archivedMd5 = $this->normalizeMd5($archivedMetadata['data']['md5'] ?? null);
            if ($archivedMd5 === null) {
                continue;
            }

            $validArchivedMd5Found = true;
            if (hash_equals($archivedMd5, $currentMd5)) {
                return null;
            }
        }

        if (!$validArchivedMd5Found) {
            return 'Cannot safely replace the original because no stored meemoo asset contains a valid MD5 checksum.';
        }

        return 'The current ResourceSpace original does not match any MD5 checksum archived by meemoo; the original was not replaced.';
    }

    private function splitStoredUrls(string $storedUrls): array
    {
        $urls = preg_split('/\R+/', $storedUrls) ?: [];
        return array_values(array_filter(array_map('trim', $urls), static fn(string $url): bool => $url !== ''));
    }

    private function normalizeMd5($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $checksum = strtolower(trim($value));
        return preg_match('/^[0-9a-f]{32}$/', $checksum) === 1 ? $checksum : null;
    }

    // A field counts as managed when the template contains it as an XML element, so it can be
    // rendered for at least some resources (most are wrapped in conditionals).
    private function isFieldManagedByTemplate(string $field, string $metadataTemplate): bool
    {
        return preg_match('/<' . preg_quote($field, '/') . '[\s>]/', $metadataTemplate) === 1;
    }

    private function extractManagedNestedMetadataFields(string $metadataTemplate): array
    {
        $managedFields = [];
        preg_match_all(
            '/<([A-Za-z_][A-Za-z0-9_.:-]*)\b[^>]*\btype\s*=\s*["\']list["\'][^>]*>(.*?)<\/\1\s*>/su',
            $metadataTemplate,
            $listFields,
            PREG_SET_ORDER
        );

        foreach ($listFields as $listField) {
            $field = $listField[1];
            preg_match_all('/<([A-Za-z_][A-Za-z0-9_.:-]*)\b[^>]*>/u', $listField[2], $nestedFields);
            $managedFields[$field] = array_values(array_unique($nestedFields[1] ?? []));
        }

        return $managedFields;
    }

    // Clears the offload error, but only once the resource is genuinely in sync with meemoo.
    private function clearOffloadError($resourceId, array $resourceMetadata): void
    {
        // Keep the error field when the MD5 backfill failed, so the next run retries this resource
        if ($this->dryRun || $this->md5BackfillFailed) {
            return;
        }
        $this->resourceSpace->updateError($resourceId, $this->errorField, '', $resourceMetadata);
    }

    // Records a failed MD5 backfill without touching the offload status: flipping an already
    // offloaded resource to 'Failed' would trigger a new file offload that the checksum guard blocks.
    private function warnMissingMd5($resourceId, array $resourceMetadata, string $message): void
    {
        echo 'WARNING at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->md5BackfillFailed = true;
        if (!$this->dryRun) {
            $this->resourceSpace->updateError($resourceId, $this->errorField, $message, $resourceMetadata, false, true);
        }
    }

    private function failResourceOffload($resourceId, array $resourceMetadata, string $message): void
    {
        echo 'ERROR at resource ' . $resourceId . ': ' . $message . PHP_EOL;
        $this->markResourceOffloadFailed($resourceId, $resourceMetadata);
        if (!$this->dryRun) {
            $this->resourceSpace->updateError($resourceId, $this->errorField, $message, $resourceMetadata, false, true);
        }
    }

    private function generateAndValidateXMLFile(
        $resourceId,
        $data,
        $uniqueFilename,
        $xmlFile,
        $collection,
        $md5,
        $creationDate,
        bool $isIngest,
        bool $writeFile = true
    ): ?DOMDocument
    {
        // Initialize metadata template
        if ($this->metadataTemplate == null) {
            $loader = new FilesystemLoader('./');
            $twig = new Environment($loader, [ 'strict_variables' => true ]);
            $twig->addExtension(new MetadataTemplateExtension());
            try {
                $this->metadataTemplate = $twig->load($this->templateFile);
            } catch (LoaderError|RuntimeError|SyntaxError $e) {
                echo 'ERROR initializing Twig template: ' . $e . PHP_EOL;
                $this->metadataTemplate = null;
            }
        }
        if ($this->metadataTemplate == null) {
            die('Could not initialize Twig template - exiting.');
        }

        // The XSD marks dc_description as optional, but meemoo's ingest requires it to be filled in
        // (confirmed by meemoo via e-mail, July 2026)
        $mainDescription = $this->getMainDescription($data, $isIngest);
        if ($isIngest && empty($mainDescription)) {
            echo 'ERROR: resource ' . $resourceId . ' is missing a description' . PHP_EOL;
            if (!$this->dryRun) {
                $this->resourceSpace->updateError($resourceId, $this->errorField, 'Error: description is missing', $data, false, true);
            }
            return null;
        }

        $xmlData = null;
        try {
            $xmlData = $this->metadataTemplate->render(array(
                'resource' => $data,
                'resource_id' => $resourceId,
                'filename' => $uniqueFilename,
                'main_description' => $mainDescription,
                'collection' => $collection,
                'md5_hash' => $md5,
                'creation_date' => str_replace(' ', 'T', $creationDate),
                'conversion_table' => $this->conversionTable,
                'digitization_partners' => $this->digitizationPartners
            ));
            $xmlData = $this->stripInvisibleUnicode($xmlData);
            $validated = true;
        } catch(Exception $e) {
            echo 'ERROR: XML file ' . $xmlFile . ' is not valid:' . PHP_EOL . $e->getMessage() . PHP_EOL;
            if(!$this->dryRun) {
                $this->resourceSpace->updateError($resourceId, $this->errorField, 'Error: ' . $e->getMessage(), $data, false, true);
            }
            $validated = false;
        }

        if(!$validated) {
            return null;
        }
        $validated = false;

        if ($writeFile) {
            file_put_contents($xmlFile, $xmlData);
        }

        $domDoc = null;
        try {
            $domDoc = new DOMDocument();
            $domDoc->loadXML($xmlData, LIBXML_NOBLANKS);
            if ($domDoc->schemaValidate($this->templateXsdSchemaFile)) {
                $validated = true;
            }
        } catch (Exception $e) {
            echo 'ERROR: XML file ' . $xmlFile . ' is not valid:' . PHP_EOL . $e->getMessage() . PHP_EOL;
            if(!$this->dryRun) {
                $this->resourceSpace->updateError($resourceId, $this->errorField, 'Invalid XML: ' . $e->getMessage(), $data, false, true);
            }
        }
        return $validated ? $domDoc : null;
    }

    private function stripInvisibleUnicode(string $value): string
    {
        return preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
    }

    private function cleanMetadataText($value): string
    {
        $value = (string) ($value ?? '');
        $value = $this->stripInvisibleUnicode($value);
        $value = preg_replace('/\x{00A0}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeForComparison($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '';
        }

        return $this->stripInvisibleUnicode($json);
    }

    private function sanitizeMeemooFilenamePart(string $value): string
    {
        $value = $this->cleanMetadataText($value);

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }

        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
        $value = trim($value, '_-');

        return $value !== '' ? $value : 'bestand';
    }

    private function getMainDescription(array $metadata, bool $allowFallback): string
    {
        $publisher = $this->cleanMetadataText($metadata['publisher'] ?? '');
        $tmsDescription = $this->cleanMetadataText($metadata['tmsdescription'] ?? '');
        $description = $this->cleanMetadataText($metadata['description'] ?? '');

        if ($publisher === 'MOMU' && $tmsDescription !== '') {
            return $tmsDescription;
        }

        if ($description !== '') {
            return $description;
        }

        // The fallback exists to satisfy meemoo's mandatory ingest description. Existing assets
        // keep their archived description when ResourceSpace has no regular description.
        if (!$allowFallback) {
            return '';
        }

        $fallbackValues = [];
        foreach ($this->descriptionFallbackFields as $field) {
            $value = $this->cleanMetadataText($metadata[$field] ?? '');
            if ($value !== '' && !in_array($value, $fallbackValues, true)) {
                $fallbackValues[] = $value;
            }
        }

        return implode($this->descriptionFallbackSeparator, $fallbackValues);
    }

    private function offloadResource($resourceId, $data, $md5, $domDoc, $xmlFile, $offloadFile, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection): bool
    {
        $result = true;
        $statusKey = $this->offloadStatusField['key'];
        // Upload the image file and delete locally, but only if the file has been modified since the last offload (or the file has not been offloaded yet)
        if ($offloadFile && $localFilename != null) {
            if (!$this->dryRun) {
                $uploaded = $this->uploadOffloadFiles(
                    $collection,
                    $localFilename,
                    $uniqueFilename,
                    $xmlFile,
                    $uniqueFilenameWithoutExtension . '.xml'
                );

                if (!$uploaded) {
                    $this->uploadError = true;
                    $this->failResourceOffload($resourceId, $data, 'Could not upload the resource file and its XML metadata to the meemoo FTP server.');
                    $result = false;
                } else {
                    // Only remember the checksum after both the resource and its XML metadata were uploaded successfully.
                    $fileChecksum = new FileChecksum();
                    $fileChecksum->setFileChecksum($md5);
                    $fileChecksum->setResourceId($resourceId);
                    $this->entityManager->persist($fileChecksum);
                    $this->entityManager->flush();

                    // Update offload status in ResourceSpace. The file is already at meemoo at this
                    // point, so a failing status update must be visible instead of silently ignored.
                    $pendingStatus = null;
                    if ($data[$statusKey] == $this->offloadStatusField['values']['offload']
                        || $data[$statusKey] == $this->offloadStatusField['values']['offloaded']
                        || $data[$statusKey] == $this->offloadStatusField['values']['offload_failed']) {
                        $pendingStatus = $this->offloadStatusField['values']['offload_pending'];
                    } else if ($data[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original']
                        || $data[$statusKey] == $this->offloadStatusField['values']['offload_failed_but_keep_original']
                        || $data[$statusKey] == $this->offloadStatusField['values']['offloaded_but_keep_original']) {
                        $pendingStatus = $this->offloadStatusField['values']['offload_pending_but_keep_original'];
                    }

                    if ($pendingStatus !== null && !$this->resourceSpace->updateFieldVerified($resourceId, $statusKey, $pendingStatus)) {
                        $this->statusUpdateError = true;
                        echo 'ERROR at resource ' . $resourceId . ': file was uploaded to meemoo but the offload status could not be updated in ResourceSpace.' . PHP_EOL;
                        $this->resourceSpace->updateError($resourceId, $this->errorField, 'File was uploaded to meemoo but the offload status could not be updated.', $data, false, true);
                    } else {
                        $this->resourceSpace->updateError($resourceId, $this->errorField, '', $data);
                    }
                    // Update offload timestamp (resource) in ResourceSpace
                    $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_resource'], DateTimeUtil::formatTimestampWithTimezone());
                }
            }
        } else {
            if (array_key_exists($this->resourceSpaceMetadataFields['meemoo_asset_url'], $data)) {
                $assetUrl = $data[$this->resourceSpaceMetadataFields['meemoo_asset_url']];
                if (empty($assetUrl)) {
                    echo 'Error: no meemoo asset URL for resource ' . $resourceId . PHP_EOL;
                    $this->resourceSpace->updateError($resourceId, $this->errorField, 'Meemoo asset could not be found.', $data, false, true);
                    $result = false;
                } else {
                    $pos = strrpos($assetUrl, '/');
                    $meemooUrl = $this->oaiPmhApi['url'] . '?verb=GetRecord&metadataPrefix=' . $this->oaiPmhApi['metadata_prefix'] . '&identifier=' . substr($assetUrl, $pos + 1);
                    // Compare old & new metadata
                    $currentMeemooMetadata = $this->getCurrentMeemooMetadata($meemooUrl, $collection);
                    if($currentMeemooMetadata == null) {
                        echo 'Error: could not fetch existing meemoo metadata for resource ' . $resourceId . PHP_EOL;
                        if(!$this->dryRun) {
                            $this->resourceSpace->updateError($resourceId, $this->errorField, 'Cannot fetch meemoo asset data.', $data, false, true);
                        }
                        $result = false;
                    } else {
                        // NOTE: the error field is deliberately not cleared here. It is only cleared once
                        // the metadata actually reached meemoo, so a failing update keeps the resource
                        // flagged and the existing retry logic picks it up on the next run.
                        $fragmentId = $currentMeemooMetadata['fragment_id'];
                        $oldMetadata = $currentMeemooMetadata['data'];

                        $oldMetadata = $this->filterRelevantFields($oldMetadata);
                        $newMetadata = XMLUtil::convertXmlToArray($domDoc, new DOMXPath($domDoc), null, true);
                        $newMetadata = $this->filterRelevantFields($newMetadata);
                        $newMetadata = $this->preserveExistingMetadataValues($oldMetadata, $newMetadata);

                        if ($this->blockUnknownNestedKeyRemovals($resourceId, $data, $oldMetadata, $newMetadata)) {
                            $result = false;
                        } else {
                            // Create a query to use in the meemoo REST API
                            $difference = $this->getDifference($oldMetadata, $newMetadata);
                            if (empty($difference)) {
                                echo 'No actual difference in metadata for resource ' . $resourceId . ', skipping.' . PHP_EOL;
                                // Already in sync with meemoo, so any earlier error no longer applies
                                $this->clearOffloadError($resourceId, $data);
                                $result = false;
                            } else {
                                $this->logMetadataChanges($resourceId, $fragmentId, $collection, $oldMetadata, $difference);
                                $descriptiveDifference = [];
                                // If dc_title or dc_description have changed, then Title and Description also need to be updated as separate fields.
                                if (array_key_exists($this->oaiPmhApi['title'], $difference)) {
                                    $descriptiveDifference['Title'] = $difference[$this->oaiPmhApi['title']];
                                }
                                if (array_key_exists($this->oaiPmhApi['description'], $difference)) {
                                    $descriptiveDifference['Description'] = $difference[$this->oaiPmhApi['description']];
                                }

                                // Use 'OVERWRITE' merge strategy for every single item
                                $mergeStrategies = array();
                                foreach ($difference as $key => $value) {
                                    $mergeStrategies[$key] = 'OVERWRITE';
                                }
                                foreach ($descriptiveDifference as $key => $value) {
                                    $mergeStrategies[$key] = 'OVERWRITE';
                                }

                                if(!empty($descriptiveDifference)) {
                                    $query = array('Metadata' => array('MergeStrategies' => $mergeStrategies, 'Descriptive' => $descriptiveDifference, 'Dynamic' => $difference));
                                } else {
                                    $query = array('Metadata' => array('MergeStrategies' => $mergeStrategies, 'Dynamic' => $difference));
                                }

                                if(!$this->dryRun) {
                                    $result = $this->restApi->updateMetadata($collection, $fragmentId, json_encode($query));
                                    if ($result) {
                                        $this->clearOffloadError($resourceId, $data);
                                    } else {
                                        $this->metadataUpdateError = true;
                                        echo 'ERROR at resource ' . $resourceId . ': the metadata update to meemoo failed.' . PHP_EOL;
                                        $this->resourceSpace->updateError($resourceId, $this->errorField, 'Metadata update to meemoo failed.', $data, false, true);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!$this->dryRun) {
            unlink($xmlFile);

            if($result) {
                // Update offload timestamp (metadata) in ResourceSpace
                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_metadata'], DateTimeUtil::formatTimestampWithTimezone());
            }
        }
        return $result;
    }

    private function uploadOffloadFiles(string $collection, string $localFilename, string $remoteFilename, string $xmlFile, string $remoteXmlFilename): bool
    {
        if (!$this->ftpUtil->uploadFile($collection, $localFilename, $remoteFilename)) {
            return false;
        }

        return $this->ftpUtil->uploadFile($collection, $xmlFile, $remoteXmlFilename);
    }

    private function getCurrentMeemooMetadata($assetUrl, $collection): ?array
    {
        $oldData = null;
        try {
            if (!isset($this->oaiPmhEndpoints[$collection])) {
                $this->oaiPmhEndpoints[$collection] = OaiPmhApiUtil::connect($this->restApi, $this->oaiPmhApi, $collection, $this->overrideCertificateAuthorityFile, $this->sslCertificateAuthorityFile);
            }
            if (isset($this->oaiPmhEndpoints[$collection])) {
                $urlComponents = parse_url($assetUrl);
                parse_str($urlComponents['query'], $params);

                $record = $this->oaiPmhEndpoints[$collection]->getRecord($params['identifier'], $this->oaiPmhApi['metadata_prefix']);
                $data = $record->GetRecord->record->metadata->children($this->oaiPmhApi['namespace'], true);

                //Add missing namespaces
                foreach ($record->getNamespaces() as $name => $value) {
                    if (!empty($name)) {
                        $data->addAttribute('xmlsn:xmlns:' . $name, $value);
                    }
                }
                $domDoc = new DOMDocument;
                $xmlData = $data->asXML();
                $domDoc->loadXML($xmlData);
                $xpath = new DOMXPath($domDoc);
                $results = $xpath->query($this->oaiPmhApi['fragment_id_xpath']);
                $fragmentId = '';
                //We really only expect 1 result
                foreach ($results as $result) {
                    $fragmentId = $result->nodeValue;
                }
                if (!empty($fragmentId)) {
                    $oldData = [
                        'fragment_id' => $fragmentId,
                        'data' => XMLUtil::convertXmlToArray($domDoc, $xpath, $this->oaiPmhApi['resource_data_xpath'])
                    ];
                }
            }
        } catch(Exception $e) {
            echo 'Error fetching ' . $collection . ' OAI-PMH record at ' . $assetUrl . ': ' . $e . PHP_EOL;
            echo $e . PHP_EOL;
        }
        return $oldData;
    }

    private function filterRelevantFields($metadata): array
    {
        $newObject = array();
        foreach($metadata as $key => $value) {
            if(in_array($key, $this->relevantMetadataFields)) {
                $newObject[$key] = $value;
            }
        }
        return $newObject;
    }

    private function preserveExistingMetadataValues(array $oldMetadata, array $newMetadata): array
    {
        // A missing regular ResourceSpace description must never wipe or replace an archived
        // description. Description fallback is reserved for ingest and is therefore absent here.
        if (!array_key_exists('dc_description', $newMetadata)
            && array_key_exists('dc_description', $oldMetadata)) {
            $newMetadata['dc_description'] = $oldMetadata['dc_description'];
        }

        // These nested values are maintained by meemoo or by an older ingest template. The REST
        // API overwrites an entire top-level field, so copy them into our payload before diffing.
        $preservedNestedFields = [
            'dc_identifier_localids' => ['bestandsnaam', 'Bestandsnaam']
        ];
        foreach ($preservedNestedFields as $field => $nestedFields) {
            if (!array_key_exists($field, $oldMetadata) || !is_array($oldMetadata[$field])) {
                continue;
            }

            foreach ($nestedFields as $nestedField) {
                if (!array_key_exists($nestedField, $oldMetadata[$field])) {
                    continue;
                }
                if (!array_key_exists($field, $newMetadata) || !is_array($newMetadata[$field])) {
                    $newMetadata[$field] = [];
                }
                if (!array_key_exists($nestedField, $newMetadata[$field])) {
                    $newMetadata[$field][$nestedField] = $oldMetadata[$field][$nestedField];
                }
            }
        }

        return $newMetadata;
    }

    private function findUnknownNestedKeyRemovals(array $oldMetadata, array $newMetadata): array
    {
        $unknownRemovals = [];

        foreach ($oldMetadata as $field => $oldValue) {
            if (!is_array($oldValue)) {
                continue;
            }

            $newValue = array_key_exists($field, $newMetadata) && is_array($newMetadata[$field])
                ? $newMetadata[$field]
                : [];
            $managedNestedFields = $this->managedNestedMetadataFields[$field] ?? [];

            foreach (array_keys($oldValue) as $nestedField) {
                // Numeric entries are list values, not named metadata subkeys.
                if (is_int($nestedField)
                    || array_key_exists($nestedField, $newValue)
                    || in_array($nestedField, $managedNestedFields, true)) {
                    continue;
                }

                $unknownRemovals[$field][] = (string) $nestedField;
            }
        }

        return $unknownRemovals;
    }

    private function blockUnknownNestedKeyRemovals(
        $resourceId,
        array $resourceMetadata,
        array $oldMetadata,
        array $newMetadata
    ): bool {
        $unknownRemovals = $this->findUnknownNestedKeyRemovals($oldMetadata, $newMetadata);
        if ($unknownRemovals === []) {
            return false;
        }

        $paths = [];
        foreach ($unknownRemovals as $field => $nestedFields) {
            foreach ($nestedFields as $nestedField) {
                $paths[] = $field . '.' . $nestedField;
                echo 'WARNING at resource ' . $resourceId . ': metadata update blocked because existing meemoo subkey "'
                    . $nestedField . '" in "' . $field
                    . '" is neither generated by the current template nor explicitly preserved.' . PHP_EOL;
            }
        }

        $message = 'Metadata update blocked to protect unmanaged nested meemoo metadata: '
            . implode(', ', $paths) . '.';
        $this->metadataUpdateError = true;
        if (!$this->dryRun) {
            if (!$this->resourceSpace->updateErrorVerified(
                $resourceId,
                $this->errorField,
                $message,
                $resourceMetadata[$this->errorField] ?? '',
                true
            )) {
                echo 'ERROR at resource ' . $resourceId
                    . ': the nested-metadata safeguard warning could not be written to ResourceSpace.' . PHP_EOL;
                // Without a stored error the resource would not be retried after the global cursor
                // advances, so keep that cursor in place as a second line of defence.
                $this->cursorSafetyError = true;
            }
        }

        return true;
    }

    private function getDifference($oldMetadata, $newMetadata): array
    {
        $difference = array();
        foreach($oldMetadata as $key => $value) {
            if(!array_key_exists($key, $newMetadata)) {
                $isArr = false;
                if(is_array($value)) {
                    foreach($value as $k => $v) {
                        $difference[$key][$k] = array();
                        $isArr = true;
                    }
                }
                if(!$isArr) {
                    // Pass an empty string in order to wipe data
                    $difference[$key] = "";
                }
            } else if ($this->normalizeForComparison($value) !== $this->normalizeForComparison($newMetadata[$key])) {
                $difference[$key] = $newMetadata[$key];
            }
        }
        foreach($newMetadata as $key => $value) {
            if(!array_key_exists($key, $oldMetadata)) {
                $difference[$key] = $value;
            }
        }
        return $difference;
    }

    // Log every field addition, overwrite and wipe. Existing values make the log usable as a
    // restore record, while additions make fallback or template migrations fully visible.
    private function logMetadataChanges($resourceId, $fragmentId, $collection, $oldMetadata, $difference): void
    {
        foreach ($difference as $key => $newValue) {
            if (!array_key_exists($key, $oldMetadata)) {
                $this->recordMetadataChange('ADD', $key);
                $newValueJson = json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                echo ($this->dryRun ? 'DRY RUN - ' : '') . 'ADD resource ' . $resourceId
                    . ' (' . $collection . ', fragment ' . $fragmentId . '): field "' . $key
                    . '", new value: ' . $newValueJson . PHP_EOL;
                continue;
            }
            $oldValueJson = json_encode($oldMetadata[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($this->isWipeValue($newValue)) {
                $this->recordMetadataChange('WIPE', $key);
                echo ($this->dryRun ? 'DRY RUN - ' : '') . 'WIPE resource ' . $resourceId
                    . ' (' . $collection . ', fragment ' . $fragmentId . '): field "' . $key
                    . '", old meemoo value: ' . $oldValueJson . PHP_EOL;
            } else {
                $this->recordMetadataChange('UPDATE', $key);
                $newValueJson = json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                echo ($this->dryRun ? 'DRY RUN - ' : '') . 'UPDATE resource ' . $resourceId
                    . ' (' . $collection . ', fragment ' . $fragmentId . '): field "' . $key . '"' . PHP_EOL
                    . '    old: ' . $oldValueJson . PHP_EOL
                    . '    new: ' . $newValueJson . PHP_EOL;
            }
        }
    }

    private function recordMetadataChange(string $action, string $field): void
    {
        if (!array_key_exists($action, $this->metadataChangeSummary)) {
            $this->metadataChangeSummary[$action] = [];
        }
        if (!array_key_exists($field, $this->metadataChangeSummary[$action])) {
            $this->metadataChangeSummary[$action][$field] = 0;
        }
        $this->metadataChangeSummary[$action][$field]++;
    }

    private function logMetadataChangeSummary(): void
    {
        if (!$this->dryRun) {
            return;
        }

        echo 'DRY RUN - METADATA CHANGE SUMMARY' . PHP_EOL;
        $total = 0;
        foreach (['ADD', 'UPDATE', 'WIPE'] as $action) {
            $fieldCounts = $this->metadataChangeSummary[$action] ?? [];
            ksort($fieldCounts);
            $actionTotal = array_sum($fieldCounts);
            $total += $actionTotal;
            echo '  ' . $action . ': ' . $actionTotal . PHP_EOL;
            foreach ($fieldCounts as $field => $count) {
                echo '    ' . $field . ': ' . $count . PHP_EOL;
            }
        }
        echo '  TOTAL: ' . $total . PHP_EOL;
    }

    private function isWipeValue($value): bool
    {
        if ($value === '') {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item !== array() && $item !== '') {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}

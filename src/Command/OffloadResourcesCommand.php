<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use App\Util\FtpUtil;
use App\Util\OaiPmhApiUtil;
use App\Util\RestApi;
use App\Util\XMLUtil;
use DOMDocument;
use DOMXPath;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class OffloadResourcesCommand extends Command
{
    private $params;
    private $dryRun;
    private $verbose;

    private $ftpUtil;
    private $resourceSpace;
    private $oaiPmhEndpoint;
    private $restApi;

    private $mandatoryResourceSpaceFields;
    private $forbiddenResourceSpaceFields;
    private $relevantResourceSpaceFields;
    private $relevantMetadataFields;
    private $lastTimestampFile;
    private $outputFolder;
    private $templateFile;
    private $templateXsdSchemaFile;
    private $supportedExtensions;
    private $collections;
    private $offloadStatusField;
    private $resourceSpaceMetadataFields;
    private $offloadValues;
    private $conversionTable;
    private $collectionKey;
    private $offloadStatusFilter;

    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;
    private $oaiPmhApi;

    private $lastOffloadTimestamp;
    private $lastMetadataTemplateChange;
    private $metadataTemplate;

    public function __construct(ParameterBagInterface $params, $dryRun = false)
    {
        $this->params = $params;
        $this->dryRun = $dryRun;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:offload-resources')
            ->setDescription('Lists all ResourceSpace resources and offloads all images with the appropriate metadata onto an FTP server. Also updates changed metadata of existing resources in meemoo\'s archive.');
    }

    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');
        $this->offloadImages();
        return 0;
    }

    public function offloadImages()
    {
        $this->init();
        $this->processCollections();
    }

    private function init()
    {
        $this->resourceSpace = new ResourceSpace($this->params);
        $this->ftpUtil = new FtpUtil($this->params);
        $this->restApi = new RestApi($this->params);

        $this->lastOffloadTimestamp = 0;
        $this->lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if (file_exists($this->lastTimestampFile)) {
            $file = fopen($this->lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $this->lastTimestampFile . "').");
            $this->lastOffloadTimestamp = fgets($file);
            fclose($file);
        }

        $this->outputFolder = $this->params->get('output_folder');
        if (!is_dir($this->outputFolder)) {
            mkdir($this->outputFolder);
        }

        $this->templateFile = $this->params->get('template_file');
        if (!file_exists($this->templateFile)) {
            die('Metadata template is missing, please configure the location of your template in connector.yml and make sure it exists.');
        }

        $this->lastMetadataTemplateChange = filemtime($this->templateFile);

        // Automatically determine which ResourceSpace fields are relevant based on the occurrences of 'resource.' in the metadata template.
        $this->relevantResourceSpaceFields = array();
        $metadataTemplate = file_get_contents($this->templateFile);
        preg_match_all('/[^a-zA-Z0-9\-_]resource\.([a-zA-Z0-9\-_]+)[^a-zA-Z0-9\-_]/', $metadataTemplate, $matches);
        foreach ($matches[1] as $match) {
            if (!in_array($match, $this->relevantResourceSpaceFields)) {
                $this->relevantResourceSpaceFields[] = $match;
            }
        }

        $this->templateXsdSchemaFile = $this->params->get('template_xsd_schema_file');
        if (!file_exists($this->templateXsdSchemaFile)) {
            die('XSD schema is missing, please configure the location of your xsd schema in connector.yml and make sure it exists.');
        }

        $this->relevantMetadataFields = array();
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
                                $this->relevantMetadataFields[] = $attribute->nodeValue;
                            }
                            break;
                        }
                    }
                }
            }
        }

        $this->supportedExtensions = $this->params->get('supported_extensions');
        $this->mandatoryResourceSpaceFields = $this->params->get('mandatory_resourcespace_fields');
        $this->forbiddenResourceSpaceFields = $this->params->get('forbidden_resourcespace_fields');
        $this->collections = $this->params->get('collections');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $this->offloadValues = $this->offloadStatusField['values'];
        $this->conversionTable = $this->params->get('conversion_table');

        $this->collectionKey = $this->collections['key'];

        $this->offloadStatusFilter = [
            $this->offloadValues['offload'],
            $this->offloadValues['offload_but_keep_original'],
            $this->offloadValues['offloaded'],
            $this->offloadValues['offloaded_but_keep_original']
        ];

        $this->overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $this->sslCertificateAuthorityFile = $this->params->get('ssl_certificate_authority_file');
        $this->oaiPmhApi = $this->params->get('oai_pmh_api');
    }

    private function processCollections()
    {
        // Keep track of resource ID's that are already processed to prevent duplicates (duplicates may emerge through different searches)
        $alreadyProcessed = array();

        // Loop through all collections
        foreach ($this->collections['values'] as $collection) {
            foreach($this->offloadStatusFilter as $filter) {
                $allResources = $this->resourceSpace->getAllResources(urlencode('"' . $this->collectionKey . ':' . $collection . '" "' . $this->offloadStatusField['key'] . ':' . $filter . '"'));
                // Loop through all resources in this collection
                foreach ($allResources as $resourceInfo) {
                    $resourceId = $resourceInfo['ref'];
                    if(in_array($resourceId, $alreadyProcessed)) {
                        continue;
                    }
                    $alreadyProcessed[] = $resourceId;

                    // Get this resource's metadata, but only if it has an appropriate offloadStatus
                    $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains($resourceId, $this->offloadStatusField['key'], $this->offloadStatusFilter);
                    if ($resourceMetadata != null) {
                        $extension = pathinfo($resourceMetadata['originalfilename'], PATHINFO_EXTENSION);
                        $failed = false;
                        if(!in_array($extension, $this->supportedExtensions)) {
                            $failed = true;
                            if($this->verbose) {
                                echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has extension "' . $extension . '", which is not supported.' . PHP_EOL;
                            }
                        }
                        if(!$failed) {
                            foreach ($this->mandatoryResourceSpaceFields as $mandatoryField) {
                                if (!array_key_exists($mandatoryField, $resourceMetadata)) {
                                    $failed = true;
                                    if($this->verbose) {
                                        echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') is missing the mandatory metadata field "' . $mandatoryField . '".' . PHP_EOL;
                                    }
                                } else if (empty($resourceMetadata[$mandatoryField])) {
                                    $failed = true;
                                    if($this->verbose) {
                                        echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has an empty mandatory metadata field "' . $mandatoryField . '".' . PHP_EOL;
                                    }
                                }
                            }
                        }
                        if(!$failed) {
                            foreach ($this->forbiddenResourceSpaceFields as $forbiddenField => $fieldData) {
                                if (array_key_exists($forbiddenField, $resourceMetadata)) {
                                    foreach($fieldData as $entry) {
                                        if ($resourceMetadata[$forbiddenField] == $entry) {
                                            $failed = true;
                                            echo 'ERROR: File ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ') has value "' . $entry . '" for metadata field "' . $forbiddenField . '", which is not allowed.' . PHP_EOL;
                                        }
                                    }
                                }
                            }
                        }
                        if(!$failed) {
                            $this->processResource($resourceId, $resourceInfo, $resourceMetadata, $collection, $extension);
                        } else {
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

        if (!$this->dryRun) {
            $file = fopen($this->lastTimestampFile, "w") or die("Unable to open file containing last offload timestamp ('" . $this->lastTimestampFile . "').");
            fwrite($file, time());
            fclose($file);
        }
    }

    private function processResource($resourceId, $resourceInfo, $resourceMetadata, $collection, $extension)
    {
        // For debugging purposes
//        var_dump($resourceMetadata);

        $uniqueFilename = $resourceId . '_' . $resourceMetadata['originalfilename'];
        $uniqueFilenameWithoutExtension = $resourceId . '_' . pathinfo($resourceMetadata['originalfilename'], PATHINFO_FILENAME);

        $md5 = null;
        $offloadFile = false;
        $localFilename = null;
        $fileModifiedTimestampAsString = null;

        // Always offload the file and metadata if offloadstatus is set to 'Offload' or 'Offload but keep original'
        if(array_key_exists($this->offloadStatusField['key'], $resourceMetadata)) {
            $fieldValue = $resourceMetadata[$this->offloadStatusField['key']];
            if($fieldValue == $this->offloadStatusField['values']['offload'] || $fieldValue == $this->offloadStatusField['values']['offload_but_keep_original']) {
                $offloadFile = true;
            }
        }
        if(!$offloadFile) {
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
            $localFilename = $this->outputFolder . '/' . $uniqueFilename;
            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId, $extension);
            copy($resourceUrl, $localFilename);
            $md5 = md5_file($localFilename);
        }

        $offloadMetadata = false;
        // Always offload the metadata if the file is to be offloaded or if the metadata template has changed since the last offload
        if ($offloadFile || $this->lastMetadataTemplateChange > $this->lastOffloadTimestamp) {
            // Always upload the metadata if the file was modified
            $offloadMetadata = true;
        } else {
            $md5 = $resourceMetadata['md5checksum'];
        }

        if ($offloadMetadata || array_key_exists('modified', $resourceInfo)) {
            $metadataModifiedDate = $resourceInfo['modified'];
            if (!$offloadMetadata) {
                if (strlen($metadataModifiedDate) > 0) {
                    // Check if the resource was modified since the last offload
                    if (strtotime($metadataModifiedDate) > $this->lastOffloadTimestamp) {
                        // Check if any of the relevant ResourceSpace fields has changed since the last offload
                        $offloadMetadata = $this->resourceSpace->didRelevantMetadataChange($resourceId, DateTimeUtil::formatTimestampSimple($this->lastOffloadTimestamp), $this->relevantResourceSpaceFields);
                    }
                }
            }
            if ($offloadMetadata) {
                $xmlFile = $this->outputFolder . '/' . $uniqueFilenameWithoutExtension . '.xml';
                $domDoc = $this->generateAndValidateXMLFile($resourceId, $resourceMetadata, $uniqueFilename, $xmlFile, $collection, $md5, $resourceInfo['creation_date']);
                if ($domDoc != null) {
                    $offloaded = $this->offloadResource($resourceId, $resourceMetadata, $md5, $domDoc, $xmlFile, $offloadFile, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection);

                    if ($this->verbose) {
                        if ($offloadFile) {
                            echo 'Resource file ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $fileModifiedTimestampAsString . ') will be offloaded' . PHP_EOL;
                        }
                        if($offloaded) {
                            echo 'Metadata ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will be offloaded' . PHP_EOL;
                        }
                    }
                } else {
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

    private function generateAndValidateXMLFile($resourceId, $data, $uniqueFilename, $xmlFile, $collection, $md5, $creationDate)
    {
        // Initialize metadata template
        if ($this->metadataTemplate == null) {
            $loader = new FilesystemLoader('./');
            $twig = new Environment($loader);
            try {
                $this->metadataTemplate = $twig->load($this->templateFile);
            } catch (LoaderError $e) {
                echo 'ERROR initializing Twig template: ' . $e . PHP_EOL;
                $this->metadataTemplate = null;
            } catch (RuntimeError $e) {
                echo 'ERROR initializing Twig template: ' . $e . PHP_EOL;
                $this->metadataTemplate = null;
            } catch (SyntaxError $e) {
                echo 'ERROR initializing Twig template: ' . $e . PHP_EOL;
                $this->metadataTemplate = null;
            }
        }
        if ($this->metadataTemplate == null) {
            die('Could not initialize Twig template - exiting.');
        }

        $xmlData = $this->metadataTemplate->render(array(
            'resource' => $data,
            'resource_id' => $resourceId,
            'filename' => $uniqueFilename,
            'collection' => $collection,
            'md5_hash' => $md5,
            'creation_date' => str_replace(' ', 'T', $creationDate),
            'conversion_table' => $this->conversionTable
        ));
        // Remove zero width spaces (no idea how they got there)
        $xmlData = str_replace( '\u200b', '', $xmlData);
        file_put_contents($xmlFile, $xmlData);

        $validated = false;
        try {
            $domDoc = new DOMDocument();
            $domDoc->loadXML($xmlData, LIBXML_NOBLANKS);
            if ($domDoc->schemaValidate($this->templateXsdSchemaFile)) {
                $validated = true;
            }
        } catch (Exception $e) {
            echo 'ERROR: XML file ' . $xmlFile . ' is not valid:' . PHP_EOL . $e->getMessage() . PHP_EOL;
        }
        return $validated ? $domDoc : null;
    }

    private function offloadResource($resourceId, $data, $md5, $domDoc, $xmlFile, $fileModified, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection)
    {
        $result = true;
        // Upload the image file and delete locally, but only if the file has been modified since the last offload (or the file has not been offloaded yet)
        if ($fileModified && $localFilename != null) {
            if (!$this->dryRun) {
                $this->ftpUtil->uploadFile($collection, $localFilename, $uniqueFilename);
                unlink($localFilename);

                // Update offload status in ResourceSpace
                $this->resourceSpace->updateField($resourceId, $this->offloadStatusField['key'], $this->offloadValues['offload_pending']);
                // Update offload timestamp (resource) in ResourceSpace
                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_resource'], DateTimeUtil::formatTimestampWithTimezone());
                // Upload the XML file and delete locally
                $this->ftpUtil->uploadFile($collection, $xmlFile, $uniqueFilenameWithoutExtension . '.xml');
            }
        } else {
            if (array_key_exists($this->resourceSpaceMetadataFields['meemoo_asset_url'], $data)) {
                $assetUrl = $data[$this->resourceSpaceMetadataFields['meemoo_asset_url']];
                if (empty($assetUrl)) {
                    echo 'Error: no meemoo asset URL for resource ' . $resourceId . PHP_EOL;
                    $result = false;
                } else {
                    // Compare old & new metadata
                    $currentMeemooMetadata = $this->getCurrentMeemooMetadata($assetUrl, $collection);
                    if($currentMeemooMetadata == null) {
                        echo 'Error: could not fetch existing meemoo metadata for resource ' . $resourceId . PHP_EOL;
                        $result = false;
                    } else {
                        $fragmentId = $currentMeemooMetadata['fragment_id'];
                        $oldMetadata = $currentMeemooMetadata['data'];

                        $oldMetadata = $this->filterRelevantFields($oldMetadata);
                        $newMetadata = XMLUtil::convertXmlToArray($domDoc, new DOMXPath($domDoc), null, true);
                        $newMetadata = $this->filterRelevantFields($newMetadata);

                        // Create a query to use in the meemoo REST API
                        $difference = $this->getDifference($oldMetadata, $newMetadata);
                        if (empty($difference)) {
                            echo 'No actual difference in metadata for resource ' . $resourceId . ', skipping.' . PHP_EOL;
                            $result = false;
                        } else {
                            // If dc_title or dc_description have changed, then Title and Description also need to be updated as separate fields.
                            if (array_key_exists($this->oaiPmhApi['title'], $difference)) {
                                $difference['Title'] = $difference[$this->oaiPmhApi['title']];
                            }
                            if (array_key_exists($this->oaiPmhApi['description'], $difference)) {
                                $difference['Description'] = $difference[$this->oaiPmhApi['description']];
                            }

                            // Use 'OVERWRITE' merge strategy for every single item
                            $mergeStrategies = array();
                            foreach ($difference as $key => $value) {
                                $mergeStrategies[$key] = 'OVERWRITE';
                            }

                            $query = array('Metadata' => array('MergeStrategies' => $mergeStrategies, 'Dynamic' => $difference));

                            if(!$this->dryRun) {
                                $this->restApi->updateMetadata($collection, $fragmentId, json_encode($query));
                            }
                        }
                    }
                }
            }
        }

        if (!$this->dryRun) {
            unlink($xmlFile);

            // Update offload timestamp (metadata) in ResourceSpace
            $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_metadata'], DateTimeUtil::formatTimestampWithTimezone());

            // Update ResourceSpace md5checksum if needed
            if ($fileModified) {
                $updatemd5 = false;
                if (!array_key_exists('md5checksum', $data)) {
                    $updatemd5 = true;
                } else if ($data['md5checksum'] != $md5) {
                    $updatemd5 = true;
                }
                if ($updatemd5) {
                    $this->resourceSpace->updateField($resourceId, 'md5checksum', $md5);
                }
            }
        }
        return $result;
    }

    private function getCurrentMeemooMetadata($assetUrl, $collection)
    {
        $oldData = null;
        if ($this->oaiPmhEndpoint == null) {
            $this->oaiPmhEndpoint = OaiPmhApiUtil::connect($this->oaiPmhApi, $collection, $this->overrideCertificateAuthorityFile, $this->sslCertificateAuthorityFile);
        }
        if ($this->oaiPmhEndpoint != null) {
            $urlComponents = parse_url($assetUrl);
            parse_str($urlComponents['query'], $params);

            $record = $this->oaiPmhEndpoint->getRecord($params['identifier'], $this->oaiPmhApi['metadata_prefix']);
            $data = $record->GetRecord->record->metadata->children($this->oaiPmhApi['namespace'], true);

            //Add missing namespaces
            foreach($record->getNamespaces() as $name => $value) {
                if(!empty($name)) {
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
            foreach($results as $result) {
                $fragmentId = $result->nodeValue;
            }
            if(empty($fragmentId)) {
                $oldData = null;
            } else {
                $oldData = [
                    'fragment_id' => $fragmentId,
                    'data' => XMLUtil::convertXmlToArray($domDoc, $xpath, $this->oaiPmhApi['resource_data_xpath'])
                ];
            }
        }
        return $oldData;
    }

    private function filterRelevantFields($metadata)
    {
        $newObject = array();
        foreach($metadata as $key => $value) {
            if(in_array($key, $this->relevantMetadataFields)) {
                $newObject[$key] = $value;
            }
        }
        return $newObject;
    }

    private function getDifference($oldMetadata, $newMetadata)
    {
        $difference = array();
        foreach($oldMetadata as $key => $value) {
            if(!array_key_exists($key, $newMetadata)) {
                $isArr = false;
                if(is_array($oldMetadata[$key])) {
                    foreach($oldMetadata[$key] as $k => $v) {
                        $difference[$key][$k] = array();
                        $isArr = true;
                    }
                }
                if(!$isArr) {
                    // Pass an empty string in order to wipe data
                    $difference[$key] = "";
                }
            } else if(str_replace( '\u200b', '', json_encode($value)) !== str_replace( '\u200b', '', json_encode($newMetadata[$key]))) {
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

}
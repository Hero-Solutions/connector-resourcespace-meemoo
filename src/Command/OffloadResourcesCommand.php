<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use App\Util\FtpUtil;
use DOMDocument;
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

    /**
     * @var FtpUtil
     */
    private $ftpUtil;
    /**
     * @var ResourceSpace
     */
    private $resourceSpace;

    private $relevantResourceSpaceFields;
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

    private $lastOffloadTimestamp;
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
            ->setName('app:offload-images')
            ->setDescription('Lists all ResourceSpace resources and offloads all images with the appropriate metadata onto an FTP server.');
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

        $this->lastOffloadTimestamp = 0;
        $this->lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if(file_exists($this->lastTimestampFile)) {
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

        // Automatically determine which ResourceSpace fields are relevant based on the occurrences of 'resource.' in the metadata template.
        $this->relevantResourceSpaceFields = array();
        $metadataTemplate = file_get_contents($this->templateFile);
        preg_match_all('/[^a-zA-Z0-9\-_]resource\.([a-zA-Z0-9\-_]+)[^a-zA-Z0-9\-_]/', $metadataTemplate, $matches);
        foreach($matches[1] as $match) {
            if(!in_array($match, $this->relevantResourceSpaceFields)) {
                $this->relevantResourceSpaceFields[] = $match;
            }
        }

        $this->templateXsdSchemaFile = $this->params->get('template_xsd_schema_file');
        if (!file_exists($this->templateXsdSchemaFile)) {
            die('XSD schema is missing, please configure the location of your xsd schema in connector.yml and make sure it exists.');
        }

        $this->supportedExtensions = $this->params->get('supported_extensions');
        $this->collections = $this->params->get('collections');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $this->offloadValues = $this->offloadStatusField['values'];
        $this->conversionTable = $this->params->get('conversion_table');

        $this->collectionKey = $this->collections['key'];

        $this->offloadStatusFilter = array(
            $this->offloadValues['offload'],
            $this->offloadValues['offload_but_keep_original'],
            $this->offloadValues['offload_pending'],
            $this->offloadValues['offload_pending_but_keep_original'],
            $this->offloadValues['offloaded'],
            $this->offloadValues['offloaded_but_keep_original']
        );
    }

    private function processCollections()
    {
        // Loop through all collections
        foreach($this->collections['values'] as $collection) {
            $allResources = $this->resourceSpace->getAllResources($this->collectionKey, $collection);
            // Loop through all resources in this collection
            foreach($allResources as $resourceInfo) {
                $resourceId = $resourceInfo['ref'];

                // Get this resource's metadata, but only if it has an appropriate offloadStatus
                $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains($resourceId, $this->offloadStatusField['key'], $this->offloadStatusFilter);
                if($resourceMetadata != null) {
                    if($this->hassupportedExtension($resourceId, $resourceMetadata, $this->supportedExtensions)) {
                        $this->processResource($resourceId, $resourceInfo, $resourceMetadata, $collection);
                    }
                }
            }
        }

        if(!$this->dryRun) {
            $file = fopen($this->lastTimestampFile, "w") or die("Unable to open file containing last offload timestamp ('" . $this->lastTimestampFile . "').");
            fwrite($file, time());
            fclose($file);
        }
    }

    private function hasSupportedExtension($resourceId, $data, $supportedExtensions)
    {
        $extension = pathinfo($data['originalfilename'], PATHINFO_EXTENSION);
        if(!in_array($extension, $supportedExtensions)) {
            if($this->verbose) {
                echo 'ERROR: File ' . $data['originalfilename'] . ' (resource ' . $resourceId . ') has extension "' . $extension . '", which is not supported.' . PHP_EOL;
            }
            return false;
        } else {
            return true;
        }
    }

    private function processResource($resourceId, $resourceInfo, $resourceMetadata, $collection)
    {
        // For debugging purposes
//        var_dump($resourceMetadata);

        $uniqueFilename = $resourceId . '_' . $resourceMetadata['originalfilename'];
        $uniqueFilenameWithoutExtension = $resourceId . '_' . pathinfo($resourceMetadata['originalfilename'], PATHINFO_FILENAME);

        $md5 = null;
        $offloadFile = false;
        $localFilename = null;
        $fileModifiedTimestampAsString = null;

        // Check when the file was last modified
        if (array_key_exists('file_modified', $resourceInfo)) {
            $fileModifiedTimestampAsString = $resourceInfo['file_modified'];
            if (strlen($fileModifiedTimestampAsString) > 0) {
                $fileModifiedTimestamp = strtotime($fileModifiedTimestampAsString);
                if ($fileModifiedTimestamp > $this->lastOffloadTimestamp) {
                    $localFilename = $this->outputFolder . '/' . $uniqueFilenameWithoutExtension . '.' . $resourceMetadata['originalfilename'];
                    $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId);
                    copy($resourceUrl, $localFilename);
                    $md5 = md5_file($localFilename);

                    $offloadFile = true;
                }
            }
        }

        $offloadMetadata = false;
        if ($offloadFile) {
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
                    if(strtotime($metadataModifiedDate) > $this->lastOffloadTimestamp) {
                        // Check if any of the relevant ResourceSpace fields has changed since the last offload
                        $offloadMetadata = $this->resourceSpace->didRelevantMetadataChange($resourceId, DateTimeUtil::formatTimestampSimple($this->lastOffloadTimestamp), $this->relevantResourceSpaceFields);
                    }
                }
            }
            if ($offloadMetadata) {
                $xmlFile = $this->generateAndValidateXMLFile($resourceId, $resourceMetadata, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection, $md5, $resourceInfo['creation_date']);
                if($xmlFile != null) {
                    if (!$this->dryRun) {
                        $this->offloadResource($resourceId, $resourceMetadata, $md5, $xmlFile, $offloadFile, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection);
                    }

                    if($this->verbose) {
                        if ($offloadFile) {
                            echo 'Resource file ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $fileModifiedTimestampAsString . ') will be offloaded' . PHP_EOL;
                        }
                        echo 'Metadata ' . $resourceMetadata['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will be offloaded' . PHP_EOL;
                    }
                }
            }
        }
    }

    private function generateAndValidateXMLFile($resourceId, $data, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection, $md5, $creationDate)
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
        if($this->metadataTemplate == null) {
            die('Could not initialize Twig template - exiting.');
        }

        $xmlData = $this->metadataTemplate->render(array(
            'resource' => $data,
            'resource_id' => $resourceId,
            'filename' => $uniqueFilename,
            'collection' => $collection,
            'md5_hash' => $md5,
            'creation_date' => $creationDate,
            'conversion_table' => $this->conversionTable
        ));
        $xmlFile = $this->outputFolder . '/' . $uniqueFilenameWithoutExtension . '.xml';
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
        return $validated ? $xmlFile : null;
    }

    private function offloadResource($resourceId, $data, $md5, $xmlFile, $fileModified, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection)
    {
        // Upload the image file and delete locally, but only if the file has been modified since the last offload (or the file has not been offloaded yet)
        if ($fileModified && $localFilename != null) {
            $this->ftpUtil->uploadFile($collection, $localFilename, $uniqueFilename);
            unlink($localFilename);

            // Update offload timestamp (resource) in ResourceSpace
            $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_resource'], DateTimeUtil::formatTimestampWithTimezone());
        }

        // Upload the XML file and delete locally
        $this->ftpUtil->uploadFile($collection, $xmlFile, $uniqueFilenameWithoutExtension . '.xml');
        unlink($xmlFile);

        // Update offload timestamp (metadata) in ResourceSpace
        $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_timestamp_metadata'], DateTimeUtil::formatTimestampWithTimezone());

        // Update offload status in ResourceSpace
        $this->resourceSpace->updateField($resourceId, $this->offloadStatusField['key'], $this->offloadValues['offload_pending']);

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
}

<?php
namespace App\Command;

use App\ResourceSpace\ResourceSpace;
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

    /**
     * @var FtpUtil
     */
    private $ftpUtil;
    /**
     * @var ResourceSpace
     */
    private $resourceSpace;

    private $lastTimestampFile;
    private $outputFolder;
    private $templateFile;
    private $templateXsdSchemaFile;
    private $supportedExtensions;
    private $collections;
    private $offloadStatus;
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->offloadImages();
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
        $lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if(file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
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

        $this->templateXsdSchemaFile = $this->params->get('template_xsd_schema_file');
        if (!file_exists($this->templateXsdSchemaFile)) {
            die('XSD schema is missing, please configure the location of your xsd schema in connector.yml and make sure it exists.');
        }

        $this->supportedExtensions = $this->params->get('supported_extensions');
        $this->collections = $this->params->get('collections');
        $this->offloadStatus = $this->params->get('offload_status');
        $this->offloadValues = $this->offloadStatus['values'];
        $this->conversionTable = $this->params->get('conversion_table');

        $this->collectionKey = $this->collections['key'];

        $this->offloadStatusFilter = array($this->offloadValues['offload'], $this->offloadValues['offload_but_keep_original'], $this->offloadValues['offload_pending'], $this->offloadValues['offloaded']);
    }

    private function processCollections()
    {
        // Loop through all collections
        foreach($this->collections['values'] as $collection) {
            $allResources = $this->resourceSpace->getAllResources($this->collectionKey, $collection);
            // Loop through all resources in this collection
            foreach($allResources as $resource) {
                $resourceId = $resource['ref'];

                // Get this resource's metadata if it has an appropriate offloadStatus
                $resourceData = $this->resourceSpace->getResourceDataIfFieldContains($resourceId, $this->offloadStatus['key'], $this->offloadStatusFilter);
                if($resourceData != null) {
                    $data = array();
                    foreach ($resourceData as $field) {
                        $data[$field['name']] = $field['value'];
                    }
                    if($this->hassupportedExtension($resourceId, $data, $this->supportedExtensions)) {
                        $this->processResource($resourceId, $resource, $data, $collection);
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
            echo 'ERROR: File ' . $data['originalfilename'] . ' (resource ' . $resourceId . ') has extension "' . $extension . '", which is not supported.' . PHP_EOL;
            return false;
        } else {
            return true;
        }
    }

    private function processResource($resourceId, $resource, $data, $collection)
    {
        // For debugging purposes
//        var_dump($resource);

        $uniqueFilename = $resourceId . '_' . $data['originalfilename'];
        $uniqueFilenameWithoutExtension = $resourceId . '_' . pathinfo($data['originalfilename'], PATHINFO_FILENAME);

        $md5 = null;
        $fileModified = false;
        $localFilename = null;

        //Check when the file was last modified
        if (array_key_exists('file_modified', $resource)) {
            $fileModifiedDate = $resource['file_modified'];
            $fileModifiedTimestamp = 0;
            if (strlen($fileModifiedDate) > 0) {
                $fileModifiedTimestamp = strtotime($fileModifiedDate);
            }
            if ($fileModifiedTimestamp > $this->lastOffloadTimestamp) {
                $localFilename = $this->outputFolder . '/' . $uniqueFilenameWithoutExtension . '.' . $data['originalfilename'];
                $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId);
                copy($resourceUrl, $localFilename);
                $md5 = md5_file($localFilename);

                $fileModified = true;
            }
        }

        $updateMetadata = false;
        if ($fileModified) {
            //Always update the metadata if the file was modified
            $updateMetadata = true;
        } else {
            $md5 = $data['md5checksum'];
        }

        if ($updateMetadata || array_key_exists('modified', $resource)) {
            $metadataModifiedDate = $resource['modified'];
            if (!$updateMetadata) {
                if (strlen($metadataModifiedDate) > 0) {
                    //Update metadata if the metadata has been modified since the last offload
                    $updateMetadata = strtotime($metadataModifiedDate) > $this->lastOffloadTimestamp;
                }
            }
            if ($updateMetadata) {
                $xmlFile = $this->generateAndValidateXMLFile($resourceId, $data, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection, $md5);
                if($xmlFile != null) {
                    if (!$this->dryRun) {
                        $this->offloadResource($resourceId, $data, $md5, $xmlFile, $fileModified, $localFilename, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection);
                    }

                    if ($fileModified) {
                        echo 'Resource file ' . $data['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will be offloaded' . PHP_EOL;
                    } else {
                        echo 'Resource file ' . $data['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will NOT be offloaded' . PHP_EOL;
                    }
                    echo 'Metadata ' . $data['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will be offloaded' . PHP_EOL;
                }
            } else {
                echo 'Resource & metadata ' . $data['originalfilename'] . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will NOT be offloaded' . PHP_EOL;
            }
        }
        echo PHP_EOL;
    }

    private function generateAndValidateXMLFile($resourceId, $data, $uniqueFilename, $uniqueFilenameWithoutExtension, $collection, $md5)
    {
        //Initialize metadata template
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
            return null;
        }

        $xmlData = $this->metadataTemplate->render(array(
            'resource' => $data,
            'resource_id' => $resourceId,
            'filename' => $uniqueFilename,
            'collection' => $collection,
            'md5_hash' => $md5,
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
        //Upload the image file and delete locally, but only if the file has been modified since the last offload
        if ($fileModified && $localFilename != null) {
            $this->ftpUtil->uploadFile($collection, $localFilename, $uniqueFilename);
            unlink($localFilename);
        }

        //Upload the XML file and delete locally
        $this->ftpUtil->uploadFile($collection, $xmlFile, $uniqueFilenameWithoutExtension . '.xml');
        unlink($xmlFile);

        //Update ResourceSpace offload status
        $this->resourceSpace->updateField($resourceId, $this->offloadStatus['key'], $this->offloadValues['offload_pending']);

        //Update ResourceSpace md5checksum if needed
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

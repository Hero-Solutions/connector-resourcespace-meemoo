<?php
namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\FtpUtil;
use App\Util\XMLValidator;
use DOMDocument;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class OffloadImagesCommand extends Command
{
    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
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
        $this->offloadImages($this->params, true);
    }

    public static function offloadImages(ParameterBagInterface $params, $doOffload)
    {
        $resourceSpace = new ResourceSpace($params);
        $ftpUtil = new FtpUtil($params);

        $lastOffloadTimestamp = 0;
        $lastTimestampFile = $params->get('last_offload_timestamp_file');
        if(file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        }
        $outputFolder = $params->get('output_folder');
        if (!is_dir($outputFolder)) {
            mkdir($outputFolder);
        }

        $templateFile = $params->get('template_file');
        if (!file_exists($templateFile)) {
            die('Metadata template is missing, please configure the location of your template in connector.yml and make sure it exists.');
        }

        $templateXsdSchemaFile = $params->get('template_xsd_schema_file');
        if (!file_exists($templateXsdSchemaFile)) {
            die('XSD schema is missing, please configure the location of your xsd schema in connector.yml and make sure it exists.');
        }

        $supportedExtensions = $params->get('supported_extensions');
        $collections = $params->get('collections');
        $offloadStatus = $params->get('offload_status');
        $offloadValues = $offloadStatus['values'];
        $conversionTable = $params->get('conversion_table');

        $collectionKey = $collections['key'];

        $filter = array($offloadValues['offload'], $offloadValues['offload_but_keep_original'], $offloadValues['offload_pending'], $offloadValues['offloaded']);
        $metadataTemplate = null;

        // Loop through all collections
        foreach($collections['values'] as $collection) {
            $allResources = $resourceSpace->getAllResources($collectionKey, $collection);
            foreach($allResources as $resource) {
                $resourceId = $resource['ref'];
                $resourceData = $resourceSpace->getResourceDataIfFieldContains($resourceId, $offloadStatus['key'], $filter);

                if($resourceData != null) {

                    // For debugging purposes
//                    var_dump($resource);

                    $data = array();
                    foreach ($resourceData as $field) {
                        $data[$field['name']] = $field['value'];
                    }

                    $extension = pathinfo($data['originalfilename'], PATHINFO_EXTENSION);
                    if (!in_array($extension, $supportedExtensions)) {
                        echo 'ERROR: File ' . $data['originalfilename'] . ' (resource ' . $resourceId . ') has extension "' . $extension . '", which is not supported.' . PHP_EOL;
                    } else {
                        $originalFilename = pathinfo($data['originalfilename'], PATHINFO_FILENAME);
                        $uniqueFilename = $resourceId . '_' . $originalFilename;

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
                            if ($fileModifiedTimestamp > $lastOffloadTimestamp) {
                                $localFilename = $outputFolder . '/' . $uniqueFilename . '.' . $extension;
                                $resourceUrl = $resourceSpace->getResourceUrl($resourceId);
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
                                    $updateMetadata = strtotime($metadataModifiedDate) > $lastOffloadTimestamp;
                                }
                            }
                            if ($updateMetadata) {
                                if ($metadataTemplate == null) {
                                    $loader = new FilesystemLoader('./');
                                    $twig = new Environment($loader);
                                    $metadataTemplate = $twig->load($templateFile);
                                }
                                $xmlData = $metadataTemplate->render(array(
                                    'resource' => $data,
                                    'resource_id' => $resourceId,
                                    'filename' => $uniqueFilename . '.' . $extension,
                                    'collection' => $collection,
                                    'md5_hash' => $md5,
                                    'conversion_table' => $conversionTable
                                ));
                                $xmlFile = $outputFolder . '/' . $uniqueFilename . '.xml';
                                file_put_contents($xmlFile, $xmlData);

                                $validated = false;
                                try {
                                    $domDoc = new DOMDocument();
                                    $domDoc->loadXML($xmlData, LIBXML_NOBLANKS);
                                    if ($domDoc->schemaValidate($templateXsdSchemaFile)) {
                                        $validated = true;
                                    }
                                } catch(Exception $e) {
                                    echo 'XML file ' . $xmlFile . ' is not valid:' . PHP_EOL . $e->getMessage() . PHP_EOL;
                                }
                                if($validated) {
                                    if ($doOffload) {
                                        //Upload the image file and delete locally, but only if the file has been modified since the last offload
                                        if ($fileModified && $localFilename != null) {
                                            $ftpUtil->uploadFile($collection, $localFilename, $uniqueFilename . '.' . $extension);
                                            unlink($localFilename);
                                        }

                                        //Upload the XML file and delete locally
                                        $ftpUtil->uploadFile($collection, $xmlFile, $uniqueFilename . '.xml');
                                        unlink($xmlFile);
                                        $resourceSpace->updateField($resourceId, $offloadStatus['key'], $offloadValues['offload_pending']);
                                        if ($fileModified) {
                                            $updatemd5 = false;
                                            if (!array_key_exists('md5checksum', $data)) {
                                                $updatemd5 = true;
                                            } else if ($data['md5checksum'] != $md5) {
                                                $updatemd5 = true;
                                            }
                                            if ($updatemd5) {
                                                $resourceSpace->updateField($resourceId, 'md5checksum', $md5);
                                            }
                                        }
                                    }

                                    if($fileModified) {
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
                }
            }
        }

        if($doOffload) {
            $file = fopen($lastTimestampFile, "w") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            fwrite($file, time());
            fclose($file);
        }
    }
}

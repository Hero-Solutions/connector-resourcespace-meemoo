<?php
namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\FtpUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class OffloadImagesCommand extends Command
{
    private $params;

    private $collections;
    private $offloadStatus;

    private $resourceSpace;
    private $ftpUtil;

    private $resourceSpaceData;
    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;
    private $exifFields;
    private $verbose;
    private $logger;

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
        $this->resourceSpace = new ResourceSpace($this->params);
        $this->ftpUtil = new FtpUtil($this->params);

        $lastOffloadTimestamp = 0;
        $lastTimestampFilename = $this->params->get('last_offload_timestamp_filename');
        if(file_exists($lastTimestampFilename)) {
            $file = fopen($lastTimestampFilename, "r") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFilename . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        }

        $this->collections = $this->params->get('collections');
        $this->offloadStatus = $this->params->get('offload_status');

        $collectionKey = $this->collections['key'];

        $offload = $this->offloadStatus['offload_value'];
        $offloadButKeepOriginal = $this->offloadStatus['offload_but_keep_original_value'];
        $offloaded = $this->offloadStatus['offloaded_value'];
        $filter = array($offload, $offloadButKeepOriginal, $offloaded);

        foreach($this->collections['values'] as $collection) {
            $allResources = $this->resourceSpace->getAllResources($collectionKey, $collection);
            foreach($allResources as $resource) {
                $resourceId = $resource['ref'];
                $resourceData = $this->resourceSpace->getResourceDataIfFieldContains($resourceId, $this->offloadStatus['key'], $filter);
                if($resourceData != null) {
                    //Check when the file was last modified
                    if(array_key_exists('file_modified', $resource)) {
                        $fileModifiedDate = $resource['file_modified'];
                        $fileModifiedTimestamp = 0;
                        if(strlen($fileModifiedDate) > 0) {
                            $fileModifiedTimestamp = strtotime($fileModifiedDate);
                        }
                        if($fileModifiedTimestamp > $lastOffloadTimestamp) {
                            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId);
                            $this->ftpUtil->copyFile($resourceUrl);

                            //TODO generate MD5

                            echo 'Offloaded resource file ' . $resourceId . ' (modified ' . $fileModifiedDate . ')' . PHP_EOL;
                        } else {
                            echo 'NO offload resource file ' . $resourceId . ' (modified ' . $fileModifiedDate . ')' . PHP_EOL;
                        }
                    }

                    //Check when the metadata was last modified
                    if(array_key_exists('modified', $resource)) {
                        $metadataModifiedTimestamp = 0;
                        $metadataModifiedDate = $resource['modified'];
                        if(strlen($metadataModifiedDate) > 0) {
                            $metadataModifiedTimestamp = strtotime($metadataModifiedDate);
                        }
                        if($metadataModifiedTimestamp > $lastOffloadTimestamp) {
                            echo 'Offload resource metadata ' . $resourceId . ' (modified ' . $metadataModifiedDate . ')' . PHP_EOL;

                            $data = array();
                            foreach($resourceData as $field) {
                                $data[$field['name']] = $field['value'];
                            }

                            //TODO generate metadata XML
                        } else {
                            echo 'NO offload resource metadata ' . $resourceId . ' (modified ' . $metadataModifiedDate . ')' . PHP_EOL;
                        }
                    }
                    echo PHP_EOL;
                }
            }
        }
    }
}

<?php
namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\FtpUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TestMetadataModelCommand extends Command
{
    private $params;
    private $template;

    private $collections;
    private $offloadStatus;

    private $resourceSpace;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:test-metadata')
            ->setDescription('Lists all ResourceSpace resources and offloads all images with the appropriate metadata onto an FTP server.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->resourceSpace = new ResourceSpace($this->params);
        $this->ftpUtil = new FtpUtil($this->params);

        $lastOffloadTimestamp = 0;
        $lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if(file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        }
        $metadataDestDir = $this->params->get('template_dest_dir');
        if (!is_dir($metadataDestDir)) {
            mkdir($metadataDestDir);
        }

        $templateFolder = $this->params->get('template_folder');
        $templateFile = $this->params->get('template_file');
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

//                    var_dump($resource);

                    $data = array();
                    foreach($resourceData as $field) {
                        $data[$field['name']] = $field['value'];
                    }

                    $filename = pathinfo($data['originalfilename'], PATHINFO_FILENAME);

                    $md5 = null;

                    //Check when the file was last modified
                    if(array_key_exists('file_modified', $resource)) {
                        $fileModifiedDate = $resource['file_modified'];
                        $fileModifiedTimestamp = 0;
                        if(strlen($fileModifiedDate) > 0) {
                            $fileModifiedTimestamp = strtotime($fileModifiedDate);
                        }
                        if($fileModifiedTimestamp > $lastOffloadTimestamp) {
                            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId);
                            $md5 = md5_file($resourceUrl);

                            //TODO generate MD5

                            echo 'Resource file ' . $filename . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will be offloaded' . PHP_EOL;
                        } else {
                            echo 'Resource file ' . $filename . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will NOT be offloaded' . PHP_EOL;
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
                            if($this->template == null) {
                                $loader = new FilesystemLoader($templateFolder);
                                $twig = new Environment($loader);
                                $this->template = $twig->load($templateFile);
                            }
                            $xmlData = $this->template->render(array(
                                'resource' => $data,
                                'resource_id' => $resourceId,
                                'collection' => $collection,
                                'md5_hash' => $md5
                            ));
                            file_put_contents($metadataDestDir . '/' . $filename . '.xml', $xmlData);

                            echo 'Resource metadata ' . $filename . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will be offloaded' . PHP_EOL;
                        } else {
                            echo 'Resource metadata ' . $filename . ' (resource ' . $resourceId . ', modified ' . $metadataModifiedDate . ') will NOT be offloaded' . PHP_EOL;
                        }
                    }
                    echo PHP_EOL;
                }
            }
        }
    }
}

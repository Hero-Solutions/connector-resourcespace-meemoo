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

class OffloadImagesCommand extends Command
{
    private $params;
    private $template;

    private $collections;
    private $offloadStatus;

    private $resourceSpace;
    private $ftpUtil;

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
        $lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if(file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        }
        $outputDir = $this->params->get('output_dir');
        if (!is_dir($outputDir)) {
            mkdir($outputDir);
        }

        $templateFolder = $this->params->get('template_folder');
        $templateFile = $this->params->get('template_file');
        $this->collections = $this->params->get('collections');
        $this->offloadStatus = $this->params->get('offload_status');
        $conversionTable = $this->params->get('conversion_table');

        $collectionKey = $this->collections['key'];

        $offload = $this->offloadStatus['offload_value'];
        $offloadButKeepOriginal = $this->offloadStatus['offload_but_keep_original_value'];
        $offloaded = $this->offloadStatus['offloaded_value'];
        $filter = array($offload, $offloadButKeepOriginal, $offloaded);

        // Loop through all collections
        foreach($this->collections['values'] as $collection) {
            $allResources = $this->resourceSpace->getAllResources($collectionKey, $collection);
            foreach($allResources as $resource) {
                $resourceId = $resource['ref'];
                $resourceData = $this->resourceSpace->getResourceDataIfFieldContains($resourceId, $this->offloadStatus['key'], $filter);

                if($resourceData != null) {

                    // For debugging purposes
//                    var_dump($resource);

                    $data = array();
                    foreach($resourceData as $field) {
                        $data[$field['name']] = $field['value'];
                    }

                    $filename = pathinfo($data['originalfilename'], PATHINFO_FILENAME);

                    $md5 = null;
                    $fileModified = false;

                    //Check when the file was last modified
                    if(array_key_exists('file_modified', $resource)) {
                        $fileModifiedDate = $resource['file_modified'];
                        $fileModifiedTimestamp = 0;
                        if(strlen($fileModifiedDate) > 0) {
                            $fileModifiedTimestamp = strtotime($fileModifiedDate);
                        }
                        if($fileModifiedTimestamp > $lastOffloadTimestamp) {
                            $destFilename = $outputDir . '/' . $data['originalfilename'];
                            $resourceUrl = $this->resourceSpace->getResourceUrl($resourceId);
                            copy($resourceUrl, $destFilename);
                            $md5 = md5_file($destFilename);

                            //TODO uncomment when we want to actually upload through FTP
//                            $this->ftpUtil->copyFile($resourceUrl);

                            $fileModified = true;

                            unlink($destFilename);

                            echo 'Resource file ' . $filename . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will be offloaded' . PHP_EOL;
                        } else {
                            echo 'Resource file ' . $filename . ' (resource ' . $resourceId . ', modified ' . $fileModifiedDate . ') will NOT be offloaded' . PHP_EOL;
                        }
                    }

                    $updateMetadata = $fileModified;

                    //Check when the metadata was last modified
                    if($updateMetadata || array_key_exists('modified', $resource)) {
                        if(!$updateMetadata) {
                            $metadataModifiedDate = $resource['modified'];
                            if (strlen($metadataModifiedDate) > 0) {
                                $updateMetadata = strtotime($metadataModifiedDate) > $lastOffloadTimestamp;
                            }
                        }
                        if($updateMetadata) {
                            if($this->template == null) {
                                $loader = new FilesystemLoader($templateFolder);
                                $twig = new Environment($loader);
                                $this->template = $twig->load($templateFile);
                            }
                            $xmlData = $this->template->render(array(
                                'resource' => $data,
                                'resource_id' => $resourceId,
                                'collection' => $collection,
                                'md5_hash' => $md5,
                                'conversion_table' => $conversionTable
                            ));

                            $xmlFile = $outputDir . '/' . $filename . '.xml';
                            file_put_contents($xmlFile, $xmlData);

                            //TODO uncomment when we want to actually upload through FTP
//                            $this->ftpUtil->copyFile($xmlFile);
//                            unlink($xmlFile);
//                            $this->resourceSpace->updateField($resourceId, $this->offloadStatus['key'], $this->offloadStatus['offload_pending']);

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

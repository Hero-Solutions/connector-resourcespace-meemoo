<?php
namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class OffloadImagesCommand extends Command
{
    private $params;

    private $collections;

    private $resourceSpace;

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
        $this->collections = $this->params->get('collections');

        $metadataField = 'offloadstatus';
        $filter = array('Ready for offload', 'Offload but keep original', 'Offloaded');
//        $filter = array('Ready for offload', 'Offload but keep original');

        foreach($this->collections as $collection) {
            $allResources = $this->resourceSpace->getAllResources($collection);
            foreach($allResources as $resource) {
                $resourceId = $resource['ref'];
                $offloadStatus = $this->resourceSpace->getResourceSpaceField($resourceId, $metadataField);
                if(in_array($offloadStatus, $filter)) {
                    echo $this->resourceSpace->getResourcePath($resourceId) . PHP_EOL;

                }
            }
        }
    }
}

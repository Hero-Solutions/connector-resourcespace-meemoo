<?php
namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestMetadataModelCommand extends Command
{
    private $params;
    private $entityManager;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:test-metadata')
            ->setDescription('Lists all ResourceSpace resources and generates XML metadata files for the appropriate resources (dry run, does not actually offload images).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cmd = new OffloadResourcesCommand($this->params, $this->entityManager, false, true);
        $cmd->setVerbose($input->getOption('verbose'));
        $cmd->offloadImages();
        return 0;
    }
}

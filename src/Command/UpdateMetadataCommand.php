<?php
namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UpdateMetadataCommand extends Command
{
    private $params;
    private $entityManager;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:update-metadata')
            ->setDescription('Force an update of the metadata of all resources with an appropriate offload status, does not offload any files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cmd = new OffloadResourcesCommand($this->params, $this->entityManager, true);
        $cmd->setVerbose($input->getOption('verbose'));
        $cmd->offloadImages();
        return 0;
    }
}

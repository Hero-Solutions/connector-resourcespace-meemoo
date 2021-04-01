<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UpdateMetadataCommand extends Command
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
            ->setName('app:update-metadata')
            ->setDescription('Force an update of the metadata of all resources with an appropriate offload status, does not offload any files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cmd = new OffloadResourcesCommand($this->params, true);
        $cmd->setVerbose($input->getOption('verbose'));
        $cmd->offloadImages();
        return 0;
    }
}

<?php


namespace App\Command;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CheckOffloadCommand extends Command
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
            ->setName('app:check-offload')
            ->setDescription('Checks the status of the last offloaded images and deletes originals if successful.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

    }
}
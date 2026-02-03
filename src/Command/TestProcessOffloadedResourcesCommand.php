<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\HttpAdapter\CurlAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestProcessOffloadedResourcesCommand extends Command
{
    private ParameterBagInterface $params;
    private EntityManagerInterface $entityManager;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:test-process-offloaded-resources')
            ->setDescription('Checks the status of the last offloaded images. Performs a dry run, does not actually update ResourceSpace metadata or delete resources.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $cmd = new ProcessOffloadedResourcesCommand($this->params, $this->entityManager, true);
        $cmd->setVerbose($verbose);
        $cmd->process();
        return 0;
    }
}

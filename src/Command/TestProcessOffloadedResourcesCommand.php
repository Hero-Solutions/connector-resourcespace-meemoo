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
use Symfony\Component\Console\Input\InputOption;
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
            ->setDescription('Checks offloaded images. Performs a dry run and never updates ResourceSpace metadata, files or timestamps.')
            ->addOption('resource-id', null, InputOption::VALUE_REQUIRED, 'Only inspect this numeric ResourceSpace resource ID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the targeted OAI-PMH window (YYYY-MM-DD).')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'End of the targeted OAI-PMH window (YYYY-MM-DD, inclusive).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $cmd = new ProcessOffloadedResourcesCommand($this->params, $this->entityManager, true);
        if (!$cmd->configureTargetOptions(
            $input->getOption('resource-id'),
            $input->getOption('from'),
            $input->getOption('until'),
            $output
        )) {
            return Command::INVALID;
        }
        $cmd->setVerbose($verbose);
        return $cmd->process();
    }
}

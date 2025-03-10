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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ReplaceOriginalCommand extends Command
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
            ->setName('app:replace-original')
            ->addArgument('id', InputArgument::REQUIRED, 'The resource ID for which to replace the original file')
            ->setDescription('Replaces an original resource by its alternative (hpr/scr/...). WARNING: will replace the original file in your production environment!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resourceId = $input->getArgument('id');
        if($resourceId == null) {
            return 1;
        }

        $verbose = $input->getOption('verbose');

        $resourceSpace = new ResourceSpace($this->params);
        $rawResourceData = $resourceSpace->getRawResourceFieldData($resourceId);
        $resourceMetadata = $resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
        $data = $resourceSpace->replaceOriginal($resourceId, $resourceMetadata['originalfilename'], $this->entityManager);
        var_dump($data);
        return 0;
    }
}

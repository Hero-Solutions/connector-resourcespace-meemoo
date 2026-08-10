<?php
namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestMetadataModelCommand extends Command
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
            ->setName('app:test-metadata')
            ->setDescription('Lists ResourceSpace resources and generates XML metadata files for the appropriate resources (dry run, does not actually offload images).')
            ->addOption(
                'resource-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Only test the ResourceSpace resource with this numeric ID.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The PID keeps concurrent runs within the same second apart
        $outputSubFolder = 'test-metadata/' . gmdate('Ymd_His') . '_' . getmypid();
        echo 'Writing test metadata XML files to output subfolder "' . $outputSubFolder . '".' . PHP_EOL;

        $resourceIdOption = $input->getOption('resource-id');
        $resourceId = null;
        if ($resourceIdOption !== null) {
            if (!is_string($resourceIdOption) || !ctype_digit($resourceIdOption) || (int) $resourceIdOption < 1) {
                $output->writeln('<error>--resource-id must be a positive numeric ResourceSpace ID.</error>');
                return Command::INVALID;
            }
            $resourceId = (int) $resourceIdOption;
            echo 'Limiting this metadata test to ResourceSpace resource ' . $resourceId . '.' . PHP_EOL;
        }

        $cmd = new OffloadResourcesCommand(
            $this->params,
            $this->entityManager,
            false,
            true,
            $outputSubFolder,
            $resourceId
        );
        $cmd->setVerbose($input->getOption('verbose'));
        return $cmd->offloadImages();
    }
}

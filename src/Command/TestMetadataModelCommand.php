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

class TestMetadataModelCommand extends Command
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
            ->setName('app:test-metadata')
            ->setDescription('Lists all ResourceSpace resources and generates XML metadata files for the appropriate resources (dry run, does not actually offload images).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cmd = new OffloadImagesCommand($this->params, true);
        $cmd->offloadImages();
    }
}

<?php
namespace App\Command;

use App\Util\RestApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestApiCommand extends Command
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:test-api')
            ->setDescription('Tests API access.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $restApi = new RestApi($this->params);
        $collections = $this->params->get('collections');
        foreach($collections as $collection) {
            echo 'API access key for ' . $collection . ':' . PHP_EOL;
            var_dump($restApi->getAccessToken($collection));
        }
        return 0;
    }
}

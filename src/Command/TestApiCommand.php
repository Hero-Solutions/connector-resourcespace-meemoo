<?php
namespace App\Command;

use App\Util\OaiPmhApiUtil;
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
        $oaiPmhApi = $this->params->get('oai_pmh_api');
        $collections = $this->params->get('collections');
        foreach($collections['values'] as $collection) {
            echo 'API access key for ' . $collection . ':' . PHP_EOL;
            var_dump($restApi->getAccessToken($collection));

            $oaiPmhEndpoint = OaiPmhApiUtil::connect($restApi, $oaiPmhApi, $collection, $this->params->get('override_certificate_authority'), $this->params->get('ssl_certificate_authority_file'));
            $records = $oaiPmhEndpoint->listRecords($oaiPmhApi['metadata_prefix']);
            $counter = 0;
            foreach($records as $record) {
                $counter++;
                if($counter % 100 === 0) {
                    echo 'At ' . $counter . ' records' . PHP_EOL;
                }
            }
            echo 'Has ' . $counter . ' records' . PHP_EOL;
        }
        return 0;
    }
}

<?php


namespace App\Command;


use DateTime;
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

class ProcessOffloadedResourcesCommand extends Command
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
            ->setName('app:process-offloaded-resources')
            ->setDescription('Checks the status of the last offloaded images and deletes originals if successful.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if(file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("ERROR: Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        } else {
            die("ERROR: Unable to locate file containing last offload timestamp ('" . $lastTimestampFile . "').");
        }

        $lastOffloadDateTime = gmdate("Y-m-d\TH:i:s\Z", $lastOffloadTimestamp);

        $overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $sslCertificateAuthorityFile = $this->params->get('ssl_certificate_authority_file');
        $collections = $this->params->get('collections')['values'];
        $oaiPmhApi = $this->params->get('oai_pmh_api');

        foreach($collections as $collection) {
            try {
                $curlAdapter = new CurlAdapter();
                $curlOpts = array(
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_USERPWD => $oaiPmhApi['credentials'][$collection]['username'] . ':' . $oaiPmhApi['credentials'][$collection]['password']
                );
                if($overrideCertificateAuthorityFile) {
                    $curlOpts[CURLOPT_CAINFO] = $sslCertificateAuthorityFile;
                    $curlOpts[CURLOPT_CAPATH] = $sslCertificateAuthorityFile;
                }
                $curlAdapter->setCurlOpts($curlOpts);
                $oaiPmhClient = new Client($oaiPmhApi['url'], $curlAdapter);
                $oaiPmhEndpoint = new Endpoint($oaiPmhClient);
                $records = $oaiPmhEndpoint->listRecords('mets', new DateTime($lastOffloadDateTime));

                $i = 0;
                foreach($records as $record) {
                    $i++;
                    echo 'At record ' . $i . PHP_EOL;
                    $this->processRecord($record, $collection);
                    //TODO process
                }
            }
            catch(OaipmhException $e) {
                echo 'OAI-PMH error at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
            catch(HttpException $e) {
                echo 'OAI-PMH error at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
            catch(Exception $e) {
                echo 'OAI-PMH error at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
        }
    }

    private function processRecord($record, $collection)
    {

    }
}
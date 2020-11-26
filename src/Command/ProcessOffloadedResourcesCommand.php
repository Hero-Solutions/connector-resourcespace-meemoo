<?php


namespace App\Command;


use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
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
    private $resourceSpace;
    private $offloadStatusField;
    private $resourceSpaceMetadataFields;

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

        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');

        $lastOffloadDateTime = DateTimeUtil::formatTimestamp($lastOffloadTimestamp);

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
                $records = $oaiPmhEndpoint->listRecords($oaiPmhApi['metadata_prefix'], new DateTime($lastOffloadDateTime));

                foreach($records as $record) {
                    $this->processRecord($record->metadata->children($oaiPmhApi['namespace'], true), $oaiPmhApi['resourcespace_id_xpath'], $oaiPmhApi['meemoo_image_url_xpath'], $collection);
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

    private function processRecord($record, $resourceIdXpath, $meemooImageUrlXpath, $collection)
    {
        $resourceIds = $record->xpath($resourceIdXpath);
        foreach($resourceIds as $resourceId) {
            echo $resourceId . PHP_EOL;

            $imageUrl = null;
            $imageUrls = $record->xpath($meemooImageUrlXpath);
            foreach($imageUrls as $url) {
                $imageUrl = $url;
            }
            echo $imageUrl . PHP_EOL;

            if($this->resourceSpace == null) {
                $this->resourceSpace = new ResourceSpace($this->params);
            }

            $rawResourceData = $this->resourceSpace->getRawResourceFieldData($resourceId);
            if($rawResourceData == null) {
                echo 'ERROR: Resource ' . $resourceId . ' not found in ResourceSpace!';
            } else {
                $resourceData = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);

                $key = $this->offloadStatusField['key'];
                $offloadedValue = $this->offloadStatusField['values']['offloaded'];
                $offloadPendingvalue = $this->offloadStatusField['values']['offload_pending'];

                //TODO process
                //TODO what about resources that have 'Pending' offload status but used to be marked with 'Offload but keep original'? How do we know we wanted to keep the original?

                $syncTimestampField = $this->resourceSpaceMetadataFields['sync_timestamp'];
                if(!empty($syncTimestampField)) {
                    $this->resourceSpace->updateField($resourceId, $syncTimestampField, DateTimeUtil::formatTimestamp());
                }

                if($imageUrl != null) {
                    if(!empty($imageUrl)) {
                        $meemooImageUrlField = $this->resourceSpaceMetadataFields['meemoo_image_url'];
                        if(!empty($meemooImageUrlField)) {
                            $this->resourceSpace->updateField($resourceId, $meemooImageUrlField, $imageUrl);
                        }
                    }
                }
            }
        }
    }
}

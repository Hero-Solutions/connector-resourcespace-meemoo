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
    private $dryRun;
    private $verbose;
    private $resourceSpace;
    private $offloadStatusField;
    private $resourceSpaceMetadataFields;

    private $resourcesProcessed;

    public function __construct(ParameterBagInterface $params, $dryRun = false)
    {
        $this->params = $params;
        $this->dryRun = $dryRun;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:process-offloaded-resources')
            ->setDescription('Checks the status of the last offloaded images and deletes originals if successful. NOTE: deleting of originals is not yet supported at this time!');
    }

    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');
        $this->process();
        return 0;
    }

    public function process()
    {
        $this->resourceSpace = new ResourceSpace($this->params);

        $lastTimestampFile = $this->params->get('last_offload_timestamp_file');
        if (file_exists($lastTimestampFile)) {
            $file = fopen($lastTimestampFile, "r") or die("ERROR: Unable to open file containing last offload timestamp ('" . $lastTimestampFile . "').");
            $lastOffloadTimestamp = fgets($file);
            fclose($file);
        } else {
            die("ERROR: Unable to locate file containing last offload timestamp ('" . $lastTimestampFile . "').");
        }

        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $collections = $this->params->get('collections');
        $collectionKey = $collections['key'];

        $lastOffloadDateTime = DateTimeUtil::formatTimestampWithTimezone($lastOffloadTimestamp);

        $this->resourcesProcessed = array();

        $this->processOaiPmhApi($collections['values'], $lastOffloadDateTime);
        $this->processMissingResources($collections['values'], $collectionKey);
    }

    private function processOaiPmhApi($collections, $lastOffloadDateTime)
    {
        $overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $sslCertificateAuthorityFile = $this->params->get('ssl_certificate_authority_file');
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
                    $this->processRecord($record->metadata->children($oaiPmhApi['namespace'], true),
                        $oaiPmhApi['url'] . '?verb=GetRecord&metadataPrefix=' . $oaiPmhApi['metadata_prefix'] . '&identifier=' . $record->header->identifier,
                        $oaiPmhApi['resourcespace_id_xpath'], $oaiPmhApi['meemoo_image_url_xpath']);
                }
            }
            catch(OaipmhException $e) {
                if($e->getOaiErrorCode() == 'noRecordsMatch') {
                    echo 'No records to process, exiting.' . PHP_EOL;
                } else {
                    echo 'OAI-PMH error (1) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
                }
            }
            catch(HttpException $e) {
                echo 'OAI-PMH error (2) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
            catch(Exception $e) {
                echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
        }
    }

    private function processRecord($record, $assetUrl, $resourceIdXpath, $meemooImageUrlXpath)
    {
        $resourceIds = $record->xpath($resourceIdXpath);
        foreach($resourceIds as $id) {
            $resourceId = strval($id);

            $imageUrl = null;
            $imageUrls = $record->xpath($meemooImageUrlXpath);
            foreach($imageUrls as $url) {
                $imageUrl = $url;
            }

            $rawResourceData = $this->resourceSpace->getRawResourceFieldData($resourceId);
            if($rawResourceData == null) {
                echo 'ERROR: Resource ' . $resourceId . ' not found in ResourceSpace!' . PHP_EOL;
            } else {
                if(!$this->dryRun) {
                    $resourceMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);

                    if(!empty($this->resourceSpaceMetadataFields['meemoo_asset_url'])) {
                        $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_asset_url'], $assetUrl);
                    }
                    if($imageUrl != null) {
                        if(!empty($imageUrl)) {
                            $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_image_url'], $imageUrl);
                        }
                    }

                    $statusKey = $this->offloadStatusField['key'];
                    if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload'] || $resourceMetadata[$statusKey] != $this->offloadStatusField['values']['offload_pending']) {
                        $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded']);
                    } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']) {
                        $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded_but_keep_original']);
                    }
                }
                if($this->verbose) {
                    echo 'Resource ' . $resourceId . ' has been processed.' . PHP_EOL;
                }

                if($this->dryRun) {
                    $this->resourcesProcessed[] = $resourceId;
                }
            }
        }
    }

    private function processMissingResources($collections, $collectionKey)
    {
        $offloadStatusFilter = array($this->offloadStatusField['values']['offload_pending'], $this->offloadStatusField['values']['offload_pending_but_keep_original']);

        // Loop through all collections
        foreach($collections as $collection) {
            $allResources = $this->resourceSpace->getAllResources($collectionKey, $collection);
            // Loop through all resources in this collection
            foreach($allResources as $resourceInfo) {
                $resourceId = $resourceInfo['ref'];
                if($this->dryRun) {
                    if (in_array($resourceId, $this->resourcesProcessed)) {
                        continue;
                    }
                }

                // Get this resource's metadata, but only if it has an appropriate offloadStatus
                $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains($resourceId, $this->offloadStatusField['key'], $offloadStatusFilter);
                if($resourceMetadata != null) {
                    echo 'Resource ' . $resourceId . ' has not been processed by meemoo!' . PHP_EOL;
                }
            }
        }
    }
}

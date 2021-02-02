<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Util\DateTimeUtil;
use App\Util\OaiPmhApiUtil;
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
    private $deleteOriginals;
    private $pendingOffloadFilter;

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

        $this->deleteOriginals = $this->params->get('delete_originals');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->pendingOffloadFilter = $this->offloadStatusField['values']['offload_pending'];
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
                $oaiPmhEndpoint = OaiPmhApiUtil::connect($oaiPmhApi, $collection, $overrideCertificateAuthorityFile, $sslCertificateAuthorityFile);
                $records = $oaiPmhEndpoint->listRecords($oaiPmhApi['metadata_prefix'], new DateTime($lastOffloadDateTime));

                foreach($records as $record) {
                    $this->processRecord($record->metadata->children($oaiPmhApi['namespace'], true),
                        $oaiPmhApi['url'] . '?verb=GetRecord&metadataPrefix=' . $oaiPmhApi['metadata_prefix'] . '&identifier=' . $record->header->identifier,
                        $oaiPmhApi['resource_data_xpath'] . '/' . $oaiPmhApi['resourcespace_id'], $oaiPmhApi['meemoo_image_url_xpath']);
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

            //Image url is currently not the original image but a derivative of the original.
            //meemoo does not currently provide a way to access the original image in their filestore.
            $imageUrl = null;
            $imageUrls = $record->xpath($meemooImageUrlXpath);
            foreach($imageUrls as $url) {
                $imageUrl = $url;
            }

            $rawResourceData = $this->resourceSpace->getRawResourceFieldData($resourceId);
            if($rawResourceData == null) {
                echo 'ERROR: Resource ' . $resourceId . ' not found in ResourceSpace!' . PHP_EOL;
            } else {
                $resourceMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
//                var_dump($resourceMetadata);

                if(!$this->dryRun) {
                    $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_asset_url'], urlencode($assetUrl));
                    if($imageUrl != null) {
                        if(!empty($imageUrl)) {
                            $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_image_url'], urlencode($imageUrl));
                        }
                    }

                    $statusKey = $this->offloadStatusField['key'];
                    if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']) {
                        $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded']);
                    } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']) {
                        $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded_but_keep_original']);
                    }

                    //TODO 'delete' original. Seems best to implement this when meemoo has a IIIF endpoint so museums can still access their originals.
                }
                if($this->verbose) {
                    echo 'Resource ' . $resourceId . ' has been processed.' . PHP_EOL;
                    echo 'Resource ' . $resourceId . ' has asset URL: ' . $assetUrl . PHP_EOL;
                    echo 'Resource ' . $resourceId . ' has image URL: ' . $imageUrl . PHP_EOL;
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
                $statusKey = $this->offloadStatusField['key'];
                $resourceMetadata = $this->resourceSpace->getResourceMetadataIfFieldContains($resourceId, $statusKey, $offloadStatusFilter);
                if($resourceMetadata != null) {
                    echo 'Resource ' . $resourceId . ' has not been processed by meemoo!' . PHP_EOL;
                    if(!$this->dryRun) {
                        if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']) {
                            $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed']);
                        } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']) {
                            $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offload_failed_but_keep_original']);
                        }
                    }
                }
            }
        }
    }
}

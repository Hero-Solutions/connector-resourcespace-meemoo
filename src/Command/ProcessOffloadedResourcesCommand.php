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
    private $connectorUrl;
    private $pendingOffloadFilter;
    private $processError = false;

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

        $lastOffloadTimestampFile = $this->params->get('last_offload_timestamp_file');
        if (file_exists($lastOffloadTimestampFile)) {
            $file = fopen($lastOffloadTimestampFile, "r") or die("ERROR: Unable to open file containing last offload timestamp ('" . $lastOffloadTimestampFile . "').");
            // Ask for resources from 2 hours earlier to compensate for time differences (probably a wrong clock offset)
            $lastOffloadTimestamp = intval(fgets($file)) - 7200;
            fclose($file);
        } else {
            die("ERROR: Unable to locate file containing last offload timestamp ('" . $lastOffloadTimestampFile . "').");
        }

        //Also grab the last processed timestamp in case the OAI-PMH API was having issues
        $lastProcessedTimestampFile = $this->params->get('last_processed_timestamp_file');
        if (file_exists($lastProcessedTimestampFile)) {
            $file = fopen($lastProcessedTimestampFile, "r") or die("ERROR: Unable to open file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
            // Ask for resources from 2 hours earlier to compensate for time differences (probably a wrong clock offset)
            $lastProcessedTimestamp = intval(fgets($file)) - 7200;
            fclose($file);
        } else {
            die("ERROR: Unable to locate file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
        }

        //Use the oldest timestamp
        if($lastProcessedTimestamp < $lastOffloadTimestamp) {
            $lastOffloadTimestamp = $lastProcessedTimestamp;
        }

        $this->deleteOriginals = $this->params->get('delete_originals');
        $this->connectorUrl = $this->params->get('connector_url');
        $this->offloadStatusField = $this->params->get('offload_status_field');
        $this->pendingOffloadFilter = $this->offloadStatusField['values']['offload_pending'];
        $this->resourceSpaceMetadataFields = $this->params->get('resourcespace_metadata_fields');
        $collections = $this->params->get('collections');
        $collectionKey = $collections['key'];

        $lastOffloadDateTime = DateTimeUtil::formatTimestampWithTimezone($lastOffloadTimestamp);

        $this->resourcesProcessed = array();

        $this->processOaiPmhApi($collections['values'], $lastOffloadDateTime);
        $this->processMissingResources($collections['values'], $collectionKey);

        if(!$this->dryRun && $this->processError === false) {
            $timestamp = time();
            $file = fopen($lastProcessedTimestampFile, "w") or die("Unable to open file containing last processed timestamp ('" . $lastProcessedTimestampFile . "').");
            fwrite($file, $timestamp);
            fclose($file);
        }
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
                    $this->processRecord($collection, $record->header->identifier, $record->metadata->children($oaiPmhApi['namespace'], true),
                        $oaiPmhApi['resource_data_xpath'] . '/' . $oaiPmhApi['resourcespace_id'], $oaiPmhApi['media_id_xpath'], $oaiPmhApi['archive_status_xpath'],
                        $oaiPmhApi['completed_status']);
                }
            }
            catch(OaipmhException $e) {
                if($e->getOaiErrorCode() == 'noRecordsMatch') {
                    echo 'No records to process for ' . $collection . '.' . PHP_EOL;
                } else {
                    echo 'OAI-PMH error (1) at collection ' . $collection . ': ' . $e . PHP_EOL;
                    $this->processError = true;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
                }
            }
            catch(HttpException $e) {
                echo 'OAI-PMH error (2) at collection ' . $collection . ': ' . $e . PHP_EOL;
                $this->processError = true;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
            catch(Exception $e) {
                echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
                $this->processError = true;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
        }
    }

    private function processRecord($collection, $assetId, $record,
                                   $resourceIdXpath, $mediaIdXpath, $archiveStatusXpath, $completedStatuses)
    {
        $resourceIds = $record->xpath($resourceIdXpath);
        foreach($resourceIds as $id) {
            $resourceId = strval($id);

            //Only process ResourceSpace ID's (maybe we should work out a more robust mechanism to detect which resources were offloaded through ResourceSpace)
            if(preg_match('/^[0-9]+$', $resourceId)) {

                $archiveStatus = null;
                $archiveStatuses = $record->xpath($archiveStatusXpath);
                foreach($archiveStatuses as $status) {
                    $archiveStatus = $status;
                }
                if($archiveStatus === null || !in_array($archiveStatus, $completedStatuses)) {
                    echo 'ERROR: resource ' . $resourceId . ' has archive status ' . $archiveStatus . PHP_EOL;
                } else {
                    $imageUrl = null;
                    $mediaIds = $record->xpath($mediaIdXpath);
                    foreach ($mediaIds as $mediaId) {
                        $imageUrl = $this->connectorUrl . 'download/' . $collection . '/' . $mediaId;
                    }
                    $assetUrl = $this->connectorUrl . 'data/' . $collection . '/' . $assetId;

                    $rawResourceData = $this->resourceSpace->getRawResourceFieldData($resourceId);
                    if ($rawResourceData == null) {
                        echo 'ERROR: Resource ' . $resourceId . ' not found in ResourceSpace!' . PHP_EOL;
                    } else if ($imageUrl == null) {
                        echo 'ERROR: cannot create link to original image' . PHP_EOL;
                    } else if (empty($imageUrl)) {
                        echo 'ERROR: cannot create link to original image' . PHP_EOL;
                    } else {
                        $resourceMetadata = $this->resourceSpace->getResourceFieldDataAsAssocArray($rawResourceData);
                        $statusKey = $this->offloadStatusField['key'];

                        if (!$this->dryRun && !empty($resourceMetadata[$statusKey])) {
                            $existingAssetUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_asset_url']];
                            if (empty($existingAssetUrl)) {
                                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_asset_url'], $assetUrl);
                            } else if (strpos($existingAssetUrl, $assetUrl) === false) {
                                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_asset_url'], $existingAssetUrl . '\n\n' . $assetUrl);
                            }

                            $existingOriginalUrl = $resourceMetadata[$this->resourceSpaceMetadataFields['meemoo_image_url']];
                            if (empty($existingOriginalUrl)) {
                                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_image_url'], $imageUrl);
                            } else if (strpos($existingOriginalUrl, $imageUrl) === false) {
                                $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['meemoo_image_url'], $existingOriginalUrl . '\n\n' . $imageUrl);
                            }

                            if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed']) {
                                $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded']);
                                if ($this->deleteOriginals) {
                                    $result = $this->resourceSpace->replaceOriginal($resourceId, $resourceMetadata['originalfilename']);
                                    if ($result['status'] === false) {
                                        $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_error'], $result['message'], false, true);
                                    }
                                }
                            } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed_but_keep_original']) {
                                $this->resourceSpace->updateField($resourceId, $statusKey, $this->offloadStatusField['values']['offloaded_but_keep_original']);
                            }
                        }
                        if ($this->verbose) {
                            echo 'Resource ' . $resourceId . ' has been processed by meemoo.' . PHP_EOL;
        /*                    echo 'Resource ' . $resourceId . ' has asset URL: ' . $assetUrl . PHP_EOL;
                            echo 'Resource ' . $resourceId . ' has image URL: ' . $imageUrl . PHP_EOL;
                            echo 'Resource ' . $resourceId . ' already has status ' . $resourceMetadata[$statusKey] . PHP_EOL;
                            $statusKey = $this->offloadStatusField['key'];
                            if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed']) {
                                echo 'Set resource ' . $resourceId . ' status from ' . $resourceMetadata[$statusKey] . ' to ' . $this->offloadStatusField['values']['offloaded'] . PHP_EOL;
                            } else if ($resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_but_keep_original'] || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_pending_but_keep_original']
                                || $resourceMetadata[$statusKey] == $this->offloadStatusField['values']['offload_failed_but_keep_original']) {
                                echo 'Set resource ' . $resourceId . ' status from ' . $resourceMetadata[$statusKey] . ' to ' . $this->offloadStatusField['values']['offloaded_but_keep_original'] . PHP_EOL;
                            }*/
                        }

                        if ($this->dryRun) {
                            $this->resourcesProcessed[] = $resourceId;
                        }
                    }
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
                        $this->resourceSpace->updateField($resourceId, $this->resourceSpaceMetadataFields['offload_error'], 'Resource has not been processed by meemoo.', false, true);
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

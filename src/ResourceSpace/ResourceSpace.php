<?php

namespace App\ResourceSpace;

use App\Entity\FileChecksum;
use App\Util\DateTimeUtil;
use App\Util\HttpUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;
    private $replacementImageTypes;
    private $allImageTypes;
    private $tmpDownloadFolderPath;
    private $tmpDownloadFolderUrl;

    // All metadata field titles, obtained during the first get_resource_field_data call
    private $metadataFieldTitles = null;
    // Relevant metadata field titles, a filtering of metadataFieldTitles based on the relevant fields that are passed on the first call of didRelevantMetadataChange()
    private $relevantMetadataFieldTitles = null;

    public function __construct(ParameterBagInterface $params)
    {
        $resourceSpaceApi = $params->get('resourcespace_api');
        $this->apiUrl = $resourceSpaceApi['url'];
        $this->apiUsername = $resourceSpaceApi['username'];
        $this->apiKey = $resourceSpaceApi['key'];
        $this->replacementImageTypes = $params->get('replacement_image_types');
        $this->allImageTypes = $params->get('all_image_types');
        $this->tmpDownloadFolderPath = $params->get('tmp_download_folder_path');
        $this->tmpDownloadFolderUrl = $params->get('tmp_download_folder_url');
    }

    public function getAllResources($search)
    {
        $allResources = $this->doApiCall('do_search&param1=' . $search);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        return $resources;
    }

    public function getResourceMetadata($ref)
    {
        return $this->getResourceFieldDataAsAssocArray($this->getRawResourceFieldData($ref));
    }

    public function getResourceMetadataIfFieldContains($ref, $fieldName, $filter)
    {
        $rawResourceMetadata = $this->getRawResourceFieldData($ref);
        $isValid = false;
        if($rawResourceMetadata != null) {
            if(!empty($rawResourceMetadata)) {

                // Initialize metadata field titles if not yet initialized
                $this->initializeMetadataFields($rawResourceMetadata);

                // Check if the field we're interested in (offloadStatus) contains one of the the appropriate values
                foreach($rawResourceMetadata as $field) {
                    if($field['name'] == $fieldName) {
                        $isValid = in_array($field['value'], $filter);
                        break;
                    }
                }
            }
        }
        return $isValid ? $this->getResourceFieldDataAsAssocArray($rawResourceMetadata) : null;
    }

    private function initializeMetadataFields($rawResourceMetadata)
    {
        if($this->metadataFieldTitles == null) {
            $this->metadataFieldTitles = array();
            foreach($rawResourceMetadata as $field) {
                $this->metadataFieldTitles[$field['name']] = $field['title'];
            }
        }
    }

    public function getResourceFieldDataAsAssocArray($data)
    {
        $result = array();
        foreach ($data as $field) {
            $result[$field['name']] = $field['value'];
        }
        return $result;
    }

    public function didRelevantMetadataChange($id, $lastOffloadTimestamp, $relevantFields)
    {
        // Initialize relevant metadata field titles if not yet initialized
        if($this->relevantMetadataFieldTitles == null) {
            $this->relevantMetadataFieldTitles = array();
            foreach($relevantFields as $field) {
                if(array_key_exists($field, $this->metadataFieldTitles)) {
                    $this->relevantMetadataFieldTitles[] = $this->metadataFieldTitles[$field];
                }
            }
        }

        // Loop through the resource log and check if there are any relevant entries that have changed since the last offload
        $didChange = false;
        $logEntries = $this->getResourceLog($id);
        foreach($logEntries as $logEntry) {
            if(in_array($logEntry['title'], $this->relevantMetadataFieldTitles)) {
                if($logEntry['date'] >= $lastOffloadTimestamp) {
                    $didChange = true;
                    break;
                }
            }
        }
        return $didChange;
    }

    public function getRawResourceFieldData($id)
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourceLog($id)
    {
        $data = $this->doApiCall('get_resource_log&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourceUrl($id, $extension)
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0&param5=' . $extension);
        return json_decode($data, true);
    }

    public function updateField($id, $field, $value, $nodeValue = false, $prependTimestamp = false)
    {
        if($prependTimestamp) {
            $value = DateTimeUtil::formatTimestampSimple() . ' - ' . $value;
        }
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . urlencode($value) . '&param4=' . $nodeValue);
        return json_decode($data, true);
    }

    public function updateError($id, $field, $value, $resourceMetadata, $nodeValue = false, $prependTimestamp = false)
    {
        $update = true;
        if(array_key_exists($field, $resourceMetadata)) {
            if(!empty($resourceMetadata[$field])) {
                $currentError = $resourceMetadata[$field];
                if(preg_match('/^[0-9]+-[0-9]+-[0-9]+ [0-9]+:[0-9]+:[0-9]+ - .*$/', $resourceMetadata[$field])) {
                    $index = strpos($resourceMetadata[$field], ' - ');
                    $currentError = substr($resourceMetadata[$field], $index + 3);
                }
                var_dump($currentError);
                var_dump($value);
                if($currentError === $value) {
                    $update = false;
                }
            }
        }
        if($update) {
            $this->updateField($id, $field, $value, $nodeValue, $prependTimestamp);
        }
    }

    public function replaceOriginal($id, $originalFilename, $entityManager)
    {
        $data = array('status' => false, 'message' => 'No alternative image found, original has not been deleted.');
        $alreadyReplaced = false;
        foreach ($this->allImageTypes as $imageType) {
            if (preg_match('/^.*' . $id . $imageType . '.*$/', $originalFilename) === 1) {
                $alreadyReplaced = true;
                $data = array('status' => true, 'message' => 'File was already replaced (' . $originalFilename . ').');
                break;
            }
        }
        if($alreadyReplaced) {
            return $data;
        }
        foreach($this->replacementImageTypes as $imgType) {
            $imageUrl = $this->getResourcePath($id, $imgType, 0);
            if(!empty($imageUrl)) {
                if (HttpUtil::urlExists($imageUrl)) {
                    //Download the replacement file because ResourceSpace cannot directly replace original files by its own alternative files
                    $dotPos = strrpos($originalFilename, '.');
                    if($dotPos === false) {
                        $filename = $originalFilename;
                    } else {
                        $filename = substr($originalFilename, 0, $dotPos);
                    }
                    $filename = $id . $imgType . '_' . $filename . '.jpg';
                    file_put_contents($this->tmpDownloadFolderPath . $filename, fopen($imageUrl, 'r'));
//                    $url = $this->tmpDownloadFolderUrl . $filename;
//                    $url = 'https://datahub.herosolutions.be/92132hpr_2018_0045_0001_0002.jpg';
                    $data = array('status' => true, 'message' => json_decode($this->doApiCall('replace_resource_file&param1=' . $id . '&param2=' . urlencode($this->tmpDownloadFolderUrl . $filename) . '&param3=1&param4=0&param5=0'), true));
                    unlink($this->tmpDownloadFolderPath . $filename);
                    break;
                }
            }
        }

        //Obtain the new MD5 checksum of the replaced file and store it in the database to prevent
        //the new replaced file from being offloaded to meemoo
        $metadata = $this->getResourceMetadata($id);
        if(array_key_exists('md5checksum', $metadata)) {
            if(!empty($metadata['md5checsum'])) {
                $fileChecksum = new FileChecksum();
                $fileChecksum->setFileChecksum($metadata['md5checksum']);
                $fileChecksum->setResourceId($id);
                $entityManager->persist($fileChecksum);
                $entityManager->flush();
            }
        }

        return $data;
    }

    public function getResourcePath($id, $type, $filePath, $extension = '')
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=' . $filePath . '&param3=' . $type . '&param5=' . $extension);
        return json_decode($data, true);
    }

    private function doApiCall($query)
    {
        $query = 'user=' . str_replace(' ', '+', $this->apiUsername) . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }
}

<?php

namespace App\ResourceSpace;

use App\Util\HttpUtil;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;
    private $imageTypes;

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
        $this->imageTypes = $params->get('image_types');
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

    public function updateField($id, $field, $value, $nodeValue = false)
    {
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . str_replace(' ', '+', $value) . '&param4=' . $nodeValue);
        return json_decode($data, true);
    }

    public function replaceOriginal($id)
    {
        $data = false;
        foreach($this->imageTypes as $imageType) {
            $imageUrl = $this->getResourcePath($id, $imageType, 0);
            if(!empty($imageUrl)) {
                if(HttpUtil::urlExists($imageUrl)) {
                    $data = json_decode($this->doApiCall('replace_resource_file&param1=' . $id . '&param2=' . urlencode($imageUrl) . '&param3=0&param4=0&param5=0'), true);
                    break;
                }
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

<?php

namespace App\ResourceSpace;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;

    public function __construct(ParameterBagInterface $params)
    {
        $resourceSpaceApi = $params->get('resourcespace_api');
        $this->apiUrl = $resourceSpaceApi['url'];
        $this->apiUsername = $resourceSpaceApi['username'];
        $this->apiKey = $resourceSpaceApi['key'];
    }

    public function getAllResources($key, $value)
    {
        $allResources = $this->doApiCall('do_search&param1=' . $key . ':' . $value);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        return $resources;
    }

    public function getResourceDataIfFieldContains($ref, $fieldName, $filter)
    {
        $rawResourceData = $this->getRawResourceFieldData($ref);
        $isValid = false;
        if($rawResourceData != null) {
            if(!empty($rawResourceData)) {
                foreach($rawResourceData as $field) {
                    if($field['name'] == $fieldName) {
                        if(in_array($field['value'], $filter)) {
                            $isValid = true;
                            break;
                        } else {
                            $isValid = false;
                            break;
                        }
                    }
                }
            }
        }
        return $isValid ? $this->getResourceFieldDataAsAssocArray($rawResourceData) : null;
    }

    public function getRawResourceFieldData($id)
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourceFieldDataAsAssocArray($data)
    {
        $result = array();
        foreach ($data as $field) {
            $result[$field['name']] = $field['value'];
        }
        return $result;
    }

    public function getResourceUrl($id)
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0');
        return json_decode($data, true);
    }

    public function updateField($id, $field, $value)
    {
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . $value);
        return json_decode($data, true);
    }

    private function doApiCall($query)
    {
        $query = 'user=' . $this->apiUsername . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }
}

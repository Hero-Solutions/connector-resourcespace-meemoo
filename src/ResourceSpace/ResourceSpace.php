<?php

namespace App\ResourceSpace;

use App\Utils\StringUtil;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private $params;

    private $apiUrl;
    private $apiUsername;
    private $apiKey;

    private $collectionShorthandKey;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;

        $this->apiUrl = $this->params->get('resourcespace_api_url');
        $this->apiUsername = $this->params->get('resourcespace_api_username');
        $this->apiKey = $this->params->get('resourcespace_api_key');

        $this->collectionShorthandKey = $this->params->get('collection_shorthand_key');
    }

    public function getAllResources($collection)
    {
        $allResources = $this->doApiCall('do_search&param1=' . $this->collectionShorthandKey . ':' . $collection);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        return $resources;
    }

    public function getResourceSpaceField($ref, $fieldName)
    {
        $currentData = $this->getResourceInfo($ref);
        if($currentData != null) {
            if(!empty($currentData)) {
                foreach($currentData as $field) {
                    if($field['name'] == $fieldName) {
                        return $field['value'];
                    }
                }
            }
        }
        return null;
    }

    private function getResourceInfo($id)
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourcePath($id)
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0');
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

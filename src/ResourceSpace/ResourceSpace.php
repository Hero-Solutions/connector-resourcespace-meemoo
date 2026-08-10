<?php

namespace App\ResourceSpace;

use App\Entity\FileChecksum;
use App\Util\DateTimeUtil;
use App\Util\HttpUtil;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private string $apiUrl;
    private string $apiUsername;
    private string $apiKey;
    private array $replacementImageTypes;
    private array $allImageTypes;
    private string $tmpDownloadFolderPath;
    private string $tmpDownloadFolderUrl;

    // All metadata field titles, obtained during the first get_resource_field_data call
    private ?array $metadataFieldTitles = null;
    // Relevant metadata field titles, a filtering of metadataFieldTitles based on the relevant fields that are passed on the first call of didRelevantMetadataChange()
    private ?array $relevantMetadataFieldTitles = null;

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

    public function getAllResources($search): mixed
    {
        $allResources = $this->doApiCall('do_search&param1=' . $search);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        return json_decode($allResources, true);
    }

    // Paginated do_search (ResourceSpace 10.3+): fetchrows 'offset,limit' returns { total, data },
    // ordered by resource id so consecutive chunks are consistent.
    public function getResourcesChunk($search, $offset, $count): ?array
    {
        $data = $this->doApiCall('do_search&param1=' . $search . '&param3=resourceid&param5=' . urlencode($offset . ',' . $count) . '&param6=asc');
        $decoded = json_decode($data, true);
        if (!is_array($decoded) || !array_key_exists('data', $decoded) || !array_key_exists('total', $decoded)) {
            return null;
        }
        return $decoded;
    }

    public function getResourceMetadataIfFieldContains($ref, $fieldName, $filter, ?bool &$requestFailed = null): ?array
    {
        $rawResourceMetadata = $this->getRawResourceFieldData($ref, $requestFailed);
        if ($requestFailed) {
            return null;
        }

        $isValid = false;
        if(!empty($rawResourceMetadata)) {

            // Initialize metadata field titles if not yet initialized
            $this->initializeMetadataFields($rawResourceMetadata);

            // Check if the field we're interested in (offloadStatus) contains one of the appropriate values
            foreach($rawResourceMetadata as $field) {
                if($field['name'] == $fieldName) {
                    $isValid = in_array($field['value'], $filter);
                    break;
                }
            }
        }
        return $isValid ? $this->getResourceFieldDataAsAssocArray($rawResourceMetadata) : null;
    }

    private function initializeMetadataFields($rawResourceMetadata): void
    {
        if($this->metadataFieldTitles == null) {
            $this->metadataFieldTitles = array();
            foreach($rawResourceMetadata as $field) {
                $this->metadataFieldTitles[$field['name']] = $field['title'];
            }
        }
    }

    public function getResourceFieldDataAsAssocArray($data): array
    {
        $result = array();
        foreach ($data as $field) {
            $result[$field['name']] = $field['value'];
        }
        return $result;
    }

    public function didRelevantMetadataChange($id, $lastOffloadTimestamp, $relevantFields, ?bool &$requestFailed = null): bool
    {
        $requestFailed = false;

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
        $logEntries = $this->getResourceLog($id, $requestFailed);
        if ($requestFailed) {
            return false;
        }

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

    public function getRawResourceFieldData($id, ?bool &$requestFailed = null): mixed
    {
        $requestFailed = false;
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        if ($data === false) {
            $requestFailed = true;
            return null;
        }

        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $requestFailed = true;
            return null;
        }

        // A missing resource may legitimately be represented as null/false. Every successful
        // get_resource_field_data response, however, is a list of field records. Treat any other
        // valid JSON response (for example an API error object) as a failed request as well.
        if ($decoded !== null && $decoded !== false) {
            if (!is_array($decoded)) {
                $requestFailed = true;
                return null;
            }
            foreach ($decoded as $field) {
                if (!is_array($field)
                    || !array_key_exists('name', $field)
                    || !array_key_exists('title', $field)
                    || !array_key_exists('value', $field)) {
                    $requestFailed = true;
                    return null;
                }
            }
        }

        return $decoded;
    }

    public function getResourceLog($id, ?bool &$requestFailed = null): ?array
    {
        $requestFailed = false;
        $data = $this->doApiCall('get_resource_log&param1=' . $id);
        if ($data === false) {
            $requestFailed = true;
            return null;
        }

        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !array_is_list($decoded)) {
            $requestFailed = true;
            return null;
        }

        foreach ($decoded as $logEntry) {
            if (!is_array($logEntry)
                || !array_key_exists('title', $logEntry)
                || !array_key_exists('date', $logEntry)) {
                $requestFailed = true;
                return null;
            }
        }

        return $decoded;
    }

    public function getResourceUrl($id, $extension): mixed
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0&param5=' . $extension);
        return json_decode($data, true);
    }

    public function updateField($id, $field, $value, $nodeValue = false, $prependTimestamp = false): mixed
    {
        if($prependTimestamp) {
            $value = DateTimeUtil::formatTimestampSimple() . ' - ' . $value;
        }
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . urlencode($value) . '&param4=' . $nodeValue);
        return json_decode($data, true);
    }

    // update_field returns true/false; retry a few times so a transient API hiccup does not
    // leave ResourceSpace out of sync with what was already uploaded to meemoo.
    public function updateFieldVerified($id, $field, $value, $attempts = 3): bool
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->updateField($id, $field, $value) === true) {
                return true;
            }
            if ($attempt < $attempts) {
                sleep(1);
            }
        }
        return false;
    }

    public function updateError($id, $field, $value, $resourceMetadata, $nodeValue = false, $prependTimestamp = false): mixed
    {
        $currentError = $resourceMetadata[$field] ?? '';
        if ($this->normalizeErrorMessage($currentError) === (string) $value) {
            return null;
        }

        return $this->updateField($id, $field, $value, $nodeValue, $prependTimestamp);
    }

    public function updateErrorVerified($id, $field, string $value, $currentValue = '', bool $prependTimestamp = false, int $attempts = 3): bool
    {
        if ($this->normalizeErrorMessage($currentValue) === $value) {
            return true;
        }

        $storedValue = $prependTimestamp
            ? DateTimeUtil::formatTimestampSimple() . ' - ' . $value
            : $value;

        return $this->updateFieldVerified($id, $field, $storedValue, $attempts);
    }

    private function normalizeErrorMessage($value): string
    {
        $message = (string) ($value ?? '');
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} - (.*)$/s', $message, $matches) === 1) {
            return $matches[1];
        }

        return $message;
    }

    public function isReplacementFilename($id, $filename): bool
    {
        if (!is_string($filename) || trim($filename) === '') {
            return false;
        }

        // Replacement files are created below as "<resource id><image type>_<original>.jpg".
        // Match that exact prefix instead of treating any filename containing the id and type
        // as a replacement.
        $basename = basename(str_replace('\\', '/', trim($filename)));
        foreach ($this->allImageTypes as $imageType) {
            if (str_starts_with($basename, (string) $id . $imageType . '_')) {
                return true;
            }
        }

        return false;
    }

    public function replaceOriginal($id, $originalFilename, $entityManager): array
    {
        $data = array('status' => false, 'message' => 'No alternative image found, original has not been deleted.');
        if (!is_string($originalFilename) || trim($originalFilename) === '') {
            $data['message'] = 'Original filename is missing; original has not been replaced.';
            return $data;
        }

        if ($this->isReplacementFilename($id, $originalFilename)) {
            $replacementExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $metadataReadFailed = false;
            $rawResourceMetadata = $this->getRawResourceFieldData($id, $metadataReadFailed);
            if ($metadataReadFailed || !is_array($rawResourceMetadata)) {
                return array(
                    'status' => false,
                    'message' => 'File appears to have been replaced already, but its stored checksum could not be retrieved.'
                );
            }

            $resourceMetadata = $this->getResourceFieldDataAsAssocArray($rawResourceMetadata);
            $expectedMd5 = strtolower(trim((string) ($resourceMetadata['md5checksum'] ?? '')));
            $actualMd5 = $this->getOriginalFileMd5($id, $replacementExtension, true);
            if (!preg_match('/^[0-9a-f]{32}$/', $expectedMd5)
                || $actualMd5 === null
                || !hash_equals($expectedMd5, $actualMd5)) {
                return array(
                    'status' => false,
                    'message' => 'File appears to have been replaced already, but its current bytes do not match the stored replacement checksum.'
                );
            }

            return array(
                'status' => true,
                'message' => 'File was already replaced and its checksum was verified (' . $originalFilename . ').'
            );
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
                    $filePath = $this->tmpDownloadFolderPath . $filename;
                    $fileUrl = $this->tmpDownloadFolderUrl . $filename;
                    try {
                        if (!$this->downloadFileVerified($imageUrl, $filePath)) {
                            $data['message'] = 'Alternative image could not be downloaded completely; original has not been replaced.';
                            break;
                        }
                        if (@getimagesize($filePath) === false) {
                            $data['message'] = 'Downloaded alternative is not a valid image; original has not been replaced.';
                            break;
                        }

                        $md5 = md5_file($filePath);
                        if (!is_string($md5) || !preg_match('/^[0-9A-Fa-f]{32}$/', $md5)) {
                            $data['message'] = 'Alternative image checksum could not be calculated; original has not been replaced.';
                            break;
                        }

                        $apiCallResult = $this->doApiCall('replace_resource_file&param1=' . $id . '&param2=' . urlencode($fileUrl) . '&param3=1&param4=0&param5=0');
                        if ($apiCallResult === false) {
                            $data['message'] = 'ResourceSpace did not return a response while replacing the original.';
                            break;
                        }

                        $resultDecoded = json_decode($apiCallResult, true);
                        if (!is_array($resultDecoded) || !array_key_exists('Status', $resultDecoded)) {
                            $data['message'] = 'ResourceSpace returned an invalid response while replacing the original.';
                            break;
                        }

                        $success = $resultDecoded['Status'] === 'SUCCESS';
                        $data = array('status' => $success, 'message' => $resultDecoded);

                        // If replacement was successful, store the checksum to prevent the replacement
                        // image itself from being offered to meemoo as a new original.
                        if($success) {
                            // Persist the expected replacement checksum before the read-back. This makes
                            // an interrupted verification safely resumable on the next run.
                            if (!$this->updateFieldVerified($id, 'md5checksum', strtolower($md5))) {
                                $data = array(
                                    'status' => false,
                                    'message' => 'Original was replaced, but the expected replacement checksum could not be stored.'
                                );
                                break;
                            }

                            // ResourceSpace downloads fileUrl itself. Re-download the new ResourceSpace
                            // original and compare it with the exact file we offered, so a truncated or
                            // stale second HTTP transfer can never be accepted as a completed replacement.
                            $storedReplacementMd5 = $this->getOriginalFileMd5($id, 'jpg', true);
                            if ($storedReplacementMd5 === null || !hash_equals(strtolower($md5), $storedReplacementMd5)) {
                                $data = array(
                                    'status' => false,
                                    'message' => 'Original was replaced, but the ResourceSpace read-back did not match the offered replacement file.'
                                );
                                break;
                            }

                            $fileChecksum = new FileChecksum();
                            $fileChecksum->setFileChecksum($md5);
                            $fileChecksum->setResourceId($id);
                            $entityManager->persist($fileChecksum);
                            $entityManager->flush();
                        }
                    } catch (\Throwable $e) {
                        $data = array(
                            'status' => false,
                            'message' => 'Error replacing original: ' . $e->getMessage()
                        );
                    } finally {
                        if (is_file($filePath)) {
                            @unlink($filePath);
                        }
                    }
                    break;
                }
            }
        }

        return $data;
    }

    public function getOriginalFileMd5($id, string $extension, bool $requireValidImage = false): ?string
    {
        $extension = strtolower(trim($extension));
        if ($extension === '') {
            return null;
        }

        $sourceUrl = $this->getResourceUrl($id, $extension);
        if (!is_string($sourceUrl) || trim($sourceUrl) === '') {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'rs_verify_');
        if ($temporaryPath === false) {
            return null;
        }

        try {
            if (!$this->downloadFileVerified($sourceUrl, $temporaryPath)) {
                return null;
            }
            if ($requireValidImage && @getimagesize($temporaryPath) === false) {
                return null;
            }

            $md5 = md5_file($temporaryPath);
            if (!is_string($md5) || !preg_match('/^[0-9A-Fa-f]{32}$/', $md5)) {
                return null;
            }

            return strtolower($md5);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function downloadFileVerified(string $sourceUrl, string $destinationPath): bool
    {
        $sourceFile = @fopen($sourceUrl, 'rb');
        if ($sourceFile === false) {
            return false;
        }

        $success = false;
        try {
            $expectedContentLength = $this->getExpectedContentLength($sourceFile);
            $writtenBytes = @file_put_contents($destinationPath, $sourceFile);
            clearstatcache(true, $destinationPath);
            $downloadedSize = is_file($destinationPath) ? @filesize($destinationPath) : false;

            $success = $writtenBytes !== false
                && $writtenBytes > 0
                && $downloadedSize !== false
                && $downloadedSize > 0
                && ($expectedContentLength === null
                    || ($writtenBytes === $expectedContentLength && $downloadedSize === $expectedContentLength));

            return $success;
        } finally {
            fclose($sourceFile);
            if (!$success && is_file($destinationPath)) {
                @unlink($destinationPath);
            }
        }
    }

    private function getExpectedContentLength($stream): ?int
    {
        if (!is_resource($stream)) {
            return null;
        }

        $metadata = stream_get_meta_data($stream);
        $headers = $metadata['wrapper_data'] ?? null;
        if (!is_array($headers)) {
            return null;
        }

        return $this->extractFinalContentLength($headers);
    }

    private function extractFinalContentLength(array $headers): ?int
    {
        $contentLength = null;
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }

            // Redirects add another HTTP response block. Reset the value so only the
            // Content-Length belonging to the final response is compared.
            if (preg_match('/^HTTP\/\S+\s+[0-9]{3}\b/i', $header) === 1) {
                $contentLength = null;
                continue;
            }

            if (preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', trim($header), $matches) === 1) {
                $parsedLength = filter_var(
                    $matches[1],
                    FILTER_VALIDATE_INT,
                    array('options' => array('min_range' => 0))
                );
                $contentLength = $parsedLength === false ? null : $parsedLength;
            }
        }

        return $contentLength;
    }

    public function getResourcePath($id, $type, $filePath, $extension = ''): mixed
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=' . $filePath . '&param3=' . $type . '&param5=' . $extension);
        return json_decode($data, true);
    }

    private function doApiCall($query): string|false
    {
        $query = 'user=' . str_replace(' ', '+', $this->apiUsername) . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        return file_get_contents($url);
    }

    private function getSign($query): string
    {
        return hash('sha256', $this->apiKey . $query);
    }
}

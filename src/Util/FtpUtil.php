<?php

namespace App\Util;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FtpUtil
{
    private bool $useSSL;
    private string $ftpUrl;
    private int $ftpPort;
    private array $ftpCredentials;

    public function __construct(ParameterBagInterface $params)
    {
        $ftpServer = $params->get('ftp_server');
        $this->useSSL = $ftpServer['use_ssl'];
        $this->ftpUrl = $ftpServer['url'];
        $this->ftpPort = array_key_exists('port', $ftpServer) ? intval($ftpServer['port']) : 22;
        $this->ftpCredentials = $ftpServer['credentials'];
    }

    public function uploadFile($collection, $localFilename, $remoteFilename): bool
    {
        echo 'Copy file ' . $localFilename . PHP_EOL;

        if (!is_file($localFilename) || !is_readable($localFilename)) {
            echo 'ERROR: Local file cannot be read: ' . $localFilename . PHP_EOL;
            return false;
        }

        $credentials = $this->getCredentials($collection);
        if ($credentials === null) {
            return false;
        }

        if ($this->useSSL) {
            return $this->uploadSftpFile($credentials, $localFilename, $remoteFilename);
        }

        $remoteUrl = $this->getFtpRemoteUrl($credentials, $remoteFilename);

        try {
            if (!@copy($localFilename, $remoteUrl)) {
                echo 'ERROR: Could not upload file to the FTP server: ' . $remoteFilename . PHP_EOL;
                return false;
            }
        } catch (\Throwable $e) {
            echo 'ERROR: Could not upload file to the FTP server: ' . $remoteFilename . ' (' . $e->getMessage() . ')' . PHP_EOL;
            return false;
        }

        return true;
    }

    private function getCredentials($collection): ?array
    {
        if (!array_key_exists($collection, $this->ftpCredentials)) {
            echo 'ERROR: Unknown FTP credentials for collection "' . $collection . '", please add them in config/connector.yml.' . PHP_EOL;
            return null;
        }

        $credentials = $this->ftpCredentials[$collection];
        foreach (array('username', 'password', 'remote_directory') as $credentialKey) {
            if (!array_key_exists($credentialKey, $credentials) || $credentials[$credentialKey] === '') {
                echo 'ERROR: No FTP ' . $credentialKey . ' specified for collection "' . $collection . '", please add it in config/connector.yml.' . PHP_EOL;
                return null;
            }
        }

        return $credentials;
    }

    private function getFtpRemoteUrl(array $credentials, string $remoteFilename): string
    {
        return 'ftp://'
            . rawurlencode($credentials['username']) . ':'
            . rawurlencode($credentials['password']) . '@'
            . $this->ftpUrl . $credentials['remote_directory'] . $remoteFilename;
    }

    private function uploadSftpFile(array $credentials, string $localFilename, string $remoteFilename): bool
    {
        $sourceFile = null;
        $destinationFile = null;
        $remotePath = $credentials['remote_directory'] . $remoteFilename;

        try {
            $connection = @ssh2_connect($this->ftpUrl, $this->ftpPort);
            if ($connection === false) {
                echo 'ERROR: Could not connect to the SFTP server.' . PHP_EOL;
                return false;
            }

            if (!@ssh2_auth_password($connection, $credentials['username'], $credentials['password'])) {
                echo 'ERROR: Unable to authenticate on the SFTP server.' . PHP_EOL;
                return false;
            }

            $sftp = @ssh2_sftp($connection);
            if ($sftp === false) {
                echo 'ERROR: Could not initialize the SFTP connection.' . PHP_EOL;
                return false;
            }

            $destinationFile = @fopen('ssh2.sftp://' . $sftp . $remotePath, 'w');
            if ($destinationFile === false) {
                echo 'ERROR: Could not open the destination file on the SFTP server: ' . $remoteFilename . PHP_EOL;
                return false;
            }

            $sourceFile = @fopen($localFilename, 'rb');
            if ($sourceFile === false) {
                echo 'ERROR: Could not open the local file for upload: ' . $localFilename . PHP_EOL;
                return false;
            }

            $expectedBytes = @filesize($localFilename);
            $writtenBytes = @stream_copy_to_stream($sourceFile, $destinationFile);
            if ($expectedBytes === false || $writtenBytes === false || $writtenBytes !== $expectedBytes) {
                echo 'ERROR: Incomplete SFTP upload for ' . $remoteFilename . '.' . PHP_EOL;
                return false;
            }

            if (!@fflush($destinationFile)) {
                echo 'ERROR: Could not finish the SFTP upload for ' . $remoteFilename . '.' . PHP_EOL;
                return false;
            }

            fclose($sourceFile);
            $sourceFile = null;
            if (!@fclose($destinationFile)) {
                $destinationFile = null;
                echo 'ERROR: Could not close the uploaded SFTP file: ' . $remoteFilename . PHP_EOL;
                return false;
            }
            $destinationFile = null;

            echo 'Written bytes: ' . $writtenBytes . PHP_EOL;
            return true;
        } catch (\Throwable $e) {
            echo 'ERROR: Could not upload file to the SFTP server: ' . $remoteFilename . ' (' . $e->getMessage() . ')' . PHP_EOL;
            return false;
        } finally {
            if (is_resource($sourceFile)) {
                fclose($sourceFile);
            }
            if (is_resource($destinationFile)) {
                fclose($destinationFile);
            }
        }
    }
}

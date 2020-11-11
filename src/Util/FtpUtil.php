<?php

namespace App\Util;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FtpUtil
{
    private $useSSL;
    private $ftpUrl;
    private $ftpPort;
    private $ftpCredentials;

    public function __construct(ParameterBagInterface $params)
    {
        $ftpServer = $params->get('ftp_server');
        $this->useSSL = $ftpServer['use_ssl'];
        $this->ftpUrl = $ftpServer['url'];
        $this->ftpPort = array_key_exists('port', $ftpServer) ? $ftpServer['port'] : 22;
        $this->ftpCredentials = $ftpServer['credentials'];
    }

    public function copyFile($collection, $fileURL)
    {
        echo 'Copy file ' . $fileURL . PHP_EOL;
        $filename = substr($fileURL, strrpos($fileURL, '/') + 1);
        if(!array_key_exists($collection, $this->ftpCredentials)) {
            echo 'ERROR: Unknown FTP credentials for collection "' . $collection . '", please add them in config/connector.yml.' . PHP_EOL;
        } else {
            $credentials = $this->ftpCredentials[$collection];
            $proceed = true;
            if(!array_key_exists('username', $credentials)) {
                echo 'ERROR: No FTP username specified for collection "' . $collection . '", please add it in config/connector.yml.' . PHP_EOL;
                $proceed = false;
            }
            if(!array_key_exists('password', $credentials)) {
                echo 'ERROR: No FTP password specified for collection "' . $collection . '", please add it in config/connector.yml.' . PHP_EOL;
                $proceed = false;
            }
            if(!array_key_exists('remote_directory', $credentials)) {
                echo 'ERROR: No FTP remote_directory specified for collection "' . $collection . '", please add it in config/connector.yml.' . PHP_EOL;
                $proceed = false;
            }
            if($proceed) {
                if ($this->useSSL) {
                    $resConnection = ssh2_connect($this->ftpUrl, $this->ftpPort);
                    if (ssh2_auth_password($resConnection, $credentials['username'], $credentials['password'])) {
                        $resSFTP = ssh2_sftp($resConnection);
                        $resFile = fopen('ssh2.sftp://' . $resSFTP . $credentials['remote_directory'] . $filename, 'w');
                        $srcFile = fopen($fileURL, 'r');
                        $writtenBytes = stream_copy_to_stream($srcFile, $resFile);
                        fclose($resFile);
                        fclose($srcFile);
                        echo 'Written bytes: ' . $writtenBytes . PHP_EOL;
                    } else {
                        echo 'Unable to authenticate on server.' . PHP_EOL;
                    }
                } else {
                    copy($fileURL, 'ftp://' . $credentials['username'] . ":" . $credentials['password'] . "@" . $this->ftpUrl . $credentials['remote_directory'] . $filename);
                }
            }
        }
    }
}

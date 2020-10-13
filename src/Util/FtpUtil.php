<?php

namespace App\Util;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FtpUtil
{
    private $ftpUrl;
    private $ftpPort;
    private $ftpUsername;
    private $ftpPassword;
    private $remoteDirectory;
    private $useSSL;

    public function __construct(ParameterBagInterface $params)
    {
        $ftpServer = $params->get('ftp_server');
        $this->ftpUrl = $ftpServer['url'];
        $this->ftpPort = array_key_exists('port', $ftpServer) ? $ftpServer['port'] : 22;
        $this->ftpUsername = $ftpServer['username'];
        $this->ftpPassword = $ftpServer['password'];
        $this->remoteDirectory = $ftpServer['remote_directory'];
        $this->useSSL = $ftpServer['use_ssl'];
    }

    public function copyFile($fileURL)
    {
        echo 'Copy file ' . $fileURL . PHP_EOL;
        $filename = substr($fileURL, strrpos($fileURL, '/') + 1);
        if($this->useSSL) {
            $resConnection = ssh2_connect($this->ftpUrl, $this->ftpPort);
            if(ssh2_auth_password($resConnection, $this->ftpUsername, $this->ftpPassword)){
                $resSFTP = ssh2_sftp($resConnection);
                $resFile = fopen('ssh2.sftp://' . $resSFTP . $this->remoteDirectory . $filename, 'w');
                $srcFile = fopen($fileURL, 'r');
                $writtenBytes = stream_copy_to_stream($srcFile, $resFile);
                fclose($resFile);
                fclose($srcFile);
                echo 'Written bytes: ' . $writtenBytes . PHP_EOL;
            } else {
                echo 'Unable to authenticate on server' . PHP_EOL;
            }
        } else {
            copy($fileURL, 'ftp://' . $this->ftpUsername . ":" . $this->ftpPassword . "@" . $this->ftpUrl . $this->remoteDirectory . $filename);
        }
    }
}

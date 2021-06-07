<?php


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\FileChecksumRepository")
 * @ORM\Table(name="file_checksums")
 */
class FileChecksum
{
    /**
     * @ORM\Id()
     * @ORM\Column(type="string", length=32)
     */
    private $fileChecksum;

    /**
     * @ORM\Column(type="integer")
     */
    private $resourceId;

    public function getFileChecksum()
    {
        return $this->fileChecksum;
    }

    public function setFileChecksum($fileChecksum)
    {
        $this->fileChecksum = $fileChecksum;
    }

    public function getResourceId()
    {
        return $this->resourceId;
    }

    public function setResourceId($resourceId)
    {
        $this->resourceId = $resourceId;
    }
}
<?php

namespace App\Entity;

use App\Repository\FileChecksumRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FileChecksumRepository::class)]
#[ORM\Table(name: 'file_checksums')]
class FileChecksum
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    private string $fileChecksum;

    #[ORM\Column(type: 'integer')]
    private int $resourceId;

    public function getFileChecksum(): string
    {
        return $this->fileChecksum;
    }

    public function setFileChecksum(string $fileChecksum): self
    {
        $this->fileChecksum = $fileChecksum;
        return $this;
    }

    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function setResourceId(int $resourceId): self
    {
        $this->resourceId = $resourceId;
        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\ExportRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExportRepository::class)]
#[ORM\Table(name: 'exports')]
class Export
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $publisher;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $jobId;

    #[ORM\Column(type: 'integer')]
    private int $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $downloadUrl = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $expires;

    public function getPublisher(): string
    {
        return $this->publisher;
    }

    public function setPublisher(string $publisher): self
    {
        $this->publisher = $publisher;
        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function setJobId(string $jobId): self
    {
        $this->jobId = $jobId;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): self
    {
        $this->downloadUrl = $downloadUrl;
        return $this;
    }

    public function getExpires(): DateTimeInterface
    {
        return $this->expires;
    }

    public function setExpires(DateTimeInterface $expires): self
    {
        $this->expires = $expires;
        return $this;
    }
}

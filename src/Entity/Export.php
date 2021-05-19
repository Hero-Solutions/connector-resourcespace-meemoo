<?php


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ExportRepository")
 * @ORM\Table(name="exports")
 */
class Export
{

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $publisher;

    /**
     * @ORM\Id()
     * @ORM\Column(type="string", length=255)
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $jobId;

    /**
     * @ORM\Column(type="integer")
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $downloadUrl;

    /**
     * @ORM\Column(type="datetime")
     */
    private $expires;

    public function getPublisher()
    {
        return $this->publisher;
    }

    public function setPublisher($publisher): void
    {
        $this->publisher = $publisher;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function getJobId()
    {
        return $this->jobId;
    }

    public function setJobId($jobId): void
    {
        $this->jobId = $jobId;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status): void
    {
        $this->status = $status;
    }

    public function getDownloadUrl()
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl($downloadUrl): void
    {
        $this->downloadUrl = $downloadUrl;
    }

    public function getExpires()
    {
        return $this->expires;
    }

    public function setExpires($expires): void
    {
        $this->expires = $expires;
    }
}
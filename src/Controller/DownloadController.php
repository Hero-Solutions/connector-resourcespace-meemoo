<?php

namespace App\Controller;

use App\Entity\Export;
use App\Util\RestApi;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DownloadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterBagInterface $params,
    ) {}

    #[Route('/download/{publisher}/{id}', name: 'download', methods: ['GET'])]
    public function download(string $publisher, string $id): Response
    {
        $downloadUrl = null;

        $exports = $this->em->createQueryBuilder()
            ->select('i')
            ->from(Export::class, 'i')
            ->where('i.id = :id')
            ->andWhere('i.status <> 2')
            ->setParameter('id', $id)
            ->getQuery()
            ->getResult();

        foreach ($exports as $export) {
            if ($export->getStatus() === 1) {
                if ($export->getExpires() > new DateTime()) {
                    $downloadUrl = $export->getDownloadUrl();
                } else {
                    $export->setStatus(2);
                    $this->em->flush();
                }
            } else {
                $restApi = new RestApi($this->params);
                $response = $restApi->checkExportJobStatus($publisher, $export->getJobId());

                if ($response['success'] === false) {
                    return new Response($response['message'], Response::HTTP_BAD_REQUEST);
                }

                if (!str_contains($response['message'], 'ExportJobId')) {
                    return new Response(
                        'Something went wrong. Please report the following error to the system administrator: '
                        . $response['message'],
                        Response::HTTP_INTERNAL_SERVER_ERROR
                    );
                }

                $job = json_decode($response['message']);
                $status = $job->Status ?? null;

                return match ($status) {
                    'Completed' => $this->handleCompletedJob($export, $job),
                    'Waiting'   => $this->refreshResponse(
                        'Download request of the original image is still pending, please wait...'
                    ),
                    'InProgress'=> $this->refreshResponse(
                        'Download request of the original image is still pending. Please wait, this may take several minutes.'
                    ),
                    default     => new Response(
                        'Something went wrong. Please report the following error to the system administrator: '
                        . $response['message'],
                        Response::HTTP_INTERNAL_SERVER_ERROR
                    ),
                };
            }
        }

        if ($downloadUrl !== null) {
            return $this->downloadReadyResponse($downloadUrl);
        }

        return $this->requestNewExport($publisher, $id);
    }

    private function handleCompletedJob(Export $export, object $job): Response
    {
        if (!property_exists($job, 'DownloadUrl')) {
            return new Response('Completed job has no download URL.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $export->setStatus(1);
        $export->setDownloadUrl($job->DownloadUrl);
        $export->setExpires(new DateTime($job->ExpiryDate));

        $this->em->flush();

        return $this->downloadReadyResponse($job->DownloadUrl);
    }

    private function requestNewExport(string $publisher, string $id): Response
    {
        $restApi = new RestApi($this->params);
        $response = $restApi->requestExportJob($publisher, $id);

        if ($response['success'] === false) {
            return new Response($response['message'], Response::HTTP_BAD_REQUEST);
        }

        if (!str_contains($response['message'], 'ExportJobId')) {
            return new Response(
                'Something went wrong. Please report the following error to the system administrator: '
                . $response['message'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $job = json_decode($response['message'])[0];
        $status = $job->Status ?? null;

        $export = new Export();
        $export->setPublisher($publisher);
        $export->setId($id);
        $export->setJobId($job->ExportJobId);
        $export->setStatus($status === 'Completed' ? 1 : 0);

        if ($status === 'Completed' && property_exists($job, 'DownloadUrl')) {
            $export->setDownloadUrl($job->DownloadUrl);
            $export->setExpires(new DateTime($job->ExpiryDate));
            $this->em->persist($export);
            $this->em->flush();

            return $this->downloadReadyResponse($job->DownloadUrl);
        }

        $this->em->persist($export);
        $this->em->flush();

        return $this->refreshResponse(
            'Download of the original image has been requested, this may take some time. Please wait...'
        );
    }

    private function refreshResponse(string $message): Response
    {
        return new Response(
            sprintf(
                '<html><head><meta http-equiv="refresh" content="10" /></head><body>%s</body></html>',
                htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            )
        );
    }

    private function downloadReadyResponse(string $downloadUrl): Response
    {
        $safeUrl = htmlspecialchars($downloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $downloadUrlJson = json_encode($downloadUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return new Response(
            sprintf(
                '<html><head><title>Image download ready</title></head><body>'
                . 'Image download ready. Your download should start automatically. '
                . '<a href="%s">Click here if it does not start.</a>'
                . '<script>window.location.href = %s;</script>'
                . '</body></html>',
                $safeUrl,
                $downloadUrlJson
            )
        );
    }
}

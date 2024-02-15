<?php


namespace App\Controller;

use App\Entity\Export;
use App\Util\RestApi;
use DateTime;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class DownloadController extends AbstractController
{
    /**
     * @Route("/download/{publisher}/{id}", name="download")
     */
    public function download(string $publisher, string $id)
    {
        $em = $this->container->get('doctrine')->getManager();
        $downloadUrl = null;
        $exports = $em->createQueryBuilder()
            ->select('i')
            ->from(Export::class, 'i')
            ->where('i.id = :id')
            ->andWhere('i.status <> 2')
            ->setParameter('id', $id)
            ->getQuery()
            ->getResult();
        foreach($exports as $export) {
            if($export->getStatus() == 1) {
                if($export->getExpires() > new DateTime()) {
                    $downloadUrl = $export->getDownloadUrl();
                } else {
                    $export->setStatus(2);
                    $em->persist($export);
                    $em->flush();
                }
            } else {
                $restApi = new RestApi($this->container->get('parameter_bag'));
                $response = $restApi->checkExportJobStatus($publisher, $export->getJobId());
                if($response['success'] === false) {
                    return new Response($response['message']);
                } else {
                    if(strpos($response['message'], 'ExportJobId') !== false) {
                        $resultJson = $response['message'];
                        $job = json_decode($resultJson);
                        $status = $job->Status;
                        if($status == 'Completed' && property_exists($job, 'DownloadUrl')) {
                            $downloadUrl = $job->DownloadUrl;
                            $expires = new DateTime($job->ExpiryDate);
                            $export->setStatus(1);
                            $export->setDownloadUrl($downloadUrl);
                            $export->setExpires($expires);
                            $em->persist($export);
                            $em->flush();
                            return $this->redirect($downloadUrl);
                        } else if($status === 'Waiting') {
                            return new Response('<html><head><meta http-equiv="refresh" content="10" /></head><body>Download request of the original image is still pending, please wait...</body>');
                        } else if($status === 'InProgress') {
                            return new Response('<html><head><meta http-equiv="refresh" content="10" /></head><body>Download request of the original image is still pending. Please wait, this may take several minutes.</body>');
                        } else {
                            return new Response('Something went wrong. Please report the following error to the system administrator: ' . $resultJson);
                        }
                    } else {
                        return new Response('Something went wrong. Please report the following error to the system administrator: ' . $response['message']);
                    }
                }
            }
        }
        if($downloadUrl != null) {
            return $this->redirect($downloadUrl);
        } else {
            $restApi = new RestApi($this->container->get('parameter_bag'));
            $response = $restApi->requestExportJob($publisher, $id);
            if($response['success'] === false) {
                return new Response($response['message']);
            } else {
                if(strpos($response['message'], 'ExportJobId') !== false) {
                    $resultJson = $response['message'];
                    $job = json_decode($resultJson)[0];
                    $jobId = $job->ExportJobId;
                    $status = $job->Status;
                    if($status === 'Completed' && property_exists($job, 'DownloadUrl')) {
                        $downloadUrl = $job->DownloadUrl;
                        $expires = new DateTime($job->ExpiryDate);
                        $export = new Export();
                        $export->setPublisher($publisher);
                        $export->setId($id);
                        $export->setJobId($jobId);
                        $export->setStatus(1);
                        $export->setDownloadUrl($downloadUrl);
                        $export->setExpires($expires);
                        $em->persist($export);
                        $em->flush();
                        return $this->redirect($downloadUrl);
                    } else if($status === 'Waiting') {
                        $export = new Export();
                        $export->setPublisher($publisher);
                        $export->setId($id);
                        $export->setJobId($jobId);
                        $export->setStatus(0);
                        $em->persist($export);
                        $em->flush();
                        return new Response('<html><head><meta http-equiv="refresh" content="10" /></head><body>Download of the original image has been requested, this may take some time. Please wait...</body>');
                    } else if($status === 'InProgress') {
                        return new Response('<html><head><meta http-equiv="refresh" content="10" /></head><body>Download request of the original image is still pending. Please wait, this may take several minutes.</body>');
                    } else {
                        return new Response('Something went wrong. Please report the following error to the system administrator: ' . $resultJson);
                    }
                } else {
                    return new Response('Something went wrong. Please report the following error to the system administrator: ' . $response['message']);
                }
            }
        }
    }
}

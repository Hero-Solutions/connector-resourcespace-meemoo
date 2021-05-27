<?php


namespace App\Controller;

use App\Entity\Export;
use App\Util\OaiPmhApiUtil;
use App\Util\RestApi;
use DateTime;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class DataController extends AbstractController
{
    /**
     * @Route("/data/{publisher}/{id}", name="data")
     */
    public function data(string $publisher, string $id)
    {
        $params = $this->container->get('parameter_bag');
        $overrideCertificateAuthorityFile = $params->get('override_certificate_authority');
        $sslCertificateAuthorityFile = $params->get('ssl_certificate_authority_file');
        $oaiPmhApi = $params->get('oai_pmh_api');

        try {
            $oaiPmhEndpoint = OaiPmhApiUtil::connect($oaiPmhApi, $publisher, $overrideCertificateAuthorityFile, $sslCertificateAuthorityFile);
            $record = $oaiPmhEndpoint->getRecord($id, $oaiPmhApi['metadata_prefix']);
            if($record == null) {
                return new Response('ERROR: no record data found. Please report this to the system administrator.');
            }
            $response = new Response($record->saveXML(), 200);
            $response->headers->set('Content-type', 'application/xml');
            $response->sendHeaders();
            return $response;
        }
        catch(OaipmhException $e) {
            return new Response('ERROR: no record data found. Please report this error to the system administrator: ' . $e);
        }
        catch(HttpException $e) {
            return new Response('ERROR: no record data found. Please report this error to the system administrator: ' . $e);
        }
        catch(Exception $e) {
            return new Response('ERROR: no record data found. Please report this error to the system administrator: ' . $e);
        }
        return new Response('ERROR: no record data found. Please report this error to the system administrator.');
    }
}

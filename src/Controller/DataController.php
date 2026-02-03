<?php

namespace App\Controller;

use App\Util\OaiPmhApiUtil;
use App\Util\RestApi;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class DataController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {}

    #[Route('/data/{publisher}/{id}', name: 'data', methods: ['GET'])]
    public function data(string $publisher, string $id): Response
    {
        $overrideCertificateAuthorityFile = $this->params->get('override_certificate_authority');
        $sslCertificateAuthorityFile      = $this->params->get('ssl_certificate_authority_file');
        $oaiPmhApi                        = $this->params->get('oai_pmh_api');

        $restApi = new RestApi($this->params);

        try {
            $oaiPmhEndpoint = OaiPmhApiUtil::connect(
                $restApi,
                $oaiPmhApi,
                $publisher,
                $overrideCertificateAuthorityFile,
                $sslCertificateAuthorityFile
            );

            $record = $oaiPmhEndpoint->getRecord(
                $id,
                $oaiPmhApi['metadata_prefix']
            );

            if ($record === null) {
                return new Response(
                    'ERROR: no record data found. Please report this to the system administrator.',
                    Response::HTTP_NOT_FOUND
                );
            }

            return new Response(
                $record->saveXML(),
                Response::HTTP_OK,
                ['Content-Type' => 'application/xml']
            );
        } catch (Exception $e) {
            return new Response(
                'ERROR: no record data found. Please report this error to the system administrator: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}

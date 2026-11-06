<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssEvidenceService;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Oss\OssThresholdService;
use MyInvoice\Service\Oss\OssXmlExporter;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OssReportAction
{
    public function __construct(
        private readonly OssLedgerService $oss,
        private readonly OssXmlExporter $xmlExporter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly OssThresholdService $threshold,
        private readonly OssEvidenceService $evidence,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $quarter = (int) ($q['quarter'] ?? (int) ceil(((int) date('n')) / 3));
        if ($year < 2020 || $year > 2050 || $quarter < 1 || $quarter > 4) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }
        if (!$this->oss->isEnabledFor($supplierId)) {
            return Json::error($response, 'oss_disabled',
                'OSS režim není v nastavení firmy aktivní.', 409);
        }

        try {
            return Json::ok($response, $this->oss->preview($supplierId, $year, $quarter));
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
    }

    /**
     * Čerpání prahu 10 000 EUR (§ 8 odst. 3 ZDPH) za kalendářní rok.
     *
     * ZÁMĚRNĚ bez guardu `oss_disabled`, na rozdíl od zbytku OSS. Práh je potřeba znát
     * PŘED registrací — právě nezaregistrovaný dodavatel se potřebuje dozvědět, že mu
     * povinnost vznikla. Vracet 409 by informaci upřelo přesně tomu, kdo ji potřebuje.
     */
    public function threshold(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $year = (int) ($request->getQueryParams()['year'] ?? date('Y'));
        if ($year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
        }

        try {
            return Json::ok($response, $this->threshold->progress($supplierId, $year));
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $quarter = (int) ($q['quarter'] ?? (int) ceil(((int) date('n')) / 3));
        if ($year < 2020 || $year > 2050 || $quarter < 1 || $quarter > 4) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }
        if (!$this->oss->isEnabledFor($supplierId)) {
            return Json::error($response, 'oss_disabled',
                'OSS režim není v nastavení firmy aktivní.', 409);
        }

        try {
            $result = $this->xmlExporter->build($supplierId, $year, $quarter);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $archived = $this->archiver->archive(
            $supplierId,
            'ossei1',
            $year,
            null,
            $quarter,
            $result['xml'],
            $result['summary'],
            $userId ?: null,
        );

        // Evidence § 110f ZDPH (čl. 63c nařízení 282/2011) z TÉHOŽ náhledu, ze kterého
        // vzniklo XML. Selhání evidence nesmí zablokovat stažení podání — lhůta k podání
        // je tvrdá, kdežto evidenci lze doplnit dalším stažením. Tiché to ale být nesmí:
        // chyba jde do activity logu vedle samotného podání.
        $evidenceCount = 0;
        $evidenceError = null;
        // Bez archivovaného podání (ukázka) není k čemu evidenci navázat.
        if ($archived['submission_id'] !== null) {
            try {
                $evidenceCount = $this->evidence->capture(
                    $supplierId,
                    (int) $archived['submission_id'],
                    $year,
                    $quarter,
                    $result['preview'],
                    $userId ?: null,
                );
            } catch (\Throwable $e) {
                $evidenceError = $e->getMessage();
            }
        }

        $this->logger->log('report.ossei1_downloaded', $userId, null, null, [
            'period' => sprintf('%04d-Q%d', $year, $quarter),
            'rows' => $result['summary']['rows_count'] ?? 0,
            'corrections' => $result['summary']['corrections_count'] ?? 0,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
            'evidence_records' => $evidenceCount,
            'evidence_error' => $evidenceError,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $filename = sprintf('ossei1-%04d-Q%d.xml', $year, $quarter);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}

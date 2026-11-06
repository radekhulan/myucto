<?php

declare(strict_types=1);

namespace MyInvoice\Action\Oss;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssEvidenceService;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Oss\OssReconciliationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Archiv OSS podání, rekonciliace a evidence § 110f ZDPH.
 *
 *   GET /api/reports/oss/submissions          → archiv OSS snapshotů (form_code=ossei1)
 *   GET /api/reports/oss/reconciliation       → archiv vs. dnešní náhled téhož období
 *   GET /api/reports/oss/evidence             → evidenční záznamy období (čl. 63c)
 *   GET /api/reports/oss/evidence/export      → tytéž záznamy jako CSV / JSON
 *
 * Detail snapshotu, stažení archivovaného XML, EPO předání a označení „podáno" tahle
 * třída ZÁMĚRNĚ nemá — to všechno už umí sdílený `/api/reports/submissions/{id}` a OSS
 * snapshoty tam leží v téže tabulce jako DPH a KH. Druhá sada endpointů nad týmž
 * záznamem by znamenala druhý životní cyklus, který by se s tím prvním rozešel.
 */
final class OssFilingArchiveAction
{
    /** Strop výpisu archivu — OSS se podává čtvrtletně, 200 řádků je 50 let. */
    private const ARCHIVE_LIMIT = 200;

    public function __construct(
        private readonly OssLedgerService $oss,
        private readonly OssReconciliationService $reconciliation,
        private readonly OssEvidenceService $evidence,
        private readonly TaxSubmissionRepository $submissions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function archive(Request $request, Response $response): Response
    {
        $guard = $this->guard($request, $response, 'reports');
        if ($guard instanceof Response) {
            return $guard;
        }

        return Json::ok($response, [
            'form_code'   => OssReconciliationService::FORM_CODE,
            'submissions' => $this->submissions->listForForm(
                $guard,
                OssReconciliationService::FORM_CODE,
                self::ARCHIVE_LIMIT,
            ),
        ]);
    }

    public function reconciliation(Request $request, Response $response): Response
    {
        $guard = $this->guard($request, $response, 'reports');
        if ($guard instanceof Response) {
            return $guard;
        }
        $period = self::period($request);
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }

        try {
            return Json::ok($response, $this->reconciliation->reconcile($guard, $period[0], $period[1]));
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
    }

    public function evidence(Request $request, Response $response): Response
    {
        $guard = $this->guard($request, $response, 'reports');
        if ($guard instanceof Response) {
            return $guard;
        }
        $period = self::period($request);
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }

        return Json::ok($response, $this->evidence->records($guard, $period[0], $period[1]));
    }

    /**
     * Poskytnutí evidence správci daně elektronicky (§ 110f odst. 2 písm. b), čl. 63c
     * odst. 3). CSV je výchozí formát, protože se otevře kdekoli; JSON drží strukturu
     * včetně vnořených úhrad a podkladu o místě plnění.
     *
     * Export se loguje: „komu a kdy jsme evidenci vydali" je při kontrole stejně
     * podstatné jako obsah.
     */
    public function evidenceExport(Request $request, Response $response): Response
    {
        $guard = $this->guard($request, $response, 'reports.export');
        if ($guard instanceof Response) {
            return $guard;
        }
        $period = self::period($request);
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/čtvrtletí.', 400);
        }
        [$year, $quarter] = $period;

        $data = $this->evidence->records($guard, $year, $quarter);
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'csv'));
        if (!in_array($format, ['csv', 'json'], true)) {
            return Json::error($response, 'validation_failed', 'Podporované formáty: csv, json.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('report.oss_evidence_exported', (int) ($user['id'] ?? 0), null, null, [
            'period'  => sprintf('%04d-Q%d', $year, $quarter),
            'format'  => $format,
            'records' => count($data['records']),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $filename = sprintf('oss-evidence-%04d-Q%d.%s', $year, $quarter, $format);
        if ($format === 'json') {
            $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $contentType = 'application/json; charset=utf-8';
        } else {
            $response->getBody()->write(self::csv($data));
            $contentType = 'text/csv; charset=utf-8';
        }

        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Společná brána: oprávnění, tenant a opt-in OSS. Vrací supplierId, nebo hotovou
     * chybovou odpověď — stejný tvar jako {@see \MyInvoice\Action\Report\OssReportAction}.
     *
     * @return int|Response
     */
    private function guard(Request $request, Response $response, string $permission): int|Response
    {
        if (!RequestAuthorization::allows($request, $permission, AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->oss->isEnabledFor($supplierId)) {
            return Json::error($response, 'oss_disabled', 'OSS režim není v nastavení firmy aktivní.', 409);
        }

        return $supplierId;
    }

    /** @return array{0:int,1:int}|null */
    private static function period(Request $request): ?array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $quarter = (int) ($q['quarter'] ?? (int) ceil(((int) date('n')) / 3));
        if ($year < 2020 || $year > 2050 || $quarter < 1 || $quarter > 4) {
            return null;
        }

        return [$year, $quarter];
    }

    /**
     * CSV přes sdílený {@see CsvWriter} — BOM pro Excel a hlavně OWASP guard proti
     * CSV injection. Evidence nese popisy položek a jména odběratelů, tedy text, který
     * do systému zadal někdo jiný; vlastní `fputcsv` by tenhle guard obešel.
     *
     * @param array{records:list<array<string,mixed>>} $data
     */
    private static function csv(array $data): string
    {
        $rows = OssEvidenceService::csvRows($data['records']);
        $header = array_shift($rows);

        return CsvWriter::build(
            $header ?? [],
            array_map(
                static fn (array $row): array => array_map(CsvWriter::safe(...), $row),
                $rows,
            ),
        );
    }
}

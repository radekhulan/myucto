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
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use MyInvoice\Service\Report\VatClassificationMapper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DPH přiznání DPHDP3 endpoints:
 *   GET /api/reports/dphdp3/preview?year=2026&month=5  — JSON summary (řádky + warnings)
 *   GET /api/reports/dphdp3?year=2026&month=5          — XML download
 *
 * Permissions: admin nebo accountant.
 *
 * ⚠️ Vygenerované XML je pomůcka. Před odesláním ověřit s účetní/poradcem.
 */
final class DphPriznaniAction
{
    public function __construct(
        private readonly DphPriznaniBuilder $builder,
        private readonly VatClassificationMapper $mapper,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
        private readonly \MyInvoice\Service\Currency\MissingExchangeRateFiller $rateFiller,
        private readonly \MyInvoice\Service\Report\VatCrossCheckService $crossCheck,
        private readonly \MyInvoice\Service\Report\VatPostFilingChangesService $postFilingChanges,
    ) {}

    /**
     * Povolené typy podání DPHDP3 (C7'): řádné, opravné (§138), dodatečné (§141),
     * dodatečné/opravné. Dodatečné (D/E) vyžaduje datum zjištění (d_zjist).
     */
    private const DPH_VARIANTS = ['radne', 'opravne', 'dodatecne', 'dodatecne_opravne'];

    /** Varianty, u kterých má smysl datum zjištění a důvody (§ 141 daňového řádu). */
    private const DPH_AMENDMENT_VARIANTS = ['dodatecne', 'dodatecne_opravne'];

    /**
     * GET /api/reports/dphdp3/settings → { vat_period, is_vat_payer }
     * Vrátí supplier nastavení potřebné pro UI (měsíční vs kvartální period picker).
     *
     * Volitelné ?year=&month= nebo ?year=&quarter= (EPIC VH-04): is_vat_payer /
     * is_identified se vrátí ke STAVU K POSLEDNÍMU DNI daného období výkazu
     * (supplier_vat_status_history přes VatStatusService::flagsAt — migrace 1181
     * historizuje i identifikovanou osobu), ať FE picker ukazuje relevanci výkazu
     * pro zvolené období, ne pro dnešek. Bez parametrů = dnešní živý stav (BC).
     */
    public function settings(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_period, is_vat_payer, is_identified, taxpayer_type, financial_office_code FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $isVatPayer = (bool) ($row['is_vat_payer'] ?? false);
        $q = $request->getQueryParams();
        if (isset($q['year']) && (isset($q['month']) || isset($q['quarter']))) {
            $year = (int) $q['year'];
            if ($year < 2020 || $year > 2050) {
                return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
            }
            if (isset($q['quarter'])) {
                $quarter = (int) $q['quarter'];
                if ($quarter < 1 || $quarter > 4) {
                    return Json::error($response, 'validation_failed', 'Neplatný kvartál.', 400);
                }
                $endMonth = $quarter * 3;
            } else {
                $endMonth = (int) $q['month'];
                if ($endMonth < 1 || $endMonth > 12) {
                    return Json::error($response, 'validation_failed', 'Neplatný měsíc.', 400);
                }
            }
            $statusDate = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
                ->modify('last day of this month')->format('Y-m-d');
            $flags = \MyInvoice\Service\Vat\VatStatusService::flagsAt($this->db->pdo(), $supplierId, $statusDate);
            $isVatPayer = $flags['is_vat_payer'];
            $row['is_identified'] = $flags['is_identified'] ? 1 : 0;
        }
        $isIdentified = !$isVatPayer && (bool) ($row['is_identified'] ?? false);
        return Json::ok($response, [
            // Identifikovaná osoba podává vždy měsíčně — UI nedostane kvartální volbu.
            'vat_period'            => $isIdentified ? 'monthly' : ($row['vat_period'] ?? null),
            'is_vat_payer'          => $isVatPayer,
            'is_identified'         => $isIdentified,
            'taxpayer_type'         => $row['taxpayer_type'] ?? null,
            'has_financial_office'  => !empty($row['financial_office_code']),
        ]);
    }

    /**
     * GET /api/reports/dphdp3/drafts-prediction?year=&month=&period= → predikce DPH
     * pro zvolené přiznací období (měsíc / kvartál). Returns:
     *   { year, month, period, vat_output, vat_input, tax_due,
     *     sale_count, sale_draft_count, purchase_count, purchase_draft_count }
     *
     * Pravidla (zařazení do období řeší VatLedgerService — viz tam):
     * - vystavené dle DUZP `COALESCE(tax_date, issue_date)`, přijaté dle pozdějšího
     *   z (DUZP, vystavení) `GREATEST(...)` — odpočet nelze uplatnit dřív, než plátce
     *   drží daňový doklad (§ 73 ZDPH). Drafty často DUZP nemají (`tax_date` NULL).
     * - sale (vydané): invoice_type IN (invoice, credit_note), status NOT IN
     *   (cancelled), tedy bere finalizované doklady i koncepty pro zvolené
     *   období.
     * - purchase (přijaté): status NOT IN (cancelled), bere obojí (doklady
     *   i koncepty).
     * - Multi-currency: total_vat × COALESCE(exchange_rate, 1) → CZK. Drafty
     *   bez nastaveného kurzu se počítají jako 1:1.
     *
     * Default year/month: aktuální datum. Default period: supplier.vat_period
     * (fallback 'monthly').
     */
    public function draftsPrediction(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $period = (string) ($q['period'] ?? '');
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $stmt = $pdo->prepare('SELECT vat_period FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $period = (string) ($stmt->fetchColumn() ?: 'monthly');
            if (!in_array($period, ['monthly', 'quarterly'], true)) $period = 'monthly';
        }

        // Predikce přes VatLedgerService (includeDrafts=true) — stejná logika jako
        // přiznání (klasifikace, CZK, RC samovyměření), jen vč. konceptů. Dříve tu bylo
        // vlastní inline SQL sčítající total_vat napřímo (bez RC samovyměření).
        $prediction = $this->mapper->predictDph($supplierId, $year, $month, $period);

        return Json::ok($response, array_merge(
            ['year' => $year, 'month' => $month, 'period' => $period],
            $prediction,
        ));
    }

    /**
     * GET /api/reports/dphdp3/trend?months=12 → list měsíčních souhrnů DPH
     * (output, input, due) pro graf.
     */
    public function trend(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $months = max(1, min(36, (int) ($request->getQueryParams()['months'] ?? 12)));
        return Json::ok($response, $this->mapper->monthlyDphTrend($supplierId, $months));
    }

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;

        $variant = (string) ($q['variant'] ?? 'radne');
        if (!in_array($variant, self::DPH_VARIANTS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný typ přiznání.', 400);
        }
        $dZjist = (string) ($q['d_zjist'] ?? '') ?: null;
        // Datum zjištění a důvody patří jen k dodatečnému přiznání (§ 141). U ostatních
        // variant se dřív tiše zahodily — kdo je pošle k opravnému, má se to dozvědět,
        // ne čekat, že se použijí. Stejné pravidlo drží kontrolní hlášení.
        if (($dZjist !== null || (string) ($q['reason'] ?? '') !== '')
            && !in_array($variant, self::DPH_AMENDMENT_VARIANTS, true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Datum zjištění a důvody lze uvést jen u dodatečného přiznání (§ 141 daňového řádu).',
                400,
            );
        }
        // Důvody pro dodatečné přiznání (§ 141 odst. 5) — textová příloha VetaR.
        $reason = (string) ($q['reason'] ?? '') ?: null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $reason);
        } catch (\Throwable $e) {
            return ReportBuildError::toJson($response, $e);
        }

        // Křížová kontrola DPHDP3↔KH↔SH↔343 (C8') — jen pro řádné/opravné (plné přiznání).
        // Dodatečné (D/E) vykazuje POUZE rozdíly, takže smír s plným KH/SH by vždy „nesouhlasil".
        $crossCheck = [];
        if ($variant === 'radne' || $variant === 'opravne') {
            try {
                $crossCheck = $this->crossCheck->check($supplierId, $year, $month, $period);
            } catch (\Throwable) {
                $crossCheck = [];
            }
        }

        // Fronta „doklady změněné po podání" — podklad pro rozhodnutí o dodatečném přiznání.
        $postFiling = ['has_filing' => false, 'snapshot_available' => false, 'submission' => null, 'documents' => []];
        try {
            $postFiling = $this->postFilingChanges->changes($supplierId, $year, $month, $period ?? 'monthly');
        } catch (\Throwable) {
            // fail-open — fronta je informativní, její selhání nesmí shodit preview
        }

        return Json::ok($response, [
            'summary'            => $result['summary'],
            'warnings'           => $result['warnings'],
            'cross_check'        => $crossCheck,
            'post_filing_changes' => $postFiling,
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;

        $variant = (string) ($q['variant'] ?? 'radne');
        if (!in_array($variant, self::DPH_VARIANTS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný typ přiznání.', 400);
        }
        $dZjist = (string) ($q['d_zjist'] ?? '') ?: null;
        // Datum zjištění a důvody patří jen k dodatečnému přiznání (§ 141). U ostatních
        // variant se dřív tiše zahodily — kdo je pošle k opravnému, má se to dozvědět,
        // ne čekat, že se použijí. Stejné pravidlo drží kontrolní hlášení.
        if (($dZjist !== null || (string) ($q['reason'] ?? '') !== '')
            && !in_array($variant, self::DPH_AMENDMENT_VARIANTS, true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Datum zjištění a důvody lze uvést jen u dodatečného přiznání (§ 141 daňového řádu).',
                400,
            );
        }
        // Důvody pro dodatečné přiznání (§ 141 odst. 5) — textová příloha VetaR.
        $reason = (string) ($q['reason'] ?? '') ?: null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $reason);
            // #238: doplň chybějící kurzy z ČNB a přebuildi. Fill JE zápis → v souladu
            // s B8/HIGH#1 (readonly download nemutuje) jen s WRITE oprávněním; READ-only
            // nebo když ČNB kurz nezná → tvrdá chyba 422.
            if (!empty($result['missing_rates'])) {
                if (RequestAuthorization::allowsReadSideEffects($request, 'reports.export')) {
                    $this->rateFiller->fill($supplierId, $result['missing_rates']);
                    $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $reason);
                }
                if (!empty($result['missing_rates'])) {
                    $labels = \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($result['missing_rates']);
                    return Json::error($response, 'exchange_rate_missing',
                        'Nelze vytvořit XML: ČNB nemá kurz pro doklady ' . implode(', ', $labels)
                        . '. Doplňte kurz ručně u faktury a zkuste znovu.', 422);
                }
            }
        } catch (\Throwable $e) {
            return ReportBuildError::toJson($response, $e);
        }
        $forma = (string) ($result['summary']['dapdph_forma'] ?? 'B');
        $isAmendment = (bool) ($result['summary']['is_amendment'] ?? false);

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Brána křížové kontroly (C8'): nenulový tvrdý rozdíl vs KH/SH/343 → 409 s daty,
        // ať FE ukáže tabulku a nabídne „Přesto stáhnout". S ?acknowledge_mismatch=1 projde
        // (vědomá volba účetní — kontrola má false-positive hrany, tvrdý blok těsně před
        // lhůtou by byl horší než falešný poplach), a fakt, že o rozdílu věděla, se zaloguje.
        // Smír je čistě čtení — jeho selhání NESMÍ blokovat podání (fail-open).
        // Dodatečné (D/E) vykazuje jen rozdíly → plný smír s KH/SH/343 se NEaplikuje.
        $acknowledged = in_array((string) ($q['acknowledge_mismatch'] ?? ''), ['1', 'true'], true);
        if (!$isAmendment) {
            try {
                $crossCheck = $this->crossCheck->check($supplierId, $year, $month, $period);
            } catch (\Throwable) {
                $crossCheck = [];
            }
            $hasBlocking = $this->crossCheck->hasBlockingMismatch($crossCheck);
            if ($hasBlocking && !$acknowledged) {
                return Json::error(
                    $response,
                    'vat_cross_check_mismatch',
                    'Přiznání se rozchází s kontrolním/souhrnným hlášením nebo obratem účtu 343. '
                        . 'Zkontroluj rozdíly, nebo stáhni znovu s potvrzením (acknowledge_mismatch=1).',
                    409,
                    ['cross_check' => $crossCheck],
                );
            }
            if ($hasBlocking && $acknowledged) {
                $this->logger->log('report.dphdp3_mismatch_acknowledged', $userId, null, null, [
                    'period'      => sprintf('%04d-%02d', $year, $month),
                    'cross_check' => $crossCheck,
                ], $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Archivovat + XSD validation
        $isQuarterly = ($result['summary']['period_type'] ?? 'monthly') === 'quarterly';
        $archived = $this->archiver->archive(
            $supplierId, 'dphdp3', $year,
            $isQuarterly ? null : $month,
            $isQuarterly ? (int) ceil($month / 3) : null,
            $result['xml'], $result['summary'], $userId ?: null,
            // readonly GET/download nesmí mutovat účetní stav (posun zámku) — dorevize B8, HIGH#1.
            RequestAuthorization::allowsReadSideEffects($request, 'reports.export'),
            $forma,
        );

        $this->logger->log('report.dphdp3_downloaded', $userId, null, null, [
            'period'            => sprintf('%04d-%02d', $year, $month),
            'period_type'       => $result['summary']['period_type'] ?? 'monthly',
            'variant'           => $variant,
            'dapdph_forma'      => $forma,
            'submission_id'     => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => 'dphdp3',
            'form_variant' => $forma,
            'period_year' => $year,
            'period_month' => $isQuarterly ? null : $month,
            'period_quarter' => $isQuarterly ? (int) ceil($month / 3) : null,
        ], 'xml');
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /api/reports/dphdp3/post-filing-changes?year=&month=&period= → fronta „doklady
     * změněné po podání" (C7'): doklady spadající do období posledního archivovaného přiznání,
     * které byly změněny/přibyly až po jeho vygenerování (kandidáti na dodatečné přiznání).
     */
    public function postFilingChanges(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : 'monthly';
        try {
            $result = $this->postFilingChanges->changes($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return ReportBuildError::toJson($response, $e);
        }
        return Json::ok($response, $result);
    }
}

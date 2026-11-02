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
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kontrolní hlášení DPHKH1 endpoints:
 *   GET /api/reports/dphkh1/preview?year=2026&month=5[&period=quarterly] → JSON summary
 *   GET /api/reports/dphkh1?year=2026&month=5[&period=quarterly]         → XML download
 *
 * PO = vždy měsíční (§ 101e odst. 1); FO = může být kvartální (§ 101e odst. 2).
 */
final class KontrolniHlaseniAction
{
    public function __construct(
        private readonly KontrolniHlaseniBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
        private readonly \MyInvoice\Service\Currency\MissingExchangeRateFiller $rateFiller,
        // Rozdíly proti podanému KH — podklad pro rozhodnutí o opravném hlášení.
        private readonly \MyInvoice\Service\Report\KhEvidenceService $evidence,
        private readonly \MyInvoice\Service\Report\VatCrossCheckService $crossCheck,
    ) {}

    /**
     * Povolené typy KH (C7'): řádné, řádné/opravné (§101f/1), následné (§101f/2),
     * následné/opravné. N/E přijímají volitelně datum zjištění a č.j. výzvy správce daně.
     */
    private const KH_VARIANTS = [
        'radne', 'opravne', 'nasledne', 'nasledne_opravne',
        // Rychlá odpověď na výzvu (§ 101g): nulové KH / potvrzení správnosti posledního KH.
        // Obě vyžadují č.j. výzvy a generují hlášení bez oddílů A/B/C.
        'vyzva_nulove', 'vyzva_potvrzeni',
    ];

    /** Varianty, které smí (a musí) nést č.j. výzvy správce daně. */
    private const KH_VYZVA_VARIANTS = ['nasledne', 'nasledne_opravne', 'vyzva_nulove', 'vyzva_potvrzeni'];

    /**
     * Varianty, u kterých má smysl datum zjištění (§ 101f odst. 2) — jen následné hlášení.
     * Rychlá odpověď na výzvu ho nemá kam dát (builder ji staví bez sekcí).
     */
    private const KH_D_ZJIST_VARIANTS = ['nasledne', 'nasledne_opravne'];

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        [$variant, $dZjist, $cJedVyzvy, $err] = $this->parseVariant($request);
        if ($err !== null) {
            return Json::error($response, 'validation_failed', $err, 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $cJedVyzvy);
        } catch (\Throwable $e) {
            return ReportBuildError::toJson($response, $e);
        }
        // Rozdíly proti podanému KH. Rychlá odpověď na výzvu oddíly nemá, není co porovnat.
        // Fail-open: je to podklad k rozhodnutí, ne součást výkazu, a jeho selhání nesmí
        // shodit náhled.
        $postFiling = ['has_filing' => false, 'snapshot_available' => false, 'submission' => null, 'documents' => []];
        if (!in_array($variant, ['vyzva_nulove', 'vyzva_potvrzeni'], true)) {
            try {
                $postFiling = $this->evidence->postFilingChanges($supplierId, $year, $month, $period ?? 'monthly', (string) $result['xml']);
            } catch (\Throwable) {
            }
        }

        return Json::ok($response, [
            'summary'  => $result['summary'],
            'warnings' => $result['warnings'],
            'cross_check' => $this->approvalCheck($supplierId, $year, $month, $period, $variant),
            'post_filing_changes' => $postFiling,
        ]);
    }

    /**
     * Přijaté doklady ve schvalování (týž nález jako v náhledu přiznání DPH). Rychlá
     * odpověď na výzvu oddíly A/B nemá, tam kontrola nedává smysl. Fail-open: čtecí
     * kontrola nesmí shodit náhled ani podání.
     *
     * @return list<array<string,mixed>>
     */
    private function approvalCheck(int $supplierId, int $year, int $month, string $period, string $variant): array
    {
        if (in_array($variant, ['vyzva_nulove', 'vyzva_potvrzeni'], true)) {
            return [];
        }
        try {
            return $this->crossCheck->pendingApprovalCheck($supplierId, $year, $month, $period);
        } catch (\Throwable) {
            return [];
        }
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        [$variant, $dZjist, $cJedVyzvy, $err] = $this->parseVariant($request);
        if ($err !== null) {
            return Json::error($response, 'validation_failed', $err, 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $cJedVyzvy);
            // #238: doplň chybějící kurzy z ČNB a přebuildi. Fill JE zápis → v souladu
            // s B8/HIGH#1 (readonly download nemutuje) jen s WRITE oprávněním; READ-only
            // nebo když ČNB kurz nezná → tvrdá chyba 422.
            if (!empty($result['missing_rates'])) {
                if (RequestAuthorization::allowsReadSideEffects($request, 'reports.export')) {
                    $this->rateFiller->fill($supplierId, $result['missing_rates']);
                    $result = $this->builder->build($supplierId, $year, $month, $period, $variant, $dZjist, $cJedVyzvy);
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
        $forma = (string) ($result['summary']['khdph_forma'] ?? 'B');
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Stejná brána jako u přiznání DPH: blokující nález (samovyměření ve schvalování)
        // → 409 s daty, s ?acknowledge_mismatch=1 projde a vědomé potvrzení se zaloguje.
        $crossCheck = $this->approvalCheck($supplierId, $year, $month, $period, $variant);
        if ($this->crossCheck->hasBlockingMismatch($crossCheck)) {
            $acknowledged = in_array((string) ($request->getQueryParams()['acknowledge_mismatch'] ?? ''), ['1', 'true'], true);
            if (!$acknowledged) {
                return Json::error(
                    $response,
                    'vat_cross_check_mismatch',
                    'V kontrolním hlášení chybí přijaté doklady se samovyměřením, které čekají na schválení. '
                        . 'Zkontroluj je, nebo stáhni znovu s potvrzením (acknowledge_mismatch=1).',
                    409,
                    ['cross_check' => $crossCheck],
                );
            }
            $this->logger->log('report.dphkh1_mismatch_acknowledged', $userId, null, null, [
                'period'      => sprintf('%04d-%02d', $year, $month),
                'cross_check' => $crossCheck,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        $isQuarterly = $period === 'quarterly';
        $quarter = $isQuarterly ? (int) ceil($month / 3) : null;
        $archived = $this->archiver->archive(
            $supplierId, 'dphkh1', $year, $isQuarterly ? null : $month, $quarter,
            $result['xml'], $result['summary'], $userId ?: null,
            // readonly GET/download nesmí mutovat účetní stav (posun zámku) — dorevize B8, HIGH#1.
            RequestAuthorization::allowsReadSideEffects($request, 'reports.export'),
            $forma,
        );
        $periodLabel = $isQuarterly ? sprintf('%04d-Q%d', $year, $quarter) : sprintf('%04d-%02d', $year, $month);
        $this->logger->log('report.dphkh1_downloaded', $userId, null, null, [
            'period' => $periodLabel,
            'variant' => $variant,
            'khdph_forma' => $forma,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => 'dphkh1',
            'form_variant' => $forma,
            'period_year' => $year,
            'period_month' => $isQuarterly ? null : $month,
            'period_quarter' => $quarter,
        ], 'xml');
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Typ podání KH + parametry následného (d_zjist, c_jed_vyzvy). c_jed_vyzvy dává smysl
     * jen u následného (N/E) — u řádného/opravného ho odmítneme.
     *
     * @return array{0:string, 1:?string, 2:?string, 3:?string} [variant, d_zjist, c_jed_vyzvy, error]
     */
    private function parseVariant(Request $request): array
    {
        $q = $request->getQueryParams();
        $variant = (string) ($q['variant'] ?? 'radne');
        if (!in_array($variant, self::KH_VARIANTS, true)) {
            return ['radne', null, null, 'Neplatný typ kontrolního hlášení.'];
        }
        $dZjist    = (string) ($q['d_zjist'] ?? '') ?: null;
        $cJedVyzvy = (string) ($q['c_jed_vyzvy'] ?? '') ?: null;
        $acceptsVyzva = in_array($variant, self::KH_VYZVA_VARIANTS, true);
        if (!$acceptsVyzva && $cJedVyzvy !== null) {
            return [$variant, null, null, 'Č.j. výzvy lze uvést jen u následného kontrolního hlášení '
                . 'nebo u rychlé odpovědi na výzvu.'];
        }
        // Datum zjištění se u ostatních variant dřív tiše zahazovalo, zatímco č.j. výzvy
        // vracelo chybu — nad stejnou třídou vstupu dvojí chování. Kdo ho pošle omylem
        // k opravnému hlášení, má vědět, že se nepoužije.
        if ($dZjist !== null && !in_array($variant, self::KH_D_ZJIST_VARIANTS, true)) {
            return [$variant, null, null, 'Datum zjištění lze uvést jen u následného kontrolního '
                . 'hlášení (§ 101f odst. 2).'];
        }
        return [$variant, $dZjist, $cJedVyzvy, null];
    }

    /**
     * @return array{0:int|null, 1:int|null, 2:string}
     */
    private function parsePeriod(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        $period = $q['period'] ?? 'monthly';
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            return [null, null, 'monthly'];
        }
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return [null, null, 'monthly'];
        }
        return [$year, $month, $period];
    }
}

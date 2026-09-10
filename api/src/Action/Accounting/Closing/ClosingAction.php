<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\FindingRemedyService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Export\CsvWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Uzávěrkový průvodce období (Epic F4) — REST API nad {@see ClosingService}.
 *
 *   GET  /api/accounting/periods/{id}/closing                          — stav průvodce (kroky + flagy)
 *   POST /api/accounting/periods/{id}/closing/start                    — open→closing — účetní|admin
 *   POST /api/accounting/periods/{id}/closing/abort                    — closing→open — účetní|admin
 *   GET  /api/accounting/periods/{id}/closing/fx-preview               — náhled kurzových rozdílů (bez zápisu)
 *   POST /api/accounting/periods/{id}/closing/steps/{step}/run         — provedení kroku 1–5 — účetní|admin
 *   POST /api/accounting/periods/{id}/closing/steps/{step}/revert      — revert kroku (R12) — admin
 *   POST /api/accounting/periods/{id}/closing/entries                  — asistovaný zápis (dohady/čas. rozlišení) — účetní|admin
 *   POST /api/accounting/periods/{id}/closing/entries/{entryId}/reverse — storno asistovaného zápisu — účetní|admin
 *   POST /api/accounting/periods/{id}/close                            — uzavření knih (krok 6) — admin
 *   POST /api/accounting/periods/{id}/open-next                        — otevření nového roku (krok 7) — admin
 *   GET  /api/accounting/periods/{id}/monthly-check                    — měsíční kontrola (D8), kdykoli, bez uzávěrky
 *
 * Byznys pravidla (stavový automat R2, gating kroků, row_version CAS R4, zámky)
 * vynucuje ClosingService; workflow auditní eventy (accounting.closing_started,
 * books_closed, closing_step_reverted s dumpem…) loguje TATO akce z návratových
 * hodnot služby (PostingService loguje accounting.posted/reversed sám). Chyby:
 * ClosingException → její errorCode/httpStatus (409 version_conflict, 422
 * validace), PostingException → mapPostingError, ostatní → log + neutrální 500.
 */
final class ClosingAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const RUNNABLE_STEPS  = ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock'];
    private const REVERTIBLE_STEPS = ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock', 'close_books', 'open_next'];
    private const ASSIST_STEPS = ['estimates', 'deferrals'];
    private const ASSIST_RULE_KEYS = [
        'estimates' => ['estimate.asset', 'estimate.liability'],
        'deferrals' => ['accrual.prepaid.expense', 'accrual.accrued.expense', 'accrual.deferred.revenue', 'accrual.accrued.revenue', 'accrual.small_asset.defer'],
    ];

    public function __construct(
        private readonly ClosingService $closing,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly PaymentMatchAuditChecker $paymentAudit,
        private readonly FindingRemedyService $remedies,
    ) {}

    public function state(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->state($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Stav uzávěrky se nepodařilo načíst');
        }

        return Json::ok($response, $data);
    }

    public function start(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $rowVersion = $this->rowVersion($request, $response, $err);
        if ($rowVersion === null) return $err;

        try {
            $data = $this->closing->start($supplierId, $periodId, $rowVersion, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Zahájení uzávěrky selhalo');
        }

        // EP-4: auditní událost (accounting.closing_started) loguje ClosingService UVNITŘ
        // téže transakce jako přechod stavu — atomicky (selhání auditu → rollback mutace).
        // Nelogovat znovu tady, jinak by vznikla duplicitní událost mimo transakci.
        return Json::ok($response, $data);
    }

    public function abort(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $rowVersion = $this->rowVersion($request, $response, $err);
        if ($rowVersion === null) return $err;

        try {
            $data = $this->closing->abort($supplierId, $periodId, $rowVersion, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Přerušení uzávěrky selhalo');
        }

        // EP-4: audit (accounting.closing_aborted) v transakci služby — viz start().
        return Json::ok($response, $data);
    }

    /**
     * Náhled kurzových rozdílů k rozvahovému dni (R10) — bez zápisu. Volitelný
     * query param bank_rows (JSON pole {account_code, currency_code, foreign_balance});
     * návrhy devizových zůstatků z bankovních výpisů vrací vždy v `proposals`.
     */
    public function fxPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        $rawParam = trim((string) ($request->getQueryParams()['bank_rows'] ?? ''));
        $raw = [];
        if ($rawParam !== '') {
            $raw = json_decode($rawParam, true);
            if (!is_array($raw)) {
                return Json::error($response, 'validation_failed', 'bank_rows musí být validní JSON pole.', 422);
            }
        }
        $bankRows = $this->parseBankRows($raw, $response, $err);
        if ($bankRows === null) return $err;

        try {
            $data = $this->closing->fxPreview($supplierId, $periodId, $bankRows);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled kurzových rozdílů selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Měsíční kontrola (audit 2026-07, D8) — reuse ClosingService::buildChecks
     * nad libovolným rozsahem uvnitř období (měsíc/kvartál/vlastní od-do),
     * KDYKOLI během roku, bez zahájení uzávěrky. Read-only, žádné row_version.
     *
     *   GET /api/accounting/periods/{id}/monthly-check?date_from=&date_to=
     */
    public function monthlyCheck(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $q = $request->getQueryParams();
        $from = $this->nullableString($q['date_from'] ?? null);
        $to = $this->nullableString($q['date_to'] ?? null);

        try {
            $data = $this->closing->monthlyCheck($supplierId, $periodId, $from, $to);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Měsíční kontrola se nezdařila');
        }

        return Json::ok($response, $data);
    }

    /**
     * Detail JEDNÉ kontroly načtený ŽIVĚ — to, co se ukáže v popupu.
     *   GET /api/accounting/periods/{id}/checks/{key}?date_from=&date_to=
     *
     * Popup se dřív plnil z payloadu kroku `precheck`. Ten je ale auditní snímek useknutý
     * na deset položek, takže kontrola hlásila „21 nálezů" a vypsala 10 — a po opravě
     * dokladu ukazovala nálezy, které už byly vyřešené. Detail proto sahá pro data znovu:
     * počet i řádky pocházejí z jednoho běhu nad aktuálním stavem účetnictví.
     */
    public function checkFindings(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $periodId = (int) ($args['id'] ?? 0);
        $key = trim((string) ($args['key'] ?? ''));
        $q = $request->getQueryParams();

        try {
            $check = $this->closing->checkFindings(
                $supplierId,
                $periodId,
                $key,
                $this->nullableString($q['date_from'] ?? null),
                $this->nullableString($q['date_to'] ?? null),
            );
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Načtení nálezů kontroly se nezdařilo');
        }

        return Json::ok($response, $check);
    }

    /**
     * Návrh doúčtování k JEDNOMU nálezu kontroly spárovaných plateb.
     *   GET /api/accounting/periods/{id}/finding-remedy?doc_type=&doc_id=&issue=
     *
     * Nález se schválně počítá ZNOVU na serveru, místo aby klient poslal částku
     * z tabulky. Návrh účetního zápisu je operace s následky — kdyby jeho výši určoval
     * požadavek, stačilo by ji podvrhnout a nechat si zápis odklepnout. Vedlejší efekt
     * je užitečný: když už nález mezitím někdo vyřešil, vrátí se 404 místo návrhu na
     * doúčtování něčeho, co je hotové.
     *
     * Odpověď nese `proposal` = null u nálezů, kde jednoznačné řešení z dat neplyne
     * (reálný přeplatek, nesouhlasící měna). Tlačítko pak otevře prázdný zápis s
     * kontextem — návrh, který systém neumí spočítat, se nevymýšlí.
     */
    public function findingRemedy(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $q = $request->getQueryParams();
        $docType = (string) ($q['doc_type'] ?? '');
        $docId = (int) ($q['doc_id'] ?? 0);
        $issue = (string) ($q['issue'] ?? '');
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true) || $docId <= 0 || $issue === '') {
            return Json::error($response, 'validation_failed', 'Chybí doc_type / doc_id / issue.', 422);
        }

        try {
            $period = $this->closing->periodOrFail($supplierId, (int) ($args['id'] ?? 0));
            $found = null;
            foreach ($this->paymentAudit->audit($supplierId, (string) $period['starts_on'], (string) $period['ends_on']) as $item) {
                if ($item['match_kind'] === $docType
                    && $item['doc_id'] === $docId
                    && in_array($issue, $item['issues'], true)
                ) {
                    $found = $item;
                    break;
                }
            }
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Návrh doúčtování se nezdařil');
        }

        if ($found === null) {
            return Json::error($response, 'not_found', 'Nález už neexistuje — pravděpodobně byl mezitím vyřešen.', 404);
        }

        $detail = is_array($found['detail'][$issue] ?? null) ? $found['detail'][$issue] : [];

        return Json::ok($response, [
            'issue'      => $issue,
            'doc_type'   => $docType,
            'doc_id'     => $docId,
            'doc_no'     => $found['doc_no'],
            'partner_name' => $found['partner_name'],
            'entry_date' => substr((string) $found['tx_posted_at'], 0, 10),
            'impact_czk' => $found['impact_czk'],
            'detail'     => $detail,
            'proposal'   => $this->remedies->propose($supplierId, $docType, $docId, $issue, $detail),
        ]);
    }

    /**
     * Export nálezů JEDNÉ kontroly do CSV — bez stropu.
     *   GET /api/accounting/periods/{id}/checks/{key}/export?date_from=&date_to=
     *
     * Náhled na stránce nese jen prvních {@see CheckFindingNormalizer::CAP} nálezů, aby
     * se u velké firmy neposílaly megabajty. Kdyby ale i export nesl jen tu useknutou
     * padesátku, byla by to past: uživatel by si stáhl „seznam" a pracoval s ním jako
     * s úplným. Proto tenhle endpoint staví kontroly znovu s `cap = 0`.
     */
    public function exportCheckFindings(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $periodId = (int) ($args['id'] ?? 0);
        $key = trim((string) ($args['key'] ?? ''));
        $q = $request->getQueryParams();

        try {
            $checks = $this->closing->checkFindingsForExport(
                $supplierId,
                $periodId,
                $this->nullableString($q['date_from'] ?? null),
                $this->nullableString($q['date_to'] ?? null),
            );
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Export nálezů se nezdařil');
        }

        $found = null;
        foreach ($checks as $c) {
            if (($c['key'] ?? '') === $key) {
                $found = $c;
                break;
            }
        }
        if ($found === null) {
            return Json::error($response, 'not_found', 'Kontrola „' . $key . '" neexistuje.', 404);
        }

        $csv = self::findingsToCsv($found);
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $key . '-' . $periodId . '.csv"');
    }

    /**
     * Nálezy do CSV. Oddělovač `;` a BOM kvůli Excelu v českém prostředí — bez nich
     * Excel rozhodí diakritiku i sloupce a export by byl k ničemu.
     *
     * @param array<string,mixed> $check
     */
    private static function findingsToCsv(array $check): string
    {
        $value = is_array($check['value'] ?? null) ? $check['value'] : [];
        $findings = is_array($value['findings'] ?? null) ? $value['findings'] : [];

        $cols = ($value['kind'] ?? '') === 'account'
            ? ['account_code', 'name', 'amount', 'note']
            : ['doc_type', 'doc_id', 'doc_no', 'doc_date', 'partner_name', 'amount', 'currency', 'entry_id', 'note'];

        $rows = [];
        foreach ($findings as $f) {
            $row = [];
            foreach ($cols as $c) {
                // `issues` je pole kódů — do CSV se slepí, protože tabulka v Excelu
                // překlad nemá. V UI se překládá, tady jde o strojově čitelný výstup.
                $v = $c === 'note' && !empty($f['issues']) && is_array($f['issues'])
                    ? implode(', ', array_map('strval', $f['issues']))
                    : ($f[$c] ?? '');
                $row[] = $c === 'amount' && is_numeric($v) ? (string) $v : CsvWriter::safe($v);
            }
            $rows[] = $row;
        }

        return CsvWriter::build($cols, $rows);
    }

    /**
     * Náhled OP k pohledávkám k rozvahovému dni (D9) — aging 311 + návrh §8a/§8c.
     *   GET /api/accounting/periods/{id}/closing/provisions-preview
     */
    public function provisionsPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $query = $request->getQueryParams();
            $data = $this->closing->provisionsPreview($supplierId, $periodId, (int) ($query['page'] ?? 1), (int) ($query['per_page'] ?? 100));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled opravných položek selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Náhled časového rozlišení drobného majetku (§DM / Task 11) — návrh 381/501 dle
     * režimu (none/pro_rata/flat_pct). Volitelné query mode + pct přepnou režim náhledu
     * (bez uložení politiky); bez nich se použije politika firmy.
     *   GET /api/accounting/periods/{id}/closing/small-asset-accrual-preview
     */
    public function smallAssetAccrualPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $q = $request->getQueryParams();
        $mode = $this->nullableString($q['mode'] ?? null);
        $pct = isset($q['pct']) && is_numeric($q['pct']) ? (float) $q['pct'] : null;
        // Limit z formuláře, ještě NEULOŽENÝ. Bez něj náhled bral limit jen z uložené
        // politiky, takže hlášku „paušál vyžaduje zdokumentovaný limit" nešlo odklikat —
        // uživatel číslo vyplnil, náhled ho ignoroval a dál tvrdil, že chybí.
        $limit = isset($q['materiality_limit']) && is_numeric($q['materiality_limit'])
            ? (float) $q['materiality_limit'] : null;

        try {
            $data = $this->closing->smallAssetAccrualPreview($supplierId, $periodId, $mode, $pct, $limit);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled časového rozlišení drobného majetku selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Náhled časového rozlišení nákladů příštích období (§DČR / Task 12) — návrh 381/5xx
     * z řádků přijatých faktur označených obdobím od–do (accrual_from/accrual_to). Částky jsou
     * dané (pro-rata dle dnů), účetní je jen potvrzuje v kroku deferrals.
     *   GET /api/accounting/periods/{id}/closing/prepaid-expense-accrual-preview
     */
    public function prepaidExpenseAccrualPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->prepaidExpenseAccrualPreview($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled časového rozlišení nákladů příštích období selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Heuristický návrh dohadných položek pasivních (389) — opakující se náklad, jehož
     * faktura za poslední měsíc roku k rozvahovému dni nedorazila. Read-only, žádný zápis.
     *   GET /api/accounting/periods/{id}/closing/estimates-suggest
     */
    public function estimatesSuggest(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->estimatesSuggest($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Návrh dohadných položek selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Náhled podkladu splatné daně z příjmů (D11) — částka z DPPO + zůstatky 341/591.
     *   GET /api/accounting/periods/{id}/closing/income-tax-preview
     */
    public function incomeTaxPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->incomeTaxPreview($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled splatné daně selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Náhled odložené daně (ČÚS 003) — přechodné rozdíly, sazba a zůstatky 481/592.
     *   GET /api/accounting/periods/{id}/closing/deferred-tax-preview
     *
     * Ruční tituly (opravné položky, rezervy nad rámec ZoR) jdou v query jako
     * `manual[popis]=částka` — systém je z dat spolehlivě neodliší, proto se zadávají.
     */
    public function deferredTaxPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $manual = self::manualTitles($request->getQueryParams()['manual'] ?? null);

        try {
            $data = $this->closing->deferredTaxPreview($supplierId, $periodId, $manual);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled odložené daně selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Ruční tituly přechodných rozdílů: popis => částka. Nečíselné a nulové hodnoty
     * se zahazují, ať se do výpočtu nedostane tichá nula tvářící se jako titul.
     *
     * @return array<string,float>
     */
    private static function manualTitles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $label => $value) {
            $label = trim((string) $label);
            if ($label === '' || !is_numeric($value)) {
                continue;
            }
            $amount = round((float) $value, 2);
            if ($amount != 0.0) {
                $out[mb_substr($label, 0, 190)] = $amount;
            }
        }

        return $out;
    }

    /**
     * Náhled rozdělení VH (D10) — disponibilní zůstatek 431 + cílové otevřené období.
     *   GET /api/accounting/periods/{id}/profit-distribution/preview
     */
    public function profitDistributionPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->profitDistributionPreview($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled rozdělení výsledku hospodaření selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Zaúčtování rozdělení VH (D10) do otevřeného období — účetní|admin.
     *   POST /api/accounting/periods/{id}/profit-distribution
     */
    public function profitDistribution(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $data = $this->closing->runProfitDistribution($supplierId, $periodId, $body, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Zaúčtování rozdělení výsledku hospodaření selhalo');
        }

        // EP-4: audit (accounting.profit_distributed) v transakci služby — viz start().
        return Json::ok($response, $data);
    }

    /**
     * Revert rozdělení VH (D10) — hard delete zápisu, admin-only.
     *   POST /api/accounting/periods/{id}/profit-distribution/revert
     */
    public function profitDistributionRevert(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $targetRowVersion = (int) ($body['target_row_version'] ?? 0);
        if ($targetRowVersion < 1) {
            return Json::error($response, 'validation_failed', 'target_row_version je povinný (celé číslo ≥ 1).', 422);
        }

        try {
            $data = $this->closing->revertProfitDistribution($supplierId, $periodId, $targetRowVersion, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Revert rozdělení výsledku hospodaření selhal');
        }

        // EP-4: audit (accounting.profit_distribution_reverted) v transakci služby — viz start().
        return Json::ok($response, $data);
    }

    /**
     * Náhled inventarizace rozvahových účtů (EP-6, §29–30 ZoÚ) — soupis účtů s účetním
     * a uloženým skutečným stavem, rozdílem a stavem vyřešení + hlavička.
     *   GET /api/accounting/periods/{id}/closing/inventory
     */
    public function inventory(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->inventoryPreview($supplierId, $periodId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Náhled inventarizace selhal');
        }

        return Json::ok($response, $data);
    }

    /**
     * Uložení inventarizace rozvahových účtů (EP-6) — skutečný stav / rozdíly /
     * odpovědná osoba / datum / protokol. Dokončení (complete=true) je povolené jen
     * bez nevyřešených rozdílů; jinak zůstává rozpracovaná a blokuje uzavření knih.
     *   POST /api/accounting/periods/{id}/closing/inventory  — účetní|admin
     */
    public function saveInventory(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = $this->rowVersion($request, $response, $err, $body);
        if ($rowVersion === null) return $err;

        $header = [
            'responsible_person' => $this->nullableString($body['responsible_person'] ?? null),
            'inventory_date'     => $this->nullableString($body['inventory_date'] ?? null),
            'protocol_ref'       => $this->nullableString($body['protocol_ref'] ?? null),
            'note'               => $this->nullableString($body['note'] ?? null),
            'complete'           => (bool) ($body['complete'] ?? false),
        ];

        $itemsByAccount = [];
        foreach ((array) ($body['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $accountId = (int) ($it['account_id'] ?? 0);
            if ($accountId <= 0) continue;
            $counted = $it['counted_balance'] ?? null;
            if ($counted !== null && $counted !== '' && !is_numeric($counted)) {
                return Json::error($response, 'validation_failed', "items[{$accountId}].counted_balance musí být číslo nebo prázdné.", 422);
            }
            $itemsByAccount[$accountId] = [
                'counted_balance' => ($counted === null || $counted === '') ? null : (float) $counted,
                'resolution'      => (($it['resolution'] ?? '') === 'resolved') ? 'resolved' : 'open',
                'note'            => $this->nullableString($it['note'] ?? null),
            ];
        }

        try {
            $data = $this->closing->saveInventory($supplierId, $periodId, $rowVersion, $header, $itemsByAccount, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Uložení inventarizace selhalo');
        }

        // EP-4: audit (accounting.inventory_saved) v transakci služby — viz start().
        return Json::ok($response, $data);
    }

    public function runStep(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $step = (string) ($args['step'] ?? '');

        if (!in_array($step, self::RUNNABLE_STEPS, true)) {
            return Json::error($response, 'validation_failed',
                "Krok '{$step}' nelze spustit tímto endpointem — close_books = POST /close, open_next = POST /open-next.", 422);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = $this->rowVersion($request, $response, $err, $body);
        if ($rowVersion === null) return $err;
        $meta = $this->auditMeta($request);

        try {
            if ($step === 'precheck') {
                $data = $this->closing->runPrecheck($supplierId, $periodId, $rowVersion, $meta);
            } elseif ($step === 'fx_revaluation') {
                $bankRows = $this->parseBankRows($body['bank_rows'] ?? [], $response, $err);
                if ($bankRows === null) return $err;
                $data = $this->closing->runFxRevaluation($supplierId, $periodId, $bankRows, $rowVersion, $meta);
            } elseif ($step === 'stock') {
                // SKLAD §3.4 (způsob B) — konečný stav 112/501+132/504, manko 549,
                // přebytek 648; otevření roku (501/112) se poste v open_next.
                $data = $this->closing->runStockValuation($supplierId, $periodId, $rowVersion, $meta);
            } elseif ($step === 'provisions') {
                // D9 — OP k pohledávkám (558/559 → 391). skip = confirmStep, jinak deklarativní
                // zaúčtování z účetní-potvrzeného seznamu items.
                $status = trim((string) ($body['status'] ?? ''));
                if ($status === 'skipped') {
                    $data = $this->closing->confirmStep($supplierId, $periodId, 'provisions', 'skipped', $this->nullableString($body['note'] ?? null), $rowVersion, $meta);
                } else {
                    $items = is_array($body['items'] ?? null) ? array_values($body['items']) : [];
                    $data = $this->closing->runProvisions($supplierId, $periodId, $items, $rowVersion, $meta, ($body['partial'] ?? false) === true);
                }
            } elseif ($step === 'income_tax') {
                // D11 — předpis splatné daně (591/341). skip = confirmStep, jinak zaúčtování částky.
                $status = trim((string) ($body['status'] ?? ''));
                if ($status === 'skipped') {
                    $data = $this->closing->confirmStep($supplierId, $periodId, 'income_tax', 'skipped', $this->nullableString($body['note'] ?? null), $rowVersion, $meta);
                } elseif (!is_numeric($body['amount'] ?? null)) {
                    return Json::error($response, 'validation_failed', 'amount (splatná daň) musí být číslo.', 422);
                } else {
                    // EP-16: volitelný důvod odchylky ruční částky proti návrhu DPPO se
                    // eviduje (payload kroku + audit); ruční částka se neblokuje.
                    $reason = $this->nullableString($body['reason'] ?? ($body['note'] ?? null));
                    if ($reason !== null && mb_strlen($reason) > 500) {
                        return Json::error($response, 'validation_failed', 'reason může mít nejvýše 500 znaků.', 422);
                    }
                    $data = $this->closing->runIncomeTax($supplierId, $periodId, (float) $body['amount'], $rowVersion, $meta, $reason);
                }
            } elseif ($step === 'deferred_tax') {
                // ČÚS 003 — odložená daň (592/481 závazek, 481/592 pohledávka).
                $status = trim((string) ($body['status'] ?? ''));
                if ($status === 'skipped') {
                    $data = $this->closing->confirmStep($supplierId, $periodId, 'deferred_tax', 'skipped', $this->nullableString($body['note'] ?? null), $rowVersion, $meta);
                } elseif (!is_numeric($body['amount'] ?? null)) {
                    return Json::error($response, 'validation_failed', 'amount (odložená daň) musí být číslo; záporná = pohledávka.', 422);
                } else {
                    $reason = $this->nullableString($body['reason'] ?? ($body['note'] ?? null));
                    if ($reason !== null && mb_strlen($reason) > 500) {
                        return Json::error($response, 'validation_failed', 'reason může mít nejvýše 500 znaků.', 422);
                    }
                    // § 59 odst. 4: pohledávku nelze zaúčtovat bez posouzení, jestli bude
                    // dosaženo základu daně, o který ji lze uplatnit. Potvrzuje účetní.
                    $data = $this->closing->runDeferredTax(
                        $supplierId,
                        $periodId,
                        (float) $body['amount'],
                        $rowVersion,
                        $meta,
                        $reason,
                        !empty($body['prudence_confirmed']),
                        self::manualTitles($body['manual'] ?? null),
                    );
                }
            } elseif ($step === 'deferrals' && is_array($body['small_asset_accrual'] ?? null)) {
                // §DM / Task 11 — zaúčtování časového rozlišení drobného majetku (381/501)
                // v rámci kroku deferrals. Režim (none/pro_rata/flat_pct) + volitelné pct.
                $sa = $body['small_asset_accrual'];
                $mode = trim((string) ($sa['mode'] ?? ''));
                $pct = isset($sa['pct']) && $sa['pct'] !== null && $sa['pct'] !== '' ? (float) $sa['pct'] : null;
                // EP-15: limit významnosti pro paušál (test přiměřenosti); jen u flat_pct.
                $materialityLimit = isset($sa['materiality_limit']) && $sa['materiality_limit'] !== null && $sa['materiality_limit'] !== ''
                    ? (float) $sa['materiality_limit'] : null;
                $data = $this->closing->runSmallAssetAccrual($supplierId, $periodId, $mode, $pct, $rowVersion, $meta, $materialityLimit);
            } elseif ($step === 'deferrals' && array_key_exists('prepaid_expense_accrual', $body)) {
                // §DČR / Task 12 — zaúčtování časového rozlišení nákladů příštích období (381/5xx)
                // v rámci kroku deferrals. Bez parametrů — částky jsou dané z označených faktur.
                $data = $this->closing->runPrepaidExpenseAccrual($supplierId, $periodId, $rowVersion, $meta);
            } else {
                $status = trim((string) ($body['status'] ?? ''));
                if (!in_array($status, ['done', 'skipped'], true)) {
                    return Json::error($response, 'validation_failed', "status musí být 'done' nebo 'skipped'.", 422);
                }
                $note = $this->nullableString($body['note'] ?? null);
                if ($note !== null && mb_strlen($note) > 500) {
                    return Json::error($response, 'validation_failed', 'note může mít nejvýše 500 znaků.', 422);
                }
                $data = $this->closing->confirmStep($supplierId, $periodId, $step, $status, $note, $rowVersion, $meta);
            }
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, "Krok uzávěrky '{$step}' selhal");
        }

        // EP-4: auditní událost (accounting.fx_revalued / accounting.closing_step_done) loguje
        // příslušná metoda ClosingService UVNITŘ transakce kroku — atomicky s účetní mutací
        // (selhání auditu → rollback). Nelogovat znovu tady (duplicita + mimo transakci).
        return Json::ok($response, $data);
    }

    /**
     * Zaúčtování odpisů majetku v rámci kroku 2 průvodce (audit 2026-07 B10). Na rozdíl
     * od DepreciationAction::bookYear (běžné účtování jen do 'open') umí přes ClosingService
     * zaúčtovat i do období ve stavu 'closing' (R7 — flag nastaví výhradně ClosingService).
     * Fiskální rok se bere z období, ne z requestu (nelze účtovat cizí rok do tohoto období).
     */
    public function bookDepreciation(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->closing->bookDepreciation($supplierId, $periodId, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Zaúčtování odpisů v uzávěrce selhalo');
        }

        $this->logEvent($request, 'accounting.closing_step_done', $periodId, [
            'step'   => 'depreciation',
            'booked' => (int) ($data['booked'] ?? 0),
        ]);
        return Json::ok($response, $data);
    }

    public function revertStep(Request $request, Response $response, array $args): Response
    {
        // Revert maže závěrkové zápisy hard delete (R12) — uzávěrkové právo (periods.close).
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $step = (string) ($args['step'] ?? '');

        if (!in_array($step, self::REVERTIBLE_STEPS, true)) {
            return Json::error($response, 'validation_failed', "Neznámý krok uzávěrky '{$step}'.", 422);
        }
        $rowVersion = $this->rowVersion($request, $response, $err);
        if ($rowVersion === null) return $err;

        try {
            $data = $this->closing->revertStep($supplierId, $periodId, $step, $rowVersion, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, "Revert kroku uzávěrky '{$step}' selhal");
        }

        // EP-4: dump mazaných zápisů (accounting.closing_step_reverted, průkaznost §35 ZoÚ / R12)
        // loguje ClosingService::revertStep UVNITŘ téže transakce jako hard delete — atomicky.
        return Json::ok($response, $data);
    }

    /** Asistovaný zápis dohadných položek / časového rozlišení (R22). */
    public function createEntry(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $rowVersion = $this->rowVersion($request, $response, $err, $body);
        if ($rowVersion === null) return $err;

        $step = trim((string) ($body['step'] ?? ''));
        if (!in_array($step, self::ASSIST_STEPS, true)) {
            return Json::error($response, 'validation_failed', "step musí být 'estimates' nebo 'deferrals'.", 422);
        }
        $ruleKey = trim((string) ($body['rule_key'] ?? ''));
        if (!in_array($ruleKey, self::ASSIST_RULE_KEYS[$step], true)) {
            return Json::error($response, 'validation_failed',
                "rule_key pro krok '{$step}' musí být jeden z: " . implode(', ', self::ASSIST_RULE_KEYS[$step]) . '.', 422);
        }
        $amount = (float) ($body['amount'] ?? 0);
        if (!is_numeric($body['amount'] ?? null) || $amount <= 0) {
            return Json::error($response, 'validation_failed', 'amount musí být kladné číslo.', 422);
        }
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '') {
            return Json::error($response, 'validation_failed', 'description je povinný.', 422);
        }
        $counterAccount = $this->nullableString($body['counter_account'] ?? null);

        try {
            $data = $this->closing->createAssistedEntry($supplierId, $periodId, $step, [
                'rule_key'        => $ruleKey,
                'amount'          => round($amount, 2),
                'description'     => $description,
                'counter_account' => $counterAccount,
                'row_version'     => $rowVersion,
            ], $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Asistovaný závěrkový zápis selhal');
        }

        return Json::ok($response, $data, 201);
    }

    public function reverseEntry(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $entryId = (int) ($args['entryId'] ?? 0);
        $rowVersion = $this->rowVersion($request, $response, $err);
        if ($rowVersion === null) return $err;

        try {
            $data = $this->closing->reverseAssistedEntry($supplierId, $periodId, $entryId, $rowVersion, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Storno asistovaného zápisu selhalo');
        }

        return Json::ok($response, $data);
    }

    /** Krok 6 — uzavření účetních knih (ČÚS 002, R8): zápis closing + status closed. */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = $this->rowVersion($request, $response, $err, $body);
        if ($rowVersion === null) return $err;

        // EP-10b: override nezaúčtovaných dokladů vyžaduje konkrétní oprávnění + doložený důvod.
        $overrideUnposted = filter_var($body['override_unposted'] ?? false, FILTER_VALIDATE_BOOL);
        $overrideReason = $this->nullableString($body['override_reason'] ?? null);
        if ($overrideUnposted) {
            if (!$this->requirePermission($request, $response, 'accounting.periods.close_override', AccessLevel::WRITE, $err)) return $err;
            if ($overrideReason === null || mb_strlen($overrideReason) < 3) {
                return Json::error($response, 'validation_failed', 'override_reason (důvod override) je povinný a musí mít alespoň 3 znaky.', 422);
            }
            if (mb_strlen($overrideReason) > 500) {
                return Json::error($response, 'validation_failed', 'override_reason může mít nejvýše 500 znaků.', 422);
            }
        }

        try {
            $data = $this->closing->closeBooks($supplierId, $periodId, $rowVersion, $this->auditMeta($request), $overrideUnposted, $overrideReason);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Uzavření účetních knih selhalo');
        }

        // EP-4: audit (accounting.books_closed) v transakci ClosingService::closeBooks — viz start().
        return Json::ok($response, $data);
    }

    /**
     * Krok 7 — otevření nového roku přes 701 (+ FX storno dle R11). Má-li další rok už
     * převzaté počáteční stavy, krok je při shodě převezme; při rozdílu je nahradí
     * vypočtenými jen na výslovnou žádost `replace_taken_over_opening` s důvodem.
     */
    public function openNext(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireClose($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = $this->rowVersion($request, $response, $err, $body);
        if ($rowVersion === null) return $err;

        $replaceReason = null;
        if (filter_var($body['replace_taken_over_opening'] ?? false, FILTER_VALIDATE_BOOL)) {
            $replaceReason = $this->nullableString($body['replace_reason'] ?? null);
            if ($replaceReason === null || mb_strlen($replaceReason) < 3) {
                return Json::error($response, 'validation_failed', 'replace_reason (důvod náhrady převzatých počátečních stavů) je povinný a musí mít alespoň 3 znaky.', 422);
            }
            if (mb_strlen($replaceReason) > 500) {
                return Json::error($response, 'validation_failed', 'replace_reason může mít nejvýše 500 znaků.', 422);
            }
        }

        try {
            $data = $this->closing->openNext($supplierId, $periodId, $rowVersion, $this->auditMeta($request), $replaceReason);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Otevření nového období selhalo');
        }

        // EP-4: audit (accounting.books_opened) v transakci ClosingService::openNext — viz start().
        return Json::ok($response, $data);
    }

    /**
     * row_version z body (R4 — povinné u každé mutace období/kroku).
     * NULL = chyba (do $err naplněna 422 response).
     */
    private function rowVersion(Request $request, Response $response, ?Response &$err, ?array $body = null): ?int
    {
        $body ??= (array) ($request->getParsedBody() ?? []);
        $v = $body['row_version'] ?? null;
        if (!is_numeric($v) || (int) $v < 1) {
            $err = Json::error($response, 'validation_failed', 'row_version je povinný (celé číslo ≥ 1).', 422);
            return null;
        }
        $err = null;
        return (int) $v;
    }

    /**
     * Validace bank řádků FX kroku (R10b): {account_code, currency_code, foreign_balance}.
     * NULL = chyba (do $err naplněna 422 response).
     *
     * @return list<array{account_code:string, currency_code:string, foreign_balance:float}>|null
     */
    private function parseBankRows(mixed $raw, Response $response, ?Response &$err): ?array
    {
        $err = null;
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            $err = Json::error($response, 'validation_failed', 'bank_rows musí být pole řádků.', 422);
            return null;
        }
        $rows = [];
        $seenCodes = [];
        foreach ($raw as $i => $r) {
            if (!is_array($r)) {
                $err = Json::error($response, 'validation_failed', "bank_rows[{$i}] má neplatný formát.", 422);
                return null;
            }
            $code = trim((string) ($r['account_code'] ?? ''));
            $currency = strtoupper(trim((string) ($r['currency_code'] ?? '')));
            $balance = $r['foreign_balance'] ?? null;
            if ($code === '' || !preg_match('/^[0-9]{3,10}$/', $code)) {
                $err = Json::error($response, 'validation_failed', "bank_rows[{$i}].account_code musí být kód účtu osnovy.", 422);
                return null;
            }
            if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency === 'CZK') {
                $err = Json::error($response, 'validation_failed', "bank_rows[{$i}].currency_code musí být cizí měna (ISO 4217).", 422);
                return null;
            }
            if (!is_numeric($balance)) {
                $err = Json::error($response, 'validation_failed', "bank_rows[{$i}].foreign_balance musí být číslo.", 422);
                return null;
            }
            // R10b — rychlé odmítnutí duplicitního účtu (skutečná pojistka je ve
            // FxRevaluationService): dvakrát zadaný účet by přecenil týž Kč zůstatek
            // dvakrát. Klíč je samotný account_code, nezávisle na měně.
            if (isset($seenCodes[$code])) {
                $err = Json::error($response, 'validation_failed', "bank_rows[{$i}].account_code '{$code}' je uveden vícekrát; každý účet smí být jen jednou.", 422);
                return null;
            }
            $seenCodes[$code] = true;
            $rows[] = [
                'account_code'    => $code,
                'currency_code'   => $currency,
                'foreign_balance' => round((float) $balance, 2),
            ];
        }
        return $rows;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function logEvent(Request $request, string $action, int $periodId, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'accounting_period', $periodId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }

    private function mapError(Response $response, \Throwable $e, string $logPrefix): Response
    {
        if ($e instanceof ClosingException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($e instanceof UnbalancedEntryException || $e instanceof PostingException) {
            return $this->mapPostingError($response, $e);
        }
        $this->log->error($logPrefix . ': ' . $e->getMessage(), ['exception' => $e]);
        return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Orchestrace přiznání k dani z příjmů (Epic DP): načtení podkladů, výpočet řádků,
 * perzistence ručních vstupů (draft/final s CAS), export + archivace validovaného XML.
 * Čisté výpočty deleguje na typové kalkulátory ({@see DppoReturnCalculator} pro PO,
 * {@see DpfoReturnCalculator} pro FO).
 *
 * Druh přiznání (`$variant`, DP v2 fáze 2): řádné / opravné (před lhůtou) / dodatečné
 * (§141 DŘ, po lhůtě). Opravné = plná náhrada řádného. Dodatečné se podává rozdílově
 * vůči poslední známé dani (derivace z předchozího finalizovaného řádného/opravného
 * přiznání, {@see TaxReturnRepository::findLastKnownTax}) + datum zjištění + důvody.
 */
final class TaxReturnService
{
    public function __construct(
        private readonly TaxReturnRepository $returns,
        private readonly TaxConstantsRepository $constants,
        private readonly DppoReturnDataProvider $dppoData,
        private readonly DppoReturnCalculator $dppoCalc,
        private readonly DppoXmlBuilder $dppoXml,
        private readonly DpfoReturnDataProvider $dpfoData,
        private readonly DpfoReturnCalculator $dpfoCalc,
        private readonly DpfoXmlBuilder $dpfoXml,
        private readonly DpfoEpoBusinessValidator $dpfoBusinessValidator,
        private readonly XmlSchemaValidator $xmlValidator,
        private readonly InsuranceSummaryService $insurance,
        private readonly \MyInvoice\Service\Pdf\InsuranceSummaryPdfRenderer $insurancePdf,
        private readonly CsszPrehledXmlBuilder $csszXml,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly TaxLossService $losses,
        private readonly PreFinalizeCheckService $preFinalizeChecks,
        private readonly TaxAdvanceScheduleService $advanceSchedules,
        private readonly \MyInvoice\Service\Pdf\HealthInsuranceOverviewPdfRenderer $healthOverviewPdf,
        private readonly Connection $db,
        // Epic DP — příloha účetní závěrky (VetaUA/UB/UD/UZ) do DPPO XML; jen 'po'.
        private readonly FinancialStatementService $statements,
        private readonly EntityCategoryService $categories,
        private readonly AccountingSupplierSettingsRepository $supplierSettings,
        // Příloha v účetní závěrce (§39 vyhl. 500/2002) jako SKUTEČNĚ připojený soubor
        // (Prilohy/PredepsanaPriloha kod=PP_OPISPUV), ne jen strukturovaná data VetaUA/UB/UD/UZ
        // — viz buildStatementNotesAttachment(). NEŘEŠÍ sama o sobě EPO chybu 2602, viz tam.
        private readonly \MyInvoice\Service\Accounting\Reports\StatementNotesService $statementNotes,
        private readonly \MyInvoice\Service\Pdf\StatementNotesPdfRenderer $statementNotesPdf,
        // Přehledy podle § 18 odst. 2 ZoÚ (peněžní toky / změny vlastního kapitálu) do
        // přílohy DPPO (PredepsanaPriloha kod=PP_PTOK/PP_ZVKAP) — STEJNÉ služby jako
        // uzávěrkový balíček ({@see \MyInvoice\Service\Export\ClosingPackageService}),
        // žádný druhý zdroj dat. Viz buildCashFlowAttachment()/buildEquityChangesAttachment().
        private readonly \MyInvoice\Service\Accounting\Reports\CashFlowStatementService $cashFlowStatement,
        private readonly \MyInvoice\Service\Pdf\CashFlowPdfRenderer $cashFlowPdf,
        private readonly \MyInvoice\Service\Accounting\Reports\EquityChangesStatementService $equityChangesStatement,
        private readonly \MyInvoice\Service\Pdf\EquityChangesPdfRenderer $equityChangesPdf,
        // Zastoupení daňovým poradcem (§29/2 DŘ) do dan_por/pln_moc — viz representationMeta().
        private readonly TaxRepresentationService $representation,
    ) {}

    private const TYPES = ['fo', 'po'];
    private const VARIANTS = ['radne', 'opravne', 'dodatecne'];

    /** Druh přiznání → hodnota atributu XML formuláře (PO dapdpp_forma / FO dap_typ). */
    private const FORMA = ['radne' => 'B', 'opravne' => 'O', 'dodatecne' => 'D'];

    /**
     * Kompletní stav přiznání pro FE: uložené vstupy + vypočtené řádky + podklady + warnings.
     * Idempotentně založí prázdný draft, pokud ještě neexistuje (aby vstupy měly kam persistovat).
     *
     * @return array<string,mixed>
     */
    public function getReturn(int $supplierId, int $year, string $type, ?int $userId, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        if ($row === null) {
            $row = $this->returns->create($supplierId, $year, $type, [], $userId, $variant, $seq);
        }

        // Párování dle účtu FÚ AUTOMATICKY při náhledu řádného přiznání: reálně zaplacené
        // zálohy §38a (dle předčíslí účtu FÚ + VS = DIČ) se chytnou bez ručního „Spárovat
        // platby". Jen JISTÉ (exact) shody se zapíšou do zaplacených záloh a předvyplní do
        // prázdného pole; nejisté/doplatky zůstanou jako návrh k potvrzení. Best-effort —
        // nikdy nesmí shodit náhled; po prefillu se řádek načte znovu, aby ho výpočet viděl.
        if ($row['status'] !== 'final' && $variant === 'radne') {
            $this->autoMatchAdvancesQuietly($supplierId, $year, $type);
            $row = $this->returns->find($supplierId, $year, $type, $variant, $seq) ?? $row;
        }

        $stored = is_array($row['computed'] ?? null) ? (array) $row['computed'] : [];
        $computation = $row['status'] === 'final' && $type === 'fo' && isset($stored['computed'])
            ? [
                'result' => (array) $stored['computed'],
                'podklady' => (array) ($stored['podklady'] ?? []),
                'warnings' => (array) ($stored['warnings'] ?? []),
            ]
            : $this->compute($supplierId, $year, $type, (array) $row['inputs'], $variant);

        // Předfinalizační kontrola (E10): u finálního přiznání vrať uložený snapshot,
        // u draftu ji spočítej živě, aby FE panel ukázal aktuální stav před finalizací.
        $prefinalize = null;
        if ($row['status'] === 'final' && isset($row['computed']['prefinalize_check'])) {
            $prefinalize = $row['computed']['prefinalize_check'];
        } else {
            $prefinalize = $this->preFinalizeChecks->run($supplierId, $year, $type, (array) $row['inputs'], $computation);
        }

        return [
            'return' => $this->publicRow($row),
            'form_code' => $type === 'po' ? 'dppdp9' : 'dpfdp7',
            'variant' => $variant,
            'variant_seq' => $seq,
            'available_variants' => $this->returns->listVariants($supplierId, $year, $type),
            'last_known_tax_suggested' => $variant === 'dodatecne'
                ? $this->returns->findLastKnownTax($supplierId, $year, $type)
                : null,
            'computed' => $computation['result'],
            'podklady' => $computation['podklady'],
            'warnings' => $computation['warnings'],
            'tax_losses' => $this->losses->card($supplierId, $year, $type),
            'prefinalize_check' => $prefinalize,
            'snapshot' => $row['final_snapshot_id'] !== null
                ? $this->snapshotMetadata($supplierId, (int) $row['final_snapshot_id'])
                : null,
            'constants_year' => $year,
        ];
    }

    /**
     * Lehký náhled doplatku/přeplatku pro dashboard „Akce pro tebe" (Epic #48/DPPO
     * reminder) — stejná matematika jako {@see getReturn()} (vč. tichého auto-párování
     * záloh dle účtu FÚ u řádného přiznání), ale BEZ prefinalizační kontroly (na
     * dashboardu není potřeba, ušetří pár dotazů navíc) a BEZ vytvoření prázdného
     * draftu, když přiznání pro daný rok ještě vůbec nezaložili — čistě čtecí náhled.
     *
     * Finalizované přiznání čte přímo uložený `computed` snapshot (bez recompute);
     * draft se přepočítá živě z aktuálních účetních dat (stejné číslo jako na
     * /reports/income-tax).
     *
     * @return array{balance_due: float, filing_deadline_input: string, status: string}|null
     *   null = přiznání pro daný rok/typ ještě neexistuje (nic k zobrazení)
     */
    public function balanceDuePreview(int $supplierId, int $year, string $type): ?array
    {
        $this->assertType($type);
        $row = $this->returns->find($supplierId, $year, $type, 'radne', 1);
        if ($row === null) {
            return null;
        }
        if ($row['status'] === 'final') {
            $result = (array) ($row['computed']['computed'] ?? []);
            return [
                'balance_due' => round((float) ($result['balance_due'] ?? 0), 2),
                'filing_deadline_input' => trim((string) ($row['inputs']['filing_deadline'] ?? '')),
                'status' => 'final',
            ];
        }

        $this->autoMatchAdvancesQuietly($supplierId, $year, $type);
        $row = $this->returns->find($supplierId, $year, $type, 'radne', 1) ?? $row;
        $computation = $this->compute($supplierId, $year, $type, (array) $row['inputs'], 'radne');
        return [
            'balance_due' => round((float) ($computation['result']['balance_due'] ?? 0), 2),
            'filing_deadline_input' => trim((string) ($row['inputs']['filing_deadline'] ?? '')),
            'status' => 'draft',
        ];
    }

    /**
     * Uloží ruční vstupy (draft, CAS na row_version). Vrací aktualizovaný stav.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    public function saveInputs(int $supplierId, int $year, string $type, array $inputs, int $expectedRowVersion, ?int $userId, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $clean = $this->sanitizeInputs($type, $inputs, $variant);

        $existing = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        if ($existing === null) {
            if ($expectedRowVersion !== 0) {
                throw new TaxReturnException('version_conflict', 'Přiznání bylo mezitím založeno jinde — načtěte znovu.', 409);
            }
            $this->returns->create($supplierId, $year, $type, $clean, $userId, $variant, $seq);
            return $this->getReturn($supplierId, $year, $type, $userId, $variant, $seq);
        }
        if ($existing['status'] === 'final') {
            throw new TaxReturnException('return_finalized', 'Přiznání je uzamčené (finální). Nejprve ho vraťte do rozpracovaného stavu.', 409);
        }
        $updated = $this->returns->updateInputs($supplierId, $year, $type, $clean, $expectedRowVersion, $variant, $seq);
        if ($updated === null) {
            throw new TaxReturnException('version_conflict', 'Přiznání bylo mezitím změněno (jiná verze) — načtěte znovu.', 409);
        }
        return $this->getReturn($supplierId, $year, $type, $userId, $variant, $seq);
    }

    /**
     * Zmrazí přiznání: uloží snapshot vypočtených řádků, status → final.
     *
     * @return array<string,mixed>
     */
    public function finalize(int $supplierId, int $year, string $type, int $expectedRowVersion, ?int $userId, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        if ($row === null) {
            $row = $this->returns->create($supplierId, $year, $type, [], $userId, $variant, $seq);
            $expectedRowVersion = (int) $row['row_version'];
        }
        if ($row['status'] === 'final') {
            throw new TaxReturnException('already_final', 'Přiznání je již finální.', 409);
        }
        $computation = $this->compute($supplierId, $year, $type, (array) $row['inputs'], $variant);
        $preFinalize = $this->preFinalizeChecks->run($supplierId, $year, $type, (array) $row['inputs'], $computation);
        if (empty($preFinalize['can_finalize'])) {
            throw new TaxReturnException(
                'prefinalize_blocked',
                'Přiznání nelze finalizovat, dokud nejsou vyřešeny všechny blokující kontroly.',
                422,
            );
        }
        $snapshot = [
            'computed' => $computation['result'],
            'podklady' => $computation['podklady'],
            'warnings' => $computation['warnings'],
            // E10: předfinalizační kontrolní checklist uložený spolu se snapshotem přiznání.
            'prefinalize_check' => $preFinalize,
            'finalized_year' => $year,
            'variant' => $variant,
            'variant_seq' => $seq,
        ];
        $snapshotXml = null;
        $businessErrors = [];
        if ($type === 'fo') {
            $businessErrors = $this->dpfoBusinessValidator->validate($computation['result'], $computation['podklady']);
            if ($businessErrors !== []) {
                throw new TaxReturnException('epo_business_validation_failed', implode(' ', $businessErrors), 422);
            }
            $supplier = $this->loadSupplier($supplierId);
            // Finalizace probíhá PRÁVĚ TEĎ (finalized_at = NOW() níže) — zastoupení
            // se čte k dnešku, ne k datu žádného předchozího řádku.
            $meta = ['verze_sw' => $this->loadAppVersion() ?? '0']
                + $this->amendmentXmlMeta($type, $variant, $computation['result'])
                + $this->representationMeta($supplierId, null);
            $snapshotXml = $this->dpfoXml->build($supplier, $year, $computation['result'], $meta)['xml'];
            $xsd = $this->xmlValidator->validate($snapshotXml, 'dpfdp7');
            if ($xsd['status'] !== 'passed') {
                throw new TaxReturnException(
                    'epo_xsd_validation_failed',
                    $xsd['errors'] !== [] ? implode(' ', $xsd['errors']) : 'Schéma DPFO není dostupné pro povinnou validaci ostrého XML.',
                    422,
                );
            }
        }
        $effectiveResult = $computation['result'];
        $effectiveReturnId = (int) ($row['id'] ?? 0) ?: null;
        $lastBefore = $this->returns->findLastFinalized($supplierId, $year, $type);
        if ($lastBefore !== null && $this->variantPosition((string) $lastBefore['variant'], (int) $lastBefore['variant_seq'])
            > $this->variantPosition($variant, $seq)) {
            $previousSnapshot = (array) ($lastBefore['computed'] ?? []);
            $effectiveResult = (array) ($previousSnapshot['computed'] ?? []);
            $effectiveReturnId = (int) ($lastBefore['id'] ?? 0) ?: null;
        }
        [$yearLoss, $appliedLoss] = $this->lossFigures($type, $effectiveResult);
        try {
            $this->losses->assertLossAmountCanBeSet($supplierId, $type, $year, $yearLoss);
        } catch (\DomainException $e) {
            throw new TaxReturnException('loss_already_applied', $e->getMessage(), 409);
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $snapshotId = null;
            if ($type === 'fo' && $snapshotXml !== null) {
                $snapshotId = $this->returns->createSnapshot(
                    (int) $row['id'],
                    $supplierId,
                    $snapshot,
                    (array) ($computation['podklady']['source_manifest'] ?? []),
                    $snapshotXml,
                    'passed',
                    $businessErrors,
                    $userId ?? 0,
                );
            }
            $updated = $this->returns->finalize(
                $supplierId,
                $year,
                $type,
                $snapshot,
                $expectedRowVersion,
                $variant,
                $seq,
                $snapshotId,
                $userId,
            );
            if ($updated === null) {
                throw new TaxReturnException('version_conflict', 'Přiznání bylo mezitím změněno — načtěte znovu.', 409);
            }
            $this->losses->reconcileFinalize($supplierId, $type, $year, $yearLoss, $appliedLoss, $effectiveReturnId);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($variant === 'radne') {
            // E9 — z finalizovaného řádného přiznání vygeneruj předpisy záloh na příští rok
            // (daň §38a / OSVČ soc.+zdrav.). Nikdy nesmí shodit finalizaci — best-effort.
            try {
                $this->advanceSchedules->generateFromReturn(
                    $supplierId, $year, $type, (int) ($row['id'] ?? 0) ?: null, $computation['result']
                );
            } catch (\Throwable) {
                // předpisy záloh jsou pomůcka; selhání jejich generování neblokuje přiznání
            }
        }

        return $this->getReturn($supplierId, $year, $type, $userId, $variant, $seq);
    }

    /**
     * E9 — ručně (re)vygeneruje předpisy záloh na PŘÍŠTÍ rok (year+1) z řádného přiznání
     * roku $year. #42: bere i DRAFT (nejen finalizované) a respektuje override rozhodnutím
     * FÚ, takže předpisy vzniknou i bez finalizace přiznání. @return array<string,int>
     */
    public function generateAdvanceSchedules(int $supplierId, int $year, string $type): array
    {
        $this->assertType($type);
        return $this->rebuildAdvancesForPeriod($supplierId, $year + 1, $type, true);
    }

    /**
     * E9/#42 — (re)vygeneruje předpisy záloh PRO rok $periodYear z řádného přiznání roku
     * $periodYear-1 (draft i final) a/nebo z override rozhodnutím FÚ. Umožní náhled a
     * párování záloh roku Y i bez finalizace přiznání roku Y-1. @return array<string,int>
     */
    public function generateAdvanceSchedulesForPeriod(int $supplierId, int $periodYear, string $type): array
    {
        $this->assertType($type);
        return $this->rebuildAdvancesForPeriod($supplierId, $periodYear, $type, true);
    }

    /**
     * Jádro generování předpisů pro $periodYear: predikce z ř. 340 posledního řádného
     * přiznání roku $periodYear-1 (draft/final, když existuje) + override rozhodnutím FÚ
     * (má přednost, žije nezávisle na finalizaci — #43/#42). Když není ani přiznání ani
     * override a $throwIfNoSource, hodí 409; jinak předpisy jen vyprázdní (cleanup po smazání
     * override). @return array<string,int>
     */
    private function rebuildAdvancesForPeriod(int $supplierId, int $periodYear, string $type, bool $throwIfNoSource): array
    {
        $sourceYear = $periodYear - 1;
        $row = $this->returns->find($supplierId, $sourceYear, $type, 'radne', 1);
        $override = $this->advanceSchedules->activeTaxOverride($supplierId, $type, $periodYear);
        if ($row === null && $override === null) {
            if ($throwIfNoSource) {
                throw new TaxReturnException(
                    'no_advance_source',
                    sprintf('Předpisy záloh na rok %d nelze vygenerovat — chybí řádné přiznání za rok %d i rozhodnutí FÚ o výši záloh.', $periodYear, $sourceYear),
                    409,
                );
            }
            // Cleanup: nic ke generování → vyprázdni naplánované předpisy.
            $row = null;
        }
        $result = [];
        if ($row !== null) {
            $result = $this->compute($supplierId, $sourceYear, $type, (array) $row['inputs'], 'radne')['result'];
        }
        return $this->advanceSchedules->generateFromReturn(
            $supplierId, $sourceYear, $type, $row !== null ? ((int) ($row['id'] ?? 0) ?: null) : null, $result
        );
    }

    /**
     * E9 — předpisy záloh za rok (period_year), volitelně jen daný typ. @return array<string,mixed>
     */
    public function advanceScheduleList(int $supplierId, int $periodYear, ?string $type = null): array
    {
        if ($type !== null) {
            $this->assertType($type);
            // Karta záloh: spáruj reálné platby dle účtu FÚ automaticky (best-effort), ať
            // se zaplacené zálohy zobrazí bez ručního „Spárovat platby".
            $this->autoMatchAdvancesQuietly($supplierId, $periodYear, $type);
        }
        return ['year' => $periodYear, 'schedules' => $this->advanceSchedules->listForYear($supplierId, $periodYear, $type)];
    }

    /**
     * Automatické párování záloh dle účtu FÚ (best-effort). Obalí {@see matchAdvancePayments()}
     * try/catch — párování je pomůcka a nikdy nesmí shodit náhled přiznání ani kartu záloh.
     */
    private function autoMatchAdvancesQuietly(int $supplierId, int $periodYear, string $type): void
    {
        try {
            $this->matchAdvancePayments($supplierId, $periodYear, $type);
        } catch (\Throwable) {
            // párování je pomůcka; jeho selhání nesmí ovlivnit načtení náhledu/karty
        }
    }

    /**
     * E9 — spáruje bankovní platby s předpisy záloh za rok $periodYear a předvyplní
     * zaplacené zálohy do rozpracovaného přiznání téhož roku. @return array<string,mixed>
     */
    public function matchAdvancePayments(int $supplierId, int $periodYear, string $type): array
    {
        $this->assertType($type);
        $result = $this->advanceSchedules->matchPayments($supplierId, $periodYear, $type);
        // F3 (adversariální review 2026-07): NEpřepisuje ruční hodnoty tiše — zapisuje jen
        // do prázdných polí; kde už je ruční vstup, vrátí ho v `skipped` jako návrh k ověření.
        $applied = $this->returns->applyAutoMatchedAdvancesIfEmpty($supplierId, $periodYear, $type, $result['totals']);
        $result['applied'] = $applied['applied'];
        $result['skipped_existing'] = $applied['skipped'];
        $result['conflict'] = $applied['conflict'];
        $result['return_prefilled'] = $applied['applied'] !== [];
        return $result;
    }

    /** E9 — nadcházející zálohy pro dashboard. @return array<string,mixed> */
    public function upcomingAdvances(int $supplierId, int $limit = 12): array
    {
        return ['items' => $this->advanceSchedules->upcoming($supplierId, $limit)];
    }

    // Pozn.: per-rok override metody (#43, advances/override singulár) byly odstraněny —
    // plně je nahradil id-based CRUD s rozsahem OD-DO (#46 níže).

    // ── #46 — rozhodnutí FÚ s rozsahem OD-DO: id-based CRUD napříč roky ─────────

    /**
     * E9/#46 — globální přehled rozhodnutí FÚ (§174) NAPŘÍČ ROKY + předpis placení záloh
     * napříč roky se stavem (zaplaceno/nezaplaceno/po splatnosti). Předpisy se před výpisem
     * auto-spárují s bankou (best-effort) pro každý rok, ať stav sedí bez ručního párování.
     * @return array<string,mixed>
     */
    public function advanceOverridesOverview(int $supplierId, string $type): array
    {
        $this->assertType($type);
        foreach ($this->advanceSchedules->scheduleYears($supplierId, $type) as $year) {
            $this->autoMatchAdvancesQuietly($supplierId, $year, $type);
        }
        return [
            'overrides' => $this->advanceSchedules->listTaxOverrides($supplierId, $type),
            'schedules' => $this->advanceSchedules->listAllYears($supplierId, $type),
        ];
    }

    /**
     * E9/#46 — založí rozhodnutí FÚ s rozsahem OD-DO a přepočítá dotčené roky. Validaci
     * rozsahu/překryvu řeší doménová služba (hodí TaxReturnException). @return array<string,mixed>
     * @param array<string,mixed> $body
     */
    public function createAdvanceOverrideEntry(int $supplierId, string $type, array $body): array
    {
        $this->assertType($type);
        $saved = $this->advanceSchedules->createTaxOverride(
            $supplierId,
            $type,
            $this->requireDate($body['effective_from'] ?? ''),
            $this->optionalDate($body['effective_to'] ?? null),
            $this->money($body['amount'] ?? 0),
            (string) ($body['periodicity'] ?? 'quarterly'),
            $this->text($body['note'] ?? '', 255) ?: null,
            (string) ($body['source'] ?? 'fu_decision'),
        );
        $this->rebuildOverrideRange($supplierId, $type, $saved);
        return $this->advanceOverridesOverview($supplierId, $type) + ['override' => $saved];
    }

    /**
     * E9/#46 — upraví rozhodnutí FÚ a přepočítá dotčené roky (staré i nové rozsahy).
     * @param array<string,mixed> $body @return array<string,mixed>
     */
    public function updateAdvanceOverrideEntry(int $supplierId, string $type, int $id, array $body): array
    {
        $this->assertType($type);
        $before = $this->advanceSchedules->findTaxOverride($supplierId, $id);
        $saved = $this->advanceSchedules->updateTaxOverride(
            $supplierId,
            $id,
            $this->requireDate($body['effective_from'] ?? ''),
            $this->optionalDate($body['effective_to'] ?? null),
            $this->money($body['amount'] ?? 0),
            (string) ($body['periodicity'] ?? 'quarterly'),
            $this->text($body['note'] ?? '', 255) ?: null,
            (string) ($body['source'] ?? 'fu_decision'),
        );
        if ($before !== null) {
            $this->rebuildOverrideRange($supplierId, $type, $before);
        }
        $this->rebuildOverrideRange($supplierId, $type, $saved);
        return $this->advanceOverridesOverview($supplierId, $type) + ['override' => $saved];
    }

    /**
     * E9/#46 — smaže rozhodnutí FÚ dle id a přepočítá roky, které pokrývalo (zpět na predikci).
     * @return array<string,mixed>
     */
    public function deleteAdvanceOverrideEntry(int $supplierId, string $type, int $id): array
    {
        $this->assertType($type);
        $before = $this->advanceSchedules->findTaxOverride($supplierId, $id);
        $deleted = $this->advanceSchedules->deleteTaxOverrideById($supplierId, $id);
        if ($before !== null) {
            $this->rebuildOverrideRange($supplierId, $type, $before);
        }
        return $this->advanceOverridesOverview($supplierId, $type) + ['deleted' => $deleted];
    }

    /**
     * Přepočítá předpisy pro každý kalendářní rok, který rozsah rozhodnutí protíná
     * (best-effort — přepočet je pomůcka, nesmí shodit zápis rozhodnutí).
     * @param array<string,mixed> $override
     */
    private function rebuildOverrideRange(int $supplierId, string $type, array $override): void
    {
        $fromYear = (int) substr((string) ($override['effective_from'] ?? ''), 0, 4);
        $toRaw = $override['effective_to'] ?? null;
        $toYear = $toRaw !== null && $toRaw !== '' ? (int) substr((string) $toRaw, 0, 4) : $fromYear;
        if ($fromYear <= 0) {
            return;
        }
        for ($year = $fromYear; $year <= $toYear; $year++) {
            try {
                $this->rebuildAdvancesForPeriod($supplierId, $year, $type, false);
            } catch (\Throwable) {
                // přepočet je pomůcka; jeho selhání nesmí shodit CRUD rozhodnutí
            }
        }
    }

    /** Datum OD je povinné; prázdné/neplatné → TaxReturnException. */
    private function requireDate(mixed $v): string
    {
        $d = $this->date($v);
        if ($d === '') {
            throw new TaxReturnException('invalid_range', 'Účinnost OD je povinná (YYYY-MM-DD).', 422);
        }
        return $d;
    }

    /** Datum DO je volitelné; prázdné → null (otevřený konec). */
    private function optionalDate(mixed $v): ?string
    {
        $d = $this->date($v);
        return $d === '' ? null : $d;
    }

    /** E9/#43 — ruční úprava předepsané výše NEzaplaceného předpisu. @return array<string,mixed> */
    public function updateAdvanceAmount(int $supplierId, int $periodYear, string $type, int $scheduleId, float $amount): array
    {
        $this->assertType($type);
        $this->assertScheduleBelongs($supplierId, $periodYear, $type, $scheduleId);
        if (!$this->advanceSchedules->updatePlannedAmount($supplierId, $scheduleId, $amount)) {
            throw new TaxReturnException('not_planned', 'Výši lze upravit jen u dosud nezaplaceného předpisu.', 409);
        }
        return ['schedules' => $this->advanceSchedules->listForYear($supplierId, $periodYear, $type)];
    }

    /**
     * E9/#43 — ruční potvrzení úhrady předpisu (bez bankovní transakce) + předvyplnění
     * reálně zaplacených záloh do rozpracovaného přiznání. @return array<string,mixed>
     */
    public function confirmAdvancePaid(int $supplierId, int $periodYear, string $type, int $scheduleId, ?float $amount, ?string $paidOn): array
    {
        $this->assertType($type);
        $this->assertScheduleBelongs($supplierId, $periodYear, $type, $scheduleId);
        $paidOn = $paidOn !== null && $paidOn !== '' ? $this->date($paidOn) : null;
        if (!$this->advanceSchedules->confirmPaidManual($supplierId, $scheduleId, $amount, $paidOn ?: null)) {
            throw new TaxReturnException('not_planned', 'Potvrdit lze jen dosud nezaplacený předpis.', 409);
        }
        return $this->advancesResultWithPrefill($supplierId, $periodYear, $type);
    }

    /**
     * E9/#43 — hromadné „vše zaplaceno" pro rok/typ (volitelně druh) + předvyplnění
     * přiznání. @return array<string,mixed>
     */
    public function confirmAllAdvancesPaid(int $supplierId, int $periodYear, string $type, ?string $kind): array
    {
        $this->assertType($type);
        $kind = in_array($kind, ['tax', 'social', 'health'], true) ? $kind : null;
        $count = $this->advanceSchedules->confirmAllPaidManual($supplierId, $type, $periodYear, $kind);
        $out = $this->advancesResultWithPrefill($supplierId, $periodYear, $type);
        $out['confirmed'] = $count;
        return $out;
    }

    /** E9/#43 — vrátí ručně potvrzený předpis do 'planned'. @return array<string,mixed> */
    public function unconfirmAdvance(int $supplierId, int $periodYear, string $type, int $scheduleId): array
    {
        $this->assertType($type);
        $this->assertScheduleBelongs($supplierId, $periodYear, $type, $scheduleId);
        if (!$this->advanceSchedules->unconfirmManual($supplierId, $scheduleId)) {
            throw new TaxReturnException('not_paid', 'Zrušit potvrzení lze jen u ručně potvrzeného předpisu.', 409);
        }
        return ['schedules' => $this->advanceSchedules->listForYear($supplierId, $periodYear, $type)];
    }

    /**
     * Předvyplní jisté (exact) zaplacené zálohy do rozpracovaného přiznání téhož roku a
     * vrátí aktuální seznam předpisů + info o aplikaci. @return array<string,mixed>
     */
    private function advancesResultWithPrefill(int $supplierId, int $periodYear, string $type): array
    {
        $totals = $this->advanceSchedules->paidTotals($supplierId, $type, $periodYear);
        $applied = $this->returns->applyAutoMatchedAdvancesIfEmpty($supplierId, $periodYear, $type, $totals['exact']);
        return [
            'schedules' => $this->advanceSchedules->listForYear($supplierId, $periodYear, $type),
            'totals' => $totals['exact'],
            'applied' => $applied['applied'],
            'skipped_existing' => $applied['skipped'],
            'conflict' => $applied['conflict'],
            'return_prefilled' => $applied['applied'] !== [],
        ];
    }

    /** Ověří, že předpis existuje, patří supplerovi a odpovídá typu/roku (jinak 404). */
    private function assertScheduleBelongs(int $supplierId, int $periodYear, string $type, int $scheduleId): void
    {
        $schedule = $this->advanceSchedules->findSchedule($supplierId, $scheduleId);
        if ($schedule === null
            || (string) $schedule['taxpayer_type'] !== $type
            || (int) $schedule['period_year'] !== $periodYear) {
            throw new TaxReturnException('schedule_not_found', 'Předpis zálohy nenalezen.', 404);
        }
    }

    /**
     * Vytáhne z výsledku kalkulátoru [ztráta vzniklá v roce, uplatněná ztráta minulých let]
     * pro evidenci §34. FO: úhrn §7–§10 (ř. 41) záporný = ztráta; PO: záporný základ ř. 200.
     *
     * @param array<string,mixed> $result
     * @return array{0:float,1:float}
     */
    private function lossFigures(string $type, array $result): array
    {
        $summary = (array) ($result['summary'] ?? []);
        if ($type === 'po') {
            $base = (float) ($summary['base'] ?? 0);
            $yearLoss = $base < 0 ? round(-$base, 2) : 0.0;
        } else {
            $yearLoss = round(max(0.0, (float) ($summary['year_tax_loss'] ?? 0)), 2);
        }
        $applied = round(max(0.0, (float) ($summary['loss_applied'] ?? 0)), 2);
        return [$yearLoss, $applied];
    }

    private function variantPosition(string $variant, int $seq): int
    {
        return match ($variant) {
            'dodatecne' => 300000 + max(1, $seq),
            'opravne' => 200000,
            default => 100000,
        };
    }

    /** Vrátí finální přiznání zpět do draftu. @return array<string,mixed> */
    public function reopen(int $supplierId, int $year, string $type, int $expectedRowVersion, ?int $userId, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        if ($row === null || $row['status'] !== 'final') {
            throw new TaxReturnException('not_final', 'Přiznání není ve finálním stavu.', 409);
        }
        $lastFinal = $this->returns->findLastFinalized($supplierId, $year, $type, (int) $row['id']);
        $previousYearLoss = 0.0;
        if ($lastFinal !== null) {
            $snapshot = (array) ($lastFinal['computed'] ?? []);
            [$previousYearLoss] = $this->lossFigures($type, (array) ($snapshot['computed'] ?? []));
        }
        try {
            $this->losses->assertLossAmountCanBeSet($supplierId, $type, $year, $previousYearLoss);
        } catch (\DomainException $e) {
            throw new TaxReturnException('loss_already_applied', $e->getMessage(), 409);
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $updated = $this->returns->reopen($supplierId, $year, $type, $expectedRowVersion, $variant, $seq);
            if ($updated === null) {
                throw new TaxReturnException('version_conflict', 'Přiznání bylo mezitím změněno — načtěte znovu.', 409);
            }
            $this->losses->releaseReturn($supplierId, $type, $year);
            if ($lastFinal !== null) {
                $snapshot = (array) ($lastFinal['computed'] ?? []);
                [$yearLoss, $appliedLoss] = $this->lossFigures($type, (array) ($snapshot['computed'] ?? []));
                $this->losses->reconcileFinalize(
                    $supplierId,
                    $type,
                    $year,
                    $yearLoss,
                    $appliedLoss,
                    (int) ($lastFinal['id'] ?? 0) ?: null
                );
            } else {
                $this->losses->reconcileFinalize($supplierId, $type, $year, 0.0, 0.0, null);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->getReturn($supplierId, $year, $type, $userId, $variant, $seq);
    }

    /**
     * Sestaví validované EPO XML přiznání (DPPDP9/DPFDP7) BEZ archivace a bez zápisu do
     * tax_submissions — pro uzávěrkový balíček a náhledy. Sdílí přesně tentýž build jako
     * {@see generateXml()}, aby balíček i samostatný export produkovaly IDENTICKÉ XML
     * (jedna cesta, žádný duplicitní generátor).
     *
     * @return array{xml:string,form_code:string,filename:string,summary:array<string,mixed>,warnings:list<string>,variant:string,variant_seq:int}
     */
    public function previewXml(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        if ($type !== 'fo') {
            throw new TaxReturnException('preview_not_supported', 'Pracovní XML je určeno pro DPFO.', 400);
        }
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        $inputs = $row !== null ? (array) $row['inputs'] : [];
        $computation = $this->compute($supplierId, $year, $type, $inputs, $variant);
        // Pracovní náhled, nic zmrazené — zastoupení k dnešku (viz representationMeta()).
        $meta = ['verze_sw' => $this->loadAppVersion() ?? '0']
            + $this->amendmentXmlMeta($type, $variant, $computation['result'])
            + $this->representationMeta($supplierId, null);
        $built = $this->dpfoXml->build($this->loadSupplier($supplierId), $year, $computation['result'], $meta);
        $businessErrors = $this->dpfoBusinessValidator->validate($computation['result'], $computation['podklady']);
        $xsd = $this->xmlValidator->validate($built['xml'], 'dpfdp7');
        $xsdErrors = $xsd['status'] === 'failed' ? $xsd['errors'] : [];
        return [
            'xml' => $built['xml'],
            'form_code' => 'dpfdp7-preview',
            'filename' => sprintf('dpfdp7-%04d-pracovni.xml', $year),
            'summary' => (array) ($computation['result']['summary'] ?? []),
            'warnings' => array_merge($computation['warnings'], $built['warnings'], $businessErrors, $xsdErrors),
            'business_errors' => $businessErrors,
            'xsd_status' => $xsd['status'],
            'variant' => $variant,
            'variant_seq' => $seq,
        ];
    }

    public function buildXml(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        if ($type === 'fo') {
            if ($row === null || $row['status'] !== 'final' || $row['final_snapshot_id'] === null) {
                throw new TaxReturnException('final_snapshot_required', 'Ostré XML DPFO lze vytvořit pouze z finalizovaného snapshotu.', 409);
            }
            $stored = $this->returns->snapshot($supplierId, (int) $row['final_snapshot_id']);
            if ($stored === null) {
                throw new TaxReturnException('snapshot_not_found', 'Finální snapshot DPFO nebyl nalezen.', 409);
            }
            $snapshot = (array) $stored['snapshot_json'];
            $summary = (array) (($snapshot['computed']['summary'] ?? []));
            $summary['variant'] = $variant;
            $summary['warnings'] = (array) ($snapshot['warnings'] ?? []);
            $suffix = $variant === 'radne' ? '' : '-' . $variant . ($variant === 'dodatecne' && $seq > 1 ? '-' . $seq : '');
            return [
                'xml' => (string) $stored['xml_content'],
                'form_code' => 'dpfdp7',
                'filename' => sprintf('dpfdp7-%04d%s.xml', $year, $suffix),
                'summary' => $summary,
                'warnings' => $summary['warnings'],
                'variant' => $variant,
                'variant_seq' => $seq,
            ];
        }
        $inputs = $row !== null ? (array) $row['inputs'] : [];

        $computation = $this->compute($supplierId, $year, $type, $inputs, $variant);
        $supplier = $this->loadSupplier($supplierId);
        $appVersion = $this->loadAppVersion();
        $amendMeta = $this->amendmentXmlMeta($type, $variant, $computation['result']);

        $appendixWarnings = [];
        if ($type === 'po') {
            // DPPO se na rozdíl od DPFO nezmrazuje do snapshotu (regeneruje se z $row['inputs']
            // vždy znovu) — u finálního přiznání proto zastoupení čteme K DATU FINALIZACE
            // ($row['finalized_at']), ne k dnešku, ať přegenerování starého přiznání nezmění
            // dan_por podle toho, jestli firma dnes zastoupení má/nemá (viz representationMeta()).
            $finalizedAt = ($row !== null && $row['status'] === 'final' && !empty($row['finalized_at']))
                ? (string) $row['finalized_at']
                : null;
            $meta = ['verze_sw' => $appVersion ?? '0']
                + $this->periodMeta($computation['podklady']['period'] ?? null, $year)
                + $amendMeta
                + $this->representationMeta($supplierId, $finalizedAt)
                // Žádost o předání Přílohy do sbírky listin (pr11_puz) — výchozí ANO, viz
                // sanitizeInputs()/DppoXmlBuilder::buildVetaUZ. Nikdy neuložený draft ($inputs
                // == []) čte klíč jako chybějící → default true stejně jako po sanitizaci.
                + ['puz_to_registry' => (bool) ($inputs['puz_to_registry'] ?? true)];
            $appendix = $this->buildDppoAppendix($supplierId, (array) ($computation['podklady']['period'] ?? []), $year);
            $appendixWarnings = (array) ($appendix['warnings'] ?? []);
            unset($appendix['warnings']);
            $built = $this->dppoXml->build($supplier, $year, $computation['result'], $meta, $appendix);
            $formCode = 'dppdp9';
        }

        $summary = $computation['result']['summary'] ?? [];
        $summary['variant'] = $variant;
        $summary['warnings'] = array_merge($computation['warnings'], $built['warnings'], $appendixWarnings);

        $variantSuffix = $variant === 'radne'
            ? ''
            : '-' . $variant . ($variant === 'dodatecne' && $seq > 1 ? '-' . $seq : '');

        return [
            'xml' => $built['xml'],
            'form_code' => $formCode,
            'filename' => sprintf('%s-%04d%s.xml', $formCode, $year, $variantSuffix),
            'summary' => $summary,
            'warnings' => $summary['warnings'],
            'variant' => $variant,
            'variant_seq' => $seq,
        ];
    }

    /**
     * Vygeneruje validované EPO XML, zarchivuje (tax_submissions) a naváže na přiznání.
     * Samotný build deleguje na {@see buildXml()} — společná cesta s uzávěrkovým balíčkem.
     *
     * @return array{xml:string,form_code:string,filename:string,validation_status:string,validation_errors:list<string>,submission_id:int}
     */
    public function generateXml(int $supplierId, int $year, string $type, ?int $userId, string $variant = 'radne', int $variantSeq = 1): array
    {
        $built = $this->buildXml($supplierId, $year, $type, $variant, $variantSeq);
        $seq = (int) $built['variant_seq'];
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);

        $archived = $this->archiver->archive(
            $supplierId, $built['form_code'], $year, null, null,
            $built['xml'], $built['summary'], $userId,
            true,
            self::FORMA[$variant] ?? 'B',
        );
        if ($row !== null) {
            $this->returns->setLastSubmission($supplierId, $year, $type, (int) $archived['submission_id'], $variant, $seq);
        }

        return [
            'xml' => $built['xml'],
            'form_code' => $built['form_code'],
            'filename' => $built['filename'],
            'validation_status' => (string) $archived['validation_status'],
            'validation_errors' => (array) $archived['validation_errors'],
            'submission_id' => (int) $archived['submission_id'],
        ];
    }

    /**
     * Meta pro amendment (opravné/dodatečné) do XML builderů: forma/typ přiznání
     * + u dodatečného datum zjištění, důvody a rozdílové řádky (PO: iv1/iv2/iv3).
     *
     * @param array<string,mixed> $result výstup kalkulátoru (s attach. amendment blokem)
     * @return array<string,mixed>
     */
    private function amendmentXmlMeta(string $type, string $variant, array $result): array
    {
        $forma = self::FORMA[$variant] ?? 'B';
        $meta = $type === 'po' ? ['dapdpp_forma' => $forma] : ['dap_typ' => $forma];
        if ($variant !== 'dodatecne') {
            return $meta;
        }
        $amend = (array) ($result['amendment'] ?? []);
        if (!empty($amend['d_zjist'])) {
            $meta['d_zjist'] = (string) $amend['d_zjist'];
        }
        if (!empty($amend['reason'])) {
            $meta['duvod'] = (string) $amend['reason'];
        }
        if ($type === 'po') {
            // V. oddíl DPPDP9: iv1 = nově zjištěná daň (ř.340), iv2 = poslední známá,
            // iv3 = rozdíl. Celá čísla Kč. iv3 se odvozuje AŽ z zaokrouhlených iv1/iv2,
            // aby platila cross-kontrola EPO iv3 = iv1 − iv2 (žádný drift o 1 Kč z haléřů).
            $iv1 = (int) round((float) ($amend['new_tax'] ?? 0));
            $iv2 = (int) round((float) ($amend['last_known_tax'] ?? 0));
            $meta['kc_dppiv1'] = $iv1;
            $meta['kc_dppiv2'] = $iv2;
            $meta['kc_dppiv3'] = $iv1 - $iv2;
        }
        return $meta;
    }

    /**
     * Meta pro zastoupení daňovým poradcem (`dan_por`/`pln_moc` + `zast_*`, viz
     * {@see TaxRepresentationService}). Datum "k čemu" je JEDNO místo pro všechny
     * volající: u finalizovaného přiznání (`$finalizedAt` != null) stav K DATU
     * FINALIZACE — jednou zmrazené přiznání nesmí měnit obsah podle toho, jestli
     * firma dnes (třeba o rok později) pořád má/nemá poradce; u draftu/náhledu
     * stav K DNEŠKU, protože nic zmrazené ještě není.
     *
     * @return array{representation: array<string,mixed>}
     */
    private function representationMeta(int $supplierId, ?string $finalizedAt): array
    {
        $date = $finalizedAt !== null ? substr($finalizedAt, 0, 10) : date('Y-m-d');

        return ['representation' => $this->representation->at($supplierId, $date)];
    }

    /**
     * Meta pro DPPO XML z reálného účetního období (hospodářský rok). Odvozuje
     * datumy zdaňovacího období (dd.mm.rrrr, vedoucí nuly dle EPO konvence) a typ_zo
     * dle §21a: A = kalendářní rok, B = hospodářský rok, D = období delší než 12 měsíců.
     *
     * @param array{starts_on?:string,ends_on?:string}|null $period
     * @return array{zdobd_od?:string,zdobd_do?:string,typ_zo?:string}
     */
    private function periodMeta(?array $period, int $year): array
    {
        if ($period === null || empty($period['starts_on']) || empty($period['ends_on'])) {
            return [];
        }
        $startsOn = substr((string) $period['starts_on'], 0, 10);
        $endsOn = substr((string) $period['ends_on'], 0, 10);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
        if ($start === false || $end === false) {
            return [];
        }
        // typ_zo §21a: období končící 31. 12. je kalendářní režim (i zkrácený první
        // rok) → 'A'; skutečný hospodářský rok → 'B'; delší než 12 měsíců (přechodné)
        // → 'D'; jiné atypické zkrácené období → bezpečná 'A'.
        $daysDiff = (int) $start->diff($end)->format('%a');
        if (substr($endsOn, 5) === '12-31') {
            $typZo = 'A';
        } elseif (FiscalCalendar::isFiscalYearShape($startsOn, $endsOn)) {
            $typZo = 'B';
        } elseif ($daysDiff > 380) {
            $typZo = 'D';
        } else {
            $typZo = 'A';
        }
        return [
            'zdobd_od' => $start->format('d.m.Y'),
            'zdobd_do' => $end->format('d.m.Y'),
            'typ_zo' => $typZo,
        ];
    }

    /**
     * Podklady pro přílohu účetní závěrky (Epic DP — VetaUA/UB/UD/UZ, viz
     * private/APPENDIX-XML-MAPPING-SPEC.md). Best-effort: appendix je aditivní nadstavba
     * nad hlavní II. oddíl přiznání — chyba při čtení podkladů (chybějící mapování výkazů,
     * neuzavřené období apod.) appendix jen vynechá, nezpůsobí pád generování XML.
     *
     * VZZ se čte vždy v rozsahu 'full' bez ohledu na kategorii ÚJ (spec §6.c/§7.c) — oba
     * reálně ověřené vzorky (mikro ÚJ) podávaly VZZ v plném rozsahu, zkrácení je jen
     * dobrovolná volba účetního nad rámec zákonného minima.
     *
     * Best-effort NEZNAMENÁ tiché: dřív se chybějící/nezdařená příloha jen vynechala
     * (`return []`), takže přiznání odešlo bez VetaUA/UB/UD/UZ a nikdo se to nedozvěděl,
     * dokud to EPO nevytklo jako chybu 2602 „Není vložena příloha účetní závěrky" (reálné
     * podání 30. 8. 2026). Důvod vynechání teď jde ven přes `warnings`, ať to účetní vidí
     * v UI dřív, než podá.
     *
     * Přílohu v účetní závěrce (§ 39 vyhlášky — souvislý text: účetní metody, události po
     * rozvahovém dni…, kterou účetní vyplňuje v Účetnictví → Uzávěrka → Příloha v účetní
     * závěrce, {@see \MyInvoice\Service\Accounting\Reports\StatementNotesService}) proto
     * podání nese i jako SKUTEČNĚ PŘILOŽENÝ SOUBOR (`Prilohy/PredepsanaPriloha` kod=`PP_OPISPUV`,
     * dppdp9.xsd:6180/6234) — jiný obsah než rozpad rozvahy/VZZ po řádcích výše. Samostatný
     * krok, viz {@see buildStatementNotesAttachment()}.
     *
     * Ručně vyplněný vzor v EPO (ověřený fakt — zadavatel doložil snímkem, viz
     * DANE-PLAN.md) ukázal, že tahle příloha patří do PŘEDEPSANÉ přílohy s kódem
     * `PP_OPISPUV` (jeden řádek v tabulce příloh EPO), NE do obecné přílohy bez kódu —
     * dřív se stavěla jako `ObecnaPriloha`, což je špatná přihrádka.
     *
     * Stejnou cestou (`Prilohy/PredepsanaPriloha`) nese podání i další dvě přílohy, KDYŽ
     * je pro danou ÚJ § 18 odst. 2 ZoÚ vyžaduje (viz needsSection18Statements()):
     * přehled o peněžních tocích (`PP_PTOK`, {@see buildCashFlowAttachment()}) a přehled
     * o změnách vlastního kapitálu (`PP_ZVKAP`, {@see buildEquityChangesAttachment()}).
     * `PP_UZMUS` (IFRS závěrka) se NESTAVÍ — aplikace IFRS nepodporuje.
     *
     * POZOR — tohle NEODSTRAŇUJE chybu 2602: ověřeno proti zkušebnímu EPO 31. 8. 2026,
     * výtka „Není vložena příloha účetní závěrky" se s přiloženým souborem i bez něj
     * chová IDENTICKY (DANE-PLAN.md dodatek 13, §13.3) — příčinu se nepodařilo zjistit
     * (viz tam), přiložení souboru zůstává správné samo o sobě (§39 vyhláška), jen to není
     * lék na 2602. Přesun z ObecnaPriloha na PredepsanaPriloha/PP_OPISPUV na tohle nemá vliv
     * — je to strukturální oprava (správná přihrádka v EPO), ne pokus o odstranění 2602.
     *
     * @param array{id?:int,starts_on?:string,ends_on?:string} $period
     * @return array{balance_sheet?:array<string,mixed>,income_statement?:array<string,mixed>,category?:array<string,mixed>,settings?:array<string,mixed>,statement_notes_attachment?:array{content:string,filename:string,label:string},cash_flow_attachment?:array{content:string,filename:string,label:string},equity_changes_attachment?:array{content:string,filename:string,label:string},warnings:list<string>}
     */
    private function buildDppoAppendix(int $supplierId, array $period, int $fiscalYear = 0): array
    {
        $periodId = (int) ($period['id'] ?? 0);
        if ($periodId <= 0) {
            return ['warnings' => [
                'Příloha účetní závěrky (VetaUA/UB/UD/UZ) nebyla k přiznání připojena — '
                . 'chybí navázané účetní období. EPO to při skutečném podání vytkne jako '
                . 'chybu 2602 „Není vložena příloha účetní závěrky". Vyberte/uzavřete '
                . 'účetní období v Účetnictví → Uzávěrka a přiznání vygenerujte znovu.',
            ]];
        }
        try {
            $appendix = [
                'balance_sheet' => $this->statements->balanceSheet($supplierId, $periodId, null, 'auto'),
                'income_statement' => $this->statements->incomeStatement($supplierId, $periodId, null, 'full'),
                'category' => $this->categories->evaluate($supplierId, $periodId),
                'settings' => $this->supplierSettings->get($supplierId),
                'warnings' => [],
            ];
        } catch (\Throwable $e) {
            return ['warnings' => [
                'Příloha účetní závěrky (VetaUA/UB/UD/UZ) se nepodařilo sestavit ('
                . $e->getMessage() . ') — EPO to při skutečném podání vytkne jako chybu 2602 '
                . '„Není vložena příloha účetní závěrky". Ověřte, že je účetní období '
                . 'uzavřené a výkazy (rozvaha/VZZ) mají platné mapování, a přiznání '
                . 'vygenerujte znovu.',
            ]];
        }

        // Rok pro pojmenování/popis příloh — přednost má explicitně předaný rok podání
        // (zdaňovací období DPPO nemusí být kalendářní), fallback na rok konce účetního
        // období, když volající rok nepředal (zpětná kompatibilita se stávajícími testy
        // přes reflexi, které tenhle parametr nezadávají).
        $attachmentYear = $fiscalYear > 0 ? $fiscalYear : (int) substr((string) ($period['ends_on'] ?? ''), 0, 4);

        // Součet velikosti e-příloh se hlídá napříč VŠEMI třemi (ne jen u jedné) — proto
        // sdílený $usedBytes, viz attachIfWithinBudget().
        $usedBytes = 0;
        $this->attachIfWithinBudget(
            $appendix,
            'statement_notes_attachment',
            'Příloha v účetní závěrce',
            $this->buildStatementNotesAttachment($supplierId, $periodId, $period),
            $usedBytes,
        );

        // § 18 odst. 2 ZoÚ — jen velká/střední ÚJ a každá s povinným auditem (stejné
        // kritérium jako Section18StatementsAction/ClosingService, viz
        // needsSection18Statements()). Mikro/malá ÚJ bez auditu povinnost nemá — tam se
        // přehledy k DPPO NEPŘIKLÁDAJÍ, i kdyby šly sestavit (appendix by nesl přílohu,
        // kterou zákon nevyžaduje).
        if ($this->needsSection18Statements((array) $appendix['category'])) {
            $this->attachIfWithinBudget(
                $appendix,
                'cash_flow_attachment',
                'Přehled o peněžních tocích',
                $this->buildCashFlowAttachment($supplierId, $periodId, $attachmentYear),
                $usedBytes,
            );
            $this->attachIfWithinBudget(
                $appendix,
                'equity_changes_attachment',
                'Přehled o změnách vlastního kapitálu',
                $this->buildEquityChangesAttachment($supplierId, $periodId, $attachmentYear),
                $usedBytes,
            );
        }

        return $appendix;
    }

    /**
     * Součet velikosti všech e-příloh (`Prilohy/PredepsanaPriloha`) v JEDNOM podání smí
     * být podle dokumentace XSD (dppdp9.xsd:6211) nejvýš 10 240 kB — kontroluje se na
     * velikost SOUBORU (před base64), protože to je částka, kterou dokumentace jmenuje.
     */
    private const STATEMENT_NOTES_MAX_BYTES = 10_240 * 1024;

    /**
     * § 18 odst. 2 ZoÚ: přehled o peněžních tocích a o změnách vlastního kapitálu jsou
     * POVINNOU součástí závěrky jen u velké a střední ÚJ a u KAŽDÉ ÚJ s povinným auditem
     * (auditovaná mikro/malá ÚJ dostane plný `scope`, i když by jinak byla zkrácená —
     * R18/Epic F4, {@see \MyInvoice\Service\Accounting\Reports\EntityCategoryService::evaluate()}).
     * Mikro a malá ÚJ bez auditu povinnost NEMAJÍ.
     *
     * Stejné kritérium jako {@see \MyInvoice\Action\Accounting\Reports\Section18StatementsAction::get()}
     * (`required` v odpovědi) a kontrola `section18_statements_required` v
     * {@see \MyInvoice\Service\Accounting\Closing\ClosingService} — JEDNO místo pravdy;
     * kdyby se rozešlo, uzávěrka/report/přiznání by o téže povinnosti tvrdily každé něco
     * jiného.
     *
     * @param array<string,mixed> $category výstup EntityCategoryService::evaluate()
     */
    private function needsSection18Statements(array $category): bool
    {
        return in_array((string) ($category['category'] ?? ''), ['large', 'medium'], true)
            || (($category['scope_override'] ?? null) === null && (string) ($category['scope'] ?? '') === 'full');
    }

    /**
     * Připojí e-přílohu k appendixu, jen když se s dosavadním součtem vejde do limitu
     * `STATEMENT_NOTES_MAX_BYTES` — limit XSD (dppdp9.xsd:6211) je na SOUČET všech e-příloh
     * podání, ne na jednu, takže se musí hlídat SPOLEČNÝM `$usedBytes` napříč voláními pro
     * všechny tři možné přílohy (viz buildDppoAppendix()), ne nezávisle per příloha.
     * Warning z `$built` (chyba sestavení/renderu, nekompletní obsah) se propíše vždy,
     * bez ohledu na to, jestli přiložení proběhlo.
     *
     * @param array<string,mixed> $appendix
     * @param array{file:?array{content:string,filename:string,label:string},warning:?string} $built
     */
    private function attachIfWithinBudget(array &$appendix, string $key, string $label, array $built, int &$usedBytes): void
    {
        if ($built['warning'] !== null) {
            $appendix['warnings'][] = $built['warning'];
        }
        $file = $built['file'];
        if ($file === null) {
            return;
        }
        $size = strlen((string) $file['content']);
        if ($usedBytes + $size > self::STATEMENT_NOTES_MAX_BYTES) {
            $appendix['warnings'][] = sprintf(
                '%s má %s kB — se součtem dosavadních e-příloh podání (%s kB) by přesáhl '
                . 'limit 10 240 kB, proto se K PŘIZNÁNÍ NEPŘIPOJIL(A) (raději vůbec než přes '
                . 'limit). Podejte přiznání a přílohu doručte finančnímu úřadu samostatně '
                . '(mimo XML podání).',
                $label,
                number_format($size / 1024, 0, ',', ' '),
                number_format($usedBytes / 1024, 0, ',', ' '),
            );
            return;
        }
        $appendix[$key] = $file;
        $usedBytes += $size;
    }

    /**
     * Vyrobí PDF „Příloha v účetní závěrce" (§ 18/1/c ZoÚ, § 39/39a/39b vyhl. 500/2002,
     * {@see \MyInvoice\Service\Accounting\Reports\StatementNotesService::build()} +
     * {@see \MyInvoice\Service\Pdf\StatementNotesPdfRenderer}) pro vložení do podání jako
     * `Prilohy/PredepsanaPriloha` kod=`PP_OPISPUV` — dokument, který zákon k závěrce žádá
     * (viz {@see buildDppoAppendix()} nad touto metodou).
     *
     * NEŘEŠÍ EPO chybu 2602 „Není vložena příloha účetní závěrky" — ověřeno proti
     * zkušebnímu EPO 31. 8. 2026, výtka se s přiloženým souborem i bez něj chová
     * IDENTICKY (DANE-PLAN.md dodatek 13). Přiložit soubor je přesto správné samo o
     * sobě (dokumentuje splnění § 39), varovné texty níže proto NEslibují, že 2602 zmizí.
     *
     * Soubor VĚDOMĚ nevloží (vrátí `file: null` + `warning`), místo aby ho tiše
     * ořízla/vynechala, ve dvou situacích:
     *   1) příloha není kompletní (účetní nevyplnila některou z povinných sekcí) —
     *      vložit prázdný/poloprázdný dokument do ostrého podání by bylo HORŠÍ než ho
     *      nevložit vůbec (vypadalo by to jako splněná povinnost, ačkoli není);
     *   2) načtení/render selže.
     * Limit velikosti e-příloh (10 240 kB SOUČTOVĚ, ne per soubor) hlídá až volající
     * {@see attachIfWithinBudget()} — tahle metoda ho sama neřeší, protože v okamžiku
     * jejího běhu ještě neví, kolik místa zabraly ostatní přílohy.
     *
     * @param array{starts_on?:string,ends_on?:string} $period
     * @return array{file:?array{content:string,filename:string,label:string},warning:?string}
     */
    private function buildStatementNotesAttachment(int $supplierId, int $periodId, array $period): array
    {
        $path = sprintf(
            'Účetnictví → Uzávěrka a přiznání → Příloha v účetní závěrce (/accounting/periods/%d/statement-notes).',
            $periodId,
        );
        try {
            $notes = $this->statementNotes->build($supplierId, $periodId);
        } catch (\Throwable $e) {
            return ['file' => null, 'warning' =>
                'Příloha v účetní závěrce (§ 39 vyhlášky 500/2002 Sb.) se nepodařilo načíst ('
                . $e->getMessage() . ') — k přiznání se PŘIPOJIT NEMOHLA jako soubor. Ověřte v: ' . $path];
        }

        if ($notes['missing'] !== []) {
            return ['file' => null, 'warning' =>
                'Příloha v účetní závěrce (§ 39 vyhlášky 500/2002 Sb.) není vyplněná celá — chybí '
                . count($notes['missing']) . ' povinných sekcí, proto se k přiznání NEPŘIPOJILA jako '
                . 'soubor. Doplňte v: ' . $path];
        }

        try {
            $pdf = $this->statementNotesPdf->render([
                'notes'  => $notes,
                'entity' => $this->statements->entityHeader($supplierId),
                'period' => ['starts_on' => $period['starts_on'] ?? null, 'ends_on' => $period['ends_on'] ?? null],
            ]);
        } catch (\Throwable $e) {
            return ['file' => null, 'warning' =>
                'Příloha v účetní závěrce (§ 39 vyhlášky 500/2002 Sb.) se nepodařilo vyrenderovat '
                . 'do PDF (' . $e->getMessage() . ') — k přiznání se NEPŘIPOJILA jako soubor. '
                . 'Vygenerujte přiznání znovu; opakuje-li se to, ověřte obsah v: ' . $path];
        }

        $fiscalYear = (int) ($notes['fiscal_year'] ?? 0);
        return ['file' => [
            'content'  => $pdf,
            'filename' => sprintf('priloha-ucetni-zaverky-%d.pdf', $fiscalYear),
            'label'    => sprintf('Příloha v účetní závěrce za rok %d (§ 39 vyhl. 500/2002 Sb.)', $fiscalYear),
        ], 'warning' => null];
    }

    /**
     * Přehled o peněžních tocích (§ 18 odst. 2 ZoÚ, § 40–43 vyhl. 500/2002 Sb.) jako
     * `Prilohy/PredepsanaPriloha` kod=`PP_PTOK`. STEJNÝ zdroj dat jako uzávěrkový balíček
     * ({@see \MyInvoice\Service\Export\ClosingPackageService}, část `cash_flow`) — žádný
     * druhý generátor, jen jiný výstupní kontejner (příloha DPPO místo souboru v ZIPu).
     *
     * Volá se JEN když {@see needsSection18Statements()} vrátí true (přehled je pro tuhle
     * ÚJ povinný) — proto je selhání sestavení/renderu vždy warning „je povinný, ale…",
     * ne tiché vynechání.
     *
     * @return array{file:?array{content:string,filename:string,label:string},warning:?string}
     */
    private function buildCashFlowAttachment(int $supplierId, int $periodId, int $fiscalYear): array
    {
        try {
            $data = $this->cashFlowStatement->build($supplierId, $periodId);
            $data['entity'] = $this->statements->entityHeader($supplierId);
            $pdf = $this->cashFlowPdf->render($data);
        } catch (\Throwable $e) {
            return ['file' => null, 'warning' =>
                'Přehled o peněžních tocích (§ 18 odst. 2 ZoÚ) je pro tuto účetní jednotku '
                . 'povinnou součástí závěrky, ale nepodařilo se ho sestavit (' . $e->getMessage()
                . ') — k přiznání se NEPŘIPOJIL. Ověřte v Účetnictví → Uzávěrka → Přehledy podle '
                . '§ 18/2 a přiznání vygenerujte znovu.'];
        }
        // Nesedící přehled (chybný protiúčet apod.) se přesto přiloží — stejné chování
        // jako uzávěrkový balíček (viz ClosingPackageService::run()): vada v datech se
        // hlásí warningem, ne tichým vynecháním povinné přílohy.
        $warning = ($data['reconciles'] ?? true) !== true
            ? 'Přehled o peněžních tocích v příloze DPPO nesedí (počáteční stav + čistý tok '
                . '≠ konečný stav) — přiložen je i tak, ale ověřte účtování peněžních prostředků.'
            : null;
        return ['file' => [
            'content'  => $pdf,
            'filename' => sprintf('prehled-penezni-toky-%d.pdf', $fiscalYear),
            'label'    => sprintf('Přehled o peněžních tocích za rok %d (§ 18 odst. 2 ZoÚ)', $fiscalYear),
        ], 'warning' => $warning];
    }

    /**
     * Přehled o změnách vlastního kapitálu (§ 18 odst. 2 ZoÚ, § 44 vyhl. 500/2002 Sb.)
     * jako `Prilohy/PredepsanaPriloha` kod=`PP_ZVKAP`. Stejná logika jako
     * {@see buildCashFlowAttachment()} (stejný zdroj dat jako uzávěrkový balíček, volá
     * se jen když je přehled povinný, nesedící přehled se přiloží s warningem).
     *
     * @return array{file:?array{content:string,filename:string,label:string},warning:?string}
     */
    private function buildEquityChangesAttachment(int $supplierId, int $periodId, int $fiscalYear): array
    {
        try {
            $data = $this->equityChangesStatement->build($supplierId, $periodId);
            $data['entity'] = $this->statements->entityHeader($supplierId);
            $pdf = $this->equityChangesPdf->render($data);
        } catch (\Throwable $e) {
            return ['file' => null, 'warning' =>
                'Přehled o změnách vlastního kapitálu (§ 18 odst. 2 ZoÚ) je pro tuto účetní '
                . 'jednotku povinnou součástí závěrky, ale nepodařilo se ho sestavit ('
                . $e->getMessage() . ') — k přiznání se NEPŘIPOJIL. Ověřte v Účetnictví → '
                . 'Uzávěrka → Přehledy podle § 18/2 a přiznání vygenerujte znovu.'];
        }
        $warning = ($data['reconciles'] ?? true) !== true
            ? 'Přehled o změnách vlastního kapitálu v příloze DPPO nesedí u některé složky '
                . '— přiložen je i tak, ale ověřte účtování vlastního kapitálu.'
            : null;
        return ['file' => [
            'content'  => $pdf,
            'filename' => sprintf('prehled-zmeny-vlastniho-kapitalu-%d.pdf', $fiscalYear),
            'label'    => sprintf('Přehled o změnách vlastního kapitálu za rok %d (§ 18 odst. 2 ZoÚ)', $fiscalYear),
        ], 'warning' => $warning];
    }

    /** Přehledy pojistného OSVČ (jen FO). @return array<string,mixed> */
    public function getInsurance(int $supplierId, int $year, string $type): array
    {
        $this->assertType($type);
        if ($type !== 'fo') {
            throw new TaxReturnException('invalid_type', 'Přehledy pojistného jsou jen pro fyzické osoby (OSVČ).', 400);
        }
        return $this->insurance->build($supplierId, $year);
    }

    /** PDF Přehledů pojistného OSVČ. @return array{pdf:string,filename:string} */
    public function insurancePdf(int $supplierId, int $year, string $type): array
    {
        $summary = $this->getInsurance($supplierId, $year, $type);
        $supplier = $this->loadSupplier($supplierId);
        $pdf = $this->insurancePdf->render([
            'summary' => $summary,
            'supplier' => [
                'name' => (string) ($supplier['company_name'] ?? ''),
                'ic' => (string) ($supplier['ic'] ?? ''),
                'dic' => (string) ($supplier['dic'] ?? ''),
            ],
        ]);
        return ['pdf' => $pdf, 'filename' => sprintf('prehledy-pojistne-%04d.pdf', $year)];
    }

    /**
     * E11 — PDF „Přehled OSVČ pro zdravotní pojišťovnu" ve struktuře oficiálního formuláře.
     * Čísla (VZ, pojistné, zálohy, doplatek) ze STEJNÉHO zdroje jako sociální přehled
     * ({@see InsuranceSummaryService::build()} → větev `health`), aby platila parita.
     *
     * @return array{pdf:string,filename:string}
     */
    public function healthInsurancePdf(int $supplierId, int $year, string $type): array
    {
        $summary = $this->getInsurance($supplierId, $year, $type);
        $supplier = $this->loadSupplierHealth($supplierId);
        $pdf = $this->healthOverviewPdf->render([
            'summary' => $summary,
            'supplier' => $supplier,
            'insurer' => $this->healthInsurer((string) ($supplier['health_insurance_code'] ?? '')),
        ]);
        return ['pdf' => $pdf, 'filename' => sprintf('prehled-osvc-zp-%04d.pdf', $year)];
    }

    /**
     * Číselník je sdílený s mzdovou agendou ({@see HealthInsurers}).
     * Tady zůstává tolerantní: neznámý kód se do PDF vytiskne bez názvu,
     * aby se Přehled OSVČ pro ZP dal vygenerovat i s rozpracovaným nastavením.
     *
     * @return array{code:string,name:string}
     */
    private function healthInsurer(string $code): array
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        return ['code' => $code, 'name' => HealthInsurers::name($code) ?? ''];
    }

    /** @return array<string,mixed> */
    private function loadSupplierHealth(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.company_name, s.street, s.city, s.zip,
                    s.ic, s.dic, s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.health_insurance_number, s.health_insurance_code
               FROM supplier s WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        return $row;
    }

    /**
     * Vygeneruje validovanou datovou větu ČSSZ „Přehled OSVČ" (XML) a zarchivuje
     * (tax_submissions, form_code 'osvc25'). Jen FO/OSVČ. Přímé odeslání = uživatel.
     *
     * @return array{xml:string,form_code:string,filename:string,validation_status:string,validation_errors:list<string>,submission_id:int,warnings:list<string>}
     */
    public function generateCsszXml(int $supplierId, int $year, string $type, ?int $userId): array
    {
        $this->assertType($type);
        if ($type !== 'fo') {
            throw new TaxReturnException('invalid_type', 'Přehled ČSSZ je jen pro fyzické osoby (OSVČ).', 400);
        }
        $summary = $this->insurance->build($supplierId, $year);
        $supplier = $this->loadSupplierCssz($supplierId);
        $meta = [
            'productVersion' => $this->loadAppVersion() ?? '0',
            'typ' => 'N',
            'fill_date' => (new \DateTimeImmutable())->format('Y-m-d'),
        ];
        $built = $this->csszXml->build($supplier, $year, $summary, $meta);

        $archived = $this->archiver->archive(
            $supplierId, 'osvc25', $year, null, null,
            $built['xml'], ['insurance' => 'social', 'warnings' => $built['warnings']], $userId,
        );

        return [
            'xml' => $built['xml'],
            'form_code' => 'osvc25',
            'filename' => sprintf('prehled-osvc-cssz-%04d.xml', $year),
            'validation_status' => (string) $archived['validation_status'],
            'validation_errors' => (array) $archived['validation_errors'],
            'submission_id' => (int) $archived['submission_id'],
            'warnings' => $built['warnings'],
        ];
    }

    // ── Interní ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $inputs
     * @return array{result:array<string,mixed>,podklady:array<string,mixed>,warnings:list<string>}
     */
    private function compute(int $supplierId, int $year, string $type, array $inputs, string $variant = 'radne'): array
    {
        try {
            $const = $this->constants->forExactYear($year);
        } catch (\OutOfRangeException $e) {
            throw new TaxReturnException('missing_tax_constants', $e->getMessage(), 422);
        }

        if ($type === 'po') {
            $data = $this->dppoData->gather($supplierId, $year, $inputs);
            $result = $this->dppoCalc->compute($data, $inputs, $const);
            $newTax = (float) ($result['summary']['total_tax'] ?? 0);
            $podklady = [
                'period' => $data['period'],
                'vh' => $data['vh'],
                'non_deductible_costs' => $data['non_deductible_costs'],
                'depreciation' => $data['depreciation'],
                'disposal_nondeductible_residual' => $data['disposal_nondeductible_residual'],
                'disposals' => $data['disposals'],
                // Feature 1 (projekce VH) + Feature 2 (auto-návrhy §25/§20/§34) pro náhled DPPO.
                'closing_projection' => $data['closing_projection'] ?? null,
                'suggestions' => $data['suggestions'] ?? ['addbacks' => [], 'deductions' => []],
                // Volba bankovního účtu pro vrácení přeplatku (VetaNP) — appka dřív vybírala
                // za poplatníka (první aktivní CZK účet); FE z `bank_accounts` staví výběr,
                // `bank_account` je efektivně použitý (výchozí, nebo `inputs.bank_account_id`).
                'bank_account' => $data['bank_account'] ?? null,
                'bank_accounts' => $data['bank_accounts'] ?? [],
            ];
            $warnings = array_merge($data['warnings'], $result['warnings']);
        } else {
            // DPFO — §6–§10, Příloha 1 §7 (kasová báze / paušál / VH pro double_entry).
            $data = $this->dpfoData->gather($supplierId, $year, $inputs);
            $result = $this->dpfoCalc->compute($data, $inputs, (array) $data['profile'], $const);
            $newTax = (float) ($result['tax'] ?? 0);
            $podklady = [
                's7_income' => $data['s7_income'],
                's7_expenses' => $data['s7_expenses'],
                's7_base' => $data['s7_base'],
                'expense_mode' => $data['expense_mode'],
                'expense_rate' => $data['expense_rate'],
                'is_vat_payer' => $data['is_vat_payer'],
                'accounting_mode' => $data['accounting_mode'],
                'profile' => $data['profile'],
                'activities' => $data['activities'] ?? [],
                'closing' => $data['closing'] ?? null,
                'blocking_issues' => $data['blocking_issues'] ?? [],
                'source_manifest' => $data['source_manifest'] ?? [],
                'child_bonus_min_income' => $const['child_bonus_min_income'] ?? 0,
            ];
            $warnings = array_merge($data['warnings'], $result['warnings']);
        }

        // Fáze E nález N2: zadaná ztráta k uplatnění (§34) MUSÍ jít proti evidenci
        // (tax_losses) — bez kontroly by fat-finger vstup (např. 1 200 000 místo 120 000)
        // prošel kalkulátorem (ten jen ořízne do výše ř. 41/§7-10, ne proti evidenci) a
        // v XML/kc_ztrata2 by se uplatnila neexistující ztráta → podhodnocená daň beze stopy.
        // Ponecháno jako VÝRAZNÉ varování (ne blok) — legitimní edge case jsou historické
        // ztráty vzniklé před zavedením evidence v systému (evidence=0, ztráta reálně existuje).
        $requestedLoss = max(0.0, (float) ($inputs['loss_carryforward'] ?? 0));
        if ($requestedLoss > 0.0) {
            $available = (float) ($this->losses->card($supplierId, $year, $type)['available_total'] ?? 0.0);
            if ($requestedLoss > $available + 0.01) {
                $warnings[] = 'POZOR: zadaná ztráta k uplatnění (' . number_format($requestedLoss, 0, ',', ' ')
                    . ' Kč) PŘESAHUJE evidovanou dostupnou ztrátu (' . number_format($available, 0, ',', ' ')
                    . ' Kč, karta Daňové ztráty §34). Ověřte částku PŘED finalizací přiznání — pokud jde o '
                    . 'historickou ztrátu vzniklou před zavedením evidence v systému, doplňte ji nejprve do evidence.';
            }
        }

        // Dodatečné přiznání — rozdílový blok vůči poslední známé dani.
        if ($variant === 'dodatecne') {
            $amend = $this->amendmentBlock($supplierId, $year, $type, $inputs, $newTax);
            $result['amendment'] = $amend;
            if (!empty($amend['warnings'])) {
                $warnings = array_merge($warnings, $amend['warnings']);
            }
        }

        return ['result' => $result, 'podklady' => $podklady, 'warnings' => $warnings];
    }

    /**
     * Rozdílový blok dodatečného přiznání: poslední známá daň (ruční vstup má přednost,
     * jinak z předchozího finalizovaného řádného/opravného), nově zjištěná daň, rozdíl.
     *
     * @param array<string,mixed> $inputs
     * @return array{new_tax:float,last_known_tax:float,tax_difference:float,d_zjist:string,reason:string,warnings:list<string>}
     */
    private function amendmentBlock(int $supplierId, int $year, string $type, array $inputs, float $newTax): array
    {
        $warnings = [];
        $manualLast = isset($inputs['last_known_tax']) ? (float) $inputs['last_known_tax'] : null;
        $derivedLast = $this->returns->findLastKnownTax($supplierId, $year, $type);
        $lastKnown = $manualLast ?? $derivedLast ?? 0.0;

        if ($manualLast === null && $derivedLast === null) {
            $warnings[] = 'Dodatečné přiznání: není k dispozici poslední známá daň (žádné finalizované '
                . 'řádné/opravné přiznání za období) — zadejte ji ručně, jinak se rozdíl počítá proti nule.';
        }
        $newTax = round($newTax, 2);
        $lastKnown = round($lastKnown, 2);
        $diff = round($newTax - $lastKnown, 2);
        if ($diff < 0) {
            $warnings[] = 'Dodatečné přiznání na nižší daň (rozdíl ' . number_format($diff, 0, ',', ' ')
                . ' Kč) — lze podat jen za podmínek § 141 odst. 2 daňového řádu.';
        } elseif ($diff === 0.0) {
            $warnings[] = 'Dodatečné přiznání nemění poslední známou daň (rozdíl 0 Kč) — bez rozdílu '
                . 'není důvod k podání dodatečného přiznání; ověřte vstupy.';
        }
        $dZjist = $this->date($inputs['d_zjist'] ?? '');
        if ($dZjist === '') {
            $warnings[] = 'Dodatečné přiznání vyžaduje datum zjištění důvodů (§141 DŘ) — doplňte ho.';
        }
        return [
            'new_tax' => $newTax,
            'last_known_tax' => $lastKnown,
            'tax_difference' => $diff,
            'd_zjist' => $dZjist,
            'reason' => $this->text($inputs['amend_reason'] ?? '', 2000),
            'warnings' => $warnings,
        ];
    }

    /**
     * Sanitizace ručních vstupů — whitelist klíčů per typ (plán §2.2). Neznámé klíče
     * se zahodí, částky se coercují na nezáporná čísla. Pro opravné/dodatečné přidává
     * důvody podání (§141 DŘ) a u dodatečného poslední známou daň + datum zjištění.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private function sanitizeInputs(string $type, array $inputs, string $variant = 'radne'): array
    {
        $out = [];
        // Společné
        $out['tax_paid_advances'] = $this->money($inputs['tax_paid_advances'] ?? 0);
        $out['notes'] = $this->text($inputs['notes'] ?? '', 2000);

        if ($type === 'po') {
            $out['manual_increase_items'] = $this->items($inputs['manual_increase_items'] ?? []);
            $out['manual_decrease_items'] = $this->items($inputs['manual_decrease_items'] ?? []);
            $out['loss_carryforward'] = $this->money($inputs['loss_carryforward'] ?? 0);
            $out['donations'] = $this->money($inputs['donations'] ?? 0);
            // Položkové dary §20/8 (min. 2 000 Kč/dar) — preferováno před agregátem `donations`.
            $out['donation_items'] = $this->items($inputs['donation_items'] ?? []);
            // Odečty §34 odst. 4 — výzkum a vývoj (ř. 242, §34a–34e) a odborné vzdělávání
            // (ř. 243, §34f–34h). {@see DppoReturnCalculator::compute()} je čte, whitelist
            // je ale neměl: účetní odečet zadala, uložení ho tiše zahodilo a přiznání
            // vyšlo s vyšší daní, aniž by o tom cokoli hlásilo.
            $out['rnd_deduction'] = $this->money($inputs['rnd_deduction'] ?? 0);
            $out['education_deduction'] = $this->money($inputs['education_deduction'] ?? 0);
            $out['disabled_employees_avg'] = max(0.0, (float) ($inputs['disabled_employees_avg'] ?? 0));
            $out['disabled_employees_severe_avg'] = max(0.0, (float) ($inputs['disabled_employees_severe_avg'] ?? 0));
            $out['filing_deadline'] = $this->date($inputs['filing_deadline'] ?? '');
            $out['nace_code'] = $this->text($inputs['nace_code'] ?? '', 10);
            // Účet pro vrácení přeplatku (VetaNP) — volba poplatníka místo tichého výběru
            // prvního aktivního CZK účtu; viz DppoReturnDataProvider::pickBankAccount().
            // null = žádná explicitní volba, ponechá se dosavadní výchozí chování.
            $rawBankAccountId = $inputs['bank_account_id'] ?? null;
            $out['bank_account_id'] = ($rawBankAccountId !== null && $rawBankAccountId !== '' && is_numeric($rawBankAccountId))
                ? (int) $rawBankAccountId : null;
            // Žádost o předání Přílohy účetní závěrky do sbírky listin (VetaUZ/pr11_puz) —
            // výchozí ANO (rozhodnutí zadavatele 31. 8. 2026, viz DppoXmlBuilder::buildVetaUZ),
            // ruční vstup umožňuje vypnout.
            $out['puz_to_registry'] = filter_var($inputs['puz_to_registry'] ?? true, FILTER_VALIDATE_BOOLEAN);
        } else {
            // DPFO — sekce §6/§8/§9/§10 (typované) + zálohy pojistného (pro přehledy DP4).
            $s6 = (array) ($inputs['s6_employment'] ?? []);
            $out['s6_employment'] = [
                'income' => $this->money($s6['income'] ?? 0),
                'withholding' => $this->money($s6['withholding'] ?? 0),
            ];
            $s8 = (array) ($inputs['s8_capital'] ?? []);
            $out['s8_capital'] = ['base' => $this->money($s8['base'] ?? 0)];
            $s9 = (array) ($inputs['s9_rental'] ?? []);
            $out['s9_rental'] = [
                'income' => $this->money($s9['income'] ?? 0),
                'expenses' => $this->money($s9['expenses'] ?? 0),
                // Způsob uplatnění výdajů (§ 9 odst. 4) — jde do `vyd9proc` v podání.
                // Neuloženou hodnotu bere přiznání jako skutečné výdaje, což je stav
                // před zavedením volby.
                'expense_mode' => ($s9['expense_mode'] ?? '') === 'pausal' ? 'pausal' : 'actual',
            ];
            $s10 = (array) ($inputs['s10_other'] ?? []);
            $out['s10_other'] = [
                'income' => $this->money($s10['income'] ?? 0),
                'expenses' => $this->money($s10['expenses'] ?? 0),
            ];
            $out['s10_items'] = $this->section10Items($inputs['s10_items'] ?? []);
            // Samostatný základ daně §16a (zahraniční podíly na zisku, sazba 15 %) —
            // {@see DpfoReturnCalculator::compute()} ho čte, whitelist ho neměl, takže
            // volba podle §16a odst. 1 se uložením ztratila. Do XML se nezapisuje
            // (chybí atributy v XSD), kalkulátor na to upozorňuje varováním.
            $out['s16a_separate_base'] = $this->money($inputs['s16a_separate_base'] ?? 0);
            $out['social_paid_advances'] = $this->money($inputs['social_paid_advances'] ?? 0);
            $out['health_paid_advances'] = $this->money($inputs['health_paid_advances'] ?? 0);
            // Odečet daňové ztráty minulých let §34 (ř. 44) — ruční vstup, návrh FIFO z evidence.
            $out['loss_carryforward'] = $this->money($inputs['loss_carryforward'] ?? 0);
            // §23 ruční položky (Fáze E nález N1) — u FO se použijí jen pro accounting_mode=
            // double_entry (§7 z VH, DpfoReturnDataProvider::vhBase); pro paušál/kasovou bázi
            // se ignorují (žádný VH základ, na který by se dalo navázat).
            $out['manual_increase_items'] = $this->items($inputs['manual_increase_items'] ?? []);
            $out['manual_decrease_items'] = $this->items($inputs['manual_decrease_items'] ?? []);
        }

        // Opravné/dodatečné: důvody podání (§141 DŘ). Dodatečné: poslední známá daň + datum zjištění.
        if ($variant === 'opravne' || $variant === 'dodatecne') {
            $out['amend_reason'] = $this->text($inputs['amend_reason'] ?? '', 2000);
        }
        if ($variant === 'dodatecne') {
            if (isset($inputs['last_known_tax']) && $inputs['last_known_tax'] !== '' && $inputs['last_known_tax'] !== null) {
                $out['last_known_tax'] = $this->money($inputs['last_known_tax']);
            }
            $out['d_zjist'] = $this->date($inputs['d_zjist'] ?? '');
        }

        return $out;
    }

    /**
     * Ruční položky §23 (a dary §20/8) — text + částka, u položek §23 navíc explicitní
     * druh `kind`.
     *
     * `kind` se dřív zahazoval, přestože {@see DppoReturnCalculator::isFlatRateTravelItem()}
     * mu dává PŘEDNOST před rozpoznáváním podle textu. Zůstávala tak funkční jen ta
     * heuristika, kterou má `kind` nahradit — a kalkulátor uživatele vyzýval, ať položku
     * označí, což ale nešlo natrvalo udělat. Propouští se jen ZNÁMÝ druh: neznámý řetězec
     * by heuristiku vypnul, aniž by ji cokoli nahradilo.
     *
     * @param mixed $items
     * @return list<array{text:string,amount:float,kind?:string}>
     */
    private function items(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $amount = $this->money($item['amount'] ?? 0);
            $text = $this->text($item['text'] ?? '', 255);
            if ($amount === 0.0 && $text === '') {
                continue;
            }
            $row = ['text' => $text, 'amount' => $amount];
            if ($this->text($item['kind'] ?? '', 30) === DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL) {
                $row['kind'] = DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL;
            }
            $out[] = $row;
        }
        return array_slice($out, 0, 200);
    }

    /** @return list<array{kind_code:string,text:string,income:float,expenses:float,evidence_ref:string}> */
    private function section10Items(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $income = $this->money($item['income'] ?? 0);
            $expenses = $this->money($item['expenses'] ?? 0);
            $kind = $this->text($item['kind_code'] ?? '', 30);
            // `kind` je historický název pole z formuláře: ten posílal popis druhu
            // příjmu pod klíčem, který se tady nikdy nečetl, takže se text při uložení
            // tiše zahodil. Formulář posílá `text`, tohle je pojistka pro rozeditované
            // koncepty. `kind_code` je písmenný číselník druhu příjmu, který aplikace
            // neeviduje — nechává se prázdný a Příloha č. 2 na to upozorní.
            $text = $this->text($item['text'] ?? ($item['kind'] ?? ''), 255);
            if ($income === 0.0 && $expenses === 0.0 && $kind === '' && $text === '') {
                continue;
            }
            $out[] = [
                'kind_code' => $kind,
                'text' => $text,
                'income' => $income,
                'expenses' => $expenses,
                'evidence_ref' => $this->text($item['evidence_ref'] ?? '', 190),
            ];
        }
        return array_slice($out, 0, 200);
    }

    /** Nezáporná částka zaokrouhlená na haléře, shora omezená (kc_ii* XSD má totalDigits=14). */
    private function money(mixed $v): float
    {
        return min(1_000_000_000_000.0, max(0.0, round((float) $v, 2)));
    }

    private function text(mixed $v, int $max): string
    {
        return mb_substr(trim((string) $v), 0, $max);
    }

    /** Normalizuje datum na ISO YYYY-MM-DD; prázdné/neplatné → ''. */
    private function date(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        return $d === false ? '' : $d->format('Y-m-d');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicRow(array $row): array
    {
        return [
            'year' => (int) $row['year'],
            'type' => (string) $row['taxpayer_type'],
            'variant' => (string) ($row['variant'] ?? 'radne'),
            'variant_seq' => (int) ($row['variant_seq'] ?? 1),
            'status' => (string) $row['status'],
            'row_version' => (int) $row['row_version'],
            'inputs' => (array) $row['inputs'],
            'last_submission_id' => $row['last_submission_id'] ?? null,
            'final_snapshot_id' => $row['final_snapshot_id'] ?? null,
            'finalized_at' => $row['finalized_at'] ?? null,
            'finalized_by' => $row['finalized_by'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function snapshotMetadata(int $supplierId, int $snapshotId): ?array
    {
        $snapshot = $this->returns->snapshot($supplierId, $snapshotId);
        if ($snapshot === null) {
            return null;
        }
        return [
            'id' => $snapshot['id'],
            'revision_no' => $snapshot['revision_no'],
            'source_sha256' => $snapshot['source_sha256'],
            'xml_sha256' => $snapshot['xml_sha256'],
            'business_status' => $snapshot['business_status'],
            'finalized_at' => $snapshot['finalized_at'],
            'finalized_by' => $snapshot['finalized_by'],
        ];
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new TaxReturnException('invalid_type', 'Neplatný typ přiznání (fo|po).', 400);
        }
    }

    private function assertSupplierType(int $supplierId, string $type): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $actual = $stmt->fetchColumn();
        if ($actual === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        $actual = strtolower(trim((string) $actual));
        if ($actual !== '' && $actual !== $type) {
            throw new TaxReturnException('taxpayer_type_mismatch', 'Typ přiznání neodpovídá typu poplatníka firmy.', 422);
        }
    }

    private function assertVariant(string $variant): void
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new TaxReturnException('invalid_variant', 'Neplatný druh přiznání (radne|opravne|dodatecne).', 400);
        }
    }

    /**
     * Pořadí přiznání (E8): řádné/opravné mají vždy 1. Dodatečné 1..N — smí se
     * zobrazit/založit jen existující pořadí nebo bezprostředně následující (max+1),
     * aby nevznikaly díry v řetězu dodatečných přiznání (§141 DŘ).
     */
    private function resolveSeq(int $supplierId, int $year, string $type, string $variant, int $requestedSeq): int
    {
        if ($variant !== 'dodatecne') {
            return 1;
        }
        $max = $this->returns->maxVariantSeq($supplierId, $year, $type, 'dodatecne');
        if ($requestedSeq <= 0) {
            // Auto: zobraz poslední existující dodatečné; když žádné není, založ č. 1.
            return max(1, $max);
        }
        if ($requestedSeq > $max + 1) {
            throw new TaxReturnException(
                'invalid_variant_seq',
                sprintf('Dodatečné přiznání č. %d nelze založit — nejdřív podejte předchozí (poslední existující č. %d).', $requestedSeq, $max),
                400,
            );
        }
        return $requestedSeq;
    }

    /**
     * E10 — předfinalizační kontrolní checklist (bez perzistence, pro FE panel).
     *
     * @return array<string,mixed>
     */
    public function prefinalizeCheck(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1): array
    {
        $this->assertType($type);
        $this->assertSupplierType($supplierId, $type);
        $this->assertVariant($variant);
        $seq = $this->resolveSeq($supplierId, $year, $type, $variant, $variantSeq);
        $row = $this->returns->find($supplierId, $year, $type, $variant, $seq);
        $inputs = $row !== null ? (array) $row['inputs'] : [];
        $computation = $this->compute($supplierId, $year, $type, $inputs, $variant);
        return $this->preFinalizeChecks->run($supplierId, $year, $type, $inputs, $computation);
    }

    /** @return array<string,mixed> */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.taxpayer_type, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.phone, s.email,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        return $row;
    }

    /** Supplier row s identifikátory pro ČSSZ přehled OSVČ (vsdp/dep, datovka, kontakty). */
    private function loadSupplierCssz(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.dic, s.email, s.phone, s.data_box_id,
                    s.street_number_pop, s.street_number_orient,
                    s.cssz_vsdp, s.cssz_ossz_code, s.health_insurance_number,
                    s.opr_jmeno, s.opr_prijmeni
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        return $row;
    }

    /** Verze pro `verzeSW` v podání — SSOT je {@see EpoEnvelope::appVersion}. */
    private function loadAppVersion(): ?string
    {
        return EpoEnvelope::appVersion();
    }
}

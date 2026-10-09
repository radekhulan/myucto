<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;

/**
 * AssetService — CRUD + lifecycle majetkových karet (Epic F3, §3.3).
 *
 * Validační matice create/update (422, česky), zámky R13 (tax_* a input_price po
 * prvním potvrzeném daňovém řádku; input_price/data po zařazení), lifecycle
 * v transakcích: putIntoUse (MD 02x / D 042, idempotence ('asset', id)),
 * TZ §33 (R15), dispose (R19: odpis roku → daňový řádek → disposal zápis
 * ('asset_disposal', id) → status), revert (R24, PostingService::reverse),
 * přerušení §26/8 (R14). Warningy neblokují (R15/R23/R25).
 */
final class AssetService
{
    /** R18 — default oprávkový účet dle majetkového (3znakový syntetický prefix). */
    private const ACCUMULATED_MAP = [
        '012' => '072', '013' => '073', '014' => '074', '015' => '075', '019' => '079',
        '021' => '081', '022' => '082', '025' => '085', '026' => '086', '029' => '089',
        '031' => null, '032' => null,
    ];

    /** R19 — kontace nákladové strany vyřazení + fallback účty. */
    private const DISPOSAL_RULES = [
        'sold' => ['asset.disposal.sold.residual', '541'],
        'liquidated' => ['asset.disposal.liquidated.residual', '551'],
        'donated' => ['asset.disposal.donated.residual', '543'],
        'damaged' => ['asset.disposal.damaged.residual', '549'],
    ];

    private const TAX_METHODS_TANGIBLE = ['straight', 'accelerated', 'extraordinary', 'none'];
    private const TAX_METHODS_INTANGIBLE = ['by_accounting', 'none'];
    private const FIRST_YEAR_INCREASES = ['none', 'p10', 'p15', 'p20'];
    /** § 28 ZDP — právní důvod odpisování. */
    private const DEPRECIATOR_GROUNDS = ['owner', 'lessee_improvement', 'co_owner', 'legal_successor'];
    private const ACC_METHODS = ['straight_line', 'by_tax'];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly DepreciationEntryRepository $entries,
        private readonly DepreciationCalculator $calculator,
        private readonly DepreciationPostingService $depreciationPosting,
        private readonly AccountingPeriodRepository $periods,
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $chart,
        private readonly JournalEntryRepository $journal,
        private readonly TaxConstantsRepository $constants,
        private readonly DisposalResiduals $residuals,
    ) {}

    // ── CRUD ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function create(int $supplierId, array $data, array $meta = []): array
    {
        $card = $this->normalize($data, null);
        [$card, $warnings] = $this->validateCard($supplierId, $card, true);
        $card['created_by'] = $meta['user_id'] ?? null;

        try {
            $id = $this->assets->insert($supplierId, $card);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new AssetException(
                    'duplicate_inventory_number',
                    'Inventární číslo "' . $card['inventory_number'] . '" už ve firmě existuje.',
                );
            }
            throw $e;
        }

        $asset = $this->get($supplierId, $id);
        return ['asset' => $asset, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $data
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function update(int $supplierId, int $id, array $data, array $meta = []): array
    {
        $existing = $this->assets->find($supplierId, $id);
        if ($existing === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if ($existing['status'] === 'disposed') {
            throw new AssetException('asset_locked', 'Vyřazený majetek nelze upravovat — nejdřív vraťte vyřazení.');
        }

        // stav a vyřazení se mění výhradně lifecycle metodami, ne přes update.
        // `sale_invoice_id` patří do TÉŽE skupiny (píše ho jen dispose()/revertDisposal()),
        // ale ve výčtu chybělo — a protože normalize() staví kartu nad `SELECT *` existující
        // řádky, prošlo z těla requestu do whitelistu AssetRepository::COLUMNS bez jediné
        // kontroly vlastnictví. Cizí `invoices.id` se tak dal natrvalo zapsat na vlastní
        // kartu (CWE-639, externí report 2026-08 R2 — třída, kterou sweep u /assets minul).
        // Stejně vazba vyřazení na zápis deníku a účet souhrnné karty: píší je jen
        // disposeFromJournal() a AccountSummaryCardService.
        unset(
            $data['status'],
            $data['disposal_date'],
            $data['disposal_type'],
            $data['disposal_price'],
            $data['sale_invoice_id'],
            $data['disposal_entry_id'],
            $data['summary_account_code'],
        );
        $card = $this->normalize($data, $existing);
        $this->assertLocks($existing, $card, $data);
        [$card, $warnings] = $this->validateCard($supplierId, $card, false);

        unset($card['created_by']);
        try {
            $this->assets->update($supplierId, $id, $card);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new AssetException(
                    'duplicate_inventory_number',
                    'Inventární číslo "' . $card['inventory_number'] . '" už ve firmě existuje.',
                );
            }
            throw $e;
        }

        return ['asset' => $this->get($supplierId, $id), 'warnings' => $warnings];
    }

    /**
     * Karta + TZ + řádky odpisů + zámky (R13) + souhrn cen.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $supplierId, int $id): ?array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            return null;
        }
        $improvements = $this->assets->improvements($id);
        $entries = $this->entries->forAsset($id);

        $impTotal = 0.0;
        foreach ($improvements as $imp) {
            $impTotal += (float) $imp['amount'];
        }
        $taxFull = 0.0;
        $accAmount = 0.0;
        foreach ($entries as $e) {
            if ($e['kind'] === 'tax') {
                $taxFull += (float) $e['full_amount'];
            } else {
                $accAmount += (float) $e['amount'];
            }
        }
        $increased = round((float) $asset['input_price'] + $impTotal, 2);

        $asset['improvements'] = $improvements;
        $asset['entries'] = $entries;
        $asset['improvements_total'] = round($impTotal, 2);
        $asset['increased_input_price'] = $increased;
        $asset['tax_residual'] = round($increased - (float) $asset['opening_tax_amount'] - $taxFull, 2);
        $asset['acc_residual'] = round($increased - (float) $asset['opening_acc_amount'] - $accAmount, 2);
        $asset['accumulated_depreciation'] = round((float) $asset['opening_acc_amount'] + $accAmount, 2);
        $asset['locked'] = [
            'tax_params' => $this->entries->existsAnyTax($id),
            'in_use' => $asset['status'] !== 'draft',
        ];
        return $asset;
    }

    /**
     * Smaže koncept nebo chybně zařazenou kartu bez materializovaných odpisů.
     * Případný zápis zařazení se odstraní ve stejné transakci; uzavřené či
     * uzamčené období a navazující účetní historie zůstávají nedotknutelné.
     *
     * @return array{status:string, inventory_number:string, activation_entry_id:?int}
     */
    public function delete(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $asset = $this->assets->findForUpdate($supplierId, $id);
            if ($asset === null) {
                throw new AssetException('not_found', 'Majetek nenalezen.', 404);
            }
            if ($asset['status'] === 'disposed') {
                throw new AssetException(
                    'invalid_status',
                    'Vyřazený majetek nelze smazat — nejdřív vraťte vyřazení.',
                    409,
                );
            }

            $depreciation = $pdo->prepare(
                'SELECT id FROM depreciation_entries
                  WHERE supplier_id = ? AND asset_id = ?
                  LIMIT 1 FOR UPDATE'
            );
            $depreciation->execute([$supplierId, $id]);
            if ($depreciation->fetchColumn() !== false) {
                throw new AssetException(
                    'asset_has_depreciation',
                    'Karta má potvrzené nebo zaúčtované odpisy — nejdřív smažte zaúčtování odpisů odzadu a zrušte případná přerušení.',
                    409,
                );
            }

            $improvements = $pdo->prepare(
                'SELECT id FROM asset_improvements
                  WHERE supplier_id = ? AND asset_id = ?
                  LIMIT 1 FOR UPDATE'
            );
            $improvements->execute([$supplierId, $id]);
            if ($improvements->fetchColumn() !== false) {
                throw new AssetException(
                    'asset_has_improvements',
                    'Karta má technická zhodnocení — před smazáním je odeberte.',
                    409,
                );
            }

            $activation = $pdo->prepare(
                "SELECT id, period_id, entry_date, reversed_by
                   FROM journal_entries
                  WHERE supplier_id = ? AND source_type = 'asset' AND source_id = ?
                  ORDER BY id DESC
                  LIMIT 1
                  FOR UPDATE"
            );
            $activation->execute([$supplierId, $id]);
            $activationEntry = $activation->fetch(\PDO::FETCH_ASSOC);
            $activationEntryId = null;

            if ($activationEntry !== false) {
                $activationEntryId = (int) $activationEntry['id'];
                $period = $this->periods->findForUpdate((int) $activationEntry['period_id'], $supplierId);
                if ($period === null || $period['status'] !== 'open') {
                    throw new AssetException(
                        'period_not_open',
                        'Zařazení je v uzavřeném účetním období — kartu nelze smazat.',
                        409,
                    );
                }
                if ($activationEntry['reversed_by'] !== null) {
                    throw new AssetException(
                        'entry_has_reversal',
                        'Zápis zařazení už byl stornován — kartu nelze smazat bez porušení účetní historie.',
                        409,
                    );
                }

                $lock = $pdo->prepare(
                    'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE'
                );
                $lock->execute([$supplierId]);
                $lockedUntil = $lock->fetchColumn();
                if ($lockedUntil !== false && $lockedUntil !== null
                    && (string) $activationEntry['entry_date'] <= (string) $lockedUntil
                ) {
                    throw new AssetException(
                        'date_locked',
                        'Datum zařazení spadá do uzamčené části účetnictví.',
                        409,
                    );
                }

                $attachments = $pdo->prepare(
                    'SELECT id FROM journal_entry_attachments
                      WHERE supplier_id = ? AND entry_id = ?
                      LIMIT 1 FOR UPDATE'
                );
                $attachments->execute([$supplierId, $activationEntryId]);
                if ($attachments->fetchColumn() !== false) {
                    throw new AssetException(
                        'entry_has_attachments',
                        'Zápis zařazení má přílohy — před smazáním je odeberte v účetním deníku.',
                        409,
                    );
                }

                $deletedEntry = $pdo->prepare(
                    'DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?'
                );
                $deletedEntry->execute([$activationEntryId, $supplierId]);
                if ($deletedEntry->rowCount() !== 1) {
                    throw new \RuntimeException('Zápis zařazení se nepodařilo smazat.');
                }
            }

            $this->assets->delete($supplierId, $id);
            $pdo->prepare(
                "DELETE FROM sample_data_entries
                  WHERE supplier_id = ? AND entity_type = 'asset' AND entity_id = ?"
            )->execute([$supplierId, $id]);

            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'status' => (string) $asset['status'],
                'inventory_number' => (string) $asset['inventory_number'],
                'activation_entry_id' => $activationEntryId,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }
    }

    // ── lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Zařazení do užívání: status in_use + volitelný zápis MD 02x / D 042 (R3, R23).
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function putIntoUse(int $supplierId, int $id, string $date, bool $bookEntry, array $meta): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if ($asset['status'] !== 'draft') {
            throw new AssetException('invalid_status', 'Do užívání lze zařadit jen kartu ve stavu koncept.');
        }
        if (!self::isDate($date)) {
            throw new AssetException('validation_failed', 'Neplatné datum zařazení (YYYY-MM-DD).');
        }
        if ($date < (string) $asset['acquisition_date']) {
            throw new AssetException('validation_failed', 'Datum zařazení nesmí předcházet datu pořízení.');
        }

        $warnings = [];
        $taxEvidence = $this->isTaxEvidence($supplierId);
        if ($taxEvidence) {
            // Daňová evidence nemá deník: zařazení je jen evidenční údaj karty.
            $bookEntry = false;
        } elseif (!$bookEntry) {
            $period = $this->periods->findForDate($supplierId, $date);
            if ($period !== null && $period['status'] === 'open') {
                $warnings[] = [
                    'code' => 'no_entry_in_open_period',
                    'message' => 'Datum zařazení spadá do otevřeného účetního období, ale zápis o zařazení se neúčtuje (historický majetek R23) — zůstatky 02x musí přijít počátečními stavy.',
                ];
            }
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->assets->update($supplierId, $id, ['status' => 'in_use', 'put_into_use_date' => $date]);

            if ($bookEntry) {
                $period = $this->periods->ensureOpenPeriodFor($supplierId, $date);
                if ($period['status'] !== 'open') {
                    throw new AssetException(
                        'period_not_open',
                        'Účetní období ' . $period['fiscal_year'] . ' je "' . $period['status'] . '" — zařazení nelze zaúčtovat.',
                    );
                }
                $amount = (float) $asset['input_price'];
                foreach ($this->assets->improvements($id) as $imp) {
                    if ((string) $imp['completed_on'] <= $date) {
                        $amount += (float) $imp['amount'];
                    }
                }
                $amount = round($amount, 2);
                $this->posting->postDocument($supplierId, 'asset', $id, [
                    ['account_code' => (string) $asset['asset_account_code'], 'side' => 'debit', 'amount' => $amount],
                    ['account_code' => (string) $asset['acquisition_account_code'], 'side' => 'credit', 'amount' => $amount],
                ], [
                    'entry_date' => $date,
                    'document_no' => (string) $asset['inventory_number'],
                    'description' => 'Zařazení majetku ' . $asset['name'],
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        return ['asset' => $this->get($supplierId, $id), 'warnings' => $warnings];
    }

    /**
     * Technické zhodnocení §33 (R15). Zaúčtování 02x/042 TZ dělá uživatel ručním
     * zápisem — v F3 se TZ promítá jen do odpisů a PC při vyřazení.
     *
     * @param array{completed_on?:mixed, amount?:mixed, description?:?string, purchase_invoice_id?:mixed} $data
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{improvement: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function addImprovement(int $supplierId, int $id, array $data, array $meta = []): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if ($asset['status'] !== 'in_use') {
            throw new AssetException('invalid_status', 'Technické zhodnocení lze přidat jen k majetku v užívání.');
        }
        if ($asset['tax_method'] === 'extraordinary') {
            throw new AssetException(
                'improvement_not_allowed_30a',
                'TZ nezvyšuje vstupní cenu majetku odpisovaného dle §30a — založte pro TZ samostatnou kartu (odpisuje se samostatně jako HM ve skupině 2).',
            );
        }
        $completedOn = (string) ($data['completed_on'] ?? '');
        if (!self::isDate($completedOn)) {
            throw new AssetException('validation_failed', 'Neplatné datum dokončení TZ (YYYY-MM-DD).');
        }
        if ($completedOn < (string) $asset['put_into_use_date']) {
            throw new AssetException('validation_failed', 'Datum dokončení TZ nesmí předcházet datu zařazení do užívání.');
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new AssetException('validation_failed', 'Částka TZ musí být kladná.');
        }
        $year = $this->fiscalYearOf($supplierId, $completedOn);
        $this->assertTaxEvidenceYearOpenIfTaxEvidence($supplierId, $year);
        $this->assertYearNotConfirmed($id, $year);
        $this->assertNoLaterConfirmedYear($id, $year);
        $piId = isset($data['purchase_invoice_id']) && $data['purchase_invoice_id'] !== null
            ? (int) $data['purchase_invoice_id']
            : null;
        $this->assertPurchaseInvoiceOwned($supplierId, $piId);

        $impId = $this->assets->insertImprovement($supplierId, $id, [
            'completed_on' => $completedOn,
            'amount' => $amount,
            'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'purchase_invoice_id' => $piId,
        ]);

        $warnings = [];
        $yearTotal = 0.0;
        foreach ($this->assets->improvements($id) as $imp) {
            if ($this->fiscalYearOf($supplierId, (string) $imp['completed_on']) === $year) {
                $yearTotal += (float) $imp['amount'];
            }
        }
        $assetLimit = (float) ($this->constants->forYear($year)['fixed_asset_limit'] ?? 80000);
        if (round($yearTotal, 2) <= $assetLimit) {
            $warnings[] = [
                'code' => 'tz_below_80k',
                'message' => 'Úhrn TZ za rok ' . $year . ' nepřesahuje ' . number_format($assetLimit, 0, ',', ' ') . ' Kč — nejde o povinné TZ dle §33, zvažte jednorázový náklad.',
            ];
        }

        return ['improvement' => $this->assets->findImprovement($supplierId, $impId), 'warnings' => $warnings];
    }

    public function deleteImprovement(int $supplierId, int $id, int $improvementId): void
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        $imp = $this->assets->findImprovement($supplierId, $improvementId);
        if ($imp === null || $imp['asset_id'] !== $id) {
            throw new AssetException('not_found', 'Technické zhodnocení nenalezeno.', 404);
        }
        $impYear = $this->fiscalYearOf($supplierId, (string) $imp['completed_on']);
        $this->assertTaxEvidenceYearOpenIfTaxEvidence($supplierId, $impYear);
        $this->assertYearNotConfirmed($id, $impYear);
        $this->assertNoLaterConfirmedYear($id, $impYear);
        $this->assets->deleteImprovement($supplierId, $improvementId);
    }

    /**
     * Vyřazení (R19) — atomicky: účetní odpis roku do měsíce vyřazení, daňový
     * řádek (půlodpis §26/7 / §30a měsíce / by_accounting zrcadlo), disposal
     * zápis ('asset_disposal', id), status disposed.
     *
     * @param array{date?:mixed, type?:mixed, price?:mixed} $data
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function dispose(int $supplierId, int $id, array $data, array $meta): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        [$date, $type, $price, $saleInvoiceId] = $this->disposalInput($supplierId, $asset, $data);
        if ($this->isTaxEvidence($supplierId)) {
            return $this->disposeTaxEvidence($supplierId, $id, $date, $type, $price, $saleInvoiceId);
        }

        $period = $this->periods->ensureOpenPeriodFor($supplierId, $date);
        if ($period['status'] !== 'open') {
            throw new AssetException(
                'period_not_open',
                'Účetní období ' . $period['fiscal_year'] . ' je "' . $period['status'] . '" — vyřazení nelze zaúčtovat.',
            );
        }

        $year = (int) $period['fiscal_year']; // label období vyřazení (hospodářský rok)
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $lockedAsset = $this->assets->findForUpdate($supplierId, $id);
            if ($lockedAsset === null) {
                throw new AssetException('not_found', 'Majetek nenalezen.', 404);
            }
            if ($lockedAsset['status'] !== 'in_use') {
                throw new AssetException('invalid_status', 'Vyřadit lze jen majetek v užívání.');
            }
            $asset = $lockedAsset;

            // kontext s datem vyřazení (karta ho v DB ještě nemá)
            $assetView = $asset;
            $assetView['disposal_date'] = $date;
            $ctx = $this->depreciationPosting->buildContext($assetView);
            $this->assertDisposalChronology($supplierId, $asset, $ctx, $year);

            // 1) účetní odpis roku vyřazení do měsíce vyřazení (včetně) — R6
            if ($asset['accumulated_account_code'] !== null) {
                $accRow = $this->calculator->accountingYearRow(
                    $ctx,
                    $year,
                    (string) $asset['acc_method'],
                    (string) $asset['tax_method'],
                );
                if ($accRow !== null && round((float) $accRow['amount'], 2) > 0.0) {
                    $this->depreciationPosting->postAccountingEntry($supplierId, $asset, $accRow, $date, $meta);
                }
            }

            // 2) daňový řádek roku vyřazení (půlodpis / §30a / by_accounting); pauza i ručně
            //    přepsaný odpis roku se nechají být
            $existingTax = $this->entries->findYear($id, 'tax', $year);
            if ($existingTax === null || (!$existingTax['is_paused'] && !DepreciationEntryRepository::isOverridden($existingTax))) {
                $taxRow = $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $year);
                if ($taxRow !== null) {
                    $this->entries->upsert([
                        'supplier_id' => $supplierId,
                        'asset_id' => $id,
                        'kind' => 'tax',
                        'fiscal_year' => $year,
                        'amount' => (float) $taxRow['amount'],
                        'full_amount' => (float) $taxRow['full_amount'],
                        'residual_value_end' => (float) $taxRow['residual_end'],
                        'is_paused' => (bool) $taxRow['is_paused'],
                        'is_half' => (bool) $taxRow['is_half'],
                        'months_count' => $taxRow['months_count'] ?? null,
                        'detail' => isset($taxRow['months']) && $taxRow['months'] !== null
                            ? json_encode($taxRow['months'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'status' => 'confirmed',
                    ]);
                }
            }

            // 3) disposal zápis: doodepsání účetní ZC + vyřazení z evidence v (zvýšené) PC
            $impTotal = 0.0;
            foreach ($this->assets->improvements($id) as $imp) {
                $impTotal += (float) $imp['amount'];
            }
            $increasedPc = round((float) $asset['input_price'] + $impTotal, 2);

            [$ruleKey, $fallback] = self::DISPOSAL_RULES[$type];
            $rule = $this->rules->resolve($supplierId, $ruleKey);
            $expense = $rule['debit_account_code'] ?? $fallback;

            $lines = [];
            if ($asset['accumulated_account_code'] === null) {
                // neodpisovaný (§27, R17): bez oprávek, jeden pár v pořizovací ceně
                $lines[] = ['account_code' => $expense, 'side' => 'debit', 'amount' => $increasedPc];
                $lines[] = ['account_code' => (string) $asset['asset_account_code'], 'side' => 'credit', 'amount' => $increasedPc];
            } else {
                $accumulatedDep = (float) $asset['opening_acc_amount'];
                foreach ($this->entries->forAsset($id) as $e) {
                    if ($e['kind'] === 'accounting') {
                        $accumulatedDep += (float) $e['amount'];
                    }
                }
                $accZc = DisposalResiduals::bookResidual($increasedPc, $accumulatedDep, true);
                if ($accZc > 0) {
                    $lines[] = ['account_code' => $expense, 'side' => 'debit', 'amount' => $accZc];
                    $lines[] = ['account_code' => (string) $asset['accumulated_account_code'], 'side' => 'credit', 'amount' => $accZc];
                }
                $lines[] = ['account_code' => (string) $asset['accumulated_account_code'], 'side' => 'debit', 'amount' => $increasedPc];
                $lines[] = ['account_code' => (string) $asset['asset_account_code'], 'side' => 'credit', 'amount' => $increasedPc];
            }

            $this->posting->postDocument($supplierId, 'asset_disposal', $id, $lines, [
                'entry_date' => $date,
                'document_no' => (string) $asset['inventory_number'],
                'description' => 'Vyřazení majetku ' . $asset['name'],
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]);

            // 4) karta
            $this->assets->update($supplierId, $id, [
                'disposal_date' => $date,
                'disposal_type' => $type,
                'disposal_price' => $price,
                'sale_invoice_id' => $saleInvoiceId,
                'disposal_entry_id' => null,
                'status' => 'disposed',
            ]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        $warnings = [];
        if ($type === 'sold') {
            $warnings[] = [
                'code' => 'sale_invoice_641',
                'message' => 'Tržba z prodeje se z karty neúčtuje (R20) — vystavte fakturu s výnosem 641 (+ DPH).',
            ];
        }
        return ['asset' => $this->get($supplierId, $id), 'warnings' => array_merge($warnings, self::disposalTypeWarnings($type))];
    }

    /**
     * Vyřazení, které už zaúčtoval deník (převod z jiného programu, ruční zápis 54x proti
     * oprávkám): karta se vyřadí BEZ zaúčtování. Zůstatková cena ani účetní odpis roku se
     * znovu neúčtují, jinak by byly v deníku dvakrát. Karta se naváže na zápis, který
     * vyřazení zaúčtoval (`entry_id`, jinak jediný zápis ke dni vyřazení s MD 54x proti
     * účtu karty, {@see DisposalResiduals::journalEntriesFor()}); z něj přiznání bere účetní
     * zůstatkovou cenu. Daňový odpis roku vyřazení se potvrdí jako u vyřazení v modulu,
     * existující řádek převodu, pauza i ruční přepis zůstávají.
     *
     * @param array{date?:mixed, type?:mixed, price?:mixed, sale_invoice_id?:mixed, entry_id?:mixed} $data
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function disposeFromJournal(int $supplierId, int $id, array $data, array $meta = []): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if ($asset['status'] !== 'in_use') {
            throw new AssetException('invalid_status', 'Vyřadit lze jen majetek v užívání.');
        }
        [$date, $type, $price, $saleInvoiceId] = $this->disposalInput($supplierId, $asset, $data);
        if ($this->isTaxEvidence($supplierId)) {
            return $this->disposeTaxEvidence($supplierId, $id, $date, $type, $price, $saleInvoiceId);
        }

        $period = $this->periods->findForDate($supplierId, $date);
        if ($period === null || $period['status'] !== 'open') {
            throw new AssetException(
                'period_not_open',
                'Účetní období data vyřazení ' . $date . ' není otevřené, vyřazení nelze zaznamenat.',
            );
        }

        $account = DisposalResiduals::residualCreditAccount($asset);
        $warnings = [];
        $entryId = isset($data['entry_id']) && $data['entry_id'] !== null && $data['entry_id'] !== '' ? (int) $data['entry_id'] : null;
        if ($entryId !== null) {
            if (!$this->residuals->isJournalDisposalEntry($supplierId, $entryId, $account)) {
                throw new AssetException(
                    'disposal_entry_mismatch',
                    'Zápis deníku č. ' . $entryId . ' vyřazení této karty nezaúčtoval: čeká se zaúčtovaný zápis s MD 54x proti účtu ' . $account . '.',
                    422,
                );
            }
        } else {
            $candidates = $this->residuals->journalEntriesFor($supplierId, $date, $account);
            if (count($candidates) === 1) {
                $entryId = $candidates[0];
            } else {
                $warnings[] = [
                    'code' => $candidates === [] ? 'disposal_entry_not_found' : 'disposal_entry_ambiguous',
                    'message' => ($candidates === []
                        ? 'V deníku ke dni ' . $date . ' není zápis s MD 54x proti účtu ' . $account . '.'
                        : 'V deníku ke dni ' . $date . ' je více zápisů s MD 54x proti účtu ' . $account . ' (' . implode(', ', $candidates) . ').')
                        . ' Karta se na zápis vyřazení nenavázala; účetní zůstatková cena se v přiznání vezme z karty.',
                ];
            }
        }

        $year = (int) $period['fiscal_year'];
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $lockedAsset = $this->assets->findForUpdate($supplierId, $id);
            if ($lockedAsset === null || $lockedAsset['status'] !== 'in_use') {
                throw new AssetException('invalid_status', 'Vyřadit lze jen majetek v užívání.');
            }
            $assetView = $lockedAsset;
            $assetView['disposal_date'] = $date;
            $ctx = $this->depreciationPosting->buildContext($assetView);
            $this->assertDisposalChronology($supplierId, $lockedAsset, $ctx, $year);

            $existingTax = $this->entries->findYear($id, 'tax', $year);
            if ($existingTax === null) {
                $taxRow = $this->calculator->taxYearRow($ctx, (string) $lockedAsset['tax_method'], $year);
                if ($taxRow !== null) {
                    $this->entries->upsert([
                        'supplier_id' => $supplierId,
                        'asset_id' => $id,
                        'kind' => 'tax',
                        'fiscal_year' => $year,
                        'amount' => (float) $taxRow['amount'],
                        'full_amount' => (float) $taxRow['full_amount'],
                        'residual_value_end' => (float) $taxRow['residual_end'],
                        'is_paused' => (bool) $taxRow['is_paused'],
                        'is_half' => (bool) $taxRow['is_half'],
                        'months_count' => $taxRow['months_count'] ?? null,
                        'detail' => isset($taxRow['months']) && $taxRow['months'] !== null
                            ? json_encode($taxRow['months'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'status' => 'confirmed',
                    ]);
                }
            }

            $this->assets->update($supplierId, $id, [
                'disposal_date' => $date,
                'disposal_type' => $type,
                'disposal_price' => $price,
                'sale_invoice_id' => $saleInvoiceId,
                'disposal_entry_id' => $entryId,
                'status' => 'disposed',
            ]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        return ['asset' => $this->get($supplierId, $id), 'warnings' => array_merge($warnings, self::disposalTypeWarnings($type))];
    }

    /**
     * Vyřazení v daňové evidenci: bez deníku. Potvrdí daňový odpis roku vyřazení
     * (§ 26 odst. 7 ZDP polovina ročního odpisu, § 30a měsíce) a kartu vyřadí. Daňová
     * zůstatková cena prodaného nebo zlikvidovaného majetku je výdajem § 24 odst. 2
     * písm. b) ZDP; do přiznání ji dodává {@see DisposalResiduals}, ne peněžní deník.
     *
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    private function disposeTaxEvidence(int $supplierId, int $id, string $date, string $type, ?float $price, ?int $saleInvoiceId): array
    {
        $year = (int) substr($date, 0, 4);
        $this->assertTaxEvidenceYearOpen($supplierId, $year);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $asset = $this->assets->findForUpdate($supplierId, $id);
            if ($asset === null) {
                throw new AssetException('not_found', 'Majetek nenalezen.', 404);
            }
            if ($asset['status'] !== 'in_use') {
                throw new AssetException('invalid_status', 'Vyřadit lze jen majetek v užívání.');
            }
            $assetView = $asset;
            $assetView['disposal_date'] = $date;
            $ctx = $this->depreciationPosting->buildContext($assetView);
            $this->assertDisposalChronology($supplierId, $asset, $ctx, $year);

            $existingTax = $this->entries->findYear($id, 'tax', $year);
            if ($existingTax === null || (!$existingTax['is_paused'] && !DepreciationEntryRepository::isOverridden($existingTax))) {
                $taxRow = $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $year);
                if ($taxRow !== null) {
                    $this->entries->upsert([
                        'supplier_id' => $supplierId,
                        'asset_id' => $id,
                        'kind' => 'tax',
                        'fiscal_year' => $year,
                        'amount' => (float) $taxRow['amount'],
                        'full_amount' => (float) $taxRow['full_amount'],
                        'residual_value_end' => (float) $taxRow['residual_end'],
                        'is_paused' => (bool) $taxRow['is_paused'],
                        'is_half' => (bool) $taxRow['is_half'],
                        'months_count' => $taxRow['months_count'] ?? null,
                        'detail' => isset($taxRow['months']) && $taxRow['months'] !== null
                            ? json_encode($taxRow['months'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'status' => 'confirmed',
                    ]);
                }
            }

            $this->assets->update($supplierId, $id, [
                'disposal_date' => $date,
                'disposal_type' => $type,
                'disposal_price' => $price,
                'sale_invoice_id' => $saleInvoiceId,
                'disposal_entry_id' => null,
                'status' => 'disposed',
            ]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        $warnings = [];
        if ($type === 'sold') {
            $warnings[] = [
                'code' => 'sale_invoice_income',
                'message' => 'Příjem z prodeje se z karty nezapisuje — vystavte fakturu; do peněžního deníku vstoupí jejím zaplacením.',
            ];
        }
        return ['asset' => $this->get($supplierId, $id), 'warnings' => array_merge($warnings, self::disposalTypeWarnings($type))];
    }

    /**
     * Datum, typ, prodejní cena a faktura prodeje z požadavku na vyřazení.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $data
     * @return array{0:string,1:string,2:?float,3:?int}
     */
    private function disposalInput(int $supplierId, array $asset, array $data): array
    {
        $date = (string) ($data['date'] ?? '');
        if (!self::isDate($date)) {
            throw new AssetException('validation_failed', 'Neplatné datum vyřazení (YYYY-MM-DD).');
        }
        if ($date < (string) $asset['put_into_use_date']) {
            throw new AssetException('validation_failed', 'Datum vyřazení nesmí předcházet datu zařazení do užívání.');
        }
        $type = (string) ($data['type'] ?? '');
        if (!isset(self::DISPOSAL_RULES[$type])) {
            throw new AssetException('validation_failed', 'Neplatný typ vyřazení (sold|liquidated|donated|damaged).');
        }
        $price = $type === 'sold' && isset($data['price']) && $data['price'] !== null
            ? round((float) $data['price'], 2)
            : null;

        // Volitelná vazba na vydanou fakturu prodeje (jen evidenční, R20 — tržba se účtuje
        // z faktury, ne z karty). ZC se doúčtuje 541/08x beze změny. Jen u type=sold.
        $saleInvoiceId = null;
        if ($type === 'sold' && isset($data['sale_invoice_id']) && $data['sale_invoice_id'] !== null && $data['sale_invoice_id'] !== '') {
            $saleInvoiceId = (int) $data['sale_invoice_id'];
            $owned = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ?');
            $owned->execute([$saleInvoiceId, $supplierId]);
            if ($owned->fetchColumn() === false) {
                throw new AssetException('sale_invoice_not_found', 'Faktura prodeje nenalezena.', 422);
            }
        }
        return [$date, $type, $price, $saleInvoiceId];
    }

    /** @return list<array{code:string, message:string}> */
    private static function disposalTypeWarnings(string $type): array
    {
        $warnings = [];
        if ($type === 'donated') {
            $warnings[] = [
                'code' => 'donation_vat_output',
                'message' => 'U darovaného majetku s uplatněným odpočtem DPH ověřte odvod DPH z ceny obvyklé (§13/4/a a §36/6/a ZDPH); vyřazení interní daňový doklad nevytváří.',
            ];
        } elseif (in_array($type, ['liquidated', 'damaged'], true)) {
            $warnings[] = [
                'code' => 'asset_vat_adjustment_evidence',
                'message' => 'U likvidace, manka nebo škody doložte způsob vyřazení a ověřte případné vyrovnání či úpravu odpočtu DPH (§77 a §78e ZDPH).',
            ];
        }
        return $warnings;
    }

    /**
     * Revert vyřazení (R24) — jen dokud je období data vyřazení otevřené: storna
     * disposal zápisu i účetního odpisu roku vyřazení, DELETE řádků obou druhů,
     * vynulování disposal_*, status in_use. Storno páry v deníku zůstávají.
     * Vyřazení bez zaúčtování ({@see disposeFromJournal()}) v deníku nic nestornuje.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    public function revertDisposal(int $supplierId, int $id, array $meta = []): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if ($asset['status'] !== 'disposed' || $asset['disposal_date'] === null) {
            throw new AssetException('invalid_status', 'Vrátit vyřazení lze jen u vyřazeného majetku.');
        }
        $disposalDate = (string) $asset['disposal_date'];
        if ($this->isTaxEvidence($supplierId)) {
            return $this->revertDisposalTaxEvidence($supplierId, $id, $disposalDate);
        }
        $period = $this->periods->findForDate($supplierId, $disposalDate);
        if ($period === null || $period['status'] !== 'open') {
            throw new AssetException(
                'period_not_open',
                'Období data vyřazení už není otevřené — vyřazení nelze vrátit (§35 ZoÚ).',
            );
        }

        $year = (int) $period['fiscal_year']; // label období vyřazení (hospodářský rok)
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $reverseMeta = [
                'entry_date' => $disposalDate,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ];

            $disposalEntry = $this->journal->findBySource($supplierId, 'asset_disposal', $id);
            if ($disposalEntry !== null) {
                if (($disposalEntry['reversed_by'] ?? null) === null) {
                    $this->posting->reverse($supplierId, (int) $disposalEntry['id'], $reverseMeta);
                }
                // Uvolnění idempotence klíče ('asset_disposal', id): stornovaný zápis
                // blokuje rewriteExisting (entry_reversed) a majetek by po revertu už
                // nikdy nešel vyřadit. Mění se jen metadata zdroje, účetní obsah ne;
                // pár originál↔storno drží reversed_by.
                $free = $pdo->prepare('UPDATE journal_entries SET source_id = NULL WHERE id = ? AND supplier_id = ?');
                $free->execute([(int) $disposalEntry['id'], $supplierId]);
            }

            $taxEntry = $this->entries->findYear($id, 'tax', $year);
            if ($disposalEntry !== null) {
                $accEntry = $this->entries->findYear($id, 'accounting', $year);
                if ($accEntry !== null) {
                    $accJournal = $this->journal->findBySource($supplierId, 'depreciation', (int) $accEntry['id']);
                    if ($accJournal !== null && ($accJournal['reversed_by'] ?? null) === null) {
                        $this->posting->reverse($supplierId, (int) $accJournal['id'], $reverseMeta);
                    }
                }
                $this->entries->deleteOne($id, 'accounting', $year);
                if ($taxEntry !== null && !$taxEntry['is_paused'] && !DepreciationEntryRepository::isOverridden($taxEntry)) {
                    $this->entries->deleteOne($id, 'tax', $year);
                }
            } elseif ($taxEntry !== null && !$taxEntry['is_paused'] && !DepreciationEntryRepository::isOverridden($taxEntry)
                && !DepreciationEntryRepository::isConfirmedByMigration($taxEntry)) {
                // Vyřazení bez zaúčtování (disposeFromJournal, převod): účetní odpisy roku
                // zaúčtoval deník a zůstávají; vrací se jen daňový odpis, který k vyřazení
                // dopočetlo MyÚčto. Řádek převzatý převodem zůstává.
                $this->entries->deleteOne($id, 'tax', $year);
            }
            $this->assets->update($supplierId, $id, [
                'disposal_date' => null,
                'disposal_type' => null,
                'disposal_price' => null,
                'sale_invoice_id' => null,
                'disposal_entry_id' => null,
                'status' => 'in_use',
            ]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        return ['asset' => $this->get($supplierId, $id), 'warnings' => []];
    }

    /**
     * Vrácení vyřazení v daňové evidenci: dokud rok nemá dokončenou roční uzávěrku.
     * Vrací se daňový odpis roku dopočtený k vyřazení; pauza, ruční přepis a řádek
     * převzatý převodem zůstávají.
     *
     * @return array{asset: array<string,mixed>, warnings: list<array{code:string, message:string}>}
     */
    private function revertDisposalTaxEvidence(int $supplierId, int $id, string $disposalDate): array
    {
        $year = (int) substr($disposalDate, 0, 4);
        $this->assertTaxEvidenceYearOpen($supplierId, $year);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->assets->findForUpdate($supplierId, $id);
            $taxEntry = $this->entries->findYear($id, 'tax', $year);
            if ($taxEntry !== null && !$taxEntry['is_paused'] && !DepreciationEntryRepository::isOverridden($taxEntry)
                && !DepreciationEntryRepository::isConfirmedByMigration($taxEntry)) {
                $this->entries->deleteOne($id, 'tax', $year);
            }
            $this->assets->update($supplierId, $id, [
                'disposal_date' => null,
                'disposal_type' => null,
                'disposal_price' => null,
                'sale_invoice_id' => null,
                'disposal_entry_id' => null,
                'status' => 'in_use',
            ]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }
        return ['asset' => $this->get($supplierId, $id), 'warnings' => []];
    }

    /**
     * Přerušení daňového odpisování §26/8 (R14) — potvrzený daňový řádek
     * is_paused=1, amount=full_amount=0, ZC beze změny.
     *
     * @return array{entry: array<string,mixed>}
     */
    public function pauseYear(int $supplierId, int $id, int $fiscalYear): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        if (!in_array($asset['tax_method'], ['straight', 'accelerated'], true)) {
            throw new AssetException(
                'pause_not_allowed',
                'Přerušit lze jen rovnoměrné (§31) a zrychlené (§32) odpisy — §30a přerušení zakazuje, režim „daňový = účetní" kopíruje účetní plán.',
            );
        }
        if ($asset['status'] !== 'in_use' || $asset['put_into_use_date'] === null) {
            throw new AssetException('invalid_status', 'Přerušit odpis lze jen u majetku v užívání.');
        }
        $firstYear = $this->fiscalYearOf($supplierId, (string) $asset['put_into_use_date']);
        if ($fiscalYear < $firstYear) {
            throw new AssetException('validation_failed', 'Rok přerušení nesmí předcházet prvnímu roku odpisování (' . $firstYear . ').');
        }
        $this->assertTaxEvidenceYearOpenIfTaxEvidence($supplierId, $fiscalYear);
        if ($this->entries->findYear($id, 'tax', $fiscalYear) !== null) {
            throw new AssetException('year_already_confirmed', 'Pro rok ' . $fiscalYear . ' už existuje potvrzený daňový řádek.');
        }
        // Chronologický zámek (R14, audit 2026-07 B9): přerušit dřívější rok nelze, pokud
        // je už potvrzen POZDĚJŠÍ daňový rok — u zrychlených odpisů (§32) by pauza posunula
        // schéma a zpětně zneplatnila výši potvrzeného pozdějšího roku. Symetrie s unpauseYear.
        $lastTax = $this->entries->lastConfirmedYear($id, 'tax');
        if ($lastTax !== null && $lastTax > $fiscalYear) {
            throw new AssetException(
                'later_year_confirmed',
                'Existuje potvrzený daňový řádek pozdějšího roku (' . $lastTax . ') — dřívější rok už nelze přerušit '
                    . '(přerušení by zneplatnilo výši odpisu potvrzeného roku). Nejdřív vraťte pozdější roky.',
            );
        }

        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        $yearEnd = $period !== null
            ? (string) $period['ends_on']
            : $this->supplierCalendar($supplierId)->periodEnd($fiscalYear);
        $increased = (float) $asset['input_price'];
        foreach ($this->assets->improvements($id) as $imp) {
            if ((string) $imp['completed_on'] <= $yearEnd) {
                $increased += (float) $imp['amount'];
            }
        }
        $taxFull = 0.0;
        foreach ($this->entries->forAsset($id) as $e) {
            if ($e['kind'] === 'tax') {
                $taxFull += (float) $e['full_amount'];
            }
        }
        $residual = round($increased - (float) $asset['opening_tax_amount'] - $taxFull, 2);
        if ($residual <= 0) {
            throw new AssetException('asset_fully_depreciated', 'Majetek je daňově plně odepsán — není co přerušovat.');
        }

        $entryId = $this->entries->upsert([
            'supplier_id' => $supplierId,
            'asset_id' => $id,
            'kind' => 'tax',
            'fiscal_year' => $fiscalYear,
            'amount' => 0.0,
            'full_amount' => 0.0,
            'residual_value_end' => $residual,
            'is_paused' => true,
            'is_half' => false,
            'months_count' => null,
            'detail' => null,
            'status' => 'confirmed',
        ]);

        return ['entry' => $this->entries->find($supplierId, $entryId)];
    }

    /** Zrušení pauzy — jen pokud neexistuje potvrzený daňový řádek pozdějšího roku (R14). */
    public function unpauseYear(int $supplierId, int $id, int $fiscalYear): void
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        $row = $this->entries->findYear($id, 'tax', $fiscalYear);
        if ($row === null || !$row['is_paused']) {
            throw new AssetException('not_found', 'Pro rok ' . $fiscalYear . ' neexistuje přerušení odpisu.', 404);
        }
        $this->assertTaxEvidenceYearOpenIfTaxEvidence($supplierId, $fiscalYear);
        $lastTax = $this->entries->lastConfirmedYear($id, 'tax');
        if ($lastTax !== null && $lastTax > $fiscalYear) {
            throw new AssetException(
                'later_year_confirmed',
                'Existuje potvrzený daňový řádek pozdějšího roku (' . $lastTax . ') — pauzu nelze zrušit.',
            );
        }
        $this->entries->deleteOne($id, 'tax', $fiscalYear);
    }

    /**
     * Ruční přepis daňového odpisu roku (R8, „převzít čísla účetní"): potvrzený daňový
     * řádek roku dostane jinou částku, než spočítala kalkulačka nebo převzal převod.
     * Důvod je povinný, řádek si drží původní hodnoty, kdo a kdy ho přepsal. Stanovený
     * i uplatněný odpis je zadaná částka, zůstatková cena roku se o rozdíl posune a stejně
     * zůstatková cena potvrzených pozdějších let (jejich částky zůstávají). Hromadné
     * potvrzení odpisů, vyřazení ani opakovaný převod přepsaný řádek nemění. Do přiznání
     * se promítne rozdílem účetních a daňových odpisů a v příloze (tabulka odpisů).
     *
     * @param array{user_id?:?int} $meta
     * @return array{entry: array<string,mixed>, previous_amount: float}
     */
    public function overrideTaxYear(int $supplierId, int $id, int $fiscalYear, float $amount, string $reason, array $meta = []): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AssetException('validation_failed', 'Důvod ručního přepisu daňového odpisu je povinný.');
        }
        if (mb_strlen($reason) > 500) {
            throw new AssetException('validation_failed', 'Důvod ručního přepisu může mít nejvýš 500 znaků.');
        }
        $amount = round($amount, 2);
        if ($amount < 0) {
            throw new AssetException('validation_failed', 'Daňový odpis nesmí být záporný.');
        }
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        $this->assertTaxYearEditable($supplierId, $fiscalYear);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->assets->findForUpdate($supplierId, $id);
            $row = $this->entries->findYear($id, 'tax', $fiscalYear);
            if ($row === null || (int) $row['supplier_id'] !== $supplierId) {
                throw new AssetException(
                    'not_found',
                    'Rok ' . $fiscalYear . ' nemá potvrzený daňový odpis, ručně přepsat jde jen potvrzený rok.',
                    404,
                );
            }
            if ($row['is_paused']) {
                throw new AssetException('validation_failed', 'Rok ' . $fiscalYear . ' je přerušený (§26/8), nejdřív zrušte přerušení.');
            }
            $residualStart = round((float) $row['residual_value_end'] + (float) $row['full_amount'], 2);
            if ($amount > $residualStart + 0.005) {
                throw new AssetException(
                    'validation_failed',
                    'Daňový odpis ' . self::money($amount) . ' převyšuje daňovou zůstatkovou cenu na začátku roku ' . self::money($residualStart) . '.',
                );
            }
            $delta = round($amount - (float) $row['full_amount'], 2);
            $later = $this->entries->minLaterTaxResidual($id, $fiscalYear);
            if ($later !== null && $later - $delta < -0.005) {
                throw new AssetException(
                    'validation_failed',
                    'Přepis by u pozdějšího potvrzeného roku snížil daňovou zůstatkovou cenu pod nulu, nejdřív upravte pozdější roky.',
                );
            }
            $this->entries->applyOverride($supplierId, (int) $row['id'], $amount, round($residualStart - $amount, 2), $reason, $meta['user_id'] ?? null);
            if (abs($delta) >= 0.005) {
                $this->entries->shiftLaterTaxResiduals($supplierId, $id, $fiscalYear, $delta);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }

        return ['entry' => $this->entries->findYear($id, 'tax', $fiscalYear), 'previous_amount' => (float) $row['amount']];
    }

    /**
     * Zruší ruční přepis daňového odpisu roku: řádek se vrátí na původní odpis
     * a zůstatkovou cenu, pozdější potvrzené roky zpět posune.
     *
     * @return array{entry: array<string,mixed>}
     */
    public function clearTaxOverride(int $supplierId, int $id, int $fiscalYear): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        $this->assertTaxYearEditable($supplierId, $fiscalYear);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->assets->findForUpdate($supplierId, $id);
            $row = $this->entries->findYear($id, 'tax', $fiscalYear);
            $original = $row !== null && (int) $row['supplier_id'] === $supplierId
                ? $this->entries->clearOverride($supplierId, (int) $row['id'])
                : null;
            if ($original === null) {
                throw new AssetException('not_found', 'Daňový odpis roku ' . $fiscalYear . ' není ručně přepsaný.', 404);
            }
            $delta = round($original['full_amount'] - (float) $row['full_amount'], 2);
            if (abs($delta) >= 0.005) {
                $this->entries->shiftLaterTaxResiduals($supplierId, $id, $fiscalYear, $delta);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->translate($e);
        }
        return ['entry' => $this->entries->findYear($id, 'tax', $fiscalYear)];
    }

    /** Daňový odpis roku se schválenou účetní závěrkou (§17/7 ZoÚ) se už nemění. */
    private function assertTaxYearEditable(int $supplierId, int $fiscalYear): void
    {
        if ($this->isTaxEvidence($supplierId)) {
            $this->assertTaxEvidenceYearOpen($supplierId, $fiscalYear);
            return;
        }
        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        if ($period !== null && (string) $period['status'] === 'approved') {
            throw new AssetException(
                'period_approved',
                'Účetní závěrka roku ' . $fiscalYear . ' je schválená, daňový odpis roku už nelze měnit.',
            );
        }
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }

    /**
     * Plán odpisů on-the-fly (R11): minulost z potvrzených řádků, budoucnost
     * dopočtená strategií A2.
     *
     * @return array{asset_summary: array<string,float>, tax: list<array<string,mixed>>, accounting: list<array<string,mixed>>}
     */
    public function plan(int $supplierId, int $id): array
    {
        $asset = $this->assets->find($supplierId, $id);
        if ($asset === null) {
            throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        }
        $ctx = $this->depreciationPosting->buildContext($asset);
        $plan = $this->calculator->plan($ctx, (string) $asset['tax_method'], (string) $asset['acc_method']);

        $impTotal = 0.0;
        foreach ($this->assets->improvements($id) as $imp) {
            $impTotal += (float) $imp['amount'];
        }
        $taxFull = 0.0;
        $accAmount = 0.0;
        $materialized = $this->entries->forAsset($id);
        $byKindYear = [];
        $journalByYear = [];
        foreach ($materialized as $e) {
            $byKindYear[(string) $e['kind']][(int) $e['fiscal_year']] = $e;
            if ($e['kind'] === 'tax') {
                $taxFull += (float) $e['full_amount'];
            } else {
                $accAmount += (float) $e['amount'];
                $journal = $this->journal->findBySource($supplierId, 'depreciation', (int) $e['id']);
                if ($journal !== null && $journal['reversed_by'] === null) {
                    $journalByYear[(int) $e['fiscal_year']] = (int) $journal['id'];
                }
            }
        }
        foreach (['tax', 'accounting'] as $kind) {
            if (!isset($plan[$kind])) {
                continue;
            }
            foreach ($plan[$kind] as &$row) {
                $entry = $byKindYear[$kind][(int) $row['fiscal_year']] ?? null;
                if ($entry === null || (string) ($row['source'] ?? '') !== 'confirmed') {
                    continue;
                }
                $row['depreciation_entry_id'] = (int) $entry['id'];
                $row['journal_entry_id'] = $journalByYear[(int) $row['fiscal_year']] ?? null;
                if ($kind === 'tax' && DepreciationEntryRepository::isOverridden($entry)) {
                    $row['override_reason'] = (string) $entry['override_reason'];
                    $row['override_original_amount'] = $entry['override_original_amount'];
                    $row['override_at'] = $entry['override_at'];
                }
            }
            unset($row);
        }
        $increased = round((float) $asset['input_price'] + $impTotal, 2);

        return [
            'asset_summary' => [
                'input_price' => (float) $asset['input_price'],
                'increased_input_price' => $increased,
                'tax_residual' => round($increased - (float) $asset['opening_tax_amount'] - $taxFull, 2),
                'acc_residual' => round($increased - (float) $asset['opening_acc_amount'] - $accAmount, 2),
                'accumulated_depreciation' => round((float) $asset['opening_acc_amount'] + $accAmount, 2),
            ],
            'tax' => $plan['tax'] ?? [],
            'accounting' => $plan['accounting'] ?? [],
        ];
    }

    // ── validace (§3.3) ───────────────────────────────────────────────────────

    /**
     * Normalizace payloadu na plnou kartu (create: defaulty; update: merge nad
     * existující kartou — mění se jen poslané klíče).
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function normalize(array $data, ?array $existing): array
    {
        $base = $existing ?? [
            'inventory_number' => '',
            'name' => '',
            'description' => null,
            'kind' => 'tangible',
            'asset_account_code' => '022',
            'acquisition_account_code' => null,
            'purchase_invoice_id' => null,
            'purchase_invoice_item_id' => null,
            'input_price' => 0.0,
            'acquisition_date' => null,
            'put_into_use_date' => null,
            'status' => 'draft',
            'tax_method' => 'straight',
            'tax_group' => null,
            'tax_first_year_increase' => 'none',
            'is_first_owner' => false,
            // § 28 ZDP — vlastník je výchozí a naprosto převažující případ; ostatní
            // důvody (TZ nájemce, spoluvlastník, nástupce) si uživatel zvolí vědomě.
            'depreciator_ground' => 'owner',
            'co_ownership_share' => null,
            'depreciator_note' => null,
            'is_m1_vehicle' => false,
            'm1_limit_exception' => false,
            'is_zero_emission' => false,
            'opening_tax_years' => 0,
            'opening_tax_amount' => 0.0,
            'opening_acc_months' => 0,
            'opening_acc_amount' => 0.0,
            'acc_useful_life_months' => null,
            'acc_method' => 'straight_line',
            'acc_residual_value' => 0.0,
        ];

        $card = [];
        foreach ($base as $key => $default) {
            $card[$key] = array_key_exists($key, $data) ? $data[$key] : $default;
        }
        // accumulated_account_code: klíč nepřítomen = odvodit mapou R18 (create),
        // resp. ponechat stávající (update); poslaný null je legitimní hodnota (§27).
        if (array_key_exists('accumulated_account_code', $data)) {
            $card['accumulated_account_code'] = $data['accumulated_account_code'];
        } elseif ($existing !== null) {
            $card['accumulated_account_code'] = $existing['accumulated_account_code'];
        } else {
            $card['accumulated_account_code'] = self::deriveAccumulated((string) $card['asset_account_code']);
        }
        if ($existing === null && ($card['acquisition_account_code'] === null || $card['acquisition_account_code'] === '')) {
            $card['acquisition_account_code'] = str_starts_with((string) $card['asset_account_code'], '01') ? '041' : '042';
        }

        // typy
        $card['inventory_number'] = trim((string) $card['inventory_number']);
        $card['name'] = trim((string) $card['name']);
        $card['description'] = $card['description'] !== null && $card['description'] !== '' ? (string) $card['description'] : null;
        $card['input_price'] = round((float) $card['input_price'], 2);
        $card['tax_group'] = $card['tax_group'] === null || $card['tax_group'] === '' ? null : (int) $card['tax_group'];
        foreach (['is_first_owner', 'is_m1_vehicle', 'm1_limit_exception', 'is_zero_emission'] as $flag) {
            $card[$flag] = (int) (bool) $card[$flag];
        }
        $card['opening_tax_years'] = max(0, (int) $card['opening_tax_years']);
        $card['opening_tax_amount'] = round((float) $card['opening_tax_amount'], 2);
        $card['opening_acc_months'] = max(0, (int) $card['opening_acc_months']);
        $card['opening_acc_amount'] = round((float) $card['opening_acc_amount'], 2);
        $card['acc_useful_life_months'] = $card['acc_useful_life_months'] === null || $card['acc_useful_life_months'] === ''
            ? null
            : (int) $card['acc_useful_life_months'];
        $card['acc_method'] = $card['acc_method'] === null || $card['acc_method'] === ''
            ? 'straight_line'
            : (string) $card['acc_method'];
        $card['acc_residual_value'] = round((float) $card['acc_residual_value'], 2);
        $card['purchase_invoice_id'] = $card['purchase_invoice_id'] === null || $card['purchase_invoice_id'] === ''
            ? null
            : (int) $card['purchase_invoice_id'];
        $card['purchase_invoice_item_id'] = $card['purchase_invoice_item_id'] === null || $card['purchase_invoice_item_id'] === ''
            ? null
            : (int) $card['purchase_invoice_item_id'];
        $card['put_into_use_date'] = $card['put_into_use_date'] !== null && $card['put_into_use_date'] !== ''
            ? (string) $card['put_into_use_date']
            : null;

        return $card;
    }

    /**
     * Validační matice §3.3. Vrací [karta se sjednocenými poli, warningy].
     *
     * @param array<string,mixed> $card
     * @return array{0: array<string,mixed>, 1: list<array{code:string, message:string}>}
     */
    private function validateCard(int $supplierId, array $card, bool $isCreate): array
    {
        $warnings = [];

        if ($card['inventory_number'] === '') {
            throw new AssetException('validation_failed', 'Inventární číslo je povinné.');
        }
        if ($card['name'] === '') {
            throw new AssetException('validation_failed', 'Název majetku je povinný.');
        }
        if ($card['input_price'] <= 0) {
            throw new AssetException('validation_failed', 'Vstupní cena musí být kladná (§29).');
        }
        if (!self::isDate((string) $card['acquisition_date'])) {
            throw new AssetException('validation_failed', 'Neplatné datum pořízení (YYYY-MM-DD).');
        }
        if ($card['put_into_use_date'] !== null) {
            if (!self::isDate((string) $card['put_into_use_date'])) {
                throw new AssetException('validation_failed', 'Neplatné datum zařazení do užívání (YYYY-MM-DD).');
            }
            if ((string) $card['put_into_use_date'] < (string) $card['acquisition_date']) {
                throw new AssetException('validation_failed', 'Datum zařazení nesmí předcházet datu pořízení.');
            }
        }

        $kind = (string) $card['kind'];
        if (!in_array($kind, ['tangible', 'intangible'], true)) {
            throw new AssetException('validation_failed', 'Neplatný druh majetku (tangible|intangible).');
        }
        $method = (string) $card['tax_method'];
        if ($kind === 'tangible' && !in_array($method, self::TAX_METHODS_TANGIBLE, true)) {
            throw new AssetException('validation_failed', 'Hmotný majetek: daňová metoda musí být straight|accelerated|extraordinary|none.');
        }
        if ($kind === 'intangible') {
            if (!in_array($method, self::TAX_METHODS_INTANGIBLE, true)) {
                throw new AssetException('validation_failed', 'Nehmotný majetek: daňová metoda musí být by_accounting|none (R16, §24/2/v).');
            }
            if ($card['is_m1_vehicle'] || $card['m1_limit_exception'] || $card['is_zero_emission']) {
                throw new AssetException('validation_failed', 'Příznaky vozidla (M1, bezemisní) nelze použít u nehmotného majetku.');
            }
        }

        if (!in_array((string) $card['tax_first_year_increase'], self::FIRST_YEAR_INCREASES, true)) {
            throw new AssetException('validation_failed', 'Neplatné zvýšení odpisu v 1. roce (none|p10|p15|p20).');
        }

        $this->assertDepreciatorGround($card);

        if (in_array($method, ['straight', 'accelerated'], true)) {
            if ($card['tax_group'] === null || $card['tax_group'] < 1 || $card['tax_group'] > 6) {
                throw new AssetException('validation_failed', 'Odpisová skupina 1–6 je povinná pro rovnoměrné a zrychlené odpisy (§30).');
            }
            if ($card['tax_first_year_increase'] !== 'none') {
                if ($card['tax_group'] > 3 || !$card['is_first_owner']) {
                    throw new AssetException(
                        'validation_failed',
                        'Zvýšení odpisu v 1. roce (§31/1 b–d) lze jen pro skupiny 1–3 a prvního odpisovatele.',
                    );
                }
                if ($card['is_m1_vehicle']) {
                    throw new AssetException(
                        'validation_failed',
                        'Zvýšený odpis v 1. roce nelze u osobního automobilu M1 použít (§31/5 ZDP).',
                    );
                }
            }
        } elseif ($method === 'extraordinary') {
            $acq = (string) $card['acquisition_date'];
            $extra = (array) ($this->constants->forYear((int) substr($acq, 0, 4))['extraordinary_depreciation'] ?? []);
            $eligibleFrom = (string) ($extra['eligible_from'] ?? '2024-01-01');
            $eligibleTo = (string) ($extra['eligible_to'] ?? '2028-12-31');
            if (!$card['is_zero_emission'] || !$card['is_first_owner'] || $acq < $eligibleFrom || $acq > $eligibleTo) {
                throw new AssetException(
                    'extraordinary_conditions_not_met',
                        'Mimořádné odpisy §30a: jen bezemisní vozidlo pořízené v konfigurovaném období ' . $eligibleFrom . ' – ' . $eligibleTo . ' prvním odpisovatelem.',
                );
            }
            $card['tax_group'] = null;
            $card['tax_first_year_increase'] = 'none';
        } else {
            $card['tax_group'] = null;
            $card['tax_first_year_increase'] = 'none';
        }

        $this->assertPurchaseInvoiceOwned($supplierId, $card['purchase_invoice_id']);
        $this->assertPurchaseInvoiceItemOwned($supplierId, $card['purchase_invoice_item_id']);

        // Pozn.: assertDepreciatorGround() se volá VÝŠ, ještě před vynulováním
        // `tax_first_year_increase` u metod, které ho neznají — jinak by se vazba
        // „nástupce nesmí uplatnit zvýšení" ověřovala nad už přepsanou hodnotou
        // a pravidlo by tiše neplatilo.

        // Daňová evidence: osnova neexistuje a účetní odpisy se nevedou. Účty zůstávají
        // na kartě jen jako druh majetku (02x/01x/03x), oprávkový účet určuje, zda se
        // majetek odpisuje. Hmotný majetek nemá účetní plán, nehmotný (§ 24 odst. 2 písm. v) ZDP) se
        // odpisuje rovnoměrně po měsících, a to právě účetní strategií karty.
        $taxEvidence = $this->isTaxEvidence($supplierId);
        if ($taxEvidence) {
            if ($method === 'none') {
                $card['accumulated_account_code'] = null;
            } elseif ($card['accumulated_account_code'] === null || $card['accumulated_account_code'] === '') {
                throw new AssetException('validation_failed', 'Odpisovaný majetek musí mít druh, ke kterému patří oprávky (např. 022, 013).');
            }
            if ($kind === 'tangible' && $method !== 'none') {
                $card['acc_method'] = 'by_tax';
            } elseif ($kind === 'intangible') {
                $card['acc_method'] = 'straight_line';
            }
            if ($kind === 'tangible' || $method === 'none') {
                $card['opening_acc_months'] = 0;
                $card['opening_acc_amount'] = 0.0;
            }
        }

        // účty: existence v osnově firmy + konzistence odpisovanosti (R17/R18)
        $codeMap = $taxEvidence ? [] : $this->chart->codeToIdMap($supplierId);
        foreach (['asset_account_code', 'acquisition_account_code', 'accumulated_account_code'] as $field) {
            if ($taxEvidence) {
                break;
            }
            $code = $card[$field];
            if ($code === null || $code === '') {
                if ($field === 'accumulated_account_code') {
                    continue;
                }
                throw new AssetException('validation_failed', 'Chybí účet (' . $field . ').');
            }
            if (!isset($codeMap[(string) $code])) {
                throw new AssetException('validation_failed', 'Účet ' . $code . ' není v účtové osnově firmy.');
            }
        }
        if ($card['accumulated_account_code'] === '') {
            $card['accumulated_account_code'] = null;
        }

        if (!in_array((string) $card['acc_method'], self::ACC_METHODS, true)) {
            throw new AssetException('validation_failed', 'Neplatná metoda účetních odpisů (straight_line|by_tax).');
        }
        if ($card['acc_method'] === 'by_tax' && $method === 'none') {
            throw new AssetException(
                'validation_failed',
                'Účetní odpis shodný s daňovým nelze zvolit u majetku bez daňových odpisů (daňová metoda „none").',
            );
        }

        if ($card['accumulated_account_code'] !== null) {
            if ($card['acc_method'] === 'by_tax') {
                // Daňový odpis je roční sazba z VC (§31/§32) — doba použitelnosti ani
                // zbytková hodnota do něj nevstupují, držet je na kartě by jen mátlo.
                $card['acc_useful_life_months'] = null;
                $card['acc_residual_value'] = 0.0;
            } elseif ($card['acc_useful_life_months'] === null || $card['acc_useful_life_months'] < 1) {
                throw new AssetException('validation_failed', 'Účetní doba použitelnosti v měsících (≥ 1) je povinná pro odpisovaný majetek.');
            }
            if ($card['acc_residual_value'] < 0 || $card['acc_residual_value'] >= $card['input_price']) {
                throw new AssetException('validation_failed', 'Účetní zbytková hodnota musí být ≥ 0 a menší než vstupní cena.');
            }
        } else {
            if ($method !== 'none') {
                throw new AssetException('validation_failed', 'Neodpisovaný majetek (bez oprávkového účtu, §27) musí mít daňovou metodu "none".');
            }
            if ($card['opening_acc_months'] !== 0 || $card['opening_acc_amount'] !== 0.0) {
                throw new AssetException(
                    'validation_failed',
                    'Neodpisovaný majetek bez oprávkového účtu nemůže mít počáteční účetní oprávky.',
                );
            }
            $card['acc_useful_life_months'] = null;
            $card['acc_residual_value'] = 0.0;
        }

        if ($card['opening_tax_amount'] < 0 || $card['opening_tax_amount'] > $card['input_price']) {
            throw new AssetException(
                'validation_failed',
                'Počáteční daňové oprávky musí být ≥ 0 a nesmí přesáhnout vstupní cenu.',
            );
        }
        $maxOpeningAcc = round($card['input_price'] - $card['acc_residual_value'], 2);
        if ($card['opening_acc_amount'] < 0 || $card['opening_acc_amount'] > $maxOpeningAcc) {
            throw new AssetException(
                'validation_failed',
                'Počáteční účetní oprávky musí být ≥ 0 a spolu se zbytkovou hodnotou nesmí přesáhnout vstupní cenu.',
            );
        }

        // status: create smí draft / in_use (historický majetek R23)
        $status = (string) ($card['status'] ?? 'draft');
        if ($isCreate) {
            if (!in_array($status, ['draft', 'in_use'], true)) {
                throw new AssetException('validation_failed', 'Nová karta smí být jen draft nebo in_use (historický majetek).');
            }
            if ($status === 'in_use' && $card['put_into_use_date'] === null) {
                throw new AssetException('validation_failed', 'Historický majetek (in_use) vyžaduje datum zařazení do užívání (R23).');
            }
            if ($status === 'in_use' && $card['put_into_use_date'] !== null && !$taxEvidence) {
                $period = $this->periods->findForDate($supplierId, (string) $card['put_into_use_date']);
                if ($period !== null && $period['status'] === 'open') {
                    $warnings[] = [
                        'code' => 'no_entry_in_open_period',
                        'message' => 'Datum zařazení historického majetku spadá do otevřeného účetního období — zápis o zařazení se neúčtuje, zůstatky 02x/08x musí přijít počátečními stavy (R23).',
                    ];
                }
            }
        } else {
            unset($card['status']);
        }

        // R25 — hranice §26/2 (movitý HM > 80 000 Kč): jen warning
        $cardYear = (int) substr((string) $card['acquisition_date'], 0, 4);
        $assetLimit = (float) ($this->constants->forYear($cardYear)['fixed_asset_limit'] ?? 80000);
        if ($kind === 'tangible'
            && in_array($method, ['straight', 'accelerated', 'extraordinary'], true)
            && $card['input_price'] <= $assetLimit
        ) {
            $warnings[] = [
                'code' => 'below_80k',
                'message' => 'Vstupní cena nepřesahuje ' . number_format($assetLimit, 0, ',', ' ') . ' Kč — movitý majetek pod hranicí §26/2 není hmotným majetkem pro daňové odpisy (stavby hranici nemají, posouzení je na vás).',
            ];
        }

        return [$card, $warnings];
    }

    /**
     * Zámky R13: po prvním potvrzeném daňovém řádku nelze měnit tax_* a
     * input_price; po zařazení do užívání input_price, acquisition_date a
     * put_into_use_date.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $card normalizovaná nová podoba
     * @param array<string,mixed> $data surový payload (jen poslané klíče se posuzují)
     */
    private function assertLocks(array $existing, array $card, array $data): void
    {
        $changed = function (string $key) use ($existing, $card, $data): bool {
            if (!array_key_exists($key, $data)) {
                return false;
            }
            $old = $existing[$key];
            $new = $card[$key];
            if (is_float($old) || is_float($new)) {
                return (int) round(((float) $old) * 100) !== (int) round(((float) $new) * 100);
            }
            return $old != $new;
        };

        if ($this->entries->existsAnyTax((int) $existing['id'])) {
            foreach (['tax_method', 'tax_group', 'tax_first_year_increase', 'input_price'] as $key) {
                if ($changed($key)) {
                    throw new AssetException(
                        'asset_locked',
                        'Po prvním potvrzeném daňovém odpisu nelze měnit způsob odpisování ani vstupní cenu (§30/2 ZDP, R13).',
                    );
                }
            }
        }
        if ($existing['status'] !== 'draft') {
            foreach ([
                'input_price', 'acquisition_date', 'put_into_use_date',
                'opening_tax_years', 'opening_tax_amount', 'opening_acc_months', 'opening_acc_amount',
            ] as $key) {
                if ($changed($key)) {
                    throw new AssetException(
                        'asset_locked',
                        'Po zařazení do užívání nelze měnit vstupní cenu, data pořízení/zařazení ani opening stavy — změny hodnoty jen přes technické zhodnocení (R13).',
                    );
                }
            }
        }
    }

    /**
     * § 28 ZDP — právní důvod odpisování a podmínky, které z něj plynou.
     *
     * Nejde o evidenční kosmetiku. Zvýšení odpisu v 1. roce podle § 31 odst. 1 písm. b)
     * až d) náleží jen PRVNÍMU odpisovateli; právní nástupce pokračuje v odpisování po
     * předchůdci (§ 30 odst. 10), takže prvním odpisovatelem být nemůže a uplatněné
     * zvýšení by znamenalo neoprávněně sníženou daň. Systém to do teď ohlídat nemohl,
     * protože o nástupnictví vůbec nevěděl.
     *
     * U spoluvlastníka je vstupní cenou jen poměrná část (§ 28 odst. 5) — bez evidovaného
     * podílu není z čeho ověřit, že se neodpisuje celý majetek místo podílu.
     *
     * U technického zhodnocení na cizím majetku smí nájemce odpisovat jen se souhlasem
     * vlastníka (§ 28 odst. 3); souhlas je podmínkou nároku, takže se musí dát doložit.
     *
     * @param array<string,mixed> $card
     */
    private function assertDepreciatorGround(array $card): void
    {
        $ground = (string) ($card['depreciator_ground'] ?? 'owner');
        if (!in_array($ground, self::DEPRECIATOR_GROUNDS, true)) {
            throw new AssetException('validation_failed', sprintf(
                'Neplatný důvod odpisování (§ 28 ZDP): %s.',
                implode('|', self::DEPRECIATOR_GROUNDS),
            ));
        }

        if ($ground === 'legal_successor') {
            if (!empty($card['is_first_owner'])) {
                throw new AssetException(
                    'validation_failed',
                    'Právní nástupce pokračuje v odpisování po předchůdci (§ 30 odst. 10 ZDP), '
                        . 'takže nemůže být zároveň prvním odpisovatelem.',
                );
            }
            if ((string) ($card['tax_first_year_increase'] ?? 'none') !== 'none') {
                throw new AssetException(
                    'validation_failed',
                    'Zvýšení odpisu v 1. roce (§ 31 odst. 1 písm. b–d) náleží jen prvnímu '
                        . 'odpisovateli — právní nástupce ho uplatnit nemůže.',
                );
            }
        }

        if ($ground === 'co_owner') {
            $share = $card['co_ownership_share'] ?? null;
            if ($share === null || (float) $share <= 0.0 || (float) $share > 100.0) {
                throw new AssetException(
                    'validation_failed',
                    'U spoluvlastníka je nutné uvést spoluvlastnický podíl v % (§ 28 odst. 5 ZDP) — '
                        . 'vstupní cenou je jen poměrná část.',
                );
            }
        }

        if ($ground === 'lessee_improvement' && trim((string) ($card['depreciator_note'] ?? '')) === '') {
            throw new AssetException(
                'validation_failed',
                'Nájemce smí technické zhodnocení na cizím majetku odpisovat jen se souhlasem '
                    . 'vlastníka (§ 28 odst. 3 ZDP) — doplňte doložení souhlasu.',
            );
        }
    }

    /** Cizí/neexistující PF nesmí jít navázat na kartu ani TZ (tenant izolace). */
    private function assertPurchaseInvoiceOwned(int $supplierId, ?int $purchaseInvoiceId): void
    {
        if ($purchaseInvoiceId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new AssetException('not_found', 'Přijatá faktura nenalezena.', 404);
        }
    }

    /**
     * Cizí/neexistující řádek PF nesmí jít navázat na kartu (tenant izolace).
     *
     * `assets.purchase_invoice_item_id` deklarovaný FK NEMÁ (viz 1013_assets.sql) a dnes
     * ho žádný read-back nejoinuje, takže cizí id se v odpovědi neprojeví. Zůstal by ale
     * TRVALÝ zápis přes hranici firmy — a první JOIN, který na ten sloupec někdo napíše,
     * z něj udělá únik. Vlastnictví se odvozuje přes hlavičku dokladu, stejně jako
     * {@see \MyInvoice\Service\Accounting\SmallAsset\SmallAssetService::assertSourceBelongsToTenant()}.
     */
    private function assertPurchaseInvoiceItemOwned(int $supplierId, ?int $purchaseInvoiceItemId): void
    {
        if ($purchaseInvoiceItemId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceItemId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new AssetException('not_found', 'Řádek přijaté faktury nenalezen.', 404);
        }
    }

    /** @var array<int, FiscalCalendar> memo režimu firmy v rámci běhu */
    private array $calendarCache = [];

    /** @var array<int, bool> */
    private array $taxEvidenceCache = [];

    /**
     * Firma v daňové evidenci (§ 7b ZDP): karta majetku je jen evidence pro daňové odpisy.
     * Nic se neúčtuje do deníku, účtová osnova firmy neexistuje a zdaňovací období je
     * kalendářní rok (fyzická osoba).
     */
    private function isTaxEvidence(int $supplierId): bool
    {
        if (!isset($this->taxEvidenceCache[$supplierId])) {
            $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $this->taxEvidenceCache[$supplierId] = $stmt->fetchColumn() === 'tax_evidence';
        }
        return $this->taxEvidenceCache[$supplierId];
    }

    /** Rok s dokončenou roční uzávěrkou daňové evidence se už nemění. */
    private function assertTaxEvidenceYearOpen(int $supplierId, int $year): void
    {
        if (TaxEvidenceYearLock::isFinal($this->db, $supplierId, $year)) {
            throw new AssetException(TaxEvidenceYearLock::ERROR_CODE, TaxEvidenceYearLock::message($year), 409);
        }
    }

    /** Přerušení a technické zhodnocení: v daňové evidenci jen v roce bez dokončené uzávěrky. */
    private function assertTaxEvidenceYearOpenIfTaxEvidence(int $supplierId, int $year): void
    {
        if ($this->isTaxEvidence($supplierId)) {
            $this->assertTaxEvidenceYearOpen($supplierId, $year);
        }
    }

    /** Režim firmy (kalendářní vs hospodářský rok) dle tvaru účetních období. */
    private function supplierCalendar(int $supplierId): FiscalCalendar
    {
        return $this->calendarCache[$supplierId]
            ??= FiscalCalendar::forPeriods($this->periods->listForTenant($supplierId));
    }

    /**
     * Label zdaňovacího období (fiscal_year), do něhož spadá datum — dle
     * existujícího účetního období (hospodářský rok), jinak dle režimu firmy
     * (konzistentní s odpisovým enginem, ne kalendářní substr — F7).
     */
    private function fiscalYearOf(int $supplierId, string $date): int
    {
        $period = $this->periods->findForDate($supplierId, $date);
        return $period !== null
            ? (int) $period['fiscal_year']
            : $this->supplierCalendar($supplierId)->fiscalYearOfDate($date);
    }

    /** Rok s potvrzeným/zaúčtovaným řádkem (kterýkoli druh) je zamčený (R15). */
    private function assertYearNotConfirmed(int $assetId, int $year): void
    {
        if ($this->entries->findYear($assetId, 'tax', $year) !== null
            || $this->entries->findYear($assetId, 'accounting', $year) !== null
        ) {
            throw new AssetException(
                'year_already_confirmed',
                'Rok ' . $year . ' už má potvrzený/zaúčtovaný odpis — nejdřív vraťte vyřazení nebo přeúčtujte rok (re-book).',
            );
        }
    }

    /**
     * Chronologický zámek pro TZ (R14/R15, audit 2026-07 B9): TZ v roce $year zpětně
     * mění odpisovou základnu, takže potvrzený/zaúčtovaný POZDĚJŠÍ rok by po zásahu
     * nesouhlasil. Symetrie s unpauseYear a s pauseYear guardem.
     */
    private function assertNoLaterConfirmedYear(int $assetId, int $year): void
    {
        foreach (['tax' => 'daňový', 'accounting' => 'účetní'] as $kind => $label) {
            $last = $this->entries->lastConfirmedYear($assetId, $kind);
            if ($last !== null && $last > $year) {
                throw new AssetException(
                    'later_year_confirmed',
                    'Existuje potvrzený ' . $label . ' odpis pozdějšího roku (' . $last . ') — technické zhodnocení '
                        . 'roku ' . $year . ' by zpětně změnilo odpisovou základnu. Nejdřív vraťte pozdější roky.',
                );
            }
        }
    }

    /** Vyřazení nesmí přeskočit nepotvrzený rok ani ponechat odpisy po datu vyřazení. */
    private function assertDisposalChronology(
        int $supplierId,
        array $asset,
        DepreciationContext $ctx,
        int $year,
    ): void {
        $assetId = (int) $asset['id'];
        foreach (['tax' => 'daňový', 'accounting' => 'účetní'] as $kind => $label) {
            $last = $this->entries->lastConfirmedYear($assetId, $kind);
            if ($last !== null && $last > $year) {
                throw new AssetException(
                    'later_year_confirmed',
                    'Existuje potvrzený ' . $label . ' odpis pozdějšího roku (' . $last . ') — před vyřazením v roce '
                        . $year . ' nejdřív vraťte pozdější roky.',
                );
            }
        }

        $calendar = $this->supplierCalendar($supplierId);
        $firstYear = $asset['put_into_use_date'] !== null
            ? $calendar->fiscalYearOfDate((string) $asset['put_into_use_date'])
            : $year;
        $systemStartYear = $firstYear + (int) $asset['opening_tax_years'];
        $priorYear = $year - 1;
        if ($priorYear < $systemStartYear
            || $this->entries->findYear($assetId, 'tax', $priorYear) !== null
        ) {
            return;
        }

        $probe = $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $priorYear);
        if ($probe !== null
            && (round((float) ($probe['full_amount'] ?? 0), 2) > 0.0
                || round((float) ($probe['residual_start'] ?? 0), 2) > 0.0)
        ) {
            throw new AssetException(
                'prior_year_not_confirmed',
                'Rok ' . $priorYear . ' nemá potvrzený ani přerušený daňový odpis — před vyřazením v roce '
                    . $year . ' jej nejdřív zaúčtujte nebo přerušte.',
            );
        }
    }

    /** Majetkový účet neodpisovaného majetku (§27, pozemky, umělecká díla) podle mapy R18. */
    public static function isNonDepreciableAccount(string $assetAccountCode): bool
    {
        $prefix = substr($assetAccountCode, 0, 3);
        return array_key_exists($prefix, self::ACCUMULATED_MAP) && self::ACCUMULATED_MAP[$prefix] === null;
    }

    private static function deriveAccumulated(string $assetAccountCode): ?string
    {
        $prefix = substr($assetAccountCode, 0, 3);
        if (!array_key_exists($prefix, self::ACCUMULATED_MAP)) {
            return null;
        }
        return self::ACCUMULATED_MAP[$prefix];
    }

    private static function isDate(?string $date): bool
    {
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        return checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    /** PostingException → AssetException se zachováním kódu a HTTP statusu (pro Action A4). */
    private function translate(\Throwable $e): \Throwable
    {
        if ($e instanceof PostingException) {
            return new AssetException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return $e;
    }
}

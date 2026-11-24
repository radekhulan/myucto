<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use Closure;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

final class PohodaAssetAccountRepair
{
    public const AUDIT_ACTION = 'asset.pohoda_account_restored';

    private const NOTE_LIKE = '%' . PohodaAssetAccountResolver::UNDETERMINED_NOTE . '%';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $imports,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return list<int> */
    public function convertedSuppliers(): array
    {
        return $this->imports->completedSuppliers();
    }

    /**
     * @param list<int> $supplierIds
     * @return list<array<string,mixed>>
     */
    public function run(array $supplierIds, bool $commit): array
    {
        $company = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $out = [];
        foreach ($supplierIds as $supplierId) {
            $company->execute([$supplierId]);
            $out[] = ['supplier_id' => $supplierId, 'company' => (string) $company->fetchColumn()]
                + ($commit ? $this->repair($supplierId) : $this->preview($supplierId));
        }
        return $out;
    }

    /** @return array{reason:?string,cards:list<array<string,mixed>>} */
    public function preview(int $supplierId): array
    {
        return $this->plan($supplierId, false);
    }

    /**
     * @param ?Closure(array{reason:?string,cards:list<array<string,mixed>>}):void $confirm ověření potvrzeného náhledu
     * @return array{reason:?string,cards:list<array<string,mixed>>}
     */
    public function repair(int $supplierId, ?int $userId = null, ?Closure $confirm = null): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $plan = $this->plan($supplierId, true);
            $confirm?->__invoke($plan);
            $update = $pdo->prepare('UPDATE assets SET asset_account_code = ?, accumulated_account_code = ?, description = ?
                WHERE supplier_id = ? AND id = ?');
            foreach ($plan['cards'] as $card) {
                if ($card['new_account'] === null && $card['new_accumulated_account'] === null) {
                    continue;
                }
                $update->execute([
                    $card['new_account'] ?? $card['asset_account_code'],
                    $card['new_accumulated_account'] ?? $card['accumulated_account_code'],
                    $card['new_description'], $supplierId, $card['id'],
                ]);
                $this->activity->log(self::AUDIT_ACTION, $userId, 'asset', $card['id'], [
                    'inventory_number' => $card['inventory_number'],
                    'old_account' => $card['asset_account_code'],
                    'new_account' => $card['new_account'],
                    'old_accumulated_account' => $card['accumulated_account_code'],
                    'new_accumulated_account' => $card['new_accumulated_account'],
                ], null, null, $supplierId);
            }
            if ($own) {
                $pdo->commit();
            }
            return $plan;
        } catch (\Throwable $e) {
            if ($own && !TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{reason:?string,cards:list<array<string,mixed>>} */
    private function plan(int $supplierId, bool $forUpdate): array
    {
        $openingEntry = $this->openingEntry($supplierId, $forUpdate);
        if ($forUpdate) {
            $lock = $this->db->pdo()->prepare('SELECT id FROM assets WHERE supplier_id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $lock->closeCursor();
        }
        if (!$this->imports->hasCompletedImport($supplierId)) {
            return ['reason' => 'Firma nemá dokončený převod z POHODY.', 'cards' => []];
        }

        $start = $openingEntry['starts_on'] ?? null;
        $cards = $this->candidates($supplierId, $start);
        if ($cards === []) {
            return ['reason' => null, 'cards' => []];
        }
        $opening = $openingEntry === null ? [] : $this->openingBalances($supplierId, (int) $openingEntry['id']);
        $assetOpening = array_filter($opening, static fn (string $code): bool => preg_match('/^0[12]/', $code) === 1, ARRAY_FILTER_USE_KEY);
        $openingCodes = [];
        $legacyBalances = [];
        foreach ($assetOpening as $code => $balance) {
            $raw = str_replace('.', '', $code);
            $openingCodes[$raw] = $code;
            $legacyBalances[$raw] = $balance;
        }
        $assigned = $assetOpening === [] ? [] : $this->assignedAmounts($supplierId, $start, $assetOpening);
        $journal = $this->journalAccounts($supplierId, $cards);
        foreach ($cards as &$card) {
            $card['id'] = (int) $card['id'];
            $card['opening_price'] = (float) $card['opening_price'];
            $card['new_account'] = null;
            $card['new_accumulated_account'] = null;
            $card['new_description'] = $card['description'];
            $card['reason'] = null;
            $hasNote = (bool) $card['has_review_note'];
            $asset = $card['asset_account_code'];
            $accumulated = $card['accumulated_account_code'];
            $assetJournal = $journal[$card['id']]['asset'] ?? [];
            $accumulatedJournal = $journal[$card['id']]['accumulated'] ?? [];
            $historical = $start !== null && $card['acquisition_date'] < $start;

            if ($hasNote) {
                $description = self::withoutReviewNote($card['description']);
                if ($description === $card['description']) {
                    $card['reason_key'] = 'review_note';
                    $card['reason'] = 'Poznámka v popisu nemá formát kontroly z převodu.';
                    continue;
                }
            }

            if ($card['refine_accumulated']) {
                $accumulated = PohodaAssetAccountResolver::resolveAnalytic($accumulated, $historical ? $opening : [], $accumulatedJournal);
                if ($accumulated === null) {
                    $card['reason_key'] = 'ambiguous_accumulated';
                    $card['reason'] = 'Analytiku účtu oprávek nelze jednoznačně určit z deníku nebo počátečních stavů.';
                    continue;
                }
            }
            if ($hasNote) {
                $rawAccumulated = $accumulated === null ? null
                    : (strlen($accumulated) === 3 ? $accumulated . '000' : str_replace('.', '', $accumulated));
                $resolved = PohodaAssetAccountResolver::resolve($historical ? $legacyBalances : [], $card['opening_price'], $rawAccumulated);
                $asset = $assetJournal === [] ? ($resolved === null ? null : $openingCodes[$resolved]) : self::singleAccount($assetJournal);
                if ($asset === null || ($card['refine_asset'] && $asset === $card['asset_account_code'])) {
                    $missingOpening = $start === null && $assetJournal === [];
                    $card['reason_key'] = $missingOpening ? 'missing_opening' : 'ambiguous_asset';
                    $card['reason'] = $missingOpening
                        ? 'Chybí platný zápis počátečních stavů z POHODY.'
                        : 'Majetkový účet nelze jednoznačně určit z deníku nebo počátečních stavů.';
                    continue;
                }
            } elseif ($card['refine_asset']) {
                $asset = PohodaAssetAccountResolver::resolveAnalytic($asset, $historical ? $assetOpening : [], $assetJournal);
                if ($asset === null) {
                    $card['reason_key'] = 'ambiguous_asset_analytic';
                    $card['reason'] = 'Analytiku majetkového účtu nelze jednoznačně určit z deníku nebo počátečních stavů.';
                    continue;
                }
            }

            if ($assetJournal !== [] && self::singleAccount($assetJournal) !== $asset) {
                $card['reason_key'] = 'asset_journal_mismatch';
                $card['reason'] = 'Zápisy o zařazení v deníku nejsou na navrženém majetkovém účtu.';
                continue;
            }
            if ($accumulatedJournal !== [] && self::singleAccount($accumulatedJournal) !== $accumulated) {
                $card['reason_key'] = 'accumulated_journal_mismatch';
                $card['reason'] = 'Zápisy odpisů v deníku nejsou na navrženém účtu oprávek.';
                continue;
            }

            if ($historical && ($hasNote || $asset !== $card['asset_account_code'])) {
                $prior = $card['asset_account_code'];
                if (!$hasNote && !isset($assetOpening[$prior]) && strlen($prior) === 3) {
                    $prior .= '.000';
                }
                $alreadyAssigned = !$hasNote && $prior === $asset ? $card['opening_price'] : 0.0;
                $amount = round(($assigned[$asset] ?? 0.0) + $card['opening_price'] - $alreadyAssigned, 2);
                if ($assetJournal === [] && $amount > round($assetOpening[$asset] + 1.0, 2)) {
                    $card['reason_key'] = 'opening_capacity';
                    $card['reason_details'] = ['account' => $asset, 'total_price' => $amount, 'opening_balance' => $assetOpening[$asset]];
                    $card['reason'] = sprintf('Účet %s: součet cen karet %s Kč by převýšil počáteční stav %s Kč o více než 1 Kč.',
                        $asset, number_format($amount, 2, ',', ' '), number_format($assetOpening[$asset], 2, ',', ' '));
                    continue;
                }
                $assigned[$asset] = $amount;
                if (!$hasNote && $prior !== $asset && isset($assigned[$prior])) {
                    $assigned[$prior] -= $card['opening_price'];
                }
            }
            $card['new_account'] = $hasNote || $asset !== $card['asset_account_code'] ? $asset : null;
            $card['new_accumulated_account'] = $accumulated !== $card['accumulated_account_code'] ? $accumulated : null;
            if ($hasNote) {
                $card['new_description'] = $description;
            }
        }
        unset($card);
        return ['reason' => null, 'cards' => $cards];
    }

    /** @param list<string> $accounts */
    private static function singleAccount(array $accounts): ?string
    {
        $accounts = array_values(array_unique($accounts));
        return count($accounts) === 1 && $accounts[0] !== '' ? $accounts[0] : null;
    }

    /**
     * Zápis počátečních stavů z POHODY v nejstarším období, které ho má. Nejstarší období
     * firmy vůbec být nemusí: zápis s překlepem v roce (2016 místo 2026) si dřív založil
     * prázdné staré období.
     *
     * @return ?array{id:int,starts_on:string}
     */
    private function openingEntry(int $supplierId, bool $forUpdate): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $entry = $this->db->pdo()->prepare(
            "SELECT e.id, p.starts_on FROM pohoda_import_map m
               JOIN journal_entries e ON e.supplier_id = m.supplier_id AND e.id = m.target_id
               JOIN accounting_periods p ON p.supplier_id = e.supplier_id AND p.id = e.period_id
              WHERE m.supplier_id = ? AND m.kind = ? AND m.pohoda_key = CONCAT(p.fiscal_year, '|', ?)
                AND e.entry_date = p.starts_on AND e.source_type = 'opening' AND e.source_id = e.period_id
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
              ORDER BY p.starts_on, p.id
              LIMIT 1" . $lock
        );
        $entry->execute([$supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY, PohodaJournal::OPENING_KEY]);
        $row = $entry->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'starts_on' => (string) $row['starts_on']];
    }

    /** @return array<string,float> */
    private function openingBalances(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code,
                    SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END)
                        * CASE WHEN c.account_code LIKE '07%' OR c.account_code LIKE '08%' THEN -1 ELSE 1 END
               FROM journal_entry_lines l
               JOIN chart_of_accounts c ON c.supplier_id = l.supplier_id AND c.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ?
                AND (c.account_code LIKE '01%' OR c.account_code LIKE '02%' OR c.account_code LIKE '07%' OR c.account_code LIKE '08%')
              GROUP BY c.account_code"
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $supplierId, ?string $start): array
    {
        $pdo = $this->db->pdo();
        $accounts = $pdo->prepare(
            "SELECT DISTINCT LEFT(account_code, 3) FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code REGEXP '^0[1278][0-9][.][0-9]+$'"
        );
        $accounts->execute([$supplierId]);
        $analytics = array_fill_keys($accounts->fetchAll(PDO::FETCH_COLUMN), true);

        $stmt = $pdo->prepare(
            "SELECT a.id, a.inventory_number, a.name, a.description, a.status, a.acquisition_date, a.input_price,
                    a.asset_account_code, a.accumulated_account_code, COALESCE(a.description LIKE ?, 0) AS has_review_note,
                    a.input_price + COALESCE((SELECT SUM(i.amount) FROM asset_improvements i
                         WHERE i.supplier_id = a.supplier_id AND i.asset_id = a.id AND i.completed_on < ?), 0) AS opening_price,
                    EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = a.supplier_id
                         AND m.kind = ? AND m.target_id = a.id) AS imported,
                    EXISTS (SELECT 1 FROM depreciation_entries d WHERE d.supplier_id = a.supplier_id AND d.asset_id = a.id)
                         AS has_depreciation
               FROM assets a
              WHERE a.supplier_id = ? AND a.status IN ('draft', 'in_use')
                AND (a.description LIKE ? OR a.asset_account_code REGEXP '^0[12][0-9]$' OR a.accumulated_account_code REGEXP '^0[78][0-9]$')
              ORDER BY a.id"
        );
        $stmt->execute([self::NOTE_LIKE, $start, PohodaImportRepository::KIND_ASSET, $supplierId, self::NOTE_LIKE]);
        $cards = [];
        while ($card = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $card['refine_asset'] = (bool) $card['imported'] && preg_match('/^0[12]/', $card['asset_account_code']) === 1
                && isset($analytics[$card['asset_account_code']]);
            $card['refine_accumulated'] = (bool) $card['imported'] && preg_match('/^0[78]/', $card['accumulated_account_code'] ?? '') === 1
                && isset($analytics[$card['accumulated_account_code']]);
            if (!$card['imported'] && $card['status'] === 'in_use' && $card['has_depreciation']) {
                continue;
            }
            if ($card['has_review_note'] || $card['refine_asset'] || $card['refine_accumulated']) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    /**
     * @param list<array<string,mixed>> $cards
     * @return array<int,array<string,list<string>>>
     */
    private function journalAccounts(int $supplierId, array $cards): array
    {
        $prices = array_column($cards, 'input_price', 'id');
        $stmt = $this->db->pdo()->prepare(
            "WITH card_entries AS (
                SELECT e.supplier_id, e.id, e.source_id AS asset_id, 'asset' AS use_for, e.posted_at, e.reversed_by
                  FROM journal_entries e
                 WHERE e.supplier_id = ? AND e.source_type = 'asset'
                UNION ALL
                SELECT d.supplier_id, e.id, d.asset_id, 'accumulated', e.posted_at, e.reversed_by
                  FROM depreciation_entries d
                  LEFT JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.source_type = 'depreciation' AND e.source_id = d.id
                 WHERE d.supplier_id = ? AND d.kind = 'accounting' AND d.voided_at IS NULL
                   AND (d.status = 'posted' OR e.id IS NOT NULL)
                UNION ALL
                SELECT e.supplier_id, e.id, COALESCE(card.target_id, a.id), 'imported', e.posted_at, e.reversed_by
                  FROM pohoda_import_map m
                  JOIN journal_entries e ON e.supplier_id = m.supplier_id AND e.id = m.target_id
                  LEFT JOIN pohoda_import_map card ON card.supplier_id = e.supplier_id
                       AND card.kind = ? AND card.pohoda_key = CONCAT('asset|', e.document_no)
                  LEFT JOIN assets a ON a.supplier_id = e.supplier_id AND a.inventory_number = e.document_no
                 WHERE m.supplier_id = ? AND m.kind = ? AND m.pohoda_key LIKE ? AND COALESCE(card.target_id, a.id) IS NOT NULL
                   AND e.source_type = 'manual' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
            )
            SELECT e.asset_id, e.id, e.use_for, e.posted_at, e.reversed_by, c.account_code,
                   SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS net_amount
              FROM card_entries e
              LEFT JOIN journal_entry_lines l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id
              LEFT JOIN chart_of_accounts c ON c.supplier_id = l.supplier_id AND c.id = l.account_id
             GROUP BY e.asset_id, e.id, e.use_for, e.posted_at, e.reversed_by, c.account_code
             ORDER BY e.asset_id, e.id"
        );
        $stmt->execute([$supplierId, $supplierId, PohodaImportRepository::KIND_ASSET, $supplierId,
            PohodaImportRepository::KIND_JOURNAL_ENTRY, '____|' . PohodaJournal::ASSETS . '|%']);
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $assetId = (int) $row['asset_id'];
            if (!isset($prices[$assetId])) {
                continue;
            }
            $entryId = (int) $row['id'];
            $type = $row['use_for'];
            if ($type !== 'imported') {
                $entries[$assetId][$type][$entryId] ??= [];
            }
            if ($row['posted_at'] === null || $row['reversed_by'] !== null) {
                continue;
            }
            $code = $row['account_code'] ?? '';
            if (preg_match('/^0[12]/', $code) === 1 && ($type === 'asset' && (float) $row['net_amount'] > 0.0
                || $type === 'imported' && abs((float) $row['net_amount'] - (float) $prices[$assetId]) < 0.005)) {
                $entries[$assetId]['asset'][$entryId][] = $code;
            }
            if ($type !== 'asset' && preg_match('/^0[78]/', $code) === 1 && (float) $row['net_amount'] < 0.0) {
                $entries[$assetId]['accumulated'][$entryId][] = $code;
            }
        }
        $out = [];
        foreach ($entries as $assetId => $types) {
            foreach ($types as $type => $byEntry) {
                $out[$assetId][$type] = array_merge(...array_map(static fn (array $codes): array => $codes === [] ? [''] : $codes, array_values($byEntry)));
            }
        }
        return $out;
    }

    /**
     * @param array<string,float> $opening
     * @return array<string,float>
     */
    private function assignedAmounts(int $supplierId, string $start, array $opening): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.asset_account_code, SUM(a.input_price + COALESCE((SELECT SUM(i.amount) FROM asset_improvements i
                         WHERE i.supplier_id = a.supplier_id AND i.asset_id = a.id AND i.completed_on < ?), 0)) AS opening_price
               FROM assets a
              WHERE a.supplier_id = ? AND (a.asset_account_code LIKE '01%' OR a.asset_account_code LIKE '02%')
                AND a.acquisition_date < ?
                AND (a.description IS NULL OR a.description NOT LIKE ?)
              GROUP BY a.asset_account_code"
        );
        // Počáteční stav nesou jen karty pořízené před začátkem agendy; majetek koupený
        // později přišel do 01x/02x deníkem, ne počátečním stavem.
        $stmt->execute([$start, $supplierId, $start, self::NOTE_LIKE]);

        $assigned = [];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $amount) {
            if (!isset($opening[$code]) && strlen((string) $code) === 3) {
                $code .= '.000';
            }
            $assigned[$code] = ($assigned[$code] ?? 0.0) + (float) $amount;
        }
        return $assigned;
    }

    private static function withoutReviewNote(string $description): ?string
    {
        $prefix = PohodaAssetAccountResolver::REVIEW_PREFIX;
        $pattern = '~^' . preg_quote($prefix, '~') . '([^\r\n]+)\.(\r?\n|\z)~m';
        $result = preg_replace_callback($pattern, static function (array $match) use ($prefix): string {
            $notes = explode('; ', $match[1]);
            $remaining = array_diff($notes, [PohodaAssetAccountResolver::UNDETERMINED_NOTE]);
            return $remaining === [] ? '' : $prefix . implode('; ', $remaining) . '.' . $match[2];
        }, $description);
        return trim($result) === '' ? null : $result;
    }
}

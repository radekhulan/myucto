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
            $update = $pdo->prepare('UPDATE assets SET asset_account_code = ?, description = ? WHERE supplier_id = ? AND id = ?');
            foreach ($plan['cards'] as $card) {
                if ($card['new_account'] === null) {
                    continue;
                }
                $update->execute([$card['new_account'], $card['new_description'], $supplierId, $card['id']]);
                $this->activity->log(self::AUDIT_ACTION, $userId, 'asset', $card['id'], [
                    'inventory_number' => $card['inventory_number'],
                    'old_account' => $card['asset_account_code'],
                    'new_account' => $card['new_account'],
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

        $cards = $this->candidates($supplierId, $openingEntry['starts_on'] ?? null);
        if ($cards === []) {
            return ['reason' => null, 'cards' => []];
        }
        $opening = $openingEntry === null ? [] : $this->openingBalances($supplierId, (int) $openingEntry['id']);
        $codes = [];
        $balances = [];
        foreach ($opening as $code => $balance) {
            $raw = str_replace('.', '', (string) $code);
            $codes[$raw] = (string) $code;
            $balances[$raw] = (float) $balance;
        }
        $assigned = $opening === [] ? [] : $this->assignedAmounts($supplierId, $openingEntry['starts_on'], $opening);
        foreach ($cards as &$card) {
            $card['id'] = (int) $card['id'];
            $card['opening_price'] = (float) $card['opening_price'];
            $card['new_account'] = null;
            $card['new_description'] = self::withoutReviewNote($card['description']);
            if ($card['new_description'] === $card['description']) {
                $card['reason'] = 'Poznámka v popisu nemá formát kontroly z převodu.';
                continue;
            }
            if ($openingEntry === null) {
                $card['reason'] = 'Chybí platný zápis počátečních stavů z POHODY.';
                continue;
            }

            $accumulated = $card['accumulated_account_code'];
            if ($accumulated !== null) {
                $accumulated = strlen($accumulated) === 3 ? $accumulated . '000' : str_replace('.', '', $accumulated);
            }
            $resolved = PohodaAssetAccountResolver::resolve($balances, $card['opening_price'], $accumulated);
            $account = $resolved === null ? null : ($codes[$resolved] ?? null);
            if ($account === null) {
                $card['reason'] = 'Majetkový účet v osnově firmy nelze z počátečních stavů jednoznačně určit.';
                continue;
            }
            $amount = round(($assigned[$account] ?? 0.0) + $card['opening_price'], 2);
            if ($amount > round((float) $opening[$account] + 1.0, 2)) {
                $card['reason'] = sprintf('Účet %s: součet cen karet %s Kč by převýšil počáteční stav %s Kč o více než 1 Kč.',
                    $account, number_format($amount, 2, ',', ' '), number_format((float) $opening[$account], 2, ',', ' '));
                continue;
            }
            $assigned[$account] = $amount;
            $card['new_account'] = $account;
            $card['reason'] = null;
        }
        unset($card);
        return ['reason' => null, 'cards' => $cards];
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
            "SELECT c.account_code, SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END)
               FROM journal_entry_lines l
               JOIN chart_of_accounts c ON c.supplier_id = l.supplier_id AND c.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND (c.account_code LIKE '01%' OR c.account_code LIKE '02%')
              GROUP BY c.account_code"
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $supplierId, ?string $start): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, a.inventory_number, a.name, a.description, a.status, a.asset_account_code, a.accumulated_account_code,
                    a.input_price + COALESCE((SELECT SUM(i.amount) FROM asset_improvements i
                         WHERE i.supplier_id = a.supplier_id AND i.asset_id = a.id AND i.completed_on < ?), 0) AS opening_price
               FROM assets a
              WHERE a.supplier_id = ? AND a.description LIKE ?
                AND (a.status = 'draft' OR (a.status = 'in_use'
                    AND NOT EXISTS (SELECT 1 FROM depreciation_entries d WHERE d.supplier_id = a.supplier_id AND d.asset_id = a.id)
                    AND NOT EXISTS (SELECT 1 FROM journal_entries e WHERE e.supplier_id = a.supplier_id AND e.source_type = 'asset' AND e.source_id = a.id)))
              ORDER BY a.id"
        );
        $stmt->execute([$start, $supplierId, self::NOTE_LIKE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

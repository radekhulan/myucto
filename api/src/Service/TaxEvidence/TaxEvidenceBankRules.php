<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

/**
 * Pravidla bankovních pohybů v daňové evidenci (issue #140, migrace 1990).
 *
 * Daňová evidence nemá účty, takže pravidla zaúčtování z podvojného účetnictví tu nedávají
 * smysl. Pohyb bez dokladu (poplatek, převod mezi vlastními účty, vklad z osobních peněz)
 * se ale opakuje a dosud se musel zařadit ručně. Pravidlo ho podle protiúčtu, VS, textu
 * a částky:
 *   - označí jako ignorovaný (nepáruje se s doklady),
 *   - a/nebo zařadí v peněžním deníku stejnou cestou jako ruční zařazení
 *     ({@see MovementClassificationRepository}). Uznatelnost výdaje je tatáž volba jako
 *     u pravidel nákladů: daňový / nedaňový výdaj.
 *
 * Jen na nespárovaný pohyb bez ručního zařazení — co uživatel rozhodl, pravidlo nepřepíše.
 * Ignorovaný pohyb v peněžním deníku zůstává (zůstatek s bankou), pravidlo mu jen určí kategorii.
 */
final class TaxEvidenceBankRules
{
    public const DIRECTIONS = ['any', 'incoming', 'outgoing'];

    private const COLUMNS = ['name', 'priority', 'is_active', 'direction', 'counterparty_account',
        'variable_symbol', 'text_contains', 'amount_min', 'amount_max', 'action_ignore', 'tax_bucket'];

    public function __construct(
        private readonly Connection $db,
        private readonly CashJournalRepository $journal,
        private readonly MovementClassificationRepository $classifications,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tax_evidence_bank_rules WHERE supplier_id = ? ORDER BY priority, id'
        );
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tax_evidence_bank_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Validace těla pravidla. Vrací chyby po polích (prázdné = v pořádku) a normalizovaná data.
     *
     * @return array{errors: array<string,string>, data: array<string,mixed>}
     */
    public static function validate(array $body): array
    {
        $errors = [];
        $text = static function (mixed $v, int $max): ?string {
            if ($v === null) return null;
            $s = trim((string) $v);
            return $s === '' ? null : mb_substr($s, 0, $max);
        };
        $amount = static function (mixed $v): ?float {
            if ($v === null || $v === '') return null;
            return is_numeric($v) ? round(abs((float) $v), 2) : null;
        };
        $data = [
            'name'                 => $text($body['name'] ?? null, 120),
            'priority'             => max(0, min(65535, (int) ($body['priority'] ?? 100))),
            'is_active'            => array_key_exists('is_active', $body) ? (filter_var($body['is_active'], FILTER_VALIDATE_BOOL) ? 1 : 0) : 1,
            'direction'            => (string) ($body['direction'] ?? 'any'),
            'counterparty_account' => $text($body['counterparty_account'] ?? null, 64),
            'variable_symbol'      => $text($body['variable_symbol'] ?? null, 20),
            'text_contains'        => $text($body['text_contains'] ?? null, 255),
            'amount_min'           => $amount($body['amount_min'] ?? null),
            'amount_max'           => $amount($body['amount_max'] ?? null),
            'action_ignore'        => filter_var($body['action_ignore'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'tax_bucket'           => $text($body['tax_bucket'] ?? null, 32),
        ];
        if ($data['name'] === null) {
            $errors['name'] = 'Vyplňte název pravidla.';
        }
        if (!in_array($data['direction'], self::DIRECTIONS, true)) {
            $errors['direction'] = 'Neplatný směr pohybu.';
        }
        if ($data['counterparty_account'] === null && $data['variable_symbol'] === null && $data['text_contains'] === null) {
            $errors['criteria'] = 'Vyplňte aspoň protiúčet, variabilní symbol nebo text.';
        }
        if ($data['tax_bucket'] !== null && !in_array($data['tax_bucket'], MovementClassificationRepository::TAX_BUCKETS, true)) {
            $errors['tax_bucket'] = 'Neplatné zařazení v peněžním deníku.';
        }
        if ($data['action_ignore'] === 0 && $data['tax_bucket'] === null) {
            $errors['action'] = 'Zvolte aspoň jednu akci: ignorovat nebo zařadit v peněžním deníku.';
        }
        if ($data['amount_min'] !== null && $data['amount_max'] !== null && $data['amount_min'] > $data['amount_max']) {
            $errors['amount_max'] = 'Maximální částka je menší než minimální.';
        }
        return ['errors' => $errors, 'data' => $data];
    }

    /** @param array<string,mixed> $data validovaná data z {@see validate()} */
    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $cols = self::COLUMNS;
        $sql = 'INSERT INTO tax_evidence_bank_rules (supplier_id, created_by, ' . implode(', ', $cols) . ')
                VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $params = [$supplierId, $userId];
        foreach ($cols as $c) {
            $params[] = $data[$c];
        }
        $this->db->pdo()->prepare($sql)->execute($params);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", self::COLUMNS));
        $params = [];
        foreach (self::COLUMNS as $c) {
            $params[] = $data[$c];
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare("UPDATE tax_evidence_bank_rules SET {$sets} WHERE id = ? AND supplier_id = ?");
        $stmt->execute($params);
        return $this->find($supplierId, $id) !== null;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tax_evidence_bank_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Uplatní aktivní pravidla na nespárované pohyby bez ručního zařazení. Bez `$statementId`
     * na všechny výpisy firmy (tlačítko „Uplatnit na stávající pohyby"), jinak jen na jeden
     * výpis (import). Mimo daňovou evidenci nic nedělá.
     *
     * @return array{applied: int, ignored: int, classified: int}
     */
    public function apply(int $supplierId, ?int $statementId = null, ?int $userId = null): array
    {
        $result = ['applied' => 0, 'ignored' => 0, 'classified' => 0];
        if (!$this->isTaxEvidence($supplierId)) {
            return $result;
        }
        $rules = array_values(array_filter($this->list($supplierId), static fn (array $r): bool => $r['is_active']));
        if ($rules === []) {
            return $result;
        }
        $statementIds = $this->journal->matchingStatementIds($supplierId);
        if ($statementId !== null) {
            $statementIds = in_array($statementId, $statementIds, true) ? [$statementId] : [];
        }
        if ($statementIds === []) {
            return $result;
        }
        $in = implode(',', array_map('intval', $statementIds));
        $pdo = $this->db->pdo();
        $txs = $pdo->query(
            "SELECT bt.id, bt.amount, bt.counterparty_account, bt.variable_symbol, bt.description,
                    bt.counterparty_name, bt.match_status
               FROM bank_transactions bt
              WHERE bt.statement_id IN ({$in})
                AND bt.match_status IN ('unmatched', 'ignored')
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id AND ip.supplier_id = {$supplierId})
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id AND pm.supplier_id = {$supplierId})
                AND NOT EXISTS (SELECT 1 FROM de_movement_classification c
                                 WHERE c.supplier_id = {$supplierId} AND c.bank_transaction_id = bt.id)
              ORDER BY bt.id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hits = [];
        foreach ($txs as $tx) {
            foreach ($rules as $rule) {
                if (!self::matches($rule, $tx)) {
                    continue;
                }
                $txId = (int) $tx['id'];
                if ($rule['action_ignore'] && (string) $tx['match_status'] === 'unmatched') {
                    $pdo->prepare(
                        "UPDATE bank_transactions SET match_status = 'ignored', ignore_origin = 'rule', ignore_note = ?
                          WHERE id = ? AND match_status = 'unmatched'"
                    )->execute([mb_substr('Pravidlo: ' . $rule['name'], 0, 1000), $txId]);
                    $result['ignored']++;
                }
                if ($rule['tax_bucket'] !== null) {
                    $this->classifications->upsert($supplierId, 'bank', $txId, (string) $rule['tax_bucket'],
                        mb_substr('Pravidlo: ' . $rule['name'], 0, 255), $userId);
                    $result['classified']++;
                }
                $result['applied']++;
                $hits[$rule['id']] = ($hits[$rule['id']] ?? 0) + 1;
                break;
            }
        }
        foreach ($hits as $ruleId => $count) {
            $pdo->prepare(
                'UPDATE tax_evidence_bank_rules SET hit_count = hit_count + ?, last_hit_at = NOW() WHERE id = ? AND supplier_id = ?'
            )->execute([$count, $ruleId, $supplierId]);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $tx
     */
    public static function matches(array $rule, array $tx): bool
    {
        $amount = (float) $tx['amount'];
        if ($rule['direction'] === 'incoming' && $amount < 0) return false;
        if ($rule['direction'] === 'outgoing' && $amount >= 0) return false;
        $abs = abs($amount);
        if ($rule['amount_min'] !== null && $abs < (float) $rule['amount_min'] - 0.005) return false;
        if ($rule['amount_max'] !== null && $abs > (float) $rule['amount_max'] + 0.005) return false;

        if ($rule['counterparty_account'] !== null) {
            $want = AccountNumberNormalizer::normalize((string) $rule['counterparty_account']);
            $have = AccountNumberNormalizer::normalize((string) ($tx['counterparty_account'] ?? ''));
            if ($want === '' || $want !== $have) return false;
        }
        if ($rule['variable_symbol'] !== null) {
            $want = ltrim(preg_replace('/\D/', '', (string) $rule['variable_symbol']) ?? '', '0');
            $have = ltrim(preg_replace('/\D/', '', (string) ($tx['variable_symbol'] ?? '')) ?? '', '0');
            if ($want === '' || $want !== $have) return false;
        }
        if ($rule['text_contains'] !== null) {
            $needle = mb_strtolower((string) $rule['text_contains']);
            $haystack = mb_strtolower(((string) ($tx['description'] ?? '')) . ' ' . ((string) ($tx['counterparty_name'] ?? '')));
            if (!str_contains($haystack, $needle)) return false;
        }
        return true;
    }

    private function isTaxEvidence(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'tax_evidence';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function cast(array $r): array
    {
        foreach (['id', 'supplier_id', 'priority', 'hit_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['is_active'] = (bool) $r['is_active'];
        $r['action_ignore'] = (bool) $r['action_ignore'];
        foreach (['amount_min', 'amount_max'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        }
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        return $r;
    }
}

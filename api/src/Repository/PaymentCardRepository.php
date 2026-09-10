<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use PDO;

/**
 * Platební karty firmy (`payment_cards`). Každý dotaz nese supplier_id.
 *
 * Karta nese jen koncovku. Kdo potřebuje vědět „čí je platba kartou" (párování
 * plateb, přehled plateb bez dokladu, kniha jízd), ptá se přes
 * {@see findByLast4OnDate()} — jediné rozhraní „karta podle koncovky a data".
 * Překryv platnosti dvou karet se stejnou koncovkou u jedné firmy se nepřipouští
 * (viz {@see overlapping()}), takže odpověď je vždy jednoznačná.
 */
final class PaymentCardRepository
{
    public const CARD_TYPES = ['debit', 'credit', 'prepaid', 'fuel', 'other'];
    public const CARD_NETWORKS = ['visa', 'mastercard', 'maestro', 'amex', 'other'];

    private const SELECT = 'SELECT pc.*,
                    pe.full_name AS employee_name,
                    u.name AS user_name,
                    cur.code AS currency_code, cur.label AS currency_label,
                    cur.account_number AS account_number, cur.bank_code AS account_bank_code
               FROM payment_cards pc
          LEFT JOIN payroll_employees pe ON pe.id = pc.employee_id AND pe.supplier_id = pc.supplier_id
          LEFT JOIN users u ON u.id = pc.user_id
          LEFT JOIN currencies cur ON cur.id = pc.currency_id AND cur.supplier_id = pc.supplier_id';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $includeArchived = false): array
    {
        $sql = self::SELECT . ' WHERE pc.supplier_id = ?'
            . ($includeArchived ? '' : ' AND pc.archived_at IS NULL')
            . ' ORDER BY pc.archived_at IS NOT NULL, pc.label, pc.last4, pc.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE pc.id = ? AND pc.supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Karta firmy, která měla k datu $date koncovku $last4. Archivovaná karta se
     * vrací taky — historický pohyb patří kartě, která tehdy platila. Žádná karta
     * nebo (neočekávaně) víc karet = null.
     *
     * @return array<string,mixed>|null
     */
    public function findByLast4OnDate(int $supplierId, string $last4, string $date): ?array
    {
        if (!CardNumberMask::isValidLast4($last4)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE pc.supplier_id = ? AND pc.last4 = ?
                AND (pc.valid_from IS NULL OR pc.valid_from <= ?)
                AND (pc.valid_to IS NULL OR pc.valid_to >= ?)
              LIMIT 2'
        );
        $stmt->execute([$supplierId, $last4, $date, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return count($rows) === 1 ? $this->cast($rows[0]) : null;
    }

    /**
     * Karty k dávce pohybů (detail výpisu, přehled plateb) jedním dotazem.
     *
     * @param list<array<string,mixed>> $transactions řádky s `id`, `card_last4`, `posted_at`
     * @return array<int, array<string,mixed>> id pohybu => stručný popis karty
     */
    public function resolveForTransactions(int $supplierId, array $transactions): array
    {
        $wanted = [];
        foreach ($transactions as $tx) {
            $last4 = isset($tx['card_last4']) ? (string) $tx['card_last4'] : '';
            if (CardNumberMask::isValidLast4($last4)) {
                $wanted[$last4] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($wanted), '?'));
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . " WHERE pc.supplier_id = ? AND pc.last4 IN ($ph)"
        );
        $stmt->execute(array_merge([$supplierId], array_keys($wanted)));
        $byLast4 = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byLast4[(string) $row['last4']][] = $this->cast($row);
        }

        $out = [];
        foreach ($transactions as $tx) {
            $last4 = isset($tx['card_last4']) ? (string) $tx['card_last4'] : '';
            $date = (string) ($tx['posted_at'] ?? '');
            $hits = array_values(array_filter(
                $byLast4[$last4] ?? [],
                static fn (array $c): bool => ($c['valid_from'] === null || $c['valid_from'] <= $date)
                    && ($c['valid_to'] === null || $c['valid_to'] >= $date),
            ));
            if (count($hits) === 1) {
                $out[(int) $tx['id']] = self::summary($hits[0]);
            }
        }
        return $out;
    }

    /**
     * Karta téže firmy se stejnou koncovkou, jejíž platnost se překrývá s <$from, $to>.
     * NULL hranice = neomezeno.
     *
     * @return array<string,mixed>|null
     */
    public function overlapping(int $supplierId, string $last4, ?string $from, ?string $to, ?int $exceptId = null): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE pc.supplier_id = ? AND pc.last4 = ? AND pc.id <> ?
                AND (pc.valid_to IS NULL OR ? IS NULL OR pc.valid_to >= ?)
                AND (pc.valid_from IS NULL OR ? IS NULL OR pc.valid_from <= ?)
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $last4, $exceptId ?? 0, $from, $from, $to, $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param array<string,mixed> $data normalizovaný vstup z PaymentCardInput */
    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_cards
                (supplier_id, label, holder_name, last4, card_type, card_network, currency_id,
                 employee_id, user_id, valid_from, valid_to, is_active, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['label'], $data['holder_name'], $data['last4'], $data['card_type'],
            $data['card_network'], $data['currency_id'], $data['employee_id'], $data['user_id'],
            $data['valid_from'], $data['valid_to'], $data['is_active'] ? 1 : 0, $data['note'], $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Karta, kterou systém založil sám z koncovky ve výpisu (režim účtování karet přes
     * mezičlen). Je neověřená, dokud ji někdo neotevře a neuloží — do té doby ji stránka
     * Platební karty ukazuje mezi kartami k doplnění.
     */
    public function createUnverified(int $supplierId, string $last4, ?int $currencyId, string $label): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payment_cards
                (supplier_id, label, last4, card_type, currency_id, is_active, is_verified)
             VALUES (?, ?, ?, 'debit', ?, 1, 0)"
        )->execute([$supplierId, mb_substr($label, 0, 120), $last4, $currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Karty firmy s danou koncovkou bez ohledu na platnost (i archivované). @return list<array<string,mixed>> */
    public function findAllByLast4(int $supplierId, string $last4): array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE pc.supplier_id = ? AND pc.last4 = ? ORDER BY pc.id');
        $stmt->execute([$supplierId, $last4]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Přidělí suffix analytiky, jen když ho karta ještě nemá. Souběh dvou importů řeší
     * unikátní index (supplier_id, analytic_suffix) a podmínka na prázdný sloupec.
     */
    public function assignSuffixIfEmpty(int $supplierId, int $id, string $suffix): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payment_cards SET analytic_suffix = ?
                  WHERE id = ? AND supplier_id = ? AND analytic_suffix IS NULL'
            );
            $stmt->execute([$suffix, $id, $supplierId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function markVerified(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('UPDATE payment_cards SET is_verified = 1 WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /** Ruční výběr analytiky mezičlenu v detailu karty (validuje volající). */
    public function setAnalyticSuffix(int $supplierId, int $id, ?string $suffix): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payment_cards SET analytic_suffix = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$suffix, $id, $supplierId]);
    }

    /** @return array<string,int> suffix => id karty */
    public function usedSuffixes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT analytic_suffix, id FROM payment_cards WHERE supplier_id = ? AND analytic_suffix IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['analytic_suffix']] = (int) $r['id'];
        }
        return $out;
    }

    /** @param array<string,mixed> $data normalizovaný vstup z PaymentCardInput */
    public function update(int $supplierId, int $id, array $data): void
    {
        // Uložení z formuláře je ověření karty člověkem — i té, kterou založil import.
        $this->db->pdo()->prepare(
            'UPDATE payment_cards
                SET label = ?, holder_name = ?, last4 = ?, card_type = ?, card_network = ?, currency_id = ?,
                    employee_id = ?, user_id = ?, valid_from = ?, valid_to = ?, is_active = ?, note = ?,
                    is_verified = 1
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $data['label'], $data['holder_name'], $data['last4'], $data['card_type'], $data['card_network'],
            $data['currency_id'], $data['employee_id'], $data['user_id'], $data['valid_from'], $data['valid_to'],
            $data['is_active'] ? 1 : 0, $data['note'], $id, $supplierId,
        ]);
    }

    /**
     * Archivace = karta se už nepoužívá. Nezadaná „platnost do" se uzavře dneškem,
     * ať nová karta se stejnou koncovkou může platit od zítřka a historické pohyby
     * zůstanou přiřazené té archivované.
     */
    public function archive(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payment_cards
                SET is_active = 0, archived_at = NOW(),
                    valid_to = COALESCE(valid_to, GREATEST(CURDATE(), COALESCE(valid_from, CURDATE())))
              WHERE id = ? AND supplier_id = ? AND archived_at IS NULL'
        )->execute([$id, $supplierId]);
    }

    public function restore(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payment_cards SET is_active = 1, archived_at = NULL WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
    }

    public function employeeBelongs(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employees WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$employeeId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function userBelongs(int $supplierId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM user_suppliers us JOIN users u ON u.id = us.user_id
              WHERE us.user_id = ? AND us.supplier_id = ?'
        );
        $stmt->execute([$userId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Nabídka držitelů do formuláře: zaměstnanci a uživatelé firmy.
     *
     * @return array{employees: list<array{id:int,name:string}>, users: list<array{id:int,name:string}>}
     */
    public function holderCandidates(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $emp = $pdo->prepare('SELECT id, full_name AS name FROM payroll_employees WHERE supplier_id = ? ORDER BY full_name, id');
        $emp->execute([$supplierId]);
        $usr = $pdo->prepare(
            'SELECT u.id, u.name FROM user_suppliers us JOIN users u ON u.id = us.user_id
              WHERE us.supplier_id = ? AND u.is_active = 1 ORDER BY u.name, u.id'
        );
        $usr->execute([$supplierId]);
        $map = static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        return [
            'employees' => array_map($map, $emp->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'users'     => array_map($map, $usr->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
    }

    /**
     * Stručný popis karty pro bankovní pohyb a přehledy.
     *
     * @param array<string,mixed> $card výstup {@see cast()}
     * @return array{id:int, label:string, last4:string, holder:?string, employee_id:?int, user_id:?int, archived:bool}
     */
    public static function summary(array $card): array
    {
        return [
            'id'          => (int) $card['id'],
            'label'       => (string) $card['label'],
            'last4'       => (string) $card['last4'],
            'holder'      => $card['holder'] ?? null,
            'employee_id' => $card['employee_id'] ?? null,
            'user_id'     => $card['user_id'] ?? null,
            'archived'    => (bool) ($card['archived'] ?? false),
            'is_verified' => (bool) ($card['is_verified'] ?? true),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $employeeName = isset($r['employee_name']) && $r['employee_name'] !== null ? (string) $r['employee_name'] : null;
        $userName = isset($r['user_name']) && $r['user_name'] !== null ? (string) $r['user_name'] : null;
        $holderName = $r['holder_name'] !== null ? (string) $r['holder_name'] : null;
        return [
            'id'                => (int) $r['id'],
            'supplier_id'       => (int) $r['supplier_id'],
            'label'             => (string) $r['label'],
            'holder_name'       => $holderName,
            'holder'            => $holderName ?? $employeeName ?? $userName,
            'last4'             => (string) $r['last4'],
            'card_type'         => (string) $r['card_type'],
            'card_network'      => $r['card_network'] !== null ? (string) $r['card_network'] : null,
            'currency_id'       => $r['currency_id'] !== null ? (int) $r['currency_id'] : null,
            'currency_code'     => isset($r['currency_code']) ? ($r['currency_code'] !== null ? (string) $r['currency_code'] : null) : null,
            'account_label'     => isset($r['currency_label']) && $r['currency_label'] !== null ? (string) $r['currency_label'] : null,
            'account_number'    => isset($r['account_number']) && $r['account_number'] !== null
                ? (string) $r['account_number'] . (isset($r['account_bank_code']) && $r['account_bank_code'] !== null ? '/' . $r['account_bank_code'] : '')
                : null,
            'employee_id'       => $r['employee_id'] !== null ? (int) $r['employee_id'] : null,
            'employee_name'     => $employeeName,
            'user_id'           => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user_name'         => $userName,
            'valid_from'        => $r['valid_from'] !== null ? (string) $r['valid_from'] : null,
            'valid_to'          => $r['valid_to'] !== null ? (string) $r['valid_to'] : null,
            'is_active'         => (bool) $r['is_active'],
            'is_verified'       => (bool) ($r['is_verified'] ?? true),
            'analytic_suffix'   => isset($r['analytic_suffix']) && $r['analytic_suffix'] !== null ? (string) $r['analytic_suffix'] : null,
            'archived'          => $r['archived_at'] !== null,
            'archived_at'       => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
            'note'              => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'        => (string) $r['created_at'],
        ];
    }
}

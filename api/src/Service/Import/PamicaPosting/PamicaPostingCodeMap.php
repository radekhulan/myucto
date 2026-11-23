<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import\PamicaPosting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use PDO;

/**
 * Mapování zkratek předkontací mzdového programu na účty firmy (migrace 2317).
 *
 * PAMICA nastavená na předkontace POHODY vyváží místo dvojice účtů zkratku (7Int, 4Oz).
 * Účetní jednou přiřadí každé zkratce účet MD a účet Dal z osnovy firmy; import zaúčtování
 * mezd je pak použije sám. Zkratka vždy znamená celou dvojici, nikdy jen jednu stranu.
 * Zkratky se porovnávají bez ohledu na velikost písmen ({@see key()}), stejně jako sloupec
 * s collation `utf8mb4_unicode_ci` v unikátním klíči.
 */
final class PamicaPostingCodeMap
{
    public const MAX_CODES = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    /** Vypadá text předkontace jako zkratka (písmena, číslice, tečka, podtržítko, pomlčka)? */
    public static function isCode(string $value): bool
    {
        return preg_match('/^[\p{L}\p{N}._-]{1,20}$/u', $value) === 1;
    }

    /** Klíč pro porovnání zkratek bez ohledu na velikost písmen. */
    public static function key(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Mapování firmy pro parser.
     *
     * @return array<string,array{debit:string,credit:string}> klíč zkratky => účty
     */
    public function forSupplier(int $supplierId, string $application): array
    {
        $map = [];
        foreach ($this->rows($supplierId, $application) as $row) {
            $map[self::key($row['code'])] = ['debit' => $row['debit_account_code'], 'credit' => $row['credit_account_code']];
        }
        return $map;
    }

    /**
     * @param list<string>|null $codes jen tyto zkratky (null = všechny)
     * @return list<array{code:string,debit_account_code:string,credit_account_code:string,updated_at:?string}>
     */
    public function rows(int $supplierId, string $application, ?array $codes = null): array
    {
        if ($codes === []) return [];
        $sql = 'SELECT code, debit_account_code, credit_account_code, updated_at
                  FROM payroll_posting_code_maps WHERE supplier_id = ? AND source_application = ?';
        $params = [$supplierId, $application];
        if ($codes !== null) {
            $sql .= ' AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($params, ...array_values($codes));
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY code');
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'code' => (string) $r['code'],
            'debit_account_code' => (string) $r['debit_account_code'],
            'credit_account_code' => (string) $r['credit_account_code'],
            'updated_at' => $r['updated_at'] !== null ? (string) $r['updated_at'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Uloží (přepíše) přiřazení zkratek. Oba účty musí v osnově firmy existovat, být aktivní
     * a lišit se; jinak se neuloží nic.
     *
     * @param list<mixed> $input [{code, debit_account_code, credit_account_code}]
     * @return list<array{code:string,debit_account_code:string,credit_account_code:string,updated_at:?string}>
     */
    public function save(int $supplierId, string $application, array $input, ?int $userId): array
    {
        if ($input === [] || count($input) > self::MAX_CODES) {
            throw new PamicaPostingException('invalid_code_map', 'Pošlete 1 až ' . self::MAX_CODES . ' zkratek předkontací.');
        }
        $rows = [];
        foreach ($input as $i => $row) {
            $label = 'Řádek ' . ((int) $i + 1);
            if (!is_array($row)) {
                throw new PamicaPostingException('invalid_code_map', "{$label}: neplatný záznam.");
            }
            $code = trim((string) ($row['code'] ?? ''));
            if (!self::isCode($code)) {
                throw new PamicaPostingException('invalid_code_map', "{$label}: zkratka předkontace „{$code}\" není platná.");
            }
            $debit = $this->account($supplierId, $row['debit_account_code'] ?? null, $code, 'MD');
            $credit = $this->account($supplierId, $row['credit_account_code'] ?? null, $code, 'Dal');
            if (mb_strtoupper($debit) === mb_strtoupper($credit)) {
                throw new PamicaPostingException('invalid_code_map', "Zkratka {$code}: účet MD a účet Dal musí být různé.");
            }
            $rows[self::key($code)] = [$code, $debit, $credit];
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_code_maps
                (supplier_id, source_application, code, debit_account_code, credit_account_code, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE code = VALUES(code), debit_account_code = VALUES(debit_account_code),
                credit_account_code = VALUES(credit_account_code), updated_by = VALUES(updated_by)'
        );
        foreach ($rows as [$code, $debit, $credit]) {
            $stmt->execute([$supplierId, $application, $code, $debit, $credit, $userId]);
        }
        return $this->rows($supplierId, $application, array_column(array_values($rows), 0));
    }

    private function account(int $supplierId, mixed $value, string $code, string $side): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new PamicaPostingException('invalid_code_map', "Zkratka {$code}: vyberte účet {$side}.");
        }
        $row = mb_strlen($value) <= 20 ? $this->accounts->findByCode($supplierId, $value) : null;
        if ($row === null) {
            throw new PamicaPostingException('invalid_code_map', "Zkratka {$code}: účet {$side} {$value} v účtové osnově firmy není.");
        }
        if (empty($row['is_active'])) {
            throw new PamicaPostingException('invalid_code_map', "Zkratka {$code}: účet {$side} {$value} je neaktivní.");
        }
        // Syntetika s analytikami se neúčtuje (stejně jako u účtů PAMICY v importu): závazek
        // by se rozpadl na syntetiku a analytiku a součet analytik by na syntetiku neseděl.
        $children = array_values(array_filter($this->accounts->childrenOf($supplierId, (int) $row['id']),
            static fn (array $c): bool => !empty($c['is_active'])));
        if ($children !== []) {
            throw new PamicaPostingException('invalid_code_map',
                "Zkratka {$code}: účet {$side} {$value} je syntetický a člení se na analytiky. Vyberte analytický účet (např. "
                . $children[0]['account_code'] . ').');
        }
        return (string) ($row['account_code'] ?? $value);
    }
}

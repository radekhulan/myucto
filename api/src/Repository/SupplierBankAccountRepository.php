<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

final class SupplierBankAccountRepository
{
    private const COLUMNS = 'id, supplier_id, currency_id, label, account_number, bank_code, iban,
        currency, account_canonical, kind, analytic_suffix, document_series, source, is_active, created_at, updated_at';

    public function __construct(
        private readonly Connection $db,
        private readonly BankStatementOwnershipResolver $ownership,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? ORDER BY is_active DESC, label, id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function findActive(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function matchCounterparty(int $supplierId, string $counterpartyAccount, ?string $counterpartyBank, bool $includeInactive = false, bool $currentRead = false): ?array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Current bank account reads require a transaction.');
        }
        $canonical = AccountNumberNormalizer::canonical($counterpartyAccount);
        if ($canonical === null) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($counterpartyBank, $counterpartyAccount);
        // Úvěrový účet kreditní karty se najde i neaktivní: bez něj by vlastní noha pohybu
        // spadla na holé 221 a dluh vůči bance by se v účetnictví tvářil jako peníze.
        $sql = "SELECT " . self::COLUMNS . " FROM supplier_bank_accounts
                 WHERE supplier_id = ? AND " . ($includeInactive ? '1 = 1' : "(is_active = 1 OR kind = 'credit_card')") . " AND account_canonical = ?";
        $params = [$supplierId, $canonical];
        $activeFirst = $includeInactive ? 'is_active DESC, ' : '';
        if ($bank !== null) {
            $sql .= " AND bank_code_norm IN ('', ?) ORDER BY (bank_code_norm = ?) DESC, {$activeFirst}id ASC";
            $params[] = $bank;
            $params[] = $bank;
        } else {
            $sql .= " ORDER BY {$activeFirst}id ASC";
        }
        $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if ($bank === null && count($rows) !== 1) {
            return null;
        }
        return $this->cast($rows[0]);
    }

    /**
     * Zaeviduje vlastní účet, který jsme viděli na výpisu.
     *
     * Dvě pojistky (SEC-01 / R4 — vlastnictví se nesmí odvozovat z čísla účtu):
     *
     *  1. Je-li znám `currencies` řádek, kanonizuje se PROVĚŘENÁ hodnota z něj,
     *     ne syrový řetězec z hlavičky výpisu. Hlavičku plní importovaný soubor,
     *     takže bez tohohle si šlo pod cizí (explicitně zvolený) měnový účet
     *     vyrobit `account_canonical` na libovolné číslo.
     *  2. Claim guard: účet, který v `currencies` drží JINÁ firma, se
     *     nezaevidovává. Jinak by v `supplier_bank_accounts` vznikly dva řádky
     *     se shodným `account_canonical` u dvou tenantů — přesně ta kolize,
     *     ze které těžilo odvozování vlastníka podle čísla účtu.
     */
    public function registerSeen(
        int $supplierId,
        string $accountNumber,
        ?string $bankCode,
        ?string $currency,
        ?int $currencyId,
    ): void {
        $verified = $currencyId !== null ? $this->verifiedAccount($supplierId, $currencyId) : null;
        // Cizí (nebo neexistující) currency_id se do registru nepropíše — vazba by
        // ukazovala mimo tenanta.
        if ($verified === null) {
            $currencyId = null;
        }
        $iban = null;
        if ($verified !== null) {
            $verifiedNumber = trim((string) ($verified['account_number'] ?? ''));
            $iban = trim((string) ($verified['iban'] ?? '')) !== '' ? (string) $verified['iban'] : null;
            if ($verifiedNumber !== '' || $iban !== null) {
                $accountNumber = $verifiedNumber !== '' ? $verifiedNumber : $accountNumber;
                $verifiedBank = trim((string) ($verified['bank_code'] ?? ''));
                if ($verifiedBank !== '') {
                    $bankCode = $verifiedBank;
                }
            }
        }

        $canonical = AccountNumberNormalizer::canonical($accountNumber, $iban);
        if ($canonical === null) {
            return;
        }
        if ($this->ownership->accountClaimedByOtherSupplier($supplierId, $accountNumber, $iban)) {
            return;
        }
        // Kód banky ZÁMĚRNĚ bez IBAN fallbacku: `bank_code_norm` se porovnává s
        // `bank_statements.bank_code`, které import plní z téhož `currencies.bank_code`.
        // Dopočet z IBANu by registru dal kód, jaký výpis nenese, a shoda by se rozešla.
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, currency_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "current", "statement", 1)
             ON DUPLICATE KEY UPDATE
                currency_id = CASE
                    WHEN currency_id IS NULL THEN NULL
                    WHEN VALUES(currency_id) IS NULL OR currency_id = VALUES(currency_id) THEN currency_id
                    ELSE NULL END,
                currency = CASE
                    WHEN currency IS NULL THEN NULL
                    WHEN VALUES(currency) IS NULL OR currency = VALUES(currency) THEN currency
                    ELSE NULL END,
                account_number = COALESCE(account_number, VALUES(account_number)),
                is_active = 1'
        )->execute([
            $supplierId,
            $currencyId,
            'Účet ' . $accountNumber,
            $accountNumber,
            $bank,
            $bank ?? '',
            $currency !== null ? strtoupper($currency) : null,
            $canonical,
        ]);
    }

    /**
     * Vlastní účet převzatý z jiného účetního programu: číslo, měna, název a analytika
     * 221.xxx podle toho programu. Pohyby na té analytice patří právě tomuto účtu, takže
     * tady (na rozdíl od automatického přidělování v BankAnalyticAssigner) je převzetí
     * analytiky i s historií správně. Existující účet si své údaje ponechá, doplní se jen
     * chybějící; analytiku, kterou drží jiný účet firmy, nepřebírá. Účet, který drží jiná
     * firma, se nezaeviduje (stejná pojistka jako {@see registerSeen()}).
     *
     * @return int|null id účtu; null = číslo nejde kanonizovat nebo patří jiné firmě
     */
    public function registerImported(
        int $supplierId,
        string $accountNumber,
        ?string $bankCode,
        ?string $iban,
        string $currency,
        string $label,
        ?string $suffix,
    ): ?int {
        $iban = $iban !== null && trim($iban) !== '' ? trim($iban) : null;
        $canonical = AccountNumberNormalizer::canonical($accountNumber, $iban);
        if ($canonical === null || $this->ownership->accountClaimedByOtherSupplier($supplierId, $accountNumber, $iban)) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode);
        if ($bank !== null) $this->promoteImportedBankCode($supplierId, $canonical, strtoupper($currency), $bank);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, iban, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "current", "statement", 1)
             ON DUPLICATE KEY UPDATE
                label = IF(label IS NULL OR label = "" OR label LIKE "Účet %", VALUES(label), label),
                iban = COALESCE(iban, VALUES(iban)),
                currency = COALESCE(currency, VALUES(currency)),
                is_active = 1'
        )->execute([
            $supplierId,
            mb_substr($label, 0, 100),
            $accountNumber,
            $bank,
            $bank ?? '',
            $iban,
            strtoupper($currency),
            $canonical,
        ]);
        $stmt = $pdo->prepare(
            'SELECT id, analytic_suffix FROM supplier_bank_accounts
              WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ?'
        );
        $stmt->execute([$supplierId, $canonical, $bank ?? '']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $id = (int) $row['id'];
        if ($suffix !== null && preg_match('/^[0-9]{1,6}$/', $suffix) === 1
            && ($row['analytic_suffix'] ?? '') === '' && $this->findBySuffix($supplierId, $suffix) === null) {
            $this->assignSuffix($supplierId, $id, $suffix);
        }
        return $id;
    }

    private function promoteImportedBankCode(int $supplierId, string $canonical, string $currency, string $bank): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, currency, source FROM supplier_bank_accounts
            WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ""');
        $stmt->execute([$supplierId, $canonical]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['source'] !== 'statement'
            || !in_array($row['currency'], [null, $currency], true)) return;
        try {
            $pdo->prepare('UPDATE supplier_bank_accounts SET bank_code = ?, bank_code_norm = ?
                WHERE id = ? AND supplier_id = ? AND bank_code_norm = ""')
                ->execute([$bank, $bank, (int) $row['id'], $supplierId]);
        } catch (\PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $error;
        }
    }

    public function registerImportedVirtual(int $supplierId, string $identity, string $currency, string $label, ?string $suffix): int
    {
        $canonical = 'V' . substr(hash('sha256', $identity), 0, 19);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO supplier_bank_accounts
            (supplier_id, label, account_number, bank_code_norm, currency, account_canonical, kind, source, is_active)
            VALUES (?, ?, NULL, "", ?, ?, "current", "manual", 1)
            ON DUPLICATE KEY UPDATE is_active = 1')->execute([
                $supplierId, mb_substr($label, 0, 100), strtoupper($currency), $canonical,
            ]);
        $stmt = $pdo->prepare('SELECT id, analytic_suffix FROM supplier_bank_accounts
            WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ""');
        $stmt->execute([$supplierId, $canonical]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = (int) $row['id'];
        if ($suffix !== null && preg_match('/^[0-9]{1,6}$/D', $suffix) === 1
            && ($row['analytic_suffix'] ?? '') === '' && $this->findBySuffix($supplierId, $suffix) === null) {
            $this->assignSuffix($supplierId, $id, $suffix);
        }
        return $id;
    }

    /**
     * `currencies` řádek firmy — prověřený zdroj čísla účtu / IBANu pro
     * {@see registerSeen()}. Cizí (nebo neexistující) id vrací null, takže se
     * spadne zpátky na hodnotu od volajícího, kterou ještě prověří claim guard.
     *
     * @return array{account_number:?string,iban:?string,bank_code:?string}|null
     */
    private function verifiedAccount(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_number, iban, bank_code FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Suffixy analytiky 221, které už drží nějaký účet firmy — VČETNĚ neaktivních.
     * Neaktivní účet si číslo drží dál, jinak by nový účet zdědil jeho historii.
     *
     * @return array<string,true>
     */
    public function usedSuffixes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT analytic_suffix FROM supplier_bank_accounts
              WHERE supplier_id = ? AND analytic_suffix IS NOT NULL AND analytic_suffix <> ''"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $suffix) {
            $out[(string) $suffix] = true;
        }
        return $out;
    }

    /** Účet firmy držící daný suffix (unikátní přes uq_sba_analytic), nebo null. */
    public function findBySuffix(int $supplierId, string $suffix): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts
              WHERE supplier_id = ? AND analytic_suffix = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $suffix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Přidělí suffix účtu, který ho ještě NEMÁ (existující se nikdy nepřepíše).
     * Vrací false při souběhu — jiný proces stihl přidělit dřív (unique index) —
     * i když účet mezitím suffix získal; volající si ho přečte znovu.
     */
    public function assignSuffix(int $supplierId, int $id, string $suffix): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                "UPDATE supplier_bank_accounts SET analytic_suffix = ?
                  WHERE supplier_id = ? AND id = ? AND (analytic_suffix IS NULL OR analytic_suffix = '')"
            );
            $stmt->execute([$suffix, $supplierId, $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Dokladové řady, které už drží nějaký účet firmy (i neaktivní — jeho zápisy řadu nesou dál).
     *
     * @return array<string,true>
     */
    public function usedDocumentSeries(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document_series FROM supplier_bank_accounts
              WHERE supplier_id = ? AND document_series IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $series) {
            $out[(string) $series] = true;
        }
        return $out;
    }

    public function findByDocumentSeries(int $supplierId, string $series): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts
              WHERE supplier_id = ? AND document_series = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $series]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Přidělí řadu účtu, který ji ještě NEMÁ. False při souběhu (unique index
     * uq_sba_document_series) i když ji účet mezitím dostal; volající si ji přečte znovu.
     */
    public function assignDocumentSeries(int $supplierId, int $id, string $series): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE supplier_bank_accounts SET document_series = ?
                  WHERE supplier_id = ? AND id = ? AND document_series IS NULL'
            );
            $stmt->execute([$series, $supplierId, $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array{kind?:string,label?:?string,is_active?:bool,analytic_suffix?:?string,document_series?:string} $patch */
    public function update(int $supplierId, int $id, array $patch): bool
    {
        $sets = [];
        $params = [];
        foreach (['kind', 'label', 'analytic_suffix', 'document_series'] as $field) {
            if (array_key_exists($field, $patch)) {
                $sets[] = $field . ' = ?';
                $params[] = $patch[$field];
            }
        }
        if (array_key_exists('is_active', $patch)) {
            $sets[] = 'is_active = ?';
            $params[] = $patch['is_active'] ? 1 : 0;
        }
        if ($sets === []) {
            return $this->find($supplierId, $id) !== null;
        }
        $params[] = $supplierId;
        $params[] = $id;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier_bank_accounts SET ' . implode(', ', $sets) . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->find($supplierId, $id) !== null;
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['currency_id'] = $row['currency_id'] === null ? null : (int) $row['currency_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}

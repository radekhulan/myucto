<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Vlastní bankovní účty firmy převzaté z cizího účetního programu:
 *  1. evidence účtů firmy (`supplier_bank_accounts`, záložka Kontace) s analytikou 221
 *     ze zdroje — bez ní se pohyby banky po převodu nemají kam zaúčtovat;
 *  2. účty firmy v měnách (`currencies`, tab Účty a zůstatky banky): prázdný řádek
 *     měny doplní první účet, každý další použitý účet dostane vlastní řádek a evidence
 *     účtů se na něj naváže (`supplier_bank_accounts.currency_id`).
 *
 * Kanonický účet zdroje: `number`, `bank`, `iban`, `currency`, `label` (už s náhradním
 * názvem, jaký zdroj používá), `suffix` (analytika 221.xxx bez syntetiky, nebo null).
 */
final class BankAccountRegistrar
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierBankAccountRepository $bankAccounts,
    ) {}

    /**
     * Porovnání čísla účtu bez oddělovačů a úvodních nul (`19-123/0100` = `0000190000123`)
     * spolu s kódem banky bez úvodních nul.
     */
    public static function accountKey(string $number, string $bank): string
    {
        if (preg_match('/^[A-Za-z]+-[0-9]+$/D', $number) === 1) return 'virtual:' . strtoupper($number);
        return AccountNumberNormalizer::normalize($number) . '/' . ltrim(trim($bank), '0');
    }

    /**
     * Zaeviduje účty do evidence účtů firmy. Účet bez čísla i IBANu se přeskočí; účet
     * s IBANem bez čísla se eviduje podle IBANu.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,label:string,suffix:?string}> $accounts kód účtu ve zdroji → účet
     * @param string|null $countStep krok protokolu pro počty `accounts_registered` / `accounts_rejected` (null = nepočítat)
     * @return array<string,int> kód účtu ve zdroji → supplier_bank_accounts.id (účet cizí firmy chybí)
     */
    public function register(int $supplierId, array $accounts, ?ImportProtocol $protocol = null, ?string $countStep = null): array
    {
        $registered = [];
        foreach ($accounts as $code => $a) {
            $number = $a['number'] !== '' ? $a['number'] : $a['iban'];
            if ($number === '') {
                continue;
            }
            $id = $this->bankAccounts->registerImported(
                $supplierId,
                $number,
                $a['bank'] !== '' ? $a['bank'] : null,
                $a['iban'] !== '' ? $a['iban'] : null,
                $a['currency'],
                $a['label'],
                $a['suffix'],
            );
            if ($id !== null) {
                $registered[(string) $code] = $id;
            }
            if ($protocol !== null && $countStep !== null) {
                $protocol->count($countStep, $id !== null ? 'accounts_registered' : 'accounts_rejected');
            }
        }
        return $registered;
    }

    public function registerVirtual(int $supplierId, string $identity, string $currency, string $label, ?string $suffix): int
    {
        return $this->bankAccounts->registerImportedVirtual($supplierId, $identity, $currency, $label, $suffix);
    }

    /**
     * Doplní číslo účtu na řádek měny firmy, který ho ještě nemá (vyplněný účet se
     * nepřepisuje, IBAN jen doplní). Hodnoty už zkrácené a s NULL místo prázdných.
     */
    public function fillCurrencyAccount(int $supplierId, string $currency, string $number, ?string $bank, ?string $iban): void
    {
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = ?, bank_code = ?, iban = COALESCE(iban, ?)
              WHERE supplier_id = ? AND code = ? AND (account_number IS NULL OR account_number = '')"
        )->execute([$number, $bank, $iban, $supplierId, $currency]);
    }

    /**
     * Každý použitý účet s číslem, který je v evidenci účtů firmy, patří mezi účty firmy
     * v měnách: chybí-li tam řádek jeho měny (podle {@see accountKey()}), dostane vlastní řádek
     * a evidence účtů se na něj naváže. Účet v cizí měně nejdřív obsadí řádek své měny bez čísla
     * účtu (výchozí řádek měny firmy), nebo převezme řádek své měny, který ho už nese třeba
     * jen IBANem. Řádek jiné měny se stejným číslem (víceměnový účet banky) se nepoužije.
     * Opakovaný převod řádky nezdvojí.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,label:string}> $accounts
     * @param list<string> $used kódy účtů s pohyby v převáděném období
     * @param array<string,int> $registered výsledek {@see register()}
     * @param bool $namesFromExistingCurrency symbol a názvy nového korunového řádku z existující
     *        měny (Money S3); false = pevně česká koruna. Cizí měna je bere z řádku své měny vždy.
     */
    public function linkCompanyAccounts(int $supplierId, array $accounts, array $used, array $registered, ImportProtocol $protocol, string $step, bool $namesFromExistingCurrency): void
    {
        $pdo = $this->db->pdo();
        $known = [];
        $byCode = [];
        $existing = $pdo->prepare('SELECT id, code, account_number, bank_code, iban, symbol, name_cs, name_en FROM currencies WHERE supplier_id = ? ORDER BY id');
        $existing->execute([$supplierId]);
        $rows = $existing->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $byCode[(string) $row['code']] ??= $row;
            if ((string) ($row['account_number'] ?? '') !== '') {
                $known[$row['code'] . '|' . self::accountKey((string) $row['account_number'], (string) $row['bank_code'])] ??= (int) $row['id'];
            }
        }
        $insert = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?, ?)'
        );
        $link = $pdo->prepare('UPDATE supplier_bank_accounts SET currency_id = ?
            WHERE id = ? AND supplier_id = ? AND currency_id IS NULL AND (currency IS NULL OR currency = ?)');
        $linkedCurrency = $pdo->prepare('SELECT c.id, c.code, c.account_number, c.bank_code
            FROM supplier_bank_accounts a JOIN currencies c ON c.id = a.currency_id AND c.supplier_id = a.supplier_id
            WHERE a.id = ? AND a.supplier_id = ?');
        $promoteBankCode = $pdo->prepare('UPDATE currencies SET bank_code = ?
            WHERE id = ? AND supplier_id = ? AND (bank_code IS NULL OR bank_code = "")');
        foreach ($used as $code) {
            $a = $accounts[$code] ?? null;
            if ($a === null || $a['number'] === '' || !isset($registered[$code])) {
                continue;
            }
            $key = $a['currency'] . '|' . self::accountKey($a['number'], $a['bank']);
            if (!isset($known[$key]) && $a['bank'] !== '') {
                $linkedCurrency->execute([$registered[$code], $supplierId]);
                $linked = $linkedCurrency->fetch(\PDO::FETCH_ASSOC);
                if ($linked !== false && $linked['code'] === $a['currency']
                    && (string) ($linked['bank_code'] ?? '') === ''
                    && self::accountKey((string) $linked['account_number'], '') === self::accountKey($a['number'], '')) {
                    $promoteBankCode->execute([$a['bank'], (int) $linked['id'], $supplierId]);
                    $known[$key] = (int) $linked['id'];
                }
            }
            if (!isset($known[$key]) && $a['currency'] !== 'CZK') {
                $found = $this->currencyRowFor($supplierId, $rows, $a, true);
                if ($found !== null) {
                    $known[$key] = $found;
                    $protocol->count($step, 'accounts_filled');
                }
            }
            if (!isset($known[$key])) {
                $base = $namesFromExistingCurrency || $a['currency'] !== 'CZK' ? ($byCode[$a['currency']] ?? null) : null;
                $czk = $a['currency'] === 'CZK';
                $insert->execute([
                    $supplierId,
                    $a['currency'],
                    mb_substr($a['label'], 0, 60),
                    $base['symbol'] ?? ($czk ? 'Kč' : $a['currency']),
                    $base['name_cs'] ?? ($czk ? 'Česká koruna' : $a['currency']),
                    $base['name_en'] ?? ($czk ? 'Czech Koruna' : $a['currency']),
                    mb_substr($a['number'], 0, 30),
                    $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                    $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null,
                ]);
                $known[$key] = (int) $pdo->lastInsertId();
                $rows[] = ['id' => $known[$key], 'code' => $a['currency'], 'account_number' => $a['number'], 'bank_code' => $a['bank'], 'iban' => $a['iban']];
                $protocol->count($step, 'accounts_added');
            }
            $link->execute([$known[$key], $registered[$code], $supplierId, $a['currency']]);
        }
    }

    /**
     * Řádek měny firmy pro účet v cizí měně: řádek té měny, který účet už nese (číslem nebo
     * domácí částí IBANu, i IBAN vepsaný do čísla účtu), jinak řádek té měny bez čísla účtu
     * i IBANu, který účet doplní (`$fill`). Null = firma takový řádek nemá.
     *
     * @param list<array<string,mixed>> $rows currencies firmy
     * @param array{number:string,bank:string,iban:string,currency:string} $a
     */
    private function currencyRowFor(int $supplierId, array $rows, array $a, bool $fill): ?int
    {
        $bank = AccountNumberNormalizer::canonicalBankCode($a['bank'] !== '' ? $a['bank'] : null, $a['iban'] !== '' ? $a['iban'] : null);
        $empty = null;
        foreach ($rows as $row) {
            if ((string) $row['code'] !== $a['currency']) {
                continue;
            }
            $number = (string) ($row['account_number'] ?? '');
            $iban = (string) ($row['iban'] ?? '');
            $rowBank = AccountNumberNormalizer::canonicalBankCode((string) ($row['bank_code'] ?? '') ?: null, $iban !== '' ? $iban : null);
            if (($number !== '' || $iban !== '') && AccountNumberNormalizer::matchesAny($a['number'], $number, $iban !== '' ? $iban : null)
                && ($rowBank === null || $bank === null || $rowBank === $bank)) {
                if ($fill) {
                    // Číslo účtu v domácím tvaru s kódem banky: lookup výpisu GPC podle čísla účtu.
                    $this->db->pdo()->prepare(
                        "UPDATE currencies SET account_number = ?, bank_code = COALESCE(NULLIF(bank_code, ''), ?), iban = COALESCE(NULLIF(iban, ''), ?)
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([mb_substr($a['number'], 0, 30), $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                        $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null, (int) $row['id'], $supplierId]);
                }
                return (int) $row['id'];
            }
            if ($empty === null && $number === '' && $iban === '') {
                $empty = (int) $row['id'];
            }
        }
        if ($empty !== null && $fill) {
            $this->db->pdo()->prepare(
                "UPDATE currencies SET account_number = ?, bank_code = ?, iban = ?
                  WHERE id = ? AND supplier_id = ? AND (account_number IS NULL OR account_number = '') AND (iban IS NULL OR iban = '')"
            )->execute([mb_substr($a['number'], 0, 30), $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null, $empty, $supplierId]);
        }
        return $empty;
    }

    /**
     * Účet v cizí měně, který dřívější převod zaevidoval jako korunový: řádek jeho měny dostane
     * číslo účtu ({@see currencyRowFor()}, případně nový řádek), evidence účtů měnu a vazbu
     * na něj. Korunový řádek měn s číslem tohoto účtu, který převod založil, se vyřadí (bez
     * čísla, neaktivní), pokud na něj nic jiného neodkazuje: výpis GPC podle čísla účtu by
     * jinak našel dvě měny a měnu výpisu by vzal z kódu v souboru (Fio uvádí CZK i u účtu
     * v EUR). Duplicitní neaktivní řádek evidence účtů z měn (číslo = IBAN, bez analytiky)
     * se deaktivuje. Účet, jehož číslo sdílí korunový účet téže banky (víceměnový účet),
     * korunovou evidenci nemění.
     *
     * @param array{number:string,bank:string,iban:string,currency:string,label:string} $a
     * @return list<string> popis změn (bez `$apply` změn, které by se provedly)
     */
    public function adoptForeignAccount(int $supplierId, array $a, bool $sharedNumber, bool $apply): array
    {
        $pdo = $this->db->pdo();
        $changes = [];
        $rows = $pdo->prepare('SELECT id, code, account_number, bank_code, iban, symbol, name_cs, name_en, is_active FROM currencies WHERE supplier_id = ? ORDER BY id');
        $rows->execute([$supplierId]);
        $currencies = $rows->fetchAll(\PDO::FETCH_ASSOC);
        $target = $this->currencyRowFor($supplierId, $currencies, $a, false);
        $targetRow = null;
        foreach ($currencies as $row) {
            if ((int) $row['id'] === $target) {
                $targetRow = $row;
            }
        }
        if ($targetRow === null) {
            $changes[] = sprintf('nový měnový účet %s %s/%s', $a['currency'], $a['number'], $a['bank']);
            if ($apply) {
                $base = null;
                foreach ($currencies as $row) {
                    $base ??= $row['code'] === $a['currency'] ? $row : null;
                }
                $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code, iban)
                    VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?, ?)')->execute([
                    $supplierId, $a['currency'], mb_substr($a['label'], 0, 60), $base['symbol'] ?? $a['currency'], $base['name_cs'] ?? $a['currency'],
                    $base['name_en'] ?? $a['currency'], mb_substr($a['number'], 0, 30), $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                    $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null,
                ]);
                $target = (int) $pdo->lastInsertId();
            }
        } elseif (!AccountNumberNormalizer::equalsCzech((string) ($targetRow['account_number'] ?? ''), $a['number'])
            || (string) ($targetRow['bank_code'] ?? '') === '') {
            $changes[] = sprintf('měnový účet %s #%d: číslo účtu %s/%s', $a['currency'], $target, $a['number'], $a['bank']);
            if ($apply) {
                $this->currencyRowFor($supplierId, $currencies, $a, true);
            }
        }

        $canonical = AccountNumberNormalizer::canonical($a['number'], $a['iban'] !== '' ? $a['iban'] : null);
        $bank = AccountNumberNormalizer::canonicalBankCode($a['bank'] !== '' ? $a['bank'] : null) ?? '';
        $own = $pdo->prepare('SELECT id, currency, currency_id FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ?');
        $own->execute([$supplierId, $canonical, $bank]);
        $sba = $own->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($sba !== null && !$sharedNumber && in_array($sba['currency'], [null, 'CZK'], true)) {
            $changes[] = sprintf('evidence účtu #%d: měna %s', (int) $sba['id'], $a['currency']);
            if ($apply) {
                $pdo->prepare('UPDATE supplier_bank_accounts SET currency = ?, currency_id = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$a['currency'], $target, (int) $sba['id'], $supplierId]);
            }
        } elseif ($sba !== null && $sba['currency'] === $a['currency'] && (int) ($sba['currency_id'] ?? 0) !== (int) $target) {
            $changes[] = sprintf('evidence účtu #%d: vazba na měnový účet %s', (int) $sba['id'], $a['currency']);
            if ($apply) {
                $pdo->prepare('UPDATE supplier_bank_accounts SET currency_id = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$target, (int) $sba['id'], $supplierId]);
            }
        }

        if (!$sharedNumber) {
            foreach ($currencies as $row) {
                if ($row['code'] !== 'CZK' || ((string) ($row['bank_code'] ?? '') !== '' && ltrim((string) $row['bank_code'], '0') !== ltrim($a['bank'], '0'))) {
                    continue;
                }
                if (!AccountNumberNormalizer::matchesAny($a['number'], (string) ($row['account_number'] ?? ''))) {
                    if (AccountNumberNormalizer::matchesAny($a['number'], null, (string) ($row['iban'] ?? '') ?: null)) {
                        // Korunový účet s IBANem účtu v cizí měně (QR platby korunových faktur): oprava je na firmě.
                        $changes[] = sprintf('POZOR: korunový měnový účet #%d %s nese IBAN účtu v %s, opravte ho ručně', (int) $row['id'], (string) $row['account_number'], $a['currency']);
                    }
                    continue;
                }
                $usage = $this->currencyUsage($supplierId, (int) $row['id'], $sba !== null ? (int) $sba['id'] : null);
                if ($usage !== []) {
                    $changes[] = sprintf('korunový měnový účet #%d s číslem %s zůstává, používá ho %s', (int) $row['id'], $a['number'], implode(', ', $usage));
                    continue;
                }
                $changes[] = sprintf('korunový měnový účet #%d s číslem %s vyřazen', (int) $row['id'], $a['number']);
                if ($apply) {
                    $pdo->prepare('UPDATE currencies SET account_number = NULL, bank_code = NULL, bank_name = NULL, iban = NULL, is_active = 0
                        WHERE id = ? AND supplier_id = ? AND code = "CZK"')->execute([(int) $row['id'], $supplierId]);
                }
            }
        }

        if ($target !== null) {
            $mirror = $pdo->prepare("SELECT id, account_number, iban FROM supplier_bank_accounts
                WHERE supplier_id = ? AND currency_id = ? AND source = 'currencies' AND is_active = 1
                  AND (analytic_suffix IS NULL OR analytic_suffix = '') AND id <> ?");
            $mirror->execute([$supplierId, $target, $sba !== null ? (int) $sba['id'] : 0]);
            foreach ($mirror->fetchAll(\PDO::FETCH_ASSOC) as $m) {
                if ($sba === null || !AccountNumberNormalizer::matchesAny($a['number'], (string) ($m['account_number'] ?? ''), (string) ($m['iban'] ?? '') ?: null)) {
                    continue;
                }
                $changes[] = sprintf('duplicitní evidence účtu #%d z měn deaktivována', (int) $m['id']);
                if ($apply) {
                    $pdo->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ? AND supplier_id = ?')->execute([(int) $m['id'], $supplierId]);
                }
            }
        }
        return $changes;
    }

    /**
     * Kde se řádek měn firmy používá (cizí klíče na `currencies.id`), bez evidence účtu
     * `$exceptAccountId`, kterou volající přepojuje.
     *
     * @return list<string> tabulky, které na řádek odkazují
     */
    private function currencyUsage(int $supplierId, int $currencyId, ?int $exceptAccountId): array
    {
        $pdo = $this->db->pdo();
        $refs = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'currencies' AND REFERENCED_COLUMN_NAME = 'id'"
        )->fetchAll(\PDO::FETCH_NUM);
        $out = [];
        foreach ($refs as [$table, $column]) {
            if (preg_match('/^[a-z0-9_]+$/D', (string) $table . (string) $column) !== 1) {
                continue;
            }
            $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ?";
            $params = [$currencyId];
            if ($table === 'supplier_bank_accounts' && $exceptAccountId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $exceptAccountId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                $out[] = (string) $table;
            }
        }
        return array_values(array_unique($out));
    }
}

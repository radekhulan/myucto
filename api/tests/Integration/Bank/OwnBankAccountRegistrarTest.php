<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Bank\OwnBankAccountRegistrar;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vlastní účet zadaný na měně se musí objevit v registru `supplier_bank_accounts`.
 *
 * PROČ test existuje: registr se dlouho plnil jen backfillem migrace 1053 a
 * {@see \MyInvoice\Repository\SupplierBankAccountRepository::registerSeen()} při importu
 * výpisu. Firma založená po migraci proto do prvního importu svůj vlastní účet
 * v registru neměla — účtování banky nemělo na co mapovat analytiku 221 a platba
 * z vlastního účtu se nepoznala jako vlastní. Ostrá instance sitecontrol na to
 * najela hned po zřízení.
 */
#[Group('integration')]
final class OwnBankAccountRegistrarTest extends TestCase
{
    private const SUPPLIER_NAME  = '__TEST REGISTRAR TENANT';
    private const CURRENCY_LABEL = '__TEST REGISTRAR';
    private const ACCOUNT        = '9990571144';
    private const ACCOUNT_ALT    = '9990571155';
    private const BANK_CODE      = '2010';
    /** CZ IBAN k ACCOUNT_IBAN_ONLY, banka 2010 — kód banky je v něm, ale ne ve sloupci. */
    private const IBAN_ONLY      = 'CZ8020100000009990571166';

    private Connection $db;
    private BankStatementOwnershipResolver $ownership;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->ownership = $container->get(BankStatementOwnershipResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->cleanup();
        $this->supplierId = $this->cloneSupplier();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        $this->db->close();
    }

    public function testAccountOnCurrencyLandsInRegistry(): void
    {
        $currencyId = $this->addCurrency('CZK', self::ACCOUNT, self::BANK_CODE, null);

        self::assertTrue(OwnBankAccountRegistrar::syncFromCurrency($this->db->pdo(), $this->supplierId, $currencyId, $this->ownership));

        $row = $this->registryRow(self::ACCOUNT);
        self::assertNotNull($row, 'Účet z currencies se nezaevidoval do registru.');
        self::assertSame(self::ACCOUNT, (string) $row['account_canonical']);
        self::assertSame(self::BANK_CODE, (string) $row['bank_code_norm']);
        self::assertSame($currencyId, (int) $row['currency_id']);
        self::assertSame('CZK', (string) $row['currency']);
        self::assertSame('currencies', (string) $row['source']);
        self::assertSame(1, (int) $row['is_active']);
    }

    public function testRepeatedSyncDoesNotDuplicate(): void
    {
        $currencyId = $this->addCurrency('CZK', self::ACCOUNT, self::BANK_CODE, null);
        $pdo = $this->db->pdo();

        OwnBankAccountRegistrar::syncFromCurrency($pdo, $this->supplierId, $currencyId, $this->ownership);
        OwnBankAccountRegistrar::syncFromCurrency($pdo, $this->supplierId, $currencyId, $this->ownership);

        self::assertSame(1, $this->registryCount(), 'Opakovaná synchronizace založila druhý řádek.');
    }

    /**
     * Kód banky se ZÁMĚRNĚ nedopočítává z IBANu — `bank_code_norm` se porovnává
     * s `bank_statements.bank_code`, které import plní z `currencies.bank_code`.
     * Kdyby registr dostal kód z IBANu, první import výpisu by kvůli jinému UNIQUE
     * klíči založil na týž účet druhý řádek.
     */
    public function testBankCodeIsNotDerivedFromIban(): void
    {
        $currencyId = $this->addCurrency('EUR', null, null, self::IBAN_ONLY);

        self::assertTrue(OwnBankAccountRegistrar::syncFromCurrency($this->db->pdo(), $this->supplierId, $currencyId, $this->ownership));

        $row = $this->registryRow('9990571166');
        self::assertNotNull($row, 'Účet zadaný jen IBANem se nezaevidoval.');
        self::assertSame('', (string) $row['bank_code_norm']);
        self::assertNull($row['bank_code']);
    }

    public function testSyncSupplierCoversEveryCurrencyWithAccount(): void
    {
        $this->addCurrency('CZK', self::ACCOUNT, self::BANK_CODE, null);
        $this->addCurrency('EUR', self::ACCOUNT_ALT, self::BANK_CODE, null);
        // Měna bez účtu do registru nepatří.
        $this->addCurrency('USD', null, null, null);

        self::assertSame(2, OwnBankAccountRegistrar::syncSupplier($this->db->pdo(), $this->supplierId, $this->ownership));
        self::assertSame(2, $this->registryCount());
    }

    public function testCurrencyWithoutAccountIsIgnored(): void
    {
        $currencyId = $this->addCurrency('USD', null, null, null);

        self::assertFalse(OwnBankAccountRegistrar::syncFromCurrency($this->db->pdo(), $this->supplierId, $currencyId, $this->ownership));
        self::assertSame(0, $this->registryCount());
    }

    /** Cizí `currencies.id` nesmí založit řádek pod naší firmou. */
    public function testForeignCurrencyIdIsRejected(): void
    {
        $foreign = (int) $this->db->pdo()->query(
            'SELECT id FROM currencies WHERE supplier_id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($foreign === 0) {
            self::markTestSkipped('V DB není měna jiné firmy.');
        }

        self::assertFalse(OwnBankAccountRegistrar::syncFromCurrency($this->db->pdo(), $this->supplierId, $foreign, $this->ownership));
        self::assertSame(0, $this->registryCount());
    }

    /**
     * Popisek a vyřazení účtu jsou uživatelská rozhodnutí (PATCH
     * /accounting/bank-accounts/{id}) — resync z měny je nesmí přepsat. Jinak by
     * přejmenovaný účet dostal zpět popisek měny a vyřazený účet by ožil při každé
     * editaci `bank_name`/`bic` na měně.
     */
    public function testUserLabelAndRetirementSurviveResync(): void
    {
        $currencyId = $this->addCurrency('CZK', self::ACCOUNT, self::BANK_CODE, null);
        $pdo = $this->db->pdo();
        OwnBankAccountRegistrar::syncFromCurrency($pdo, $this->supplierId, $currencyId, $this->ownership);

        $pdo->prepare(
            'UPDATE supplier_bank_accounts SET label = ?, is_active = 0 WHERE supplier_id = ? AND account_canonical = ?'
        )->execute(['Fio běžný', $this->supplierId, self::ACCOUNT]);

        OwnBankAccountRegistrar::syncFromCurrency($pdo, $this->supplierId, $currencyId, $this->ownership);

        $row = $this->registryRow(self::ACCOUNT);
        self::assertNotNull($row);
        self::assertSame('Fio běžný', (string) $row['label'], 'Resync přepsal uživatelský popisek.');
        self::assertSame(0, (int) $row['is_active'], 'Resync oživil vyřazený účet.');
    }

    /**
     * Účet, který si už nárokuje jiná firma, se do registru zapsat nesmí — jinak by
     * měl týž účet dva vlastníky a import výpisů by ho přestal přiřazovat.
     * Vstupní guard v Nastavení tuhle cestu nechytá: PATCH měnící jen `bank_code`
     * nebo `bic` se na `rejectForeignBankAccount()` vůbec nedostane.
     */
    public function testAccountClaimedByAnotherSupplierIsNotRegistered(): void
    {
        $other = $this->cloneSupplier();
        $this->db->pdo()->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, 'CZK', ?, 'CZK', 'CZK', 'CZK', 2, 1, 0, ?, ?)"
        )->execute([$other, self::CURRENCY_LABEL . ' cizí', self::ACCOUNT, self::BANK_CODE]);

        $currencyId = $this->addCurrency('CZK', self::ACCOUNT, self::BANK_CODE, null);

        self::assertFalse(
            OwnBankAccountRegistrar::syncFromCurrency($this->db->pdo(), $this->supplierId, $currencyId, $this->ownership),
        );
        self::assertSame(0, $this->registryCount());
    }

    // ── fixtures ───────────────────────────────────────────────────────────────

    private function addCurrency(string $code, ?string $account, ?string $bankCode, ?string $iban): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?, ?)"
        )->execute([
            $this->supplierId, $code, self::CURRENCY_LABEL . ' ' . $code, $code, $code, $code,
            $account, $bankCode, $iban,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cloneSupplier(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT ?,?,street,city,zip,country_id,0,
                    CONCAT('registrar-', id, '-', UNIX_TIMESTAMP(), '@example.test'),
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier ORDER BY id LIMIT 1"
        );
        $stmt->execute([self::SUPPLIER_NAME, self::SUPPLIER_NAME]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if ($id === 0) {
            self::markTestSkipped('V DB není žádná firma, ze které by šlo klonovat.');
        }

        return $id;
    }

    /** @return array<string,mixed>|null */
    private function registryRow(string $canonical): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ?'
        );
        $stmt->execute([$this->supplierId, $canonical]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function registryCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // `currencies` na firmu nemá ON DELETE CASCADE, takže se maže ručně a v pořadí.
        $ids = $pdo->prepare('SELECT id FROM supplier WHERE company_name = ?');
        $ids->execute([self::SUPPLIER_NAME]);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('DELETE FROM supplier_bank_accounts WHERE supplier_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
    }
}


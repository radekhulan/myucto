<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Nové podklady pro DPPDP9 VetaF/VetaD/VetaNP (chybějící věty zjištěné porovnáním s reálně
 * podaným přiznáním, viz DppoXmlBuilder): rozpad daňových odpisů podle odpisové skupiny
 * (tabulka B), příznak transakcí se spojenou osobou (spoj_zahr) a výchozí bankovní účet
 * (VetaNP — žádost o vrácení přeplatku). Vlastní minimální schéma, ať se nesahá do sdíleného
 * `createSchema()` v {@see DppoReturnDataProviderTest}.
 */
final class DppoReturnDataProviderNewFieldsTest extends TestCase
{
    private PDO $pdo;
    private DppoReturnDataProvider $provider;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $db = new Connection($config);
        (new \ReflectionClass($db))->getProperty('pdo')->setValue($db, $this->pdo);
        $this->provider = new DppoReturnDataProvider(
            $db,
            new AccountingPeriodRepository($db),
            new NonDeductibleCostsService($db),
        );

        $this->pdo->exec("INSERT INTO accounting_periods VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
    }

    public function testDepreciationByGroupSplitsByTaxGroupKindAndFlagsUnclassified(): void
    {
        $this->asset(1, 'tangible', 1);
        $this->asset(2, 'tangible', 1);
        $this->asset(3, 'tangible', 3);
        $this->asset(4, 'intangible', null);
        $this->asset(5, 'tangible', null); // bez odpisové skupiny — unclassified

        $this->depreciation(1, 1, 'tax', 2025, 1000.0);
        $this->depreciation(2, 2, 'tax', 2025, 500.0);
        $this->depreciation(3, 3, 'tax', 2025, 2000.0);
        $this->depreciation(4, 4, 'tax', 2025, 300.0);
        $this->depreciation(5, 5, 'tax', 2025, 700.0);
        // accounting odpisy se do tabulky B nepočítají — musí se ignorovat.
        $this->depreciation(6, 1, 'accounting', 2025, 999.0);

        $result = $this->provider->gather(1, 2025);

        self::assertSame(1500.0, $result['depreciation_by_group']['tangible'][1]);
        self::assertSame(2000.0, $result['depreciation_by_group']['tangible'][3]);
        self::assertArrayNotHasKey(2, $result['depreciation_by_group']['tangible']);
        self::assertSame(300.0, $result['depreciation_by_group']['intangible']);
        self::assertSame(700.0, $result['depreciation_by_group']['unclassified']);
    }

    public function testRelatedPartyCountryFlagNoneWhenNoTransactions(): void
    {
        $result = $this->provider->gather(1, 2025);
        self::assertSame('N', $result['related_party_country_flag']);
    }

    public function testRelatedPartyCountryFlagDomesticOnly(): void
    {
        $this->country(1, 'CZ');
        $this->client(1, 1, 1, 1); // related_party=1, country CZ
        $this->invoice(1, 1, 1, 'issued', '2025-06-01', 1000.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame('T', $result['related_party_country_flag']);
    }

    public function testRelatedPartyCountryFlagForeignOnly(): void
    {
        $this->country(2, 'DE');
        $this->client(2, 1, 2, 1);
        $this->purchaseInvoice(1, 1, 2, '2025-06-01', 500.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame('Z', $result['related_party_country_flag']);
    }

    public function testRelatedPartyCountryFlagBothDomesticAndForeign(): void
    {
        $this->country(1, 'CZ');
        $this->country(2, 'DE');
        $this->client(1, 1, 1, 1);
        $this->client(2, 1, 2, 1);
        $this->invoice(1, 1, 1, 'issued', '2025-06-01', 1000.0);
        $this->purchaseInvoice(1, 1, 2, '2025-06-01', 500.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame('A', $result['related_party_country_flag']);
    }

    public function testRelatedPartyCountryFlagIgnoresNonRelatedClients(): void
    {
        $this->country(1, 'CZ');
        $this->client(1, 1, 1, 0); // related_party=0
        $this->invoice(1, 1, 1, 'issued', '2025-06-01', 1000.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame('N', $result['related_party_country_flag']);
    }

    public function testRelatedPartyAppendixEmptyWithoutTransactions(): void
    {
        $result = $this->provider->gather(1, 2025);
        self::assertSame([], $result['related_party_appendix']);
    }

    public function testRelatedPartyAppendixAggregatesIssuedAndReceivedPerPartner(): void
    {
        $this->country(1, 'CZ');
        $this->client(1, 1, 1, 1, 'Dcera s.r.o.', '27604977');
        $this->invoice(1, 1, 1, 'issued', '2025-03-01', 500_000.0);
        $this->invoice(2, 1, 1, 'issued', '2025-06-01', 250_000.0);
        $this->purchaseInvoice(1, 1, 1, '2025-04-01', 300_000.0);

        $rows = $this->provider->gather(1, 2025)['related_party_appendix'];
        self::assertCount(1, $rows);
        self::assertSame('Dcera s.r.o.', $rows[0]['name']);
        self::assertSame('CZ', $rows[0]['country_iso2']);
        self::assertSame('27604977', $rows[0]['ic']);
        self::assertSame(750_000.0, $rows[0]['issued_total'], 'Dvě vydané faktury sečtené dohromady.');
        self::assertSame(300_000.0, $rows[0]['received_total']);
    }

    public function testRelatedPartyAppendixOneRowPerPartnerAcrossCountries(): void
    {
        $this->country(1, 'CZ');
        $this->country(2, 'DE');
        $this->client(1, 1, 1, 1, 'Dcera s.r.o.', null);
        $this->client(2, 1, 2, 1, 'Sister GmbH', 'DE123456789');
        $this->invoice(1, 1, 1, 'issued', '2025-03-01', 500_000.0);
        $this->purchaseInvoice(1, 1, 2, '2025-04-01', 87_500.0);

        $rows = $this->provider->gather(1, 2025)['related_party_appendix'];
        self::assertCount(2, $rows);
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }
        self::assertSame('CZ', $byName['Dcera s.r.o.']['country_iso2']);
        self::assertNull($byName['Dcera s.r.o.']['ic']);
        self::assertSame(500_000.0, $byName['Dcera s.r.o.']['issued_total']);
        self::assertSame('DE', $byName['Sister GmbH']['country_iso2']);
        self::assertSame('DE123456789', $byName['Sister GmbH']['ic']);
        self::assertSame(87_500.0, $byName['Sister GmbH']['received_total']);
    }

    public function testRelatedPartyAppendixIgnoresNonRelatedClients(): void
    {
        $this->country(1, 'CZ');
        $this->client(1, 1, 1, 0, 'Nespojená firma s.r.o.', null); // related_party=0
        $this->invoice(1, 1, 1, 'issued', '2025-03-01', 500_000.0);

        self::assertSame([], $this->provider->gather(1, 2025)['related_party_appendix']);
    }

    /**
     * `spoj_zahr` (relatedPartyCountryFlag) a VetaA (relatedPartyAppendix) MUSÍ vycházet ze
     * STEJNÉ množiny dokladů — jinak by si příznak a příloha v XML odporovaly. Obojí tu
     * vzniká ze stejné (jediné) sady faktur, takže flag 'A' (tuzemská i zahraniční) musí
     * přesně odpovídat tomu, že příloha nese jednu CZ a jednu DE položku.
     */
    public function testRelatedPartyCountryFlagAndAppendixAgreeOnSameData(): void
    {
        $this->country(1, 'CZ');
        $this->country(2, 'DE');
        $this->client(1, 1, 1, 1, 'Dcera s.r.o.', null);
        $this->client(2, 1, 2, 1, 'Sister GmbH', null);
        $this->invoice(1, 1, 1, 'issued', '2025-03-01', 500_000.0);
        $this->purchaseInvoice(1, 1, 2, '2025-04-01', 87_500.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame('A', $result['related_party_country_flag']);
        $countries = array_column($result['related_party_appendix'], 'country_iso2');
        sort($countries);
        self::assertSame(['CZ', 'DE'], $countries, 'Příloha musí nést přesně ty státy, co tvrdí spoj_zahr=A.');
    }

    public function testBankAccountReturnsDefaultCzkAccount(): void
    {
        $this->currency(1, 1, 'CZK', '19-2000145399', '0800', 'Česká spořitelna', null, 0, 1);
        $this->currency(2, 1, 'CZK', '2000145399', '0100', 'Komerční banka', null, 1, 1);
        $this->currency(3, 1, 'EUR', null, null, 'Fio banka', 'CZ0000000000001234567890', 1, 1);

        $result = $this->provider->gather(1, 2025);
        self::assertNotNull($result['bank_account']);
        self::assertSame('2000145399', $result['bank_account']['account_number']);
        self::assertSame('0100', $result['bank_account']['bank_code']);
    }

    public function testBankAccountNullWhenNoCzkCurrency(): void
    {
        $this->currency(1, 1, 'EUR', null, null, 'Fio banka', 'CZ0000000000001234567890', 1, 1);

        $result = $this->provider->gather(1, 2025);
        self::assertNull($result['bank_account']);
    }

    private function asset(int $id, string $kind, ?int $taxGroup): void
    {
        $this->pdo->prepare('INSERT INTO assets (id, supplier_id, kind, tax_group) VALUES (?,1,?,?)')
            ->execute([$id, $kind, $taxGroup]);
    }

    private function depreciation(int $id, int $assetId, string $kind, int $fiscalYear, float $amount): void
    {
        $this->pdo->prepare('INSERT INTO depreciation_entries (id, supplier_id, asset_id, kind, fiscal_year, amount) VALUES (?,1,?,?,?,?)')
            ->execute([$id, $assetId, $kind, $fiscalYear, $amount]);
    }

    private function country(int $id, string $iso2): void
    {
        $this->pdo->prepare('INSERT INTO countries (id, iso2) VALUES (?,?)')->execute([$id, $iso2]);
    }

    private function client(int $id, int $supplierId, int $countryId, int $relatedParty, string $companyName = '', ?string $ic = null): void
    {
        $this->pdo->prepare('INSERT INTO clients (id, supplier_id, country_id, related_party, company_name, ic) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $supplierId, $countryId, $relatedParty, $companyName, $ic]);
    }

    private function invoice(int $id, int $supplierId, int $clientId, string $status, string $taxDate, float $amount): void
    {
        $this->pdo->prepare(
            "INSERT INTO invoices (id, supplier_id, client_id, status, invoice_type, effective_tax_date, total_without_vat) VALUES (?,?,?,'issued','invoice',?,?)"
        )->execute([$id, $supplierId, $clientId, $taxDate, $amount]);
    }

    private function purchaseInvoice(int $id, int $supplierId, int $vendorId, string $costDate, float $amount): void
    {
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices (id, supplier_id, vendor_id, status, document_kind, effective_cost_date, total_without_vat, tax_deductible) VALUES (?,?,?,'issued','invoice',?,?,1)"
        )->execute([$id, $supplierId, $vendorId, $costDate, $amount]);
    }

    private function currency(int $id, int $supplierId, string $code, ?string $accountNumber, ?string $bankCode, ?string $bankName, ?string $iban, int $isDefault, int $isActive): void
    {
        $this->pdo->prepare(
            'INSERT INTO currencies (id, supplier_id, code, account_number, bank_code, bank_name, iban, is_default, is_active) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $supplierId, $code, $accountNumber, $bankCode, $bankName, $iban, $isDefault, $isActive]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT, status TEXT, closed_at TEXT, created_at TEXT, row_version INTEGER, closed_by INTEGER, approved_at TEXT, approved_by INTEGER, reviewed_at TEXT, reviewed_by INTEGER, approval_body TEXT, approval_decision_ref TEXT, approval_document_hash TEXT)');
        $this->pdo->exec('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT, tax_deductibility TEXT, name TEXT)');
        $this->pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_date TEXT, source_type TEXT, source_id INTEGER, posted_at TEXT, reversed_by INTEGER)');
        $this->pdo->exec('CREATE TABLE journal_entry_lines (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_id INTEGER, account_id INTEGER, side TEXT, amount REAL)');
        $this->pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, vendor_id INTEGER, status TEXT, document_kind TEXT, effective_cost_date TEXT, total_without_vat REAL, tax_deductible INTEGER)');
        $this->pdo->exec('CREATE TABLE assets (id INTEGER PRIMARY KEY, supplier_id INTEGER, inventory_number TEXT, name TEXT, kind TEXT, tax_group INTEGER, disposal_date TEXT, disposal_type TEXT, input_price REAL, opening_tax_amount REAL, status TEXT)');
        $this->pdo->exec('CREATE TABLE asset_improvements (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, amount REAL)');
        $this->pdo->exec('CREATE TABLE depreciation_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, kind TEXT, fiscal_year INTEGER, amount REAL, residual_value_end REAL)');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, client_id INTEGER, status TEXT, invoice_type TEXT, effective_tax_date TEXT, total_without_vat REAL)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, country_id INTEGER, related_party INTEGER, company_name TEXT, ic TEXT)');
        $this->pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT)');
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INTEGER, code TEXT, account_number TEXT, bank_code TEXT, bank_name TEXT, iban TEXT, is_default INTEGER, is_active INTEGER)');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Section74bCorrectionRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Kniha DPH — bilance „Výsledná DPH" (vat_balance).
 *
 * Regression: reverse charge (samovyměření přijaté služby/zboží ze zahraničí) se
 * MUSÍ v bilanci vyrušit — samovyměřená daň je na výstupu (ř.3–13), zrcadlový
 * odpočet na vstupu (ř.43). Dřív builder účtoval RC primární řádek do `received`
 * (dle prefixu sekce 43) a mirror ř.43 vynechával, čímž bilanci o samovyměřenou
 * daň podhodnocoval a nesedělo to s DPH přiznáním.
 */
final class DphBookBuilderTest extends TestCase
{
    private PDO $pdo;
    private DphBookBuilder $builder;
    private Section74bService $section74b;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $ref = new \ReflectionClass($conn);
        $ref->getProperty('pdo')->setValue($conn, $this->pdo);

        $taxConstants = new TaxConstantsRepository($conn);
        $this->section74b = new Section74bService(
            $conn,
            new Section74bCorrectionRepository($conn),
            new ActivityLogger($conn),
            $taxConstants,
        );
        $this->builder = new DphBookBuilder($conn, new VatLedgerService($conn, $taxConstants), $taxConstants, $this->section74b);
    }

    public function testReverseChargeNetsToZeroInBalance(): void
    {
        // Prodej: základ 1000, DPH 210 (21 %) → ř.1 (výstup).
        $this->insertSale(1, '2026-05-10', 1000.0, 210.0, '1');
        // Přijatá služba ze 3. země (US), reverse charge, doklad bez DPH: samovyměření
        // 1000 × 21 % = 210 na ř.12 (výstup) + zrcadlový odpočet 210 na ř.43 (vstup).
        $this->insertPurchase(10, 300, '2026-05-12', 1000.0, 0.0, 21.0, '24');

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        // Daň na výstupu = prodej 210 + samovyměření 210 = 420.
        $this->assertSame(420.0, $r['totals']['issued']['vat'], 'issued musí obsahovat samovyměření RC (ř.12)');
        // Odpočet na vstupu = zrcadlo ř.43 = 210.
        $this->assertSame(210.0, $r['totals']['received']['vat'], 'received musí obsahovat mirror ř.43');
        // Bilance = 420 − 210 = 210 → RC se vyrušil, zůstává jen daň z prodeje.
        $this->assertSame(210.0, $r['totals']['vat_balance'], 'reverse charge se v bilanci vyruší (net 0)');
    }

    public function testEuServiceLandsOnLine5(): void
    {
        // Přijatá služba z EU (DE) → kód 24e, primary ř.5, mirror ř.43.
        $this->insertPurchase(11, 301, '2026-05-15', 2000.0, 0.0, 21.0, '24e');

        $r = $this->builder->build(1, 2026, 5, 'monthly');
        $keys = array_column($r['sections'], 'key');
        $this->assertContains('43.005', $keys, 'EU služba má primary sekci ř.5');
        $this->assertContains('43.043', $keys, 'a zrcadlový odpočet ř.43');
        // Samovyměření 2000 × 21 % = 420 na výstup, mirror 420 na vstup → bilance 0.
        $this->assertSame(420.0, $r['totals']['issued']['vat']);
        $this->assertSame(420.0, $r['totals']['received']['vat']);
        $this->assertSame(0.0, $r['totals']['vat_balance']);
    }

    public function testSection74bReductionLowersDeductionInBook(): void
    {
        // Normální tuzemský odpočet 21 % v období: základ 10 000, DPH 2 100 → ř.40 (sekce 15.040).
        $this->insertReceivedInvoice(20, 200, '2026-05-05', 'PF-DOM-1', 10000.0, 2100.0, '40');
        // Doklad s odpočtem uplatněným DŘÍVE (mimo období — DUZP 2025-01, do květnové knihy sám
        // nevstupuje), za který je v období 2026-05 zaevidováno snížení §74b celého odpočtu 2 100.
        $this->insertReceivedInvoice(30, 200, '2025-01-15', 'PF-74B-1', 10000.0, 2100.0, '40');
        $this->insertS74bCorrection(30, 2026, 5, 'reduction', 2100.0, 2100.0);

        // periodCorrectionLines: snížení → ř.40 (21 %) základ i daň ZÁPORNĚ.
        $lines = $this->section74b->periodCorrectionLines(1, 2026, 5, 'monthly');
        $this->assertSame(-2100.0, $lines['basic']['vat'], 'snížení §74b je záporná daň v ř.40');
        $this->assertSame(-10000.0, $lines['basic']['base']);

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        $section = null;
        foreach ($r['sections'] as $s) {
            if ($s['key'] === '15.040') {
                $section = $s;
            }
        }
        $this->assertNotNull($section, 'sekce 15.040 (odpočet 21 %) musí existovat');
        // Součet sekce = normální odpočet 2 100 + korekce §74b (−2 100) = 0.
        $this->assertSame(0.0, round($section['subtotal_vat'], 2));
        // received (odpočet) sedí s ř.40 po korekci = 2 100 + basic.vat.
        $this->assertSame(
            round(2100.0 + $lines['basic']['vat'], 2),
            round($r['totals']['received']['vat'], 2),
            'odpočet Knihy DPH musí sedět s ř.40 DPHDP3 po §74b korekci'
        );

        // §74b má vlastní řádek se záporným odpočtem, označený jako oprava (KH B.2).
        $s74bRow = null;
        foreach ($section['rows'] as $row) {
            if ((int) $row['invoice_id'] === 30) {
                $s74bRow = $row;
            }
        }
        $this->assertNotNull($s74bRow, '§74b korekce má vlastní řádek v 15.040');
        $this->assertSame(-2100.0, round($s74bRow['vat'], 2), 'snížení odpočtu = záporná daň');
        $this->assertSame('B.2', $s74bRow['kh_section'], '§74b doklad se v Knize DPH značí KH B.2');
    }

    public function testNoSection74bCorrectionsLeavesBookUnchanged(): void
    {
        // Jen normální odpočet, žádná evidovaná korekce §74b → sekce beze změny.
        $this->insertReceivedInvoice(21, 200, '2026-05-05', 'PF-DOM-2', 10000.0, 2100.0, '40');

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        $this->assertSame(2100.0, round($r['totals']['received']['vat'], 2));
        foreach ($r['sections'] as $s) {
            foreach ($s['rows'] as $row) {
                $this->assertStringNotContainsStringIgnoringCase('§74b', (string) ($row['description'] ?? ''));
            }
        }
    }

    // ───── helpers ───────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT NOT NULL)");
        $this->pdo->exec("INSERT INTO currencies (id, code) VALUES (1, 'CZK')");
        $this->pdo->exec("CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, is_eu INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("INSERT INTO countries (id, iso2, is_eu) VALUES (1,'CZ',1), (4,'DE',1), (9,'US',0)");
        $this->pdo->exec("CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE supplier (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', street TEXT NULL,
            city TEXT NULL, zip TEXT NULL, country_id INTEGER NULL, ic TEXT NULL, dic TEXT NULL,
            is_vat_payer INTEGER NOT NULL DEFAULT 1
        )");
        $this->pdo->exec("INSERT INTO supplier (id, company_name, country_id, is_vat_payer) VALUES (1,'Test s.r.o.',1,1)");
        $this->pdo->exec("CREATE TABLE clients (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', dic TEXT NULL, country_id INTEGER NULL
        )");
        $this->pdo->exec("INSERT INTO clients (id, company_name, dic, country_id) VALUES
            (200,'Odběratel CZ','CZ22222220',1), (300,'Anthropic US',NULL,9), (301,'Vendor DE','DE123',4)");
        $this->pdo->exec("CREATE TABLE vat_classifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NULL, code TEXT NOT NULL, label TEXT NOT NULL,
            direction TEXT NOT NULL, dphdp3_line TEXT NULL, dphdp3_line_secondary TEXT NULL, kh_section TEXT NULL,
            vat_rate REAL NULL, is_reverse_charge INTEGER NOT NULL DEFAULT 0, kod_pred_pl TEXT NULL,
            kh_regime_code TEXT NULL, kh_bad_debt TEXT NULL, display_order INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, varsymbol TEXT NULL,
            vendor_invoice_number TEXT NULL, document_kind TEXT NULL, issue_date TEXT NOT NULL, tax_date TEXT NULL,
            received_at TEXT NULL, received_at_source TEXT NOT NULL DEFAULT 'import',
            currency_id INTEGER NOT NULL, exchange_rate REAL NULL, reverse_charge INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'received', vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full', vat_deduction_percent REAL NOT NULL DEFAULT 100,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0, total_without_vat REAL NOT NULL DEFAULT 0,
            total_vat REAL NOT NULL DEFAULT 0, total_with_vat REAL NOT NULL DEFAULT 0,
            parent_purchase_invoice_id INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE vat_s74b_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL,
            purchase_invoice_id INTEGER NOT NULL, period_year INTEGER NOT NULL, period_month INTEGER NOT NULL,
            movement TEXT NOT NULL, vat_amount REAL NOT NULL, claimed_deduction_vat REAL NOT NULL,
            unpaid_ratio REAL NOT NULL, state TEXT NOT NULL, note TEXT NULL, created_by INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_items (
            id INTEGER PRIMARY KEY, purchase_invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, is_fixed_asset INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_vat_allocations (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL DEFAULT 1, purchase_invoice_id INTEGER NOT NULL,
            description TEXT NULL, vat_rate REAL NOT NULL,
            base_amount REAL NOT NULL, vat_amount REAL NOT NULL,
            vat_classification_code TEXT NULL, vat_deduction TEXT NOT NULL DEFAULT 'full',
            vat_deduction_percent REAL NOT NULL DEFAULT 100
        )");
        $this->pdo->exec("CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, client_id INTEGER NULL, varsymbol TEXT NULL,
            issue_date TEXT NOT NULL, tax_date TEXT NULL, currency_id INTEGER NOT NULL, exchange_rate REAL NULL,
            reverse_charge INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'issued',
            invoice_type TEXT NOT NULL DEFAULT 'invoice', vat_classification_code TEXT NULL, total_with_vat REAL NOT NULL DEFAULT 0,
            effective_tax_date TEXT GENERATED ALWAYS AS (COALESCE(tax_date, issue_date)) STORED
        )");
        $this->pdo->exec("CREATE TABLE invoice_items (
            id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, oss_applicable INTEGER NOT NULL DEFAULT 0
        )");

        // Pokladna (mini-epic #14) — VatLedgerService::fetchCash() JOINuje tyto tabulky.
        // Prázdné → žádné cash řádky (chování neutrální k faktury-only testům).
        $this->pdo->exec("CREATE TABLE cash_documents (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, doc_number TEXT NULL,
            status TEXT NOT NULL DEFAULT 'draft', doc_type TEXT NOT NULL, vat_mode TEXT NOT NULL DEFAULT 'none',
            tax_date TEXT NULL, issue_date TEXT NOT NULL, total_amount REAL NOT NULL DEFAULT 0,
            partner_name TEXT NULL, partner_dic TEXT NULL, description TEXT NULL,
            invoice_id INTEGER NULL, purchase_invoice_id INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE cash_document_vat_lines (
            id INTEGER PRIMARY KEY, cash_document_id INTEGER NOT NULL, vat_rate REAL NOT NULL,
            base_amount REAL NOT NULL, vat_amount REAL NOT NULL, vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full', vat_deduction_percent REAL NOT NULL DEFAULT 100
        )");
    }

    private function seed(): void
    {
        $rows = [
            ['1',   'Sale 21 %',           'sale',     '1',  null, 'A.4', 21.0, 0],
            ['24',  'Služba ze 3. země',   'purchase', '12', '43', null, 21.0, 1],
            ['24e', 'Služba z EU',         'purchase', '5',  '43', null, 21.0, 1],
            ['40',  'Tuzemsko 21 %',       'purchase', '40', null, 'B.2', 21.0, 0],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO vat_classifications
            (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate, is_reverse_charge, display_order, archived)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function insertSale(int $id, string $date, float $base, float $vat, string $code): void
    {
        $this->pdo->prepare("INSERT INTO invoices (id, supplier_id, client_id, varsymbol, issue_date, tax_date, currency_id, exchange_rate, status, invoice_type, total_with_vat)
            VALUES (?, 1, 200, ?, ?, ?, 1, 1, 'issued', 'invoice', ?)")
            ->execute([$id, (string) $id, $date, $date, $base + $vat]);
        $this->pdo->prepare("INSERT INTO invoice_items (id, invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, 21.0, 'prodej', ?, ?, ?)")
            ->execute([$id, $id, $base, $vat, $code]);
    }

    private function insertPurchase(int $id, int $vendorId, string $date, float $base, float $vat, float $rate, string $code): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (id, supplier_id, vendor_id, varsymbol, issue_date, tax_date, currency_id, exchange_rate, reverse_charge, status, vat_classification_code, total_with_vat)
            VALUES (?, 1, ?, ?, ?, ?, 1, 1, 1, 'received', ?, ?)")
            ->execute([$id, $vendorId, (string) $id, $date, $date, $code, $base + $vat]);
        $this->pdo->prepare("INSERT INTO purchase_invoice_items (id, purchase_invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, ?, 'služba', ?, ?, ?)")
            ->execute([$id, $id, $rate, $base, $vat, $code]);
    }

    private function insertReceivedInvoice(int $id, int $vendorId, string $taxDate, string $vendorInvoiceNumber, float $base, float $vat, string $code): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (id, supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date, currency_id, exchange_rate, reverse_charge, status, vat_classification_code, vat_deduction, vat_deduction_percent, total_without_vat, total_vat, total_with_vat)
            VALUES (?, 1, ?, ?, ?, 'invoice', ?, ?, 1, 1, 0, 'received', ?, 'full', 100, ?, ?, ?)")
            ->execute([$id, $vendorId, (string) $id, $vendorInvoiceNumber, $taxDate, $taxDate, $code, $base, $vat, $base + $vat]);
        $this->pdo->prepare("INSERT INTO purchase_invoice_items (id, purchase_invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, 21.0, 'tuzemsko', ?, ?, ?)")
            ->execute([$id, $id, $base, $vat, $code]);
    }

    private function insertS74bCorrection(int $purchaseInvoiceId, int $year, int $month, string $movement, float $vatAmount, float $claimed): void
    {
        $this->pdo->prepare("INSERT INTO vat_s74b_corrections
            (supplier_id, purchase_invoice_id, period_year, period_month, movement, vat_amount, claimed_deduction_vat, unpaid_ratio, state, note, created_by)
            VALUES (1, ?, ?, ?, ?, ?, ?, 1.0, 'corrected', NULL, NULL)")
            ->execute([$purchaseInvoiceId, $year, $month, $movement, $vatAmount, $claimed]);
    }
}

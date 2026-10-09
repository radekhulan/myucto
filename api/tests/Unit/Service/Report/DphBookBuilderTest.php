<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Section46CorrectionRepository;
use MyInvoice\Repository\Section74bCorrectionRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\InvoiceKhSections;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\Section79Service;
use MyInvoice\Service\Report\SubmissionVariantGuard;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use MyInvoice\Service\Tax\Vat\Section43Service;
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
    private InvoiceKhSections $khSections;
    private Section74bService $section74b;
    private Section46Service $section46;
    private KontrolniHlaseniBuilder $kh;
    private Section43Service $section43;
    private Section79Service $section79;

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
        $ledger = new VatLedgerService($conn, $taxConstants);
        $submissions = new TaxSubmissionRepository($conn);
        $this->section46 = new Section46Service($conn, new Section46CorrectionRepository($conn), new ActivityLogger($conn), $taxConstants);
        $this->section43 = new Section43Service($conn, $taxConstants);
        $kh = new KontrolniHlaseniBuilder(
            $conn,
            $ledger,
            $taxConstants,
            $this->section74b,
            $this->section46,
            $submissions,
            new SubmissionVariantGuard($submissions),
            $this->section43,
        );
        $this->kh = $kh;
        $this->section79 = new Section79Service($conn, $taxConstants);
        $this->builder = new DphBookBuilder($conn, $ledger, $taxConstants, $this->section74b, $kh, $this->section46, $this->section43, $this->section79);
        $this->khSections = new InvoiceKhSections($this->builder, $ledger);
    }

    public function testInvoiceKhSectionsUseBookAndExcludeCashDocumentWithSameId(): void
    {
        $this->insertSale(1, '2026-05-10', 1000.0, 210.0, '1');
        $this->insertReceivedInvoice(20, 200, '2026-05-05', 'PF-TEST-20', 10000.0, 2100.0, '40');
        $this->pdo->exec("INSERT INTO cash_documents
            (id, supplier_id, doc_number, status, doc_type, vat_mode, tax_date, issue_date, total_amount)
            VALUES (1, 1, 'PD-TEST-1', 'posted', 'in', 'vat', '2026-05-10', '2026-05-10', 24200)");
        $this->pdo->exec("INSERT INTO cash_document_vat_lines
            (id, cash_document_id, vat_rate, base_amount, vat_amount, vat_classification_code)
            VALUES (1, 1, 21, 20000, 4200, '1')");

        $issued = [['invoices' => [['id' => 1, 'month_bucket' => '2026-05']]]];
        $this->khSections->addToGroups(1, $issued, 'issued');
        self::assertSame(['A.5'], $issued[0]['invoices'][0]['kh_sections']);

        $received = [['invoices' => [['id' => 20, 'month_bucket' => '2026-05']]]];
        $this->khSections->addToGroups(1, $received, 'received');
        self::assertSame(['B.2'], $received[0]['invoices'][0]['kh_sections']);
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

    public function testSection46CorrectionLowersOutputVatInBookLikeReturnAndKh(): void
    {
        // Běžný prodej v období: základ 1 000, DPH 210 → ř.1 (sekce 36.001).
        $this->insertSale(1, '2026-05-10', 1000.0, 210.0, '1');
        // Vydaná faktura z dřívějška (DUZP 2025-01, do květnové knihy sama nevstupuje),
        // za kterou je v období 2026-05 zaevidována věřitelská oprava §46 celé daně 2 100.
        $this->insertSale(40, '2025-01-15', 10000.0, 2100.0, '1');
        $this->insertS46Correction(40, 2026, 5, 'correction', 2100.0, 2100.0);

        $lines = $this->section46->periodCorrectionLines(1, 2026, 5, 'monthly');
        $this->assertSame(-2100.0, $lines['basic']['vat'], 'oprava §46 je záporná daň v ř.1');
        $this->assertSame(-10000.0, $lines['basic']['base']);

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        $section = null;
        foreach ($r['sections'] as $s) {
            if ($s['key'] === '36.001') {
                $section = $s;
            }
        }
        $this->assertNotNull($section, 'sekce 36.001 (výstup 21 %) musí existovat');
        // Součty Knihy = ř.1 DPHDP3 po opravě (210 + (−2 100), základ 1 000 + (−10 000)).
        $this->assertSame(round(210.0 + $lines['basic']['vat'], 2), round($section['subtotal_vat'], 2));
        $this->assertSame(round(1000.0 + $lines['basic']['base'], 2), round($section['subtotal_base'], 2));
        $this->assertSame(
            round(210.0 + $lines['basic']['vat'], 2),
            round($r['totals']['issued']['vat'], 2),
            'daň na výstupu Knihy DPH musí sedět s ř.1 DPHDP3 po §46 opravě'
        );
        $this->assertSame(-1890.0, round($r['totals']['vat_balance'], 2));

        $s46Row = null;
        foreach ($section['rows'] as $row) {
            if ((int) $row['invoice_id'] === 40) {
                $s46Row = $row;
            }
        }
        $this->assertNotNull($s46Row, '§46 oprava má vlastní řádek v 36.001');
        $this->assertSame(-2100.0, round($s46Row['vat'], 2), 'oprava daně věřitele = záporná daň');
        $this->assertSame('issued', $s46Row['direction']);
        $this->assertStringContainsString('§46', (string) $s46Row['description']);
        $this->assertSame('A.4', $s46Row['kh_section'], '§46 oprava se v Knize DPH značí KH A.4');

        // KH A.4 nese tutéž opravu se zdph_44='P' a stejnými částkami jako Kniha.
        $a4 = $this->kh->sectionDocuments(1, 2026, 5, 'monthly')['sections']['A.4']['rows'];
        $a4Vat = array_sum(array_map(static fn (array $row): float => (float) $row['vat21'], array_values(array_filter(
            $a4,
            static fn (array $row): bool => (string) $row['doc_number'] === '40',
        ))));
        $this->assertSame(round($s46Row['vat'], 2), round($a4Vat, 2), 'Kniha a KH A.4 nesou stejnou opravu §46');
    }

    public function testSection46RestorationRaisesOutputVatInBook(): void
    {
        $this->insertSale(41, '2025-01-15', 10000.0, 2100.0, '1');
        $this->insertS46Correction(41, 2026, 5, 'restoration', 2100.0, 2100.0);

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        $this->assertSame(2100.0, round($r['totals']['issued']['vat'], 2), 'obnova po úhradě (§46e) zvyšuje daň na výstupu');
        $this->assertSame(10000.0, round($r['totals']['issued']['base'], 2));
    }

    public function testSection46NotAppliedForNonPayerLikeReturn(): void
    {
        $this->pdo->exec('UPDATE supplier SET is_vat_payer = 0 WHERE id = 1');
        $this->insertSale(42, '2025-01-15', 10000.0, 2100.0, '1');
        $this->insertS46Correction(42, 2026, 5, 'correction', 2100.0, 2100.0);

        $r = $this->builder->build(1, 2026, 5, 'monthly');

        $this->assertSame(0.0, round($r['totals']['issued']['vat'], 2), 'neplátce opravu §46 v přiznání nemá, Kniha také ne');
    }

    public function testSection43CorrectionsMatchReturnLines1And2(): void
    {
        // Prodej v období původního plnění 2026-05: 1 000 + 210 (ř.1).
        $this->insertSale(1, '2026-05-10', 1000.0, 210.0, '1');
        // Opravy §43 za období 2026-05 (doručené později): základní skupina +40 daně,
        // snížená −12 daně; oprava jiného období (2026-04) do květnové knihy nepatří.
        $this->insertS43Correction(1, 'invoice', 2026, 5, 'basic', 0.0, 40.0, 'OD-TEST-1');
        $this->insertS43Correction(1, 'invoice', 2026, 5, 'reduced', -100.0, -12.0, null);
        $this->insertS43Correction(1, 'invoice', 2026, 4, 'basic', 0.0, 999.0, null);

        $lines = $this->section43->periodCorrectionLines(1, 2026, 5, 'monthly');
        $this->assertSame(40.0, $lines['basic']['vat']);
        $this->assertSame(-12.0, $lines['reduced']['vat']);

        $r = $this->builder->build(1, 2026, 5, 'monthly');
        $byKey = array_column($r['sections'], null, 'key');

        $this->assertArrayHasKey('36.001', $byKey);
        $this->assertArrayHasKey('36.002', $byKey, 'oprava snížené sazby jde na ř.2');
        $this->assertSame(round(210.0 + $lines['basic']['vat'], 2), round($byKey['36.001']['subtotal_vat'], 2),
            'ř.1 Knihy DPH = ř.1 přiznání po opravě §43');
        $this->assertSame($lines['reduced']['vat'], round($byKey['36.002']['subtotal_vat'], 2));
        $this->assertSame($lines['reduced']['base'], round($byKey['36.002']['subtotal_base'], 2));
        $this->assertSame(round(210.0 + $lines['basic']['vat'] + $lines['reduced']['vat'], 2), round($r['totals']['issued']['vat'], 2));

        $docs = array_column($byKey['36.001']['rows'], 'doc_number');
        $this->assertContains('OD-TEST-1', $docs, 'řádek §43 nese číslo opravného dokladu');

        // KH: původní doklad (1 210 Kč s DPH) je v A.5, oprava pod limitem také.
        $kh = $this->kh->sectionDocuments(1, 2026, 5, 'monthly')['sections'];
        $a5Docs = array_column($kh['A.5']['rows'], 'doc_number');
        $this->assertContains('OD-TEST-1', $a5Docs, 'oprava §43 k dokladu z A.5 jde do A.5');
        $this->assertSame(
            round(210.0 + $lines['basic']['vat'], 2),
            round($kh['A.5']['totals']['vat21'] ?? 0.0, 2),
            'KH A.5 daň 21 % = ř.1 přiznání po opravě'
        );
    }

    public function testSection43OnPurchaseInvoiceCorrectsDeductionAndGoesToB2(): void
    {
        // Přijatý doklad nad limitem (B.2), opravný doklad dodavatele snížil daň o 100.
        $this->insertReceivedInvoice(20, 200, '2026-05-05', 'PF-43-1', 10000.0, 2100.0, '40');
        $this->insertS43Correction(20, 'purchase_invoice', 2026, 5, 'basic', 0.0, -100.0, 'OD-PF-1');

        $lines = $this->section43->periodCorrectionLines(1, 2026, 5, 'monthly');
        $this->assertSame(0.0, $lines['basic']['vat'], 'oprava přijatého dokladu nepatří na ř.1');
        $this->assertSame(-100.0, $lines['lines']['40']['vat'] ?? null, 'oprava přijatého dokladu snižuje odpočet ř.40');

        $r = $this->builder->build(1, 2026, 5, 'monthly');
        $byKey = array_column($r['sections'], null, 'key');
        $this->assertArrayNotHasKey('36.001', $byKey, 'na výstupu nic');
        $this->assertSame(0.0, round($r['totals']['issued']['vat'], 2));
        $this->assertSame(2000.0, round($byKey['15.040']['subtotal_vat'], 2), 'ř.40 Knihy = 2 100 − 100');

        $row = null;
        foreach ($byKey['15.040']['rows'] as $candidate) {
            if (($candidate['doc_number'] ?? '') === 'OD-PF-1') {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame('B.2', $row['kh_section'], 'opravný doklad k dokladu z B.2 jde do B.2 i pod limitem');

        $b2 = $this->kh->sectionDocuments(1, 2026, 5, 'monthly')['sections']['B.2'];
        $this->assertContains('OD-PF-1', array_column($b2['rows'], 'doc_number'));
        $this->assertSame(2000.0, round($b2['totals']['vat21'] ?? 0.0, 2), 'KH B.2 = ř.40 přiznání');

        // Seznam přijatých dokladů nebere řádek opravy za zařazení původního dokladu.
        $received = [['invoices' => [['id' => 20, 'month_bucket' => '2026-05']]]];
        $this->khSections->addToGroups(1, $received, 'received');
        $this->assertSame(['B.2'], $received[0]['invoices'][0]['kh_sections']);
    }

    public function testSection43ProportionalAndNoDeductionPurchase(): void
    {
        $this->insertReceivedInvoice(21, 200, '2026-05-05', 'PF-43-2', 1000.0, 210.0, '40');
        $this->pdo->exec("UPDATE purchase_invoices SET vat_deduction = 'proportional', vat_deduction_percent = 50 WHERE id = 21");
        $this->insertS43Correction(21, 'purchase_invoice', 2026, 5, 'basic', 0.0, -100.0, 'OD-PF-2');
        $this->insertReceivedInvoice(22, 200, '2026-05-06', 'PF-43-3', 1000.0, 210.0, '40');
        $this->pdo->exec("UPDATE purchase_invoices SET vat_deduction = 'none' WHERE id = 22");
        $this->insertS43Correction(22, 'purchase_invoice', 2026, 5, 'basic', 0.0, -100.0, 'OD-PF-3');

        $lines = $this->section43->periodCorrectionLines(1, 2026, 5, 'monthly');
        $this->assertSame(-50.0, $lines['lines']['40']['vat'], 'poměrný odpočet § 75 se opraví poměrem');
        $this->assertCount(1, $lines['warnings'], 'doklad bez nároku do přiznání nejde, jen varuje');
    }

    public function testSection79RegistrationCorrectionLandsOnLine45(): void
    {
        $this->insertReceivedInvoice(20, 200, '2026-05-05', 'PF-DOM-79', 10000.0, 2100.0, '40');
        // Nárok při registraci 500 (ve lhůtě), snížení při zrušení u zásob −300 by
        // patřilo do jiného období; položka mimo lhůtu (applies=false) do ř.45 nejde.
        $this->insertS79Item('registration', 'Zásoby k registraci', '2026-01-10', '2026-05-01', 'inventory', null, 500.0);
        $this->insertS79Item('registration', 'Stroj mimo lhůtu', '2024-01-10', '2026-05-01', 'fixed_asset', 5, 800.0);
        $this->insertS79Item('deregistration', 'Zásoby při zrušení', '2026-01-10', '2026-06-30', 'inventory', null, 300.0);

        $expected = $this->section79->totalForReturn(1, '2026-05-01', '2026-05-31');
        $this->assertSame(500.0, $expected);

        $r = $this->builder->build(1, 2026, 5, 'monthly');
        $byKey = array_column($r['sections'], null, 'key');

        $this->assertArrayHasKey('15.045', $byKey, 'korekce §79 má sekci ř.45');
        $this->assertSame($expected, round($byKey['15.045']['subtotal_vat'], 2), 'ř.45 Knihy = ř.45 přiznání');
        $this->assertCount(1, $byKey['15.045']['rows']);
        $this->assertSame(round(2100.0 + $expected, 2), round($r['totals']['received']['vat'], 2),
            'odpočet Knihy = ř.46 „V plné výši" (ř.40 + ř.45)');
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
        $this->pdo->exec("CREATE TABLE vat_s46_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, invoice_id INTEGER NOT NULL,
            period_year INTEGER NOT NULL, period_month INTEGER NOT NULL, movement TEXT NOT NULL,
            vat_amount REAL NOT NULL, output_vat REAL NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE vat_s43_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL,
            source_type TEXT NOT NULL DEFAULT 'invoice', source_id INTEGER NOT NULL,
            period_year INTEGER NOT NULL, period_month INTEGER NOT NULL, rate_kind TEXT NOT NULL DEFAULT 'basic',
            base_delta REAL NOT NULL DEFAULT 0, vat_delta REAL NOT NULL, corrective_doc_number TEXT NULL,
            delivered_on TEXT NOT NULL, reason TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE vat_registration_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, kind TEXT NOT NULL,
            label TEXT NOT NULL, acquired_on TEXT NOT NULL, effective_on TEXT NOT NULL,
            asset_kind TEXT NOT NULL, period_years INTEGER NULL, vat_amount REAL NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_items (
            id INTEGER PRIMARY KEY, purchase_invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, is_fixed_asset INTEGER NOT NULL DEFAULT 0,
            import_tax_base_czk REAL NULL, import_tax_vat_czk REAL NULL,
            import_tax_excluded INTEGER NOT NULL DEFAULT 0
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
            id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, vat_rate_id INTEGER NULL,
            vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, oss_applicable INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, country TEXT NOT NULL)");

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
            vat_deduction TEXT NOT NULL DEFAULT 'full', vat_deduction_percent REAL NOT NULL DEFAULT 100,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0
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

    private function insertS43Correction(int $sourceId, string $sourceType, int $year, int $month, string $rateKind, float $baseDelta, float $vatDelta, ?string $docNumber): void
    {
        $this->pdo->prepare("INSERT INTO vat_s43_corrections
            (supplier_id, source_type, source_id, period_year, period_month, rate_kind, base_delta, vat_delta, corrective_doc_number, delivered_on, reason)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, '2026-08-10', 'chybná sazba')")
            ->execute([$sourceType, $sourceId, $year, $month, $rateKind, $baseDelta, $vatDelta, $docNumber]);
    }

    private function insertS79Item(string $kind, string $label, string $acquiredOn, string $effectiveOn, string $assetKind, ?int $periodYears, float $vat): void
    {
        $this->pdo->prepare("INSERT INTO vat_registration_corrections
            (supplier_id, kind, label, acquired_on, effective_on, asset_kind, period_years, vat_amount)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$kind, $label, $acquiredOn, $effectiveOn, $assetKind, $periodYears, $vat]);
    }

    private function insertS46Correction(int $invoiceId, int $year, int $month, string $movement, float $vatAmount, float $outputVat): void
    {
        $this->pdo->prepare("INSERT INTO vat_s46_corrections
            (supplier_id, invoice_id, period_year, period_month, movement, vat_amount, output_vat)
            VALUES (1, ?, ?, ?, ?, ?, ?)")
            ->execute([$invoiceId, $year, $month, $movement, $vatAmount, $outputVat]);
    }

    private function insertS74bCorrection(int $purchaseInvoiceId, int $year, int $month, string $movement, float $vatAmount, float $claimed): void
    {
        $this->pdo->prepare("INSERT INTO vat_s74b_corrections
            (supplier_id, purchase_invoice_id, period_year, period_month, movement, vat_amount, claimed_deduction_vat, unpaid_ratio, state, note, created_by)
            VALUES (1, ?, ?, ?, ?, ?, ?, 1.0, 'corrected', NULL, NULL)")
            ->execute([$purchaseInvoiceId, $year, $month, $movement, $vatAmount, $claimed]);
    }
}

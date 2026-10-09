<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * C8' — křížová kontrola DPHDP3 ↔ KH ↔ SH ↔ obrat účtu 343 (audit 2026-07).
 *
 * Ověřuje smír, bránu na stažení (409 bez potvrzení / průchod s acknowledge_mismatch) a
 * informativní přeskočení kontroly 343 při nezaúčtovaných dokladech. Vše v jedné transakci,
 * tearDown rollbackne. Soft-skip bez cfg.php / bez podvojné osnovy.
 */
#[Group('integration')]
final class VatCrossCheckTest extends TestCase
{
    private const YEAR = 2048;
    private const MONTH = 6;

    private Connection $db;
    private VatCrossCheckService $crossCheck;
    private DphPriznaniAction $action;
    private DocumentAutoPoster $autoPoster;
    private AccountingPeriodRepository $periods;
    private VatLedgerService $ledger;
    private ClosingService $closing;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->crossCheck = $container->get(VatCrossCheckService::class);
            $this->action     = $container->get(DphPriznaniAction::class);
            $this->autoPoster = $container->get(DocumentAutoPoster::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->ledger     = $container->get(VatLedgerService::class);
            $this->closing    = $container->get(ClosingService::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        try {
            $seeder->seedForSupplier($this->supplierId);
            $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->inTx = false;
            $this->markTestSkipped('Podvojná osnova / období nedostupné: ' . $e->getMessage());
        }

        // Plátce DPH v režimu podvojného účetnictví (smír 343 dává smysl jen tam).
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // (a) vše sedí + zaúčtováno → cross_check prázdné, download projde ────────────

    public function testConsistentAndPostedYieldsEmptyCrossCheckAndDownloadPasses(): void
    {
        $cust = $this->client('Odběratel konzistence', 'CZ11111118');
        $vend = $this->client('Dodavatel konzistence', 'CZ22222220');
        $s1 = $this->sale('FV-2048-A1', $cust, '1', false, 50000.0, 10500.0, 21.0);
        $p1 = $this->purchase('PF-2048-A1', $vend, '40', 20000.0, 4200.0, 21.0);
        $this->autoPoster->post($this->supplierId, 'invoice', $s1);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $p1);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertSame([], $findings, 'Konzistentní a zaúčtované období → žádné nálezy.');

        $res = $this->download();
        self::assertSame(200, $res['status'], 'Download bez rozdílů projde.');
        self::assertStringContainsString('xml', strtolower($res['content_type']));
        self::assertStringContainsString('DPHDP3', $res['raw']);
    }

    /** M26: koncept DDKP/finálu s DUZP v období musí být vidět před podáním DPH. */
    public function testDraftTaxDocumentFromPaidProformaIsReportedAsNonBlockingPrecheck(): void
    {
        $cust = $this->client('Odběratel záloha', 'CZ11111126');
        $proformaId = $this->sale('ZAL-2048', $cust, '1', false, 10000.0, 2100.0, 21.0);
        $draftId = $this->sale('DDKP-DRAFT', $cust, '1', false, 10000.0, 2100.0, 21.0);

        $this->db->pdo()->prepare(
            'UPDATE invoices SET invoice_type = "proforma", status = "paid", tax_date = NULL WHERE id = ?'
        )->execute([$proformaId]);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET invoice_type = "tax_document", status = "draft", parent_invoice_id = ? WHERE id = ?'
        )->execute([$proformaId, $draftId]);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $finding = $this->findingByCheck($findings, 'draft_advance_tax_documents');

        self::assertNotNull($finding, 'Koncept DDKP s DUZP v období musí být v prechecku.');
        self::assertFalse($finding['blocking'], 'M26 je informativní precheck, ne tvrdá brána.');
        self::assertSame('info', $finding['severity']);
        self::assertContains($draftId, array_column($finding['documents'], 'invoice_id'));
    }

    /** M26: precheck zachytí i přijatou platbu proformy, ke které DDKP vůbec nevznikl. */
    public function testPaidProformaWithoutAnyTaxDocumentIsReported(): void
    {
        $cust = $this->client('Odběratel chybějící DDKP', 'CZ11111134');
        $proformaId = $this->sale('ZAL-NO-DDKP', $cust, '1', false, 20000.0, 4200.0, 21.0);
        $paidOn = sprintf('%04d-%02d-12', self::YEAR, self::MONTH);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET invoice_type = "proforma", status = "paid", tax_date = NULL WHERE id = ?'
        )->execute([$proformaId]);
        $payment = $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_by)
             VALUES (?, ?, ?, ?, "CZK", "manual", ?)'
        );
        $payment->execute([$this->supplierId, $proformaId, $paidOn, 12100, $this->userId]);
        $firstPaymentId = (int) $this->db->pdo()->lastInsertId();
        $payment->execute([$this->supplierId, $proformaId, $paidOn, 12100, $this->userId]);

        // První platba DDKP má, druhá nikoli — jeden DDKP nesmí skrýt druhou platbu.
        $taxDocumentId = $this->sale('DDKP-FIRST', $cust, '1', false, 10000.0, 2100.0, 21.0);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET invoice_type = "tax_document", parent_invoice_id = ? WHERE id = ?'
        )->execute([$proformaId, $taxDocumentId]);
        $this->db->pdo()->prepare(
            'UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ?'
        )->execute([$taxDocumentId, $firstPaymentId]);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $finding = $this->findingByCheck($findings, 'draft_advance_tax_documents');

        self::assertNotNull($finding);
        self::assertContains($proformaId, array_column($finding['documents'], 'invoice_id'));
        self::assertSame('missing', $finding['documents'][0]['document_kind']);
        self::assertSame(12100.0, (float) $finding['counter'], 'precheck má sečíst jen platbu bez DDKP');
    }

    /** Finál s DUZP v jiném období nesmí skrýt povinnost z dřívější přijaté zálohy. */
    public function testFinalInvoiceInLaterPeriodDoesNotHideMissingTaxDocument(): void
    {
        $cust = $this->client('Odběratel pozdější finál', 'CZ11111142');
        $proformaId = $this->sale('ZAL-LATER-FINAL', $cust, '1', false, 10000.0, 2100.0, 21.0);
        $paidOn = sprintf('%04d-%02d-10', self::YEAR, self::MONTH);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET invoice_type = "proforma", status = "paid", tax_date = NULL WHERE id = ?'
        )->execute([$proformaId]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_by)
             VALUES (?, ?, ?, 12100, "CZK", "manual", ?)'
        )->execute([$this->supplierId, $proformaId, $paidOn, $this->userId]);

        $finalId = $this->sale('FINAL-NEXT-MONTH', $cust, '1', false, 10000.0, 2100.0, 21.0);
        $nextMonth = sprintf('%04d-%02d-01', self::YEAR, self::MONTH + 1);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET parent_invoice_id = ?, tax_date = ?, issue_date = ? WHERE id = ?'
        )->execute([$proformaId, $nextMonth, $nextMonth, $finalId]);

        $finding = $this->findingByCheck(
            $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly'),
            'draft_advance_tax_documents',
        );
        self::assertNotNull($finding);
        self::assertContains($proformaId, array_column($finding['documents'], 'invoice_id'));
    }

    // (b) doklad v DPHDP3, chybí v KH → cross_check najde rozdíl s částkou ─────────

    public function testDocInReturnButMissingInKhIsDetected(): void
    {
        $cust = $this->client('Odběratel nesoulad', 'CZ11111118');
        // Klasifikace '1' (tuzemsko na výstupu → DPHDP3 ř.1), ALE příznak reverse_charge=1
        // → KH doklad odsměruje do A.1 (ne A.4/A.5). Přesně ten nesoulad, který FÚ chytí.
        $s1 = $this->sale('FV-2048-B1', $cust, '1', true, 50000.0, 10500.0, 21.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $domestic = $this->findingByCheck($findings, 'dphdp3_vs_kh_domestic');
        self::assertNotNull($domestic, 'Nesoulad DPHDP3 ř.1 vs KH A.4/A.5 musí být nalezen.');
        self::assertTrue($domestic['blocking'], 'Nenulový rozdíl je blokující.');
        self::assertEqualsWithDelta(50000.0, $domestic['difference'], 0.01, 'Rozdíl = základ dokladu (v DPHDP3, ne v KH).');
        self::assertEqualsWithDelta(50000.0, (float) $domestic['declared'], 0.01);
        self::assertEqualsWithDelta(0.0, (float) $domestic['counter'], 0.01);
        // Drill-down musí ukázat konkrétní doklad (invoice_id), aby účetní věděla, co opravit.
        $ids = array_map(static fn (array $d): int => $d['invoice_id'], $domestic['documents']);
        self::assertContains($s1, $ids, 'Drill-down obsahuje konkrétní invoice_id.');
    }

    // (c) download bez acknowledge při nenulovém rozdílu → 409 s daty ─────────────

    public function testDownloadBlockedWithoutAcknowledge(): void
    {
        $cust = $this->client('Odběratel blok', 'CZ11111118');
        $this->sale('FV-2048-C1', $cust, '1', true, 50000.0, 10500.0, 21.0);

        $res = $this->download();
        self::assertSame(409, $res['status'], 'Nenulový rozdíl bez potvrzení → 409.');
        self::assertSame('vat_cross_check_mismatch', $res['body']['error']['code'] ?? null);
        self::assertNotEmpty($res['body']['error']['cross_check'] ?? [], 'Odpověď nese data smíru pro FE tabulku.');
    }

    // (d) download s acknowledge_mismatch=1 → projde i s rozdílem, zaloguje se ─────

    public function testDownloadPassesWithAcknowledgeAndLogs(): void
    {
        $cust = $this->client('Odběratel ack', 'CZ11111118');
        $this->sale('FV-2048-D1', $cust, '1', true, 50000.0, 10500.0, 21.0);

        $res = $this->download(['acknowledge_mismatch' => '1']);
        self::assertSame(200, $res['status'], 'S potvrzením projde i přes rozdíl.');
        self::assertStringContainsString('xml', strtolower($res['content_type']));

        $log = $this->db->pdo()->query(
            "SELECT id FROM activity_log
              WHERE action = 'report.dphdp3_mismatch_acknowledged'
              ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotFalse($log, 'Vědomé podání přes rozdíl je auditované.');
    }

    // (e) nezaúčtované doklady → kontrola 343 se přeskočí s poznámkou ─────────────

    public function testUnpostedDocsSkip343WithInfoNote(): void
    {
        $cust = $this->client('Odběratel nezaúčt.', 'CZ11111118');
        $vend = $this->client('Dodavatel nezaúčt.', 'CZ22222220');
        // Konzistentní DPHDP3↔KH (žádný tvrdý rozdíl), ale NEzaúčtované → 343 se přeskočí.
        $this->sale('FV-2048-E1', $cust, '1', false, 50000.0, 10500.0, 21.0);
        $this->purchase('PF-2048-E1', $vend, '40', 20000.0, 4200.0, 21.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc, 'Kontrola 343 vrátí informativní poznámku.');
        self::assertSame('info', $acc['severity'], 'Přeskočení není tvrdý rozdíl.');
        self::assertFalse($acc['blocking'], 'Informativní poznámka nebrání stažení.');
        self::assertStringContainsString('nezaúčtovaných', (string) $acc['note']);
        self::assertFalse($this->crossCheck->hasBlockingMismatch($findings), 'Bez tvrdého rozdílu → download by prošel.');
    }

    // (f) rozdíl SH u dokladu S DIČ → úplný drill-down ho MUSÍ uvést ──────────────

    public function testShDrillDownListsDivergentDocWithDic(): void
    {
        // EU dodání zboží (kód 20 → DPHDP3 ř.20), ALE odběratel je tuzemský (CZ) a MÁ DIČ.
        // DPHDP3 ř.20 základ zahrne, souhrnné hlášení ho vyloučí (bere jen EU země ≠ CZ) → rozdíl.
        // Doklad DIČ MÁ, takže starý drill-down (jen doklady BEZ DIČ) by ho neuvedl —
        // tenhle test hlídá úplnost rozpadu i pro rozdíl z jiné příčiny než chybějící DIČ.
        $cust = $this->client('Odběratel SH drill', 'CZ11111118');
        $s1 = $this->sale('FV-2048-F1', $cust, '20', false, 40000.0, 0.0, 0.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $sh = $this->findingByCheck($findings, 'dphdp3_vs_sh');
        self::assertNotNull($sh, 'Rozdíl DPHDP3 ř.20 vs souhrnné hlášení musí být nalezen.');
        self::assertTrue($sh['blocking'], 'Nenulový rozdíl je blokující.');
        self::assertEqualsWithDelta(40000.0, (float) $sh['declared'], 0.01, 'DPHDP3 strana = základ dokladu.');
        self::assertEqualsWithDelta(0.0, (float) $sh['counter'], 0.01, 'SH stranu doklad nemá (tuzemská země).');
        $ids = array_map(static fn (array $d): int => $d['invoice_id'], $sh['documents']);
        self::assertContains($s1, $ids, 'Úplný drill-down uvede i doklad s DIČ (rozdíl z jiné příčiny než chybějící DIČ).');
    }

    // Issue #141: SH zaokrouhluje každý řádek nahoru, přiznání až součet → není to nesoulad.
    public function testShPerRowRoundUpIsNotMismatch(): void
    {
        $skId = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = 'SK' AND is_eu = 1 LIMIT 1")->fetchColumn() ?: 0);
        if ($skId === 0) {
            self::markTestSkipped('SK není v countries jako EU.');
        }
        foreach (['SK1410000011', 'SK1410000012', 'SK1410000013', 'SK1410000014', 'SK1410000015'] as $i => $dic) {
            $cust = $this->client('Odběratel SH141 ' . $i, $dic, $skId);
            $this->sale('FV-2048-R' . $i, $cust, '22', true, 1000.10, 0.0, 0.0);
        }

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        self::assertNull($this->findingByCheck($findings, 'dphdp3_vs_sh'),
            'SH 5 × 1001 Kč vs ř.21 5000,50 Kč je jen zaokrouhlení po řádcích, ne nesoulad.');
    }

    // ── F-0b: drill-down kontroly 343 + klasifikace § 73 (E1-QUICKWINS §3–§4) ──

    // T1: timing vpřed (vzor PF 255) — předpis k DUZP v M, odpočet dle § 73 v M+1.
    public function testTimingShiftForwardIsInfoAndDoesNotBlock(): void
    {
        $vend = $this->client('Dodavatel timing T1', 'CZ22222220');
        $pf = $this->purchase('PF-2048-T1', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc, 'Timing rozdíl 343 musí vrátit nález (s vysvětlením).');
        self::assertSame('info', $acc['severity'], 'Plně vysvětlený § 73 posun je informativní.');
        self::assertFalse($acc['blocking'], 'Timing-only rozdíl neblokuje stažení.');
        self::assertEqualsWithDelta(-4200.0, (float) $acc['difference'], 0.01);
        self::assertEqualsWithDelta((float) $acc['difference'], (float) $acc['explained'], 0.005, 'explained == difference (vše vysvětleno).');
        self::assertCount(1, $acc['documents']);
        $doc = $acc['documents'][0];
        self::assertSame($pf, $doc['invoice_id']);
        self::assertSame('purchase', $doc['source']);
        self::assertSame('timing_73', $doc['reason']);
        self::assertSame(sprintf('%04d-07', self::YEAR), $doc['claim_period']);
        self::assertSame(sprintf('%04d-07-02', self::YEAR), $doc['received_at'], 'received_at se vrací jen u manual zdroje.');
        self::assertNull($doc['entry_date']);
        self::assertFalse($this->crossCheck->hasBlockingMismatch($findings));

        $res = $this->download();
        self::assertSame(200, $res['status'], 'Timing-only rozdíl → download bez acknowledge projde.');
    }

    // T2: zrcadlo — odpočet nárokován v M+1, předpis zaúčtován v M.
    public function testTimingShiftMirrorInClaimPeriodIsInfo(): void
    {
        $vend = $this->client('Dodavatel timing T2', 'CZ22222220');
        $pf = $this->purchase('PF-2048-T2', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, 7, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertSame('info', $acc['severity']);
        self::assertFalse($acc['blocking']);
        self::assertEqualsWithDelta(4200.0, (float) $acc['difference'], 0.01);
        self::assertCount(1, $acc['documents']);
        $doc = $acc['documents'][0];
        self::assertSame('timing_73', $doc['reason']);
        self::assertSame(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), $doc['entry_date'], 'Zrcadlo nese datum zápisu v dřívějším období.');
        self::assertNull($doc['claim_period']);
    }

    /**
     * T2b: uzavření a otevření knih (ČÚS 002) NENÍ daňová transakce a do obratu 343 nepatří.
     *
     * K rozvahovému dni se zůstatek 343 převede na 702, k 1. dni dalšího období se přes 701
     * vrátí. Bez vyloučení se celý zůstatek objeví v obratu Q4 jako přebývající zápis
     * a v Q1 dalšího roku s opačným znaménkem — kontrola pak hlásí dva falešné nesoulady,
     * které se navzájem přesně ruší.
     *
     * V produkci to vypadalo jako účetní nález: pár shodných částek s opačným
     * znaménkem mezi Q4 a následujícím Q1. Vyplavalo to až spuštěním kontroly nad CELÝM rokem (`CrossCheckSuite`) —
     * uzávěrkový zápis padne jen do Q4, takže dřívější volání za jedno období na něj
     * většinou nenarazilo. Blind spot byl přitom stejného druhu jako vyloučení úhrad
     * DPH proti bance, které v dotazu bylo od začátku; jen na tenhle případ nikdo nepomyslel.
     *
     * @return iterable<string, array{string}>
     */
    public static function bookClosingSourceTypes(): iterable
    {
        yield 'uzavření knih' => ['closing'];
        yield 'otevření knih' => ['opening'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bookClosingSourceTypes')]
    public function testBookClosingEntriesAreNotCountedIn343Turnover(string $sourceType): void
    {
        // Konzistentní pár doklad ↔ zaúčtování, aby kontrola sama o sobě neměla co hlásit.
        $cust = $this->client('Odběratel uzávěrka', 'CZ11111118');
        $fv = $this->sale('FV-2048-CL', $cust, '1', false, 50000.0, 10500.0, 21.0);
        $this->autoPoster->post($this->supplierId, 'invoice', $fv);

        // Převod zůstatku 343 na 702/701 — částka řádově nad tolerancí 1 Kč.
        $this->entry343(sprintf('%04d-%02d-28', self::YEAR, self::MONTH), 65_760.16, 'debit', $sourceType, 1);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $acc = $this->findingByCheck($findings, 'account_343_vs_return');

        self::assertNull($acc, sprintf(
            'Zápis se source_type="%s" je převod zůstatku 343 na 702/701, ne daňová transakce — '
                . 'do obratu 343 nesmí vstupovat. Nález: %s',
            $sourceType,
            json_encode($acc, JSON_UNESCAPED_UNICODE),
        ));
        self::assertFalse($this->crossCheck->hasBlockingMismatch($findings), 'Uzávěrka nesmí blokovat stažení.');
    }

    // T3: věcný rozdíl — ruční zápis na 343 bez zdroje → extra_entry, blokuje, 409 gate.
    public function testManualEntryOn343IsExtraEntryAndBlocks(): void
    {
        $entryId = $this->manualEntry343(sprintf('%04d-%02d-20', self::YEAR, self::MONTH), 1000.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertSame('mismatch', $acc['severity']);
        self::assertTrue($acc['blocking'], 'Nevysvětlený rozdíl > 1 Kč blokuje.');
        self::assertCount(1, $acc['documents']);
        $doc = $acc['documents'][0];
        self::assertSame('manual', $doc['source']);
        self::assertSame('extra_entry', $doc['reason']);
        self::assertSame($entryId, $doc['invoice_id']);

        // Ruční zápis doklad nemá, takže se hlásí sám za sebe — datum a označení zápisu
        // v deníku ale existují a rozpis je musí nést. Doplňování metadat je dlouho
        // pokrývalo jen pro faktury a pokladnu, takže jediný takový řádek zůstával bez
        // data i bez čísla a účetní ho neměla podle čeho v deníku najít. V ostrých datech
        // to byl zápis „INT 15 — převod dph" s haléřovým vyrovnáním na 343.
        self::assertSame(
            sprintf('%04d-%02d-20', self::YEAR, self::MONTH),
            $doc['doc_date'],
            'Řádek rozpisu musí nést datum zápisu z deníku.'
        );

        $res = $this->download();
        self::assertSame(409, $res['status'], 'Nevysvětlený rozdíl → 409 gate beze změny.');
        self::assertSame('vat_cross_check_mismatch', $res['body']['error']['code'] ?? null);

        $res2 = $this->download(['acknowledge_mismatch' => '1']);
        self::assertSame(200, $res2['status'], 'S potvrzením projde i přes rozdíl.');
        $log = $this->db->pdo()->query(
            "SELECT id FROM activity_log
              WHERE action = 'report.dphdp3_mismatch_acknowledged'
              ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotFalse($log, 'Vědomé podání přes rozdíl je auditované.');
    }

    // T4 + T5: kombinace timing + věcný rozdíl; invariant Σ per-doc == headline.
    public function testTimingPlusManualEntryBlocksWithClassifiedDocsAndInvariantHolds(): void
    {
        $vend = $this->client('Dodavatel timing T4', 'CZ22222220');
        $pf = $this->purchase('PF-2048-T4', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);
        $entryId = $this->manualEntry343(sprintf('%04d-%02d-20', self::YEAR, self::MONTH), 1000.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertSame('mismatch', $acc['severity']);
        self::assertTrue($acc['blocking'], 'Nevysvětlený zbytek (ruční zápis) > 1 Kč → blokuje.');
        self::assertEqualsWithDelta(-4200.0, (float) $acc['explained'], 0.01, 'explained = jen timing část.');
        self::assertStringContainsString('vysvětlují', (string) $acc['note']);
        self::assertStringContainsString('nevysvětlený zbytek', (string) $acc['note']);

        $byId = [];
        foreach ($acc['documents'] as $d) {
            $byId[$d['invoice_id'] . ':' . $d['source']] = $d;
        }
        self::assertCount(2, $acc['documents']);
        self::assertSame('timing_73', $byId[$pf . ':purchase']['reason'] ?? null);
        self::assertSame('extra_entry', $byId[$entryId . ':manual']['reason'] ?? null);

        // T5 — invariant drill-downu: žádný „ztracený" rozdíl mezi headline a rozpisem.
        $sumDiff = round(array_sum(array_map(static fn (array $d): float => (float) $d['difference'], $acc['documents'])), 2);
        self::assertEqualsWithDelta((float) $acc['difference'], $sumDiff, 0.005, 'Σ documents.difference == difference.');
        $sumCounter = round(array_sum(array_map(static fn (array $d): float => (float) $d['counter'], $acc['documents'])), 2);
        self::assertEqualsWithDelta((float) $acc['counter'], $sumCounter, 0.005, 'Σ booked per-doklad == headline counter.');
    }

    // T7: měsíční kontrola — timing-only rozdíl je ✓ (ok=true) s info nálezem v hodnotě.
    public function testMonthlyCheckIsOkForTimingOnlyDifference(): void
    {
        $vend = $this->client('Dodavatel timing T7', 'CZ22222220');
        $pf = $this->purchase('PF-2048-T7', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);

        $res = $this->closing->monthlyCheck(
            $this->supplierId,
            $this->periodId,
            sprintf('%04d-%02d-01', self::YEAR, self::MONTH),
            sprintf('%04d-%02d-30', self::YEAR, self::MONTH),
        );

        $item = null;
        foreach ($res['checks'] as $c) {
            if (($c['key'] ?? null) === 'vat_343_vs_return') {
                $item = $c;
                break;
            }
        }
        self::assertNotNull($item, 'Měsíční kontrola obsahuje položku vat_343_vs_return.');
        self::assertTrue($item['ok'], 'Timing-only rozdíl (blocking=false) → položka je ✓.');
        self::assertNotNull($item['value'], 'Hodnota nese info nález s vysvětlením.');
        self::assertSame('info', $item['value']['severity'] ?? null);
    }

    // T8: purchaseClaimInfo — claim_date odpovídá větvím periodExpr (§ 73).
    public function testPurchaseClaimInfoMatchesPeriodExprBranches(): void
    {
        $deId = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($deId === 0) {
            self::markTestSkipped('Chybí země DE v číselníku.');
        }
        $vendCz = $this->client('Dodavatel T8 CZ', 'CZ22222220');
        $vendDe = $this->client('Dodavatel T8 DE', 'DE123456789', $deId);

        // manual → GREATEST(received_at, DUZP, vystavení)
        $a = $this->purchase('PF-2048-T8A', $vendCz, '40', 100.0, 21.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        // import → GREATEST(DUZP, vystavení); received_at se ignoruje
        $b = $this->purchase('PF-2048-T8B', $vendCz, '40', 100.0, 21.0, 21.0, [
            'tax_date'           => sprintf('%04d-05-31', self::YEAR),
            'issue_date'         => sprintf('%04d-06-02', self::YEAR),
            'received_at'        => sprintf('%04d-06-20', self::YEAR),
            'received_at_source' => 'import',
        ]);
        // zahraniční RC → DUZP bez ohledu na vystavení/držení
        $c = $this->purchase('PF-2048-T8C', $vendDe, '5', 100.0, 0.0, 0.0, [
            'reverse_charge'     => 1,
            'issue_date'         => sprintf('%04d-07-01', self::YEAR),
            'received_at'        => sprintf('%04d-07-01', self::YEAR),
            'received_at_source' => 'manual',
        ]);

        $info = $this->ledger->purchaseClaimInfo($this->supplierId, [$a, $b, $c]);

        self::assertSame(sprintf('%04d-07-02', self::YEAR), $info[$a]['claim_date'] ?? null, 'manual → GREATEST vč. received_at.');
        self::assertSame('manual', $info[$a]['received_at_source'] ?? null);
        self::assertSame(sprintf('%04d-06-02', self::YEAR), $info[$b]['claim_date'] ?? null, 'import → GREATEST(DUZP, vystavení).');
        self::assertSame(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), $info[$c]['claim_date'] ?? null, 'zahraniční RC → DUZP.');
    }

    // ── adversariální review F-0b: timing_73 jen pro skutečné § 73 posuny ─────

    // (a) storno pár mimo období → živý net 0 → missing_entry (ne timing) + blokace.
    public function testReversedPairOutsidePeriodIsMissingEntryAndBlocks(): void
    {
        $vend = $this->client('Dodavatel storno NA', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NA', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        // Předpis se storno párem 343 uvnitř zápisu (MD i D 4 200) v červnu → živý net mimo claim období = 0.
        $this->entry343NettedPair(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 4200.0, $pf);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, 7, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertSame('mismatch', $acc['severity']);
        self::assertTrue($acc['blocking'], 'Vynetovaný storno pár mimo období není § 73 vysvětlení.');
        self::assertCount(1, $acc['documents']);
        self::assertSame('missing_entry', $acc['documents'][0]['reason']);
    }

    // (a2) stornovaný (reversed) zápis mimo období není živý net → nikdy timing_73.
    public function testReversedEntryOutsidePeriodIsNotLiveNetForTiming(): void
    {
        $vend = $this->client('Dodavatel storno NA2', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NA2', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        // Produkční storno: originál (purchase_invoice:pf) + zrcadlo se source_id NULL, vazba reversed_by.
        $orig = $this->entry343(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 4200.0, 'debit', 'purchase_invoice', $pf);
        $rev = $this->entry343(sprintf('%04d-%02d-16', self::YEAR, self::MONTH), 4200.0, 'credit', 'purchase_invoice', null);
        $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ?')->execute([$rev, $orig]);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, 7, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        // Stornovaný předpis = doklad je fakticky nezaúčtovaný → guard smír přeskočí
        // s informativní poznámkou; hlavně NIKDY nesmí vyjít timing_73 z mrtvého zápisu.
        foreach ($acc['documents'] as $doc) {
            self::assertNotSame('timing_73', $doc['reason'], 'Stornovaný zápis mimo období není § 73 vysvětlení.');
        }
    }

    // (b) chybná částka přes hranici období → blokuje aspoň v jednom z obou období.
    public function testWrongAmountAcrossPeriodBoundaryBlocksAtLeastOnce(): void
    {
        $vend = $this->client('Dodavatel NB', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NB', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        // Předpis zaúčtován v červnu s CHYBNOU částkou (4000 místo 4200).
        $this->entry343(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 4000.0, 'debit', 'purchase_invoice', $pf);

        $june = $this->findingByCheck(
            $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly'),
            'account_343_vs_return',
        );
        $july = $this->findingByCheck(
            $this->crossCheck->check($this->supplierId, self::YEAR, 7, 'monthly'),
            'account_343_vs_return',
        );

        self::assertNotNull($july, 'Claim období musí rozdíl vidět.');
        self::assertTrue($july['blocking'], 'Net mimo období nesedí na přiznaný příspěvek → blokuje.');
        self::assertSame('value_mismatch', $july['documents'][0]['reason'] ?? null, 'Odlišná částka není timing_73.');
        self::assertTrue(($june['blocking'] ?? false) || $july['blocking'], 'Chybná částka blokuje aspoň v jednom období.');
    }

    // (c) cancelled doklad se živým zápisem a claim_date mimo okno → extra_entry + blokace.
    public function testCancelledDocWithLiveEntryIsExtraEntryAndBlocks(): void
    {
        $vend = $this->client('Dodavatel NC', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NC', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$pf]);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertTrue($acc['blocking'], 'Zrušený doklad do přiznání nevstoupí — živý zápis není timing.');
        self::assertSame('extra_entry', $acc['documents'][0]['reason'] ?? null);
    }

    // (d) vat_deduction='none' se živým zápisem a claim_date mimo okno → extra_entry + blokace.
    public function testNoDeductionDocWithLiveEntryIsExtraEntryAndBlocks(): void
    {
        $vend = $this->client('Dodavatel ND', 'CZ22222220');
        $pf = $this->purchase('PF-2048-ND', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_deduction = 'none' WHERE id = ?")->execute([$pf]);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertTrue($acc['blocking'], 'Doklad bez nároku na odpočet do přiznání nevstoupí — zápis není timing.');
        self::assertSame('extra_entry', $acc['documents'][0]['reason'] ?? null);
    }

    // (e) value_mismatch oboustranný — doklad na obou stranách s odlišnou částkou.
    public function testBothSidesAmountMismatchIsValueMismatchAndBlocks(): void
    {
        $vend = $this->client('Dodavatel NE', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NE', $vend, '40', 20000.0, 4200.0, 21.0);
        // Předpis zaúčtován s chybnou částkou → booked −3 700 vs declared −4 200.
        $this->entry343(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 3700.0, 'debit', 'purchase_invoice', $pf);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertTrue($acc['blocking']);
        self::assertCount(1, $acc['documents']);
        $doc = $acc['documents'][0];
        self::assertSame('value_mismatch', $doc['reason']);
        self::assertEqualsWithDelta(-4200.0, (float) $doc['declared'], 0.01);
        self::assertEqualsWithDelta(-3700.0, (float) $doc['counter'], 0.01);
    }

    // (f) vynetované ne-timing chyby + timing doklad → blokuje (unexplained == 0 nestačí).
    public function testNettedNonTimingErrorsStillBlockDespiteTimingDoc(): void
    {
        $vend = $this->client('Dodavatel NF', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NF', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $pf);
        // Dva věcné rozdíly, které se v součtu vynetují (+1 000 / −1 000).
        $this->manualEntry343(sprintf('%04d-%02d-20', self::YEAR, self::MONTH), 1000.0);
        $this->entry343(sprintf('%04d-%02d-21', self::YEAR, self::MONTH), 1000.0, 'debit', 'manual', null);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertSame('mismatch', $acc['severity']);
        self::assertTrue($acc['blocking'], 'Věcné rozdíly nad toleranci blokují, i když se vynetovaly.');
        self::assertEqualsWithDelta(-4200.0, (float) $acc['explained'], 0.01, 'explained = jen timing doklad.');
        self::assertStringContainsString('vynetovaly', (string) $acc['note'], 'Nota nesmí tvrdit, že je vše vysvětleno § 73.');
        self::assertTrue($this->crossCheck->hasBlockingMismatch($findings));
    }

    // (g) krácený nárok § 76: evidence DPH i předpis 343 nesou PLNOU daň (koeficient se
    //     uplatní až souhrnně na ř. 52), takže posun odpočtu § 73 je přesný jako u plného nároku.
    public function testReducedDeductionShiftedClaimIsTiming(): void
    {
        $vend = $this->client('Dodavatel NG', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NG', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_deduction = 'reduced' WHERE id = ?")->execute([$pf]);
        // Zálohový koeficient § 76 — bez něj DPHDP3 builder krácené řádky odmítne.
        $this->db->pdo()->prepare('INSERT INTO vat_coefficients (supplier_id, year, provisional_percent) VALUES (?, ?, 60)')
            ->execute([$this->supplierId, self::YEAR]);
        // Předpis v červnu se SHODNOU částkou, odpočet až v červenci (doklad přijat 2. 7.).
        $this->entry343(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 4200.0, 'debit', 'purchase_invoice', $pf);

        foreach ([self::MONTH, 7] as $month) {
            $findings = $this->crossCheck->check($this->supplierId, self::YEAR, $month, 'monthly');
            $acc = $this->findingByCheck($findings, 'account_343_vs_return');
            self::assertNotNull($acc, "měsíc {$month}");
            self::assertFalse($acc['blocking'], "měsíc {$month}: posun § 73 u kráceného nároku neblokuje.");
            self::assertSame('timing_73', $acc['documents'][0]['reason'] ?? null, "měsíc {$month}");
        }
    }

    // (g2) poměrný nárok § 75 zůstává bezpečně blokující — procento krácení je per doklad
    //      a posun § 73 se u něj nevysvětluje.
    public function testProportionalDeductionShiftedClaimIsNeverTiming(): void
    {
        $vend = $this->client('Dodavatel NH', 'CZ22222220');
        $pf = $this->purchase('PF-2048-NH', $vend, '40', 20000.0, 4200.0, 21.0, [
            'received_at'        => sprintf('%04d-07-02', self::YEAR),
            'received_at_source' => 'manual',
        ]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_deduction = 'proportional', vat_deduction_percent = 50 WHERE id = ?")->execute([$pf]);
        $this->entry343(sprintf('%04d-%02d-15', self::YEAR, self::MONTH), 2100.0, 'debit', 'purchase_invoice', $pf);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, 7, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc);
        self::assertTrue($acc['blocking']);
        self::assertSame('value_mismatch', $acc['documents'][0]['reason'] ?? null);
    }

    // ── úhrada / vratka DPH (343 proti bance) se do smíru 343 nezapočítá ───────

    /**
     * Úhrada daňové povinnosti minulého období na FÚ (343 MD / 221 D, doklad z banky)
     * je vypořádání JIŽ PŘIZNANÉ daně, ne plnění období — do obratu 343 pro smír s přiznáním
     * se nesmí započítat. Jinak by kontrola hlásila falešný „nevysvětlený rozdíl" ve výši
     * úhrady a zablokovala stažení DPHDP3 (produkční případ BANK-250 / −205 993).
     */
    public function testVatPaymentSettlementIsExcludedFrom343Turnover(): void
    {
        $cust = $this->client('Odběratel úhrada DPH', 'CZ11111118');
        $vend = $this->client('Dodavatel úhrada DPH', 'CZ22222220');
        $s1 = $this->sale('FV-2048-PAY', $cust, '1', false, 50000.0, 10500.0, 21.0);
        $p1 = $this->purchase('PF-2048-PAY', $vend, '40', 20000.0, 4200.0, 21.0);
        $this->autoPoster->post($this->supplierId, 'invoice', $s1);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $p1);

        // Úhrada vlastní daňové povinnosti minulého období: 343 MD / 221 D, source_type='bank'.
        $this->bankSettlement343(sprintf('%04d-%02d-22', self::YEAR, self::MONTH), 205993.0);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        // Období je konzistentní a zaúčtované; úhrada vyloučena → obrat 343 sedí na přiznání.
        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNull($acc, 'Úhrada DPH (343/221 z banky) se nesmí započítat do obratu 343 → 0 rozdíl.');
        self::assertFalse($this->crossCheck->hasBlockingMismatch($findings), 'Úhrada DPH nesmí blokovat stažení.');
    }

    /** Vratka nadměrného odpočtu z FÚ (221 MD / 343 D, z banky) se rovněž vyloučí. */
    public function testVatRefundSettlementIsExcludedFrom343Turnover(): void
    {
        $cust = $this->client('Odběratel vratka DPH', 'CZ11111118');
        $vend = $this->client('Dodavatel vratka DPH', 'CZ22222220');
        $s1 = $this->sale('FV-2048-REF', $cust, '1', false, 50000.0, 10500.0, 21.0);
        $p1 = $this->purchase('PF-2048-REF', $vend, '40', 20000.0, 4200.0, 21.0);
        $this->autoPoster->post($this->supplierId, 'invoice', $s1);
        $this->autoPoster->post($this->supplierId, 'purchase_invoice', $p1);

        // Vratka: 343 D / 221 MD (opačná strana), source_type='bank'.
        $this->bankSettlement343(sprintf('%04d-%02d-24', self::YEAR, self::MONTH), 12000.0, 'credit');

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNull($acc, 'Vratka DPH (343/221 z banky) se nesmí započítat do obratu 343 → 0 rozdíl.');
        self::assertFalse($this->crossCheck->hasBlockingMismatch($findings));
    }

    /**
     * Přestřelení filtru úhrad NESMÍ nastat: zápis z banky s 343 proti NE-peněžnímu účtu
     * (311, chybí výhradně peněžní protistrana) není úhrada/vratka DPH, do obratu 343 patří
     * a nezaúčtovaný/přebývající pohyb musí dál blokovat (skutečně chybný doklad se dál flagne).
     */
    public function testBankEntryWith343AgainstNonMoneyAccountStillFlags(): void
    {
        $entryId = $this->entry343(sprintf('%04d-%02d-23', self::YEAR, self::MONTH), 5000.0, 'debit', 'bank', 8888);

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');

        $acc = $this->findingByCheck($findings, 'account_343_vs_return');
        self::assertNotNull($acc, 'Bankovní 343 zápis proti ne-peněžnímu účtu musí zůstat ve smíru.');
        self::assertTrue($acc['blocking'], 'Není úhrada DPH (protistrana není 211/221/261) → dál blokuje.');
        self::assertCount(1, $acc['documents']);
        self::assertSame('bank', $acc['documents'][0]['source']);
        self::assertSame('extra_entry', $acc['documents'][0]['reason']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private function findingByCheck(array $findings, string $check): ?array
    {
        foreach ($findings as $f) {
            if (($f['check'] ?? null) === $check) {
                return $f;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $extraQuery
     * @return array{status:int, body:array<string,mixed>, raw:string, content_type:string}
     */
    private function download(array $extraQuery = []): array
    {
        $query = array_merge(['year' => (string) self::YEAR, 'month' => (string) self::MONTH, 'period' => 'monthly'], $extraQuery);
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/reports/dphdp3')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
        $resp = $this->action->download($req, new Psr7Response());
        $resp->getBody()->rewind();
        $raw = (string) $resp->getBody();
        $decoded = json_decode($raw, true);
        return [
            'status'       => $resp->getStatusCode(),
            'body'         => is_array($decoded) ? $decoded : [],
            'raw'          => $raw,
            'content_type' => $resp->getHeaderLine('Content-Type'),
        ];
    }

    /** Ruční zaúčtovaný zápis D 343 / MD 311 bez zdrojového dokladu (extra_entry vzor). */
    private function manualEntry343(string $entryDate, float $amount): int
    {
        return $this->entry343($entryDate, $amount, 'credit', 'manual', null);
    }

    /**
     * Vypořádání DPH s FÚ — 343 (strana dle $side343) proti peněžnímu účtu 221,
     * doklad z banky (source_type='bank'). Default 'debit' = úhrada daňové povinnosti,
     * 'credit' = vratka nadměrného odpočtu.
     */
    private function bankSettlement343(string $entryDate, float $amount, string $side343 = 'debit'): int
    {
        $pdo = $this->db->pdo();
        $acc343 = $this->accountId('343');
        $acc221 = $this->accountId('221');
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, "Vypořádání DPH FÚ (test)", "bank", ?, NOW(), ?)'
        )->execute([$this->supplierId, $this->periodId, $entryDate, 9250, $this->userId]);
        $entryId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $line->execute([$entryId, $this->supplierId, $acc343, $side343, $amount, 0]);
        $line->execute([$entryId, $this->supplierId, $acc221, $side343 === 'debit' ? 'credit' : 'debit', $amount, 1]);
        return $entryId;
    }

    /** Zaúčtovaný zápis s řádkem 343 (strana dle $side343) proti 311, volitelně vázaný na zdrojový doklad. */
    private function entry343(string $entryDate, float $amount, string $side343, string $sourceType, ?int $sourceId): int
    {
        $pdo = $this->db->pdo();
        $acc343 = $this->accountId('343');
        $acc311 = $this->accountId('311');
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, "Zápis 343 (test)", ?, ?, NOW(), ?)'
        )->execute([$this->supplierId, $this->periodId, $entryDate, $sourceType, $sourceId, $this->userId]);
        $entryId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $line->execute([$entryId, $this->supplierId, $acc311, $side343 === 'credit' ? 'debit' : 'credit', $amount, 0]);
        $line->execute([$entryId, $this->supplierId, $acc343, $side343, $amount, 1]);
        return $entryId;
    }

    /** Zaúčtovaný zápis dokladu se storno párem 343 uvnitř (MD i D stejná částka → živý net 343 = 0). */
    private function entry343NettedPair(string $entryDate, float $amount, int $purchaseInvoiceId): int
    {
        $pdo = $this->db->pdo();
        $acc343 = $this->accountId('343');
        $acc311 = $this->accountId('311');
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, "Zápis 343 storno pár (test)", "purchase_invoice", ?, NOW(), ?)'
        )->execute([$this->supplierId, $this->periodId, $entryDate, $purchaseInvoiceId, $this->userId]);
        $entryId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $line->execute([$entryId, $this->supplierId, $acc343, 'debit', $amount, 0]);
        $line->execute([$entryId, $this->supplierId, $acc311, 'credit', $amount, 1]);
        $line->execute([$entryId, $this->supplierId, $acc343, 'credit', $amount, 2]);
        $line->execute([$entryId, $this->supplierId, $acc311, 'debit', $amount, 3]);
        return $entryId;
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = ?
              ORDER BY is_synthetic DESC, id ASC
              LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            self::markTestSkipped('Účet ' . $code . ' není v osnově.');
        }
        return $id;
    }

    private function client(string $name, ?string $dic, ?int $countryId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId ?? $this->czId, $dic, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, bool $rc, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $issue = sprintf('%04d-%02d-15', self::YEAR, self::MONTH);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    /**
     * @param array{issue_date?:string, tax_date?:string, received_at?:string,
     *              received_at_source?:string, reverse_charge?:int} $opts
     */
    private function purchase(string $number, int $vendorId, string $code, float $base, float $vat, float $rate, array $opts = []): int
    {
        $with = $base + $vat;
        $issue = sprintf('%04d-%02d-15', self::YEAR, self::MONTH);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", ?, "full", ?)'
        );
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if ($opts !== []) {
            $set = [];
            $params = [];
            foreach (['issue_date', 'tax_date', 'received_at', 'received_at_source', 'reverse_charge'] as $col) {
                if (array_key_exists($col, $opts)) {
                    $set[] = "{$col} = ?";
                    $params[] = $opts[$col];
                }
            }
            $params[] = $id;
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }
}

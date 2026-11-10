<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class MoneyS3ImportVatTest extends MoneyS3ImportTestCase
{
    /**
     * DPH evidence (VatLedgerService, z níž se staví přiznání i KH) dostane z převodu doklady
     * podle druhu a členění DPH z Money: daňový doklad k záloze, dobropis (záporné částky),
     * doklad v cizí měně (Money drží částky v Kč) a krácený odpočet § 76. Zálohová faktura
     * do evidence nejde — DPH vedle konečné faktury by se započetlo dvakrát — a doklad
     * s členěním, které převod nezná (přenesená povinnost na vstupu), zůstane konceptem.
     */
    public function testMoneyVatClassificationDrivesVatLedger(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $ledger = $this->container(VatLedgerService::class);
        $rows = $ledger->rows($supplierId, '2025-01-01', '2025-12-31');
        $vat = ['purchase' => 0.0, 'sale' => 0.0];
        $selfAssessed = [];
        foreach ($rows as $r) {
            if (!empty($r['is_reverse_charge'])) {
                $selfAssessed[] = $r;
                continue;
            }
            $vat[$r['source']] = ($vat[$r['source']] ?? 0.0) + (float) $r['vat_czk'];
        }
        // Licence z EU FP25005: samovyměření z interního dokladu ICH25001 — přijetí služby
        // z EU (ř. 5) a zrcadlový odpočet ve sloupci krácený (ř. 43k), 21 % z 1 000.
        self::assertCount(1, $selfAssessed, json_encode($selfAssessed, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['24e', '5', '43k'], [$selfAssessed[0]['code'], $selfAssessed[0]['dphdp3_line'], $selfAssessed[0]['dphdp3_line_secondary']]);
        self::assertEqualsWithDelta(210.0, (float) $selfAssessed[0]['vat_czk'], 0.001);
        self::assertSame('2025-07-10', $selfAssessed[0]['tax_date']);
        // FP25001 1 050 + FP25002 210 + FP24001 z roku 2025 63 + pokladna PV25002 21
        // + daňový doklad k záloze DZ25001 210 + doklad v EUR FP25004 525 − dobropis DP25001 105.
        self::assertEqualsWithDelta(1974.0, $vat['purchase'], 0.001, json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '');
        // Vydaná FV25001 210 + ostatní pohledávka PH25001 s DPH 210 (věcné břemeno, kniha KP);
        // zálohová ZV25001 do evidence nejde.
        self::assertEqualsWithDelta(420.0, $vat['sale'], 0.001);
        $receivable = $this->db->pdo()->prepare(
            "SELECT i.id, i.status, (SELECT e.source_type FROM journal_entries e WHERE e.supplier_id = i.supplier_id AND e.source_id = i.id
                      AND e.source_type = 'invoice' LIMIT 1) AS entry_source
               FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = 'PH25001'"
        );
        $receivable->execute([$supplierId]);
        $receivable = $receivable->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($receivable, 'Ostatní pohledávka s DPH se převede jako vydaný doklad.');
        self::assertSame('invoice', $receivable['entry_source'], 'Zápis KP z Money je zápisem dokladu — kontrola 343 ho páruje s evidencí DPH.');

        $stmt = $this->db->pdo()->prepare(
            'SELECT vendor_invoice_number, document_kind, status, booked_at FROM purchase_invoices WHERE supplier_id = ? AND YEAR(issue_date) = 2025'
        );
        $stmt->execute([$supplierId]);
        $byNumber = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'vendor_invoice_number');
        self::assertSame(['credit_note', 'booked'], [$byNumber['DB-2025-001']['document_kind'], $byNumber['DB-2025-001']['status']]);
        self::assertSame(['tax_document', 'booked'], [$byNumber['DZ-2025-001']['document_kind'], $byNumber['DZ-2025-001']['status']]);
        self::assertSame(['advance', 'received'], [$byNumber['ZF-2025-001']['document_kind'], $byNumber['ZF-2025-001']['status']]);
        self::assertNull($byNumber['ZF-2025-001']['booked_at'], 'Zálohovou fakturu Money neúčtuje.');
        self::assertSame(['invoice', 'draft'], [$byNumber['RC-2025-001']['document_kind'], $byNumber['RC-2025-001']['status']]);
        self::assertNull($byNumber['RC-2025-001']['booked_at'], 'Koncept k ruční kontrole nesmí být zamčený jako zaúčtovaný.');
        self::assertSame('booked', $byNumber['EU-2025-001']['status']);
        // Doklad v EUR není koncept: převezme se v Kč z Money (základ 2 500, daň 525) bez kurzu.
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId,
            "vendor_invoice_number = 'EU-2025-001' AND exchange_rate IS NULL AND total_without_vat = 2500.00 AND total_vat = 525.00"));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "invoice_type = 'proforma' AND status = 'sent' AND booked_at IS NULL AND varsymbol = 'ZV25001'"));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "status = 'draft'"));

        // Krácený odpočet § 76 z členění pokladního dokladu (19Ř40,41 K).
        $cash = $this->db->pdo()->prepare(
            "SELECT l.vat_deduction FROM cash_document_vat_lines l JOIN cash_documents d ON d.id = l.cash_document_id
              WHERE d.supplier_id = ? AND d.doc_number = 'PV25002'"
        );
        $cash->execute([$supplierId]);
        self::assertSame('reduced', $cash->fetchColumn());

        // Odpočet, který Money přesunulo do února (UcPrvDPH), se uplatní v únoru.
        $claim = $this->db->pdo()->prepare(
            "SELECT received_at, received_at_source FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2025-003'"
        );
        $claim->execute([$supplierId]);
        $shifted = $claim->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['2025-02-03', 'manual'], [substr((string) $shifted['received_at'], 0, 10), $shifted['received_at_source']]);
        $purchases = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => $r['source'] === 'purchase'));
        self::assertSame([], $purchases($ledger->rows($supplierId, '2025-01-01', '2025-01-31')), 'Odpočet FP25001 v lednu není.');
        self::assertCount(1, $purchases($ledger->rows($supplierId, '2025-02-01', '2025-02-28')));

        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['purchase_invoices']['counts']['review'] ?? 0);
        self::assertSame(0, $steps['issued_invoices']['counts']['review'] ?? 0);
        self::assertSame(1, $steps['purchase_invoices']['counts']['claim_shifted'] ?? 0);
        self::assertContains('needs_review', array_column($steps['purchase_invoices']['messages'], 'code'));
    }

    /**
     * Pokladní nákup s DPH (tankování, PHM) je v Money v přiznání i v deníku na 343. Převzatý
     * bez DPH by v přiznání chyběl a kontrola obratu 343 by přiznání zablokovala.
     */
    public function testCashDocumentVatGoesToVatLedger(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PV25002' AND vat_mode = 'vat'"));
        $rows = $this->container(VatLedgerService::class)->rows($supplierId, '2025-06-01', '2025-06-30');
        $cash = array_values(array_filter($rows, static fn (array $r): bool => ($r['document_number'] ?? $r['doc_number'] ?? '') === 'PV25002' || str_contains(json_encode($r) ?: '', 'PV25002')));
        self::assertCount(1, $cash, json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(21.0, (float) $cash[0]['vat_czk'], 0.001);
        self::assertSame('purchase', $cash[0]['source']);
    }

    /**
     * Účet v cizí měně: pohyby Money vede ve valutách, ale stav účtu z doby před první
     * knihou zálohy pohybem není. Počáteční stav proto kotví deník — korunový počáteční
     * stav účtu přepočtený kurzem počátečního stavu z Money (`PSKurz`) — a výpisy navazují.
     */
    /**
     * Členění s příponou M/P/MK/PK je v Money pořízení majetku: převzatý doklad ho nese
     * na položkách i řádcích DPH pokladny, takže přiznání má ř. 47 jako v Money.
     */
    public function testFixedAssetCodesFillLine47(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/asset.lz', SyntheticAgenda::filesWithFixedAssetCodes());
        $backup = Ms3Backup::extract($this->tmp . '/asset.lz', $this->tmp . '/asset');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-010' AND is_fixed_asset = 1"));
        // Aplikace drží expense_kind='fixed_asset' ⇔ is_fixed_asset=1 i na položkách.
        self::assertGreaterThan(0, $this->rowCount('purchase_invoices', $supplierId,
            'id IN (SELECT purchase_invoice_id FROM purchase_invoice_items WHERE is_fixed_asset = 1)'));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId,
            "id IN (SELECT purchase_invoice_id FROM purchase_invoice_items WHERE (is_fixed_asset = 1) <> (expense_kind <=> 'fixed_asset'))"));
        $dph = $this->container(DphPriznaniBuilder::class);
        $march = $dph->build($supplierId, 2025, 3, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(1000.0, (float) ($march['47']['base'] ?? 0), 0.005, json_encode($march, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(210.0, (float) ($march['47']['vat'] ?? 0), 0.005);
        $june = $dph->build($supplierId, 2025, 6, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(100.0, (float) ($june['47']['base'] ?? 0), 0.005, json_encode($june, JSON_UNESCAPED_UNICODE) ?: '');
        // Daň a odpočet se nemění: ř. 47 je jen doplňující údaj k ř. 40.
        self::assertEqualsWithDelta(315.0, (float) ($march['40']['vat'] ?? 0), 0.005, 'FP25002 210 + DZ25001 210 - DP25001 105 jako bez příznaku.');
    }

    /**
     * Faktura se samovyměřením z interního dokladu nese příznak přenesené povinnosti na
     * hlavičce (účtování 343 na obě strany, zobrazení dokladu). Rozdíl proti základu
     * samovyměření (jiný kurz) je položka mimo předmět daně - přiznání i KH A.2 zůstávají
     * přesně ze základu interního dokladu, jako je vykázalo Money.
     */
    public function testSelfAssessedPurchaseIsFlaggedAndRateDifferenceStaysOutsideTheReturn(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/rc.lz', SyntheticAgenda::filesWithSelfAssessmentRateDifference());
        $backup = Ms3Backup::extract($this->tmp . '/rc.lz', $this->tmp . '/rc');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $july = $this->container(DphPriznaniBuilder::class)->build($supplierId, 2025, 7, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(1000.0, (float) ($july['5']['base'] ?? 0), 0.005, json_encode($july, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(210.0, (float) ($july['5']['vat'] ?? 0), 0.005, json_encode($july, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(210.0, (float) ($july['43k']['vat'] ?? 0), 0.005, json_encode($july, JSON_UNESCAPED_UNICODE) ?: '');

        $stmt = $this->db->pdo()->prepare(
            "SELECT p.reverse_charge, p.total_with_vat, it.total_without_vat, it.vat_classification_code
               FROM purchase_invoices p JOIN purchase_invoice_items it ON it.purchase_invoice_id = p.id
              WHERE p.supplier_id = ? AND p.varsymbol = 'FP25005' ORDER BY it.order_index"
        );
        $stmt->execute([$supplierId]);
        self::assertSame([[1, '1030.00', '1000.00', '24e'], [1, '1030.00', '30.00', 'mimo']],
            array_map(static fn (array $r): array => [(int) $r[0], (string) $r[1], (string) $r[2], $r[3]], $stmt->fetchAll(PDO::FETCH_NUM)));
    }

    /**
     * Odkaz interního dokladu na fakturu píše účetní volně — malými písmeny s mezerou,
     * jen číslicemi, nebo vůbec (pak páruje číslo dokladu dodavatele). Samovyměření se
     * musí najít ve všech případech, jinak faktura skončí v přiznání bez ř. 5 a 43.
     */
    #[DataProvider('looseSelfAssessmentReferences')]
    public function testSelfAssessmentIsFoundByLooseReferenceOrVendorDocument(string $description, string $vendorDocument): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/ref.lz', SyntheticAgenda::filesWithSelfAssessmentReference($description, $vendorDocument));
        $backup = Ms3Backup::extract($this->tmp . '/ref.lz', $this->tmp . '/ref');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP25005' AND reverse_charge = 1"));
        $july = $this->container(DphPriznaniBuilder::class)->build($supplierId, 2025, 7, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(210.0, (float) ($july['5']['vat'] ?? 0), 0.005, json_encode($july, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return iterable<string,array{string,string}> */
    public static function looseSelfAssessmentReferences(): iterable
    {
        yield 'malá písmena a mezera' => ['PDP k fp 25005', ''];
        yield 'jen číslice' => ['Přiznání daně z 25005', ''];
        yield 'číslo dokladu dodavatele' => ['Přiznání daně z licence', 'DE-2025-001'];
    }

    /**
     * Faktura s příznakem přenesené povinnosti bez interního dokladu se samovyměřením
     * nesmí projít jako plnění bez daně (ř. 40 s nulovou daní) — jde do konceptu.
     */
    public function testReverseChargeWithoutSelfAssessmentGoesToReview(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/revch.lz', SyntheticAgenda::filesWithReverseChargeWithoutSelfAssessment());
        $backup = Ms3Backup::extract($this->tmp . '/revch.lz', $this->tmp . '/revch');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP25005' AND status = 'draft'"));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001' AND status = 'draft'"), 'Ostatní faktury beze změny.');
    }

    /**
     * Přijatá FP25002 se sazbou DPH, kterou číselník nezná, se nepřevede a ostatní faktury kroku
     * ano. Zkouška nanečisto selže jen na rozdílu k přijetí, ostrý převod s přijetím rozdílů
     * doběhne s upozorněním a rozdíl vypíše v `accepted_differences`.
     */
    public function testUnknownVatRateSkipsOnlyThatDocument(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/rate.lz', SyntheticAgenda::filesWithUnknownVatRate());
        $messages = static fn (ImportProtocol $p): array => array_merge(...array_column($p->toArray()['steps'], 'messages'));

        $dry = $this->importer->run($supplierId, $this->userId, Ms3Backup::extract($this->tmp . '/rate.lz', $this->tmp . '/rate-dry'),
            new ImportOptions(ImportOptions::MODE_DRY_RUN, true));
        $unknown = array_values(array_filter($messages($dry), static fn (array $m): bool => $m['code'] === 'unknown_vat_rate'));
        self::assertCount(1, $unknown, $this->explain($dry));
        self::assertSame(['FP25002', 17.0, true], [$unknown[0]['context']['document_no'], $unknown[0]['context']['rate'], $unknown[0]['acceptable'] ?? false]);
        self::assertSame('failed', $dry->status());
        self::assertTrue($dry->toArray()['acceptable_only'], $this->explain($dry));

        $live = $this->importer->run($supplierId, $this->userId, Ms3Backup::extract($this->tmp . '/rate.lz', $this->tmp . '/rate-live'),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, true));
        self::assertSame('completed_with_warnings', $live->status(), $this->explain($live));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP25002'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP25001'"), $this->explain($live));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV24001'"));
        self::assertContains('unknown_vat_rate', array_column($live->toArray()['accepted_differences'], 'code'));
    }

    /** Vydaná faktura v tuzemském přenesení (19Ř25_S) nese příznak na hlavičce; daň se nemění. */
    public function testDomesticReverseSaleIsFlagged(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/pdp.lz', SyntheticAgenda::filesWithDomesticReverseSale());
        $backup = Ms3Backup::extract($this->tmp . '/pdp.lz', $this->tmp . '/pdp');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25002' AND vat_classification_code = '25s' AND reverse_charge = 1 AND status <> 'draft'"), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol <> 'FV25002' AND reverse_charge = 1"));
        $august = $this->container(DphPriznaniBuilder::class)->build($supplierId, 2025, 8, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(1000.0, (float) ($august['25']['base'] ?? 0), 0.005, json_encode($august, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** `19Ř26`: tuzemský odběratel = § 71i, zahraniční = služba s místem plnění mimo tuzemsko. */
    public function testLine26SplitsByCustomerCountry(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/r26.lz', SyntheticAgenda::filesWithOtherSupplyLine26());
        $backup = Ms3Backup::extract($this->tmp . '/r26.lz', $this->tmp . '/r26');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        foreach (['FV25026' => '71i', 'FV25027' => '26s'] as $number => $code) {
            self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = '{$number}' AND vat_classification_code = '{$code}' AND status <> 'draft'"), $number . ' ' . $this->explain($protocol));
            $items = $this->db->pdo()->prepare(
                'SELECT DISTINCT ii.vat_classification_code FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
                  WHERE i.supplier_id = ? AND i.varsymbol = ?');
            $items->execute([$supplierId, $number]);
            self::assertSame([$code], $items->fetchAll(PDO::FETCH_COLUMN), $number);
        }
        $august = $this->container(DphPriznaniBuilder::class)->build($supplierId, 2025, 8, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(2000.0, (float) ($august['26']['base'] ?? 0), 0.005, 'Obě faktury na ř. 26.');
    }

    /**
     * Doklad v cizí měně FP25004 (100 + 21 EUR, kurz 25 = 2 500 + 525 Kč z Money) se ve firmě
     * s eurem v číselníku měn převezme v EUR; firma bez eura ho převezme v Kč jako dřív.
     * Přiznání DPH, KH, rekonciliace i deník obou firem se musí shodovat na haléř.
     */
    public function testForeignCurrencyDocumentIsTakenOverInItsCurrencyWithIdenticalReturn(): void
    {
        $foreign = $this->supplier();
        $this->db->pdo()->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)"
        )->execute([$foreign]);
        $protocol = $this->import($foreign);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        foreach ($protocol->get('reconciliation') as $year) {
            self::assertTrue($year['ok'], json_encode($year, JSON_UNESCAPED_UNICODE) ?: '');
        }
        $home = $this->supplier();
        $inCrowns = $this->import($home);
        self::assertFalse($inCrowns->hasErrors(), $this->explain($inCrowns));

        $doc = static fn (Connection $db, int $supplierId): array => (function () use ($db, $supplierId): array {
            $stmt = $db->pdo()->prepare("SELECT c.code, d.exchange_rate, d.total_without_vat, d.total_vat, d.total_with_vat, d.note_below_items
                FROM purchase_invoices d JOIN currencies c ON c.id = d.currency_id WHERE d.supplier_id = ? AND d.vendor_invoice_number = 'EU-2025-001'");
            $stmt->execute([$supplierId]);
            return $stmt->fetch(PDO::FETCH_NUM) ?: [];
        })();
        $eur = $doc($this->db, $foreign);
        self::assertSame(['EUR', '25.000000', '100.00', '21.00', '121.00'], array_slice($eur, 0, 5));
        self::assertStringContainsString('doklad v EUR, převzat v měně dokladu kurzem 25 Kč', (string) $eur[5]);
        $czk = $doc($this->db, $home);
        self::assertSame(['CZK', null, '2500.00', '525.00', '3025.00'], array_slice($czk, 0, 5));
        self::assertStringContainsString('doklad v EUR, převzat v Kč (měna EUR není v číselníku měn firmy)', (string) $czk[5]);

        $dph = $this->container(DphPriznaniBuilder::class);
        $kh = $this->container(\MyInvoice\Service\Report\KontrolniHlaseniBuilder::class);
        foreach ([4] as $month) {
            self::assertSame($dph->build($home, 2025, $month, 'monthly')['summary']['lines'], $dph->build($foreign, 2025, $month, 'monthly')['summary']['lines']);
            $khHome = $kh->build($home, 2025, $month);
            $khForeign = $kh->build($foreign, 2025, $month);
            self::assertSame($khHome['summary'], $khForeign['summary']);
            $sections = static fn (string $xml): array => preg_match_all('~<Veta[ABC][^>]*/>~', $xml, $m) > 0 ? $m[0] : [];
            self::assertNotSame([], $sections($khHome['xml']));
            self::assertSame($sections($khHome['xml']), $sections($khForeign['xml']));
        }
        $journal = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.supplier_id = ? GROUP BY a.account_code ORDER BY a.account_code"
        );
        $journal->execute([$home]);
        $homeJournal = $journal->fetchAll(PDO::FETCH_KEY_PAIR);
        $journal->execute([$foreign]);
        self::assertSame($homeJournal, $journal->fetchAll(PDO::FETCH_KEY_PAIR));

        // Opakovaný převod porovná doklad v EUR s Money v Kč - změna to není.
        $again = $this->import($foreign);
        self::assertNotContains('changed_in_money', array_column(array_merge(...array_map(static fn (array $s): array => $s['messages'] ?? [], $again->toArray()['steps'])), 'code'));
    }
}

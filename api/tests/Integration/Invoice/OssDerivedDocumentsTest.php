<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doklady ODVOZENÉ ze zálohové faktury musí nést její místo plnění.
 *
 * Obě cesty zakládaly položky BEZ OSS sloupců, a protože `oss_applicable` má
 * DEFAULT 0, nebyl výsledek „chybějící údaj", ale TUZEMSKÝ řádek:
 *
 *   - {@see PaymentTaxDocumentCreator} — záloha na polské plnění se přiznala
 *     na ř. 1 českého přiznání (§ 28/2/d, DUZP = den přijetí úplaty);
 *   - {@see FinalFromProformaCreator} — vyúčtovací faktura zkopírovala položky
 *     proformy bez OSS, takže se z OSS řádku stal na daňovém dokladu tuzemský,
 *     a záporné odpočtové řádky § 37a odečetly daň v JINÉ evidenci, než ve které
 *     ji daňový doklad k platbě přiznal.
 *
 * Validace to nezachytí: guard „zahraniční sazbu jen na řádku v režimu OSS" stojí
 * na `vat_rates.country`, a ten je u zákazníkovy sazby „PL-23" vyplněný jako CZ.
 * Proto běhový test, ne jen architektonický.
 *
 * ── Proč má proforma DVA řádky se STEJNOU sazbou ────────────────────────────────────
 * Odvozené doklady své řádky agregují po sazbě. Zákazníkova konfigurace ale vede
 * polských 23 % v `vat_rates` se zemí CZ, takže OSS řádek a tuzemský řádek mají
 * TOTÉŽ procento i TOTÉŽ `vat_rate_id` — liší se jen místem plnění. Kdyby seskupení
 * bralo jen sazbu, slily by se do jednoho kbelíku a polovina úplaty by se přiznala
 * ve špatné zemi. Test proto vede oba řádky přes tutéž sazbu záměrně.
 *
 * Bez obalové transakce (viz {@see OssManualReviewEditorApiTest}) — služby si otevírají
 * vlastní transakce a named lock. Data jsou syntetická, tearDown maže přesně je.
 */
#[Group('integration')]
final class OssDerivedDocumentsTest extends TestCase
{
    private const PROFORMA_DATE = '2096-05-01';
    private const PAYMENT_DATE  = '2096-05-20';
    private const FINAL_DATE    = '2096-06-01';

    private Connection $db;
    private InvoicePaymentService $payments;
    private PaymentTaxDocumentCreator $taxDocs;
    private FinalFromProformaCreator $finals;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $vatRateCreated = false;
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var ?array<string,mixed> */
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->payments = $c->get(InvoicePaymentService::class);
            $this->taxDocs  = $c->get(PaymentTaxDocumentCreator::class);
            $this->finals   = $c->get(FinalFromProformaCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            $this->markTestSkipped('Chybí OSS schéma (migrace 0137).');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        // Daňový doklad k přijaté platbě vystavuje jen plátce — vynutit a v tearDown vrátit.
        // Zálohy tu hradí ruční úhrada, kterou podvojné účetnictví vyúčtovat nedovolí
        // (advance_receipt_not_posted); OSS profil dokladů na formě účetnictví nezávisí.
        $flags = $pdo->prepare('SELECT is_vat_payer, is_identified, accounting_mode FROM supplier WHERE id = ?');
        $flags->execute([$this->supplierId]);
        $this->origVatFlags = $flags->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
        $this->vatRateId  = $this->vatRate();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, accounting_mode = ? WHERE id = ?')->execute([
                (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                (int) ($this->origVatFlags['is_identified'] ?? 0),
                (string) ($this->origVatFlags['accounting_mode'] ?? 'double_entry'),
                $this->supplierId,
            ]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        // Sazba je v GLOBÁLNÍ `vat_rates` — mazat ji smíme jen tu, kterou jsme založili.
        if ($this->vatRateCreated && $this->vatRateId > 0) {
            $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$this->vatRateId]);
        }

        $this->db->close();
    }

    /**
     * BEZ OPRAVY PADÁ: doklad vznikal bez OSS sloupců, takže se úplata na polské plnění
     * přiznala jako tuzemská.
     */
    public function testPaymentTaxDocumentCarriesTheOssProfileOfTheProformaLine(): void
    {
        $proformaId = $this->seedMixedProforma();
        $payment = $this->payments->recordPayment($proformaId, 1230.00, self::PAYMENT_DATE, ['source' => 'manual']);

        $taxDocId = $this->taxDocs->createForPayment($payment['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;

        $items = $this->itemsOf($taxDocId);
        self::assertCount(2, $items,
            'Kbelík je per sazba A per místo plnění — OSS a tuzemský řádek se nesmí slít do jednoho.');

        $oss = $this->onlyOssRow($items);
        self::assertSame('PL', $oss['oss_consumer_country'], 'Stát spotřeby se přebírá z proformy.');
        self::assertSame('standard', $oss['oss_rate_type']);
        self::assertSame('goods', $oss['oss_supply_type']);
        self::assertSame(1, (int) $oss['oss_applicable'],
            'Úplata na OSS plnění se přiznává ve státě spotřeby, ne na ř. 1 tuzemského přiznání.');

        $domestic = $this->onlyDomesticRow($items);
        self::assertNull($domestic['oss_consumer_country'], 'Tuzemský řádek nesmí OSS dostat „pro jistotu".');
    }

    /**
     * BEZ OPRAVY PADÁ: vyúčtovací faktura kopírovala položky bez OSS sloupců, takže
     * `oss_applicable` spadlo na DB default 0 a OSS řádek se stal tuzemským.
     */
    public function testFinalInvoiceCopiesTheOssProfileAndTheManualReviewFlag(): void
    {
        $proformaId = $this->seedMixedProforma();
        $this->db->pdo()->prepare(
            "UPDATE invoice_items
                SET quantity = 0.5, duration_minutes = 30, unit = 'h', unit_price_without_vat = 2000.000000
              WHERE invoice_id = ? AND order_index = 0"
        )->execute([$proformaId]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::PAYMENT_DATE, $proformaId]);

        $finalId = $this->finals->create($proformaId, $this->userId, self::FINAL_DATE, self::FINAL_DATE);
        $this->invoiceIds[] = $finalId;

        $items = $this->itemsOf($finalId);
        self::assertCount(2, $items, 'Bez daňových dokladů k platbě nese finál jen zkopírované položky.');

        $oss = $this->onlyOssRow($items);
        self::assertSame('PL', $oss['oss_consumer_country']);
        self::assertSame('standard', $oss['oss_rate_type']);
        self::assertSame('goods', $oss['oss_supply_type']);
        self::assertSame(30, (int) $oss['duration_minutes']);
        self::assertSame(0.5, (float) $oss['quantity']);
        self::assertSame(2000.0, (float) $oss['unit_price_without_vat']);

        if ($this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $domestic = $this->onlyDomesticRow($items);
            self::assertSame(1, (int) $domestic['oss_needs_manual_review'],
                'Nejistota o místě plnění se vyúčtováním nevyřeší — příznak musí přejít s řádkem, '
                    . 'a to i na řádku mimo OSS (smíšený doklad).');
        }
    }

    /**
     * Záporné odpočtové řádky § 37a ruší přesně tu daň, kterou přiznal daňový doklad
     * k platbě — musí tedy jít do TÉŽE evidence. Bez přenosu ležela kladná polovina
     * v OSS podání a záporná na ř. 1 tuzemského přiznání: daň odečtená dvakrát v jedné
     * zemi a vůbec ve druhé.
     */
    public function testSection37aDeductionLinesInheritTheOssProfileOfTheTaxDocument(): void
    {
        $proformaId = $this->seedMixedProforma();

        $payment = $this->payments->recordPayment($proformaId, 1230.00, self::PAYMENT_DATE, ['source' => 'manual']);
        $taxDocId = $this->taxDocs->createForPayment($payment['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;
        // „Vystavení" dokladu — draft se do odpočtů § 37a nepočítá (není daňový doklad).
        $this->db->pdo()->prepare(
            "UPDATE invoices SET varsymbol = '2096050099', status = 'paid', paid_at = tax_date WHERE id = ?"
        )->execute([$taxDocId]);

        $this->payments->recordPayment($proformaId, 1230.00, self::FINAL_DATE, ['source' => 'manual']);
        $finalId = $this->finals->create($proformaId, $this->userId, self::FINAL_DATE, self::FINAL_DATE);
        $this->invoiceIds[] = $finalId;

        $deductions = array_values(array_filter(
            $this->itemsOf($finalId),
            static fn (array $i): bool => (float) $i['unit_price_without_vat'] < 0.0,
        ));
        self::assertCount(2, $deductions,
            'Odpočet se dělí po sazbě A po místě plnění — jinak by jeden řádek rušil daň ve dvou evidencích.');

        $oss = $this->onlyOssRow($deductions);
        self::assertSame('PL', $oss['oss_consumer_country'],
            'Odpočtový řádek musí rušit daň tam, kde ji daňový doklad k platbě přiznal.');
        self::assertNull($this->onlyDomesticRow($deductions)['oss_consumer_country']);
    }

    // ── data ─────────────────────────────────────────────────────────────────

    /**
     * Proforma se dvěma řádky TÉŽE sazby (23 %) a téhož `vat_rate_id`, lišícími se
     * jen místem plnění — viz docblock třídy.
     */
    private function seedMixedProforma(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "proforma", ?, ?, ?, ?, ?, 0, 0, 2000.00, 460.00, 2460.00, "issued", ?)'
        )->execute([
            $this->supplierId,
            '20960500' . str_pad((string) count($this->invoiceIds), 2, '0', STR_PAD_LEFT),
            $this->clientId,
            self::PROFORMA_DATE,
            self::PROFORMA_DATE,
            self::PROFORMA_DATE,
            $this->currencyId,
            $this->userId,
        ]);
        $proformaId = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $proformaId;

        $manualReview = $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');
        $columns = 'invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                    vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                    oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type'
            . ($manualReview ? ', oss_needs_manual_review' : '');
        $stmt = $pdo->prepare(
            "INSERT INTO invoice_items ({$columns})
             VALUES (?, ?, 1, 'ks', 1000.00, ?, 23.00, 1000.00, 230.00, 1230.00, ?, ?, ?, ?, ?"
            . ($manualReview ? ', ?' : '')
            . ')'
        );

        $ossRow = [$proformaId, 'TEST OSS plnění do PL (PHPUnit)', $this->vatRateId, 0, 1, 'PL', 'standard', 'goods'];
        $domesticRow = [$proformaId, 'TEST tuzemské plnění (PHPUnit)', $this->vatRateId, 1, 0, null, null, null];
        if ($manualReview) {
            $ossRow[] = 0;
            // Příznak na TUZEMSKÉM řádku schválně: nese ho i řádek mimo OSS a vazba
            // na `oss_applicable` by ho u poloviny označených řádků zhasla.
            $domesticRow[] = 1;
        }
        $stmt->execute($ossRow);
        $stmt->execute($domesticRow);

        return $proformaId;
    }

    /** @return list<array<string,mixed>> */
    private function itemsOf(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function onlyOssRow(array $items): array
    {
        $rows = array_values(array_filter($items, static fn (array $i): bool => (int) $i['oss_applicable'] === 1));
        self::assertCount(1, $rows, 'Očekáván právě jeden OSS řádek — jinak se profil cestou ztratil nebo rozmnožil.');

        return $rows[0];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function onlyDomesticRow(array $items): array
    {
        $rows = array_values(array_filter($items, static fn (array $i): bool => (int) $i['oss_applicable'] === 0));
        self::assertCount(1, $rows, 'Očekáván právě jeden tuzemský řádek.');

        return $rows[0];
    }

    /** Měna se REUSUJE — druhá CZK témuž dodavateli by rozbila `is_default`. */
    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'PL' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát PL není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "TEST OSS odberatel (PHPUnit)", "Ulica 1", "Warszawa", "00-001", ?,
                     "oss-derived@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /** Sazba státu spotřeby v globální `vat_rates` — zakládá ji uživatel, tady tedy test. */
    private function vatRate(): int
    {
        $pdo = $this->db->pdo();
        $code = 'PL-23';

        $probe = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $probe->execute([$code]);
        $this->vatRateCreated = ((int) $probe->fetchColumn()) === 0;

        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, 23.00, "PL", ?, ?, 0, 0, "2090-01-01", NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $code, $code]);

        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}

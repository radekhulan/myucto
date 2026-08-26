<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #39 — co má vzniknout po DOPLACENÍ zálohové faktury.
 *
 * „Doplacená proforma" není totéž co „uskutečněné plnění": u zakázkové výroby je
 * proforma dílčí akontace na budoucí dílo (70 000 Kč ze zakázky za 100 000 Kč),
 * takže její plná úhrada nic nedokončuje a odběratel potřebuje daňový doklad
 * k přijaté platbě. U rychlého prodeje naopak proforma kryje celou objednávku
 * a vyúčtovací faktura je správně. Volí to `supplier.proforma_payment_document`
 * (migrace 1565).
 *
 * Test hlídá právě to rozhodnutí, ne vznik DDKP jako takový: ten má vlastní
 * podmínky (plátcovství DPH, ne-RC) a testuje se jinde. Podstatné je, že
 * v režimu `always_tax_document` finální faktura NEVZNIKNE — protože právě
 * ta uzavírala zakázku, kterou dodavatel teprve začne vyrábět.
 */
#[Group('integration')]
final class ProformaPaymentDocumentModeTest extends TestCase
{
    private const MARKER = '__proforma_mode_test__';

    private Connection $db;
    private FinalFromProformaCreator $finalCreator;
    private PaymentTaxDocumentCreator $taxDocCreator;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private ?string $originalMode = null;
    private bool $columnExists = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db            = $c->get(Connection::class);
            $this->finalCreator  = $c->get(FinalFromProformaCreator::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }

        try {
            $stmt = $pdo->prepare('SELECT proforma_payment_document FROM supplier WHERE id = ?');
            $stmt->execute([$this->supplierId]);
            $this->originalMode = (string) $stmt->fetchColumn();
            $this->columnExists = true;
        } catch (\PDOException) {
            $this->markTestSkipped('Migrace 1565 zatím neproběhla.');
        }

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->columnExists && $this->originalMode !== null) {
            $pdo->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
                ->execute([$this->originalMode, $this->supplierId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    /** Výchozí režim = dnešní chování; existujícím firmám se nesmí nic změnit pod rukama. */
    public function testFullPaymentCreatesFinalInvoiceInDefaultMode(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT);
        $proformaId = $this->proforma();

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $proformaId,
            'proforma',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNotNull($result['final_draft_id'], 'Rychlý prodej má dál dostat vyúčtovací fakturu.');
        self::assertSame(1, $this->countChildren($proformaId, 'invoice'));
    }

    /**
     * BEZ OPRAVY PADÁ: zakázková výroba dostávala vyúčtovací fakturu na nepředané
     * dílo, kterou účetní odběratele odmítne převzít (issue #39).
     */
    public function testFullPaymentCreatesNoFinalInvoiceInTaxDocumentMode(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT);
        $proformaId = $this->proforma();

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $proformaId,
            'proforma',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNull($result['final_draft_id'], 'Zakázka se nesmí uzavřít zálohou.');
        self::assertSame(0, $this->countChildren($proformaId, 'invoice'));
    }

    /**
     * Ruční režim nezakládá nic — doklad vystaví uživatel sám. Že se na to nezapomene,
     * hlídá položka `proforma_awaiting_document` v denním přehledu úkolů, ne tenhle kód.
     */
    public function testManualModeCreatesNothing(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_MANUAL);
        $proformaId = $this->proforma();

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $proformaId,
            'proforma',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNull($result['final_draft_id']);
        self::assertNull($result['tax_document_id']);
        self::assertSame(0, $this->countChildren($proformaId, 'invoice'));
    }

    /** Režim se čte sám z firmy — volající ho nemusí (a nesmí muset) předávat. */
    public function testModeIsResolvedFromSupplier(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT);
        $proformaId = $this->proforma();

        self::assertSame(
            ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT,
            ProformaPaymentDocuments::modeForInvoice($this->db->pdo(), $proformaId),
        );
    }

    /** Jiný typ dokladu než proforma se automatiky netýká. */
    public function testNonProformaIsUntouched(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT);

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $this->proforma(),
            'invoice',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNull($result['final_draft_id']);
        self::assertNull($result['tax_document_id']);
    }

    /**
     * Doplatek zakázky: proforma je jen dílčí akontace (70 000 ze 100 000), takže kopie
     * jejích položek popisuje jen rozsah zálohy. Bez zadané celkové ceny by uživatel
     * zbytek dopisoval ručně (issue #39, bod 2).
     */
    public function testFinalTotalAppendsRemainderLine(): void
    {
        $proformaId = $this->proformaWithItem(70000.0);

        $finalId = $this->finalCreator->create($proformaId, 0, '2098-03-10', '2098-03-24', null, 100000.0);

        $rows = $this->itemsOf($finalId);
        self::assertCount(2, $rows, 'Kopie zálohy + dopočtený zbytek.');
        self::assertEqualsWithDelta(30000.0, (float) $rows[1]['unit_price_without_vat'], 0.01);
        self::assertStringContainsString('Doplatek', (string) $rows[1]['description']);
        self::assertSame(
            (int) $rows[0]['vat_rate_id'],
            (int) $rows[1]['vat_rate_id'],
            'Sazba se dědí po dominantním řádku zálohy.',
        );
    }

    /** Bez zadané ceny se chování nemění — vyúčtování zůstane v rozsahu proformy. */
    public function testWithoutFinalTotalNothingIsAppended(): void
    {
        $proformaId = $this->proformaWithItem(70000.0);

        $finalId = $this->finalCreator->create($proformaId, 0, '2098-03-10');

        self::assertCount(1, $this->itemsOf($finalId));
    }

    /** Zadaná cena nepřevyšující zálohu je legitimní stav, ne chyba — nic se nepřidá. */
    public function testFinalTotalBelowAdvanceAppendsNothing(): void
    {
        $proformaId = $this->proformaWithItem(70000.0);

        $finalId = $this->finalCreator->create($proformaId, 0, '2098-03-10', null, null, 70000.0);

        self::assertCount(1, $this->itemsOf($finalId));
    }

    /**
     * Scénář ze smlouvy o dílo (issue #39): zakázka 100 000, záloha 70 000 zdaněná
     * daňovým dokladem k přijaté platbě, po předání díla jedno vyúčtování.
     *
     * Odběratel potřebuje ke předávacímu protokolu doklad na CELOU cenu díla
     * s položkovým odpočtem zálohy podle § 37a — ne dvě dílčí faktury. Totéž vyžadují
     * dotační programy (podklad na 100 % smluvní ceny) a zařazení díla do majetku.
     * Test tedy měří výsledný doklad, ne jen počet řádků.
     */
    public function testContractFinalCarriesFullPriceWithAdvanceDeduction(): void
    {
        $proformaId = $this->proformaWithItem(70000.0);
        $this->issuedTaxDocumentFor($proformaId, 70000.0);

        $finalId = $this->finalCreator->create($proformaId, 0, '2098-03-10', '2098-03-24', 0.0, 100000.0);

        $rows = $this->itemsOf($finalId);
        $positive = 0.0;
        $negative = 0.0;
        foreach ($rows as $r) {
            $price = (float) $r['unit_price_without_vat'];
            $price >= 0 ? $positive += $price : $negative += $price;
        }

        self::assertEqualsWithDelta(100000.0, $positive, 0.01, 'Doklad musí znít na celou cenu díla.');
        self::assertEqualsWithDelta(-70000.0, $negative, 0.01, 'Odpočet zálohy podle § 37a.');

        $stmt = $this->db->pdo()->prepare('SELECT total_without_vat FROM invoices WHERE id = ?');
        $stmt->execute([$finalId]);
        self::assertEqualsWithDelta(30000.0, (float) $stmt->fetchColumn(), 0.01, 'K doplacení zbývá 30 000.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Vystavený daňový doklad k přijaté platbě navázaný na proformu. */
    private function issuedTaxDocumentFor(int $proformaId, float $amount): int
    {
        $pdo = $this->db->pdo();
        $vatRateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, parent_invoice_id, varsymbol, client_id, supplier_id,
                 issue_date, tax_date, due_date, currency_id, status,
                 total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('tax_document', ?, ?, ?, ?, '2098-03-01', '2098-03-01', '2098-03-01', ?, 'issued', ?, ?, 0, NULL)"
        )->execute([
            $proformaId, '8' . random_int(1000000, 9999999), $this->clientId, $this->supplierId,
            $this->currencyId, $amount, $amount * 1.21,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, "Zdanění přijaté zálohy", 1, "ks", ?, ?, 21, ?, ?, ?, 1, "standard")'
        )->execute([$id, $amount, $vatRateId, $amount, $amount * 0.21, $amount * 1.21]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function itemsOf(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT description, unit_price_without_vat, vat_rate_id FROM invoice_items
               WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Proforma s jednou položkou v dané výši (dílčí akontace zakázky). */
    private function proformaWithItem(float $amount): int
    {
        $id = $this->proforma();
        $vatRateId = (int) ($this->db->pdo()->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($vatRateId === 0) {
            self::markTestSkipped('Chybí sazby DPH.');
        }
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, "Akontace zakázky", 1, "ks", ?, ?, 21, ?, 0, ?, 1, "standard")'
        )->execute([$id, $amount, $vatRateId, $amount, $amount]);

        return $id;
    }

    private function setMode(string $mode): void
    {
        $this->db->pdo()
            ->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    private function countChildren(int $proformaId, string $type): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = ? AND invoice_type = ?'
        );
        $stmt->execute([$proformaId, $type]);

        return (int) $stmt->fetchColumn();
    }

    private function proforma(): int
    {
        $pdo = $this->db->pdo();
        $d = '2098-03-10';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('proforma', ?, ?, ?, ?, ?, ?, ?, 'issued', 70000.00, 84700.00, 0, NULL)"
        )->execute([
            '9' . random_int(1000000, 9999999),
            $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId,
        ]);

        return (int) $pdo->lastInsertId();
    }

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
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovaci 1", "Praha", "11000", ?,
                     "proforma-mode@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::MARKER, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}

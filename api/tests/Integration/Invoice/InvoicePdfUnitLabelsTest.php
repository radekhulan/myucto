<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jednotka na PDF faktury se tiskne popiskem z číselníku `units` v jazyce dokladu
 * (manuál, Nastavení → Jednotky: „Popisek CS / EN – co se zobrazí v UI a PDF").
 * Řádek dokladu dál nese kód, jednotka mimo číselník se tiskne kódem.
 *
 * Jednotky se zakládají v transakci, kterou test na konci vrátí. Data jsou syntetická.
 */
#[Group('integration')]
final class InvoicePdfUnitLabelsTest extends TestCase
{
    private Connection $db;
    private InvoicePdfRenderer $renderer;
    private string $code = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->renderer = $container->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupná: ' . $e->getMessage());
        }
        $this->code = 'ul' . substr(bin2hex(random_bytes(4)), 0, 6);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO units (code, label_cs, label_en, is_default, display_order) VALUES (?, ?, ?, 0, 0)');
        $insert->execute([$this->code . '_55', 'Lekce (55 min)', 'Class (55 min)']);
        $insert->execute([$this->code . '_bal', 'balení', 'pack']);
        $insert->execute([$this->code . '_bez', '', '']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCzechInvoicePrintsCzechLabel(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('cs', $this->code . '_55'), includeCss: false);

        self::assertStringContainsString('<td>Lekce (55 min)</td>', $html);
        self::assertStringNotContainsString($this->code . '_55', $html);
    }

    public function testEnglishInvoicePrintsEnglishLabel(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('en', $this->code . '_55'), includeCss: false);

        self::assertStringContainsString('<td>Class (55 min)</td>', $html);
        self::assertStringNotContainsString($this->code . '_55', $html);
    }

    public function testCodeIsMatchedRegardlessOfCaseAndSurroundingSpaces(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('cs', ' ' . strtoupper($this->code) . '_55 '), includeCss: false);

        self::assertStringContainsString('<td>Lekce (55 min)</td>', $html);
    }

    public function testUnknownUnitAndEmptyLabelKeepTheCode(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('cs', $this->code . '_mimo'), includeCss: false);
        self::assertStringContainsString('<td>' . $this->code . '_mimo</td>', $html);

        $html = $this->renderer->renderHtml($this->invoice('en', $this->code . '_bez'), includeCss: false);
        self::assertStringContainsString('<td>' . $this->code . '_bez</td>', $html);
    }

    public function testPackagingBreakdownPrintsBaseUnitLabelInBothLayouts(): void
    {
        $invoice = $this->invoice('en', $this->code . '_bal', ['base_qty' => 20.0, 'base_unit' => $this->code . '_55']);

        $html = $this->renderer->renderHtml($invoice, includeCss: false);
        self::assertStringContainsString('<td>pack<br><span style="font-size:85%;color:#666666;white-space:nowrap">(total 20&nbsp;Class (55 min))</span></td>', $html);

        $html = $this->renderer->renderHtml($this->invoice('en', $this->code . '_bal', ['base_qty' => 20.0, 'base_unit' => $this->code . '_55'], ['invoice_pdf_compact_items' => 1]), includeCss: false);
        self::assertStringContainsString('total&nbsp;20&nbsp;Class (55 min)</span>', $html);
        self::assertStringContainsString('<td>pack</td>', $html);
    }

    /**
     * Syntetický doklad s jedním řádkem. Snapshot dodavatele je vyplněný, render
     * tedy nesahá na živou firmu (supplier_id 0).
     *
     * @param array<string,mixed> $line
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function invoice(string $language, string $unit, array $line = [], array $supplier = []): array
    {
        return [
            'id'                  => 0,
            'invoice_type'        => 'invoice',
            'status'              => 'issued',
            'language'            => $language,
            'currency'            => 'CZK',
            'currency_id'         => 0,
            'client_id'           => 0,
            'supplier_id'         => 0,
            'varsymbol'           => 'JEDN0001',
            'issue_date'          => '2026-07-01',
            'tax_date'            => '2026-07-01',
            'due_date'            => '2026-07-15',
            'paid_at'             => null,
            'amount_to_pay'       => 121.0,
            'paid_total'          => 0.0,
            'parent_invoice_id'   => null,
            'payment_method'      => 'bank_transfer',
            'prices_include_vat'  => false,
            'reverse_charge'      => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap'           => null,
            'supplier_snapshot'   => json_encode($supplier + [
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'     => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Zkušební 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'       => null,
            'items'               => [
                $line + [
                    'description' => 'Výuka', 'quantity' => 1.0, 'unit' => $unit,
                    'unit_price_without_vat' => 100.0, 'total_without_vat' => 100.0,
                    'total_vat' => 21.0, 'total_with_vat' => 121.0,
                    'vat_rate_snapshot' => 21.0, 'item_kind' => 'standard',
                    'oss_applicable' => false, 'oss_consumer_country' => null,
                ],
            ],
            'totals'              => ['without_vat' => 100.0, 'vat' => 21.0, 'with_vat' => 121.0],
            'vat_breakdown'       => [],
        ];
    }
}

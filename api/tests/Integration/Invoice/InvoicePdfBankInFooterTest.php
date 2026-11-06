<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Bankovní spojení v patičce PDF faktury (`supplier.invoice_pdf_bank_in_footer`, migrace 2200).
 *
 * 1. Volba je ve výchozím stavu vypnutá, ukládá se přes nastavení firmy a týká se jen dané firmy.
 * 2. Bez volby je bankovní spojení v platebním panelu u součtu jako dosud.
 * 3. S volbou jde číslo účtu, IBAN, BIC a banka do patičky každé strany a platební panel
 *    ukáže jen QR s popiskem. Variabilní symbol zůstává v údajích dokladu.
 * 4. Uhrazeno, hotově, kartou a vratka se volbou nemění (panel bankovní spojení neukazuje).
 * 5. S volbou roste spodní okraj PDF s výškou patičky (zalomený dlouhý název banky
 *    nebo IBAN nesmí vrůst do položek), bez volby zůstává pevný.
 *
 * Render běží nad syntetickým polem faktury jako InvoicePdfMonochromeTest.
 */
#[Group('integration')]
final class InvoicePdfBankInFooterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private InvoicePdfRenderer $renderer;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $this->renderer = Bootstrap::buildContainer()->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testOptionIsOffByDefaultAndRoundTripsPerSupplier(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get(SettingsAction::class);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, 1);
            $otherId = $this->createIsolatedSupplier($pdo, 1);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);

            self::assertFalse($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_bank_in_footer']);

            $response = $settings->updateSupplier($request->withParsedBody(['invoice_pdf_bank_in_footer' => true]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertTrue($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_bank_in_footer']);
            $stored = $pdo->prepare('SELECT invoice_pdf_bank_in_footer FROM supplier WHERE id = ?');
            $stored->execute([$supplierId]);
            self::assertSame(1, (int) $stored->fetchColumn());

            $other = $request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $otherId);
            self::assertFalse($this->read($settings->getSupplier($other, new Response()))['invoice_pdf_bank_in_footer']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testWithoutOptionBankDetailsStayInThePaymentPanel(): void
    {
        $html = $this->render($this->invoice());

        self::assertStringContainsString('<td class="pay-info">', $html);
        self::assertStringContainsString('<div class="bank-value">1000000005 / 0100</div>', $html);
        self::assertStringContainsString('<div class="bank-meta-line">BIC: KOMBCZPP</div>', $html);
        self::assertStringNotContainsString('page-bank', $html);
    }

    public function testOptionMovesBankDetailsToThePageFooterAndLeavesOnlyTheQr(): void
    {
        $html = $this->render($this->invoice(['invoice_pdf_bank_in_footer' => 1]));

        $footer = $this->pageFooter($html);
        self::assertStringContainsString('1000000005 / 0100', $footer);
        self::assertStringContainsString('IBAN: CZ6501000000001000000005', $footer);
        self::assertStringContainsString('BIC: KOMBCZPP', $footer);
        self::assertStringContainsString('Testovací banka', $footer);

        self::assertStringContainsString('<td class="pay-qr">', $html);
        self::assertStringContainsString('QR platba', $html);
        self::assertStringNotContainsString('<td class="pay-info">', $html);
        self::assertStringNotContainsString('bank-value', $html);
        // Variabilní symbol zůstává v údajích dokladu nad položkami.
        self::assertMatchesRegularExpression('~Var\. symbol</td>\s*<td class="meta-value mono">2026000123</td>~u', $html);
    }

    /**
     * Issue #314: britský účet tiskne číslo účtu a sort code zvlášť, ne jako české
     * „číslo / kód". Platí v platebním panelu i v patičce.
     */
    public function testBritishAccountPrintsSortCodeBesideAccountNumber(): void
    {
        $british = ['currency' => 'GBP', 'bank_snapshot' => json_encode([
            'account_number' => '12345678', 'bank_code' => '608464', 'bank_name' => 'Testovací banka',
            'iban' => 'GB33BUKB20201555555555', 'bic' => 'BUKBGB22',
        ])] + $this->invoice();

        $html = $this->render($british);
        self::assertStringContainsString('<div class="bank-value">12345678</div>', $html);
        self::assertStringContainsString('<div class="bank-meta-line">Sort code: 60-84-64</div>', $html);
        self::assertStringNotContainsString('12345678 / ', $html);

        $footer = $this->pageFooter($this->render(['bank_snapshot' => $british['bank_snapshot']] + $this->invoice(['invoice_pdf_bank_in_footer' => 1])));
        self::assertStringContainsString('12345678 · Sort code: 60-84-64', $footer);
    }

    public function testOptionWithoutQrDropsThePanelAndKeepsTheFooter(): void
    {
        // Platební kalendář QR nedostává, bankovní spojení ale potřebuje.
        $html = $this->render(['invoice_type' => 'payment_calendar'] + $this->invoice(['invoice_pdf_bank_in_footer' => 1]));

        self::assertStringNotContainsString('pay-panel', $html);
        self::assertStringContainsString('1000000005 / 0100', $this->pageFooter($html));
    }

    /**
     * Volba z živého řádku firmy (koncept bez snapshotu dodavatele, jak ho čte
     * resolveSupplier) a s ní spodní okraj PDF, který roste s výškou patičky.
     */
    public function testLiveSupplierOptionDrivesFooterAndStretchingBottomMargin(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $renderer = $container->get(InvoicePdfRenderer::class);
        $newMpdf = new \ReflectionMethod($renderer, 'newMpdf');
        $bankInFooter = new \ReflectionMethod($renderer, 'bankInFooter');
        $tmpDir = sys_get_temp_dir();
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, 1);
            $invoice = ['supplier_id' => $supplierId, 'status' => 'draft', 'supplier_snapshot' => null] + $this->invoice();

            self::assertFalse($bankInFooter->invoke($renderer, $invoice));
            self::assertStringNotContainsString('page-bank', $renderer->renderHtml($invoice, includeCss: false));
            self::assertNotSame('stretch', $newMpdf->invoke($renderer, $tmpDir, false)->setAutoBottomMargin);

            $pdo->prepare('UPDATE supplier SET invoice_pdf_bank_in_footer = 1 WHERE id = ?')->execute([$supplierId]);

            self::assertTrue($bankInFooter->invoke($renderer, $invoice));
            self::assertStringContainsString('1000000005 / 0100', $this->pageFooter($renderer->renderHtml($invoice, includeCss: false)));
            self::assertSame('stretch', $newMpdf->invoke($renderer, $tmpDir, true)->setAutoBottomMargin);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function statesWithoutBankDetails(): iterable
    {
        yield 'uhrazeno' => [['paid_at' => '2026-10-05', 'paid_total' => 121.0, 'status' => 'paid']];
        yield 'hotově' => [['payment_method' => 'cash']];
        yield 'kartou' => [['payment_method' => 'card']];
        yield 'vratka' => [['amount_to_pay' => -121.0]];
    }

    /** @param array<string,mixed> $state */
    #[DataProvider('statesWithoutBankDetails')]
    public function testOptionDoesNotChangeStatesWithoutBankDetails(array $state): void
    {
        $off = $this->render($state + $this->invoice());

        self::assertStringNotContainsString('1000000005', $off, 'Stav má panel bez bankovního spojení.');
        self::assertSame($off, $this->render($state + $this->invoice(['invoice_pdf_bank_in_footer' => 1])));
    }

    private function render(array $invoice): string
    {
        return $this->renderer->renderHtml($invoice, includeCss: false);
    }

    private function pageFooter(string $html): string
    {
        self::assertSame(1, preg_match('~<htmlpagefooter name="attribution">(.*?)</htmlpagefooter>~s', $html, $m));
        return $m[1];
    }

    /** @return array<string,mixed> */
    private function read(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return (array) ($data['data'] ?? $data);
    }

    /**
     * Syntetický nezaplacený doklad k úhradě převodem. Snapshot dodavatele a banky je
     * vyplněný, render tedy nesahá na živou firmu (supplier_id 0) ani účet (currency_id 0).
     *
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function invoice(array $supplier = []): array
    {
        return [
            'id'                  => 0,
            'invoice_type'        => 'invoice',
            'status'              => 'issued',
            'language'            => 'cs',
            'currency'            => 'CZK',
            'currency_id'         => 0,
            'client_id'           => 0,
            'supplier_id'         => 0,
            'varsymbol'           => '2026000123',
            'issue_date'          => '2026-10-01',
            'tax_date'            => '2026-10-01',
            'due_date'            => '2026-10-15',
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
            'bank_snapshot'       => json_encode([
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Testovací banka',
                'iban'           => 'CZ6501000000001000000005',
                'bic'            => 'KOMBCZPP',
            ], JSON_UNESCAPED_UNICODE),
            'items'               => [[
                'description'            => 'Testovací plnění',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 100.0,
                'total_vat'              => 21.0,
                'total_with_vat'         => 121.0,
                'item_kind'              => 'standard',
                'oss_applicable'         => false,
                'oss_consumer_country'   => null,
            ]],
            'totals'              => ['without_vat' => 100.0, 'vat' => 21.0, 'with_vat' => 121.0],
            'vat_breakdown'       => [],
        ];
    }
}

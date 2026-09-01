<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OSS doložka na dokladu (§ 110a a násl. ZDPH, zvláštní režim dle čl. 369a a násl.
 * směrnice 2006/112/ES).
 *
 * ── Co test hlídá ───────────────────────────────────────────────────────────
 * Doklad s OSS řádky nese sazbu státu spotřeby. Bez doložky ji příjemce ani jeho
 * účetní nemá jak odlišit od české daně ve špatné sazbě — a přesně tenhle stav
 * na dokladu byl (odvod v OSS se nikde netvrdil). Testy proto jdou přes REÁLNÝ
 * render šablony, ne jen přes builder: doložka, která existuje v datech a netiskne
 * se, dokladu nepomůže.
 *
 * Render běží nad syntetickým polem faktury (renderHtml si ho bere jako vstup),
 * takže se do DB nic nezapisuje; z databáze se čte jen číselník zemí.
 */
#[Group('integration')]
final class OssInvoiceClauseRenderTest extends TestCase
{
    private InvoicePdfRenderer $renderer;
    /** @var array<string,array{name_cs:string,name_en:string}> */
    private array $countries = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->renderer = $c->get(InvoicePdfRenderer::class);
            $db = $c->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $stmt = $db->pdo()->query("SELECT iso2, name_cs, name_en FROM countries WHERE iso2 IN ('PL','SK')");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $this->countries[(string) $row['iso2']] = [
                'name_cs' => (string) $row['name_cs'],
                'name_en' => (string) $row['name_en'],
            ];
        }
        if (!isset($this->countries['PL'], $this->countries['SK'])) {
            $this->markTestSkipped('Číselník zemí neobsahuje PL/SK.');
        }
    }

    public function testCzechInvoiceStatesThatVatIsPaidUnderOssInTheStateOfConsumption(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->ossItem('PL')]));

        self::assertStringContainsString(
            'Daň je přiznána a odvedena ve státě spotřeby v režimu jednoho správního místa (One Stop Shop) podle § 110a a násl. zákona o DPH.',
            $html,
        );
        self::assertStringContainsString('Stát spotřeby: ' . $this->countries['PL']['name_cs'] . '.', $html);
    }

    public function testEnglishInvoiceCarriesTheSameClause(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->ossItem('PL')], 'en'));

        self::assertStringContainsString(
            'VAT is declared and paid in the Member State of consumption under the One Stop Shop scheme'
            . ' pursuant to Articles 369a et seq. of Council Directive 2006/112/EC.',
            $html,
        );
        self::assertStringContainsString('Member State of consumption: ' . $this->countries['PL']['name_en'] . '.', $html);
    }

    /**
     * Doklad s víc státy spotřeby jmenuje všechny — jeden vybraný „hlavní" stát by
     * o zbytku plnění tvrdil nepravdu.
     */
    public function testSeveralStatesOfConsumptionAreAllNamed(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->ossItem('PL'), $this->ossItem('SK')]));

        self::assertStringContainsString(
            'Státy spotřeby: ' . $this->countries['PL']['name_cs'] . ', ' . $this->countries['SK']['name_cs'] . '.',
            $html,
        );
    }

    /**
     * Smíšený doklad (část plnění tuzemská) nesmí tvrdit, že daň je odvedena ve státě
     * spotřeby — to platí jen o OSS řádcích.
     */
    public function testMixedInvoiceUsesTheQualifiedWording(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->ossItem('PL'), $this->domesticItem()]));

        self::assertStringContainsString(
            'U položek v režimu jednoho správního místa (One Stop Shop) je daň přiznána a odvedena ve státě spotřeby',
            $html,
        );
        self::assertStringNotContainsString('Daň je přiznána a odvedena ve státě spotřeby v režimu', $html);
    }

    /**
     * Bez země spotřeby (legacy import) se doložka tiskne, ale státy nejmenuje —
     * neúplný výčet by na dokladu lhal.
     */
    public function testClauseWithoutConsumerCountryOmitsTheStateList(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->ossItem('')]));

        self::assertStringContainsString('jednoho správního místa (One Stop Shop)', $html);
        self::assertStringNotContainsString('Stát spotřeby', $html);
    }

    public function testInvoiceWithoutOssItemsHasNoClause(): void
    {
        $html = $this->renderer->renderHtml($this->invoice([$this->domesticItem()]));

        self::assertStringNotContainsString('One Stop Shop', $html);
    }

    public function testOssInvoicePdfOmitsCustomerFacingCzkConversion(): void
    {
        $invoice = $this->invoice([$this->ossItem('PL')]);
        $invoice['czk_recap'] = $this->czkRecap(23.0, 23.0);

        $html = $this->renderer->renderHtml($invoice, includeCss: false);

        self::assertStringNotContainsString('Přepočet do CZK', $html);
        self::assertStringNotContainsString('Kurz ČNB', $html);
        self::assertStringNotContainsString('CZK', $html);
    }

    public function testForeignCustomerWithCzechVatKeepsOnlyMandatoryVatAmountInCzk(): void
    {
        $invoice = $this->invoice([$this->domesticItem()]);
        $invoice['czk_recap'] = $this->czkRecap(21.0, 21.0);

        $html = $this->renderer->renderHtml($invoice, includeCss: false);

        self::assertStringNotContainsString('Přepočet do CZK', $html);
        self::assertStringNotContainsString('Kurz ČNB', $html);
        self::assertStringContainsString('DPH v CZK', $html);
        self::assertStringContainsString('511,67 CZK', $html);
        self::assertSame(2, substr_count($html, 'CZK'));
    }

    public function testCzechCustomerKeepsFullCzkConversionInPdf(): void
    {
        $invoice = $this->invoice([$this->domesticItem()]);
        $invoice['czk_recap'] = $this->czkRecap(21.0, 21.0);
        $client = json_decode((string) $invoice['client_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $client['country_iso2'] = 'CZ';
        $invoice['client_snapshot'] = json_encode($client, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $html = $this->renderer->renderHtml($invoice, includeCss: false);

        self::assertStringContainsString('Přepočet do CZK', $html);
        self::assertStringContainsString('Kurz ČNB', $html);
        self::assertStringNotContainsString('DPH v CZK', $html);
    }

    /** @return array<string,mixed> */
    private function ossItem(string $country): array
    {
        return array_merge($this->domesticItem(), [
            'vat_rate_snapshot'    => 23.0,
            'total_with_vat'       => 123.0,
            'oss_applicable'       => true,
            'oss_consumer_country' => $country,
            'oss_rate_type'        => 'standard',
            'oss_supply_type'      => 'services',
        ]);
    }

    /** @return array<string,mixed> */
    private function domesticItem(): array
    {
        return [
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
        ];
    }

    /** @return array<string,mixed> */
    private function czkRecap(float $rate, float $vat): array
    {
        return CzkRecap::build(
            [['rate' => $rate, 'base' => 100.0, 'vat' => $vat]],
            24.365,
            '2026-07-01',
        );
    }

    /**
     * Syntetický doklad pro render — snapshoty stran jsou vyplněné, takže render
     * nesahá na živého dodavatele ani klienta.
     *
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function invoice(array $items, string $language = 'cs'): array
    {
        $withoutVat = 0.0;
        $withVat = 0.0;
        $vat = 0.0;
        foreach ($items as $it) {
            $withoutVat += (float) $it['total_without_vat'];
            $withVat    += (float) $it['total_with_vat'];
            $vat        += (float) $it['total_with_vat'] - (float) $it['total_without_vat'];
        }

        return [
            'id'                 => 0,
            'invoice_type'       => 'invoice',
            'status'             => 'issued',
            'language'           => $language,
            'currency'           => 'EUR',
            'currency_id'        => 0,
            'client_id'          => 0,
            'supplier_id'        => 0,
            'varsymbol'          => 'OSSTEST1',
            'issue_date'         => '2026-07-01',
            'tax_date'           => '2026-07-01',
            'due_date'           => '2026-07-15',
            'paid_at'            => null,
            'amount_to_pay'      => $withVat,
            'paid_total'         => 0.0,
            'parent_invoice_id'  => null,
            'payment_method'     => 'bank_transfer',
            'prices_include_vat' => false,
            'reverse_charge'     => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap'          => null,
            'supplier_snapshot'  => json_encode([
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'    => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Testowa 2',
                'city'         => 'Warszawa',
                'zip'          => '00001',
                'country_iso2' => 'PL',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'      => null,
            'items'              => $items,
            'totals'             => [
                'without_vat' => $withoutVat,
                'vat'         => $vat,
                'with_vat'    => $withVat,
            ],
            'vat_breakdown'      => [],
        ];
    }
}

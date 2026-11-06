<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Ceníková cena a sleva u položky faktury (migrace 2035).
 *
 * Řádek nese `list_unit_price` (cena před slevou ve stejném základu jako
 * `unit_price_without_vat`) a server z ní dopočítá `line_discount_pct`. Je to
 * informační údaj: částky dokladu, exporty a výchozí PDF se nemění. Rozpis slevy
 * na PDF zapíná volba firmy `invoice_pdf_show_line_discount`.
 */
#[Group('integration')]
final class InvoiceLineDiscountTest extends StockTestCase
{
    // ── uložení a dopočet ───────────────────────────────────────────────────

    public function testServerComputesDiscountAndIgnoresClientPercent(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [
            $this->line('Sleva', 2, 900, 1000) + ['line_discount_pct' => '50'],
            $this->line('Ceníková', 1, 1000, 1000),
            $this->line('Bez ceníku', 1, 500, null),
            $this->line('Přirážka', 1, 1050, 1000),
        ]);

        $items = $this->storedItems($id);
        self::assertSame(
            [[1000.0, 10.0], [1000.0, null], [null, null], [1000.0, -5.0]],
            array_map(static fn (array $i): array => [$i['list_unit_price'], $i['line_discount_pct']], $items),
        );
    }

    public function testDocumentAmountsDoNotDependOnListPrice(): void
    {
        $sid = $this->createSupplier();
        $lines = [$this->line('A', 3, 812.5, 1000), $this->line('B', 1, 1050, 1000)];
        $with = $this->createInvoice($sid, $lines, discountPercent: 10);
        $without = $this->createInvoice($sid, array_map(static function (array $l): array {
            unset($l['list_unit_price']);
            return $l;
        }, $lines), discountPercent: 10);

        $a = $this->repo()->find($with);
        $b = $this->repo()->find($without);
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $field) {
            self::assertSame($b[$field], $a[$field], $field);
        }
        $strip = static fn (array $items): array => array_map(
            static fn (array $i): array => [$i['item_kind'], $i['unit_price_without_vat'], $i['total_without_vat'], $i['total_vat'], $i['total_with_vat']],
            $items,
        );
        self::assertSame($strip($b['items']), $strip($a['items']));
        $discountRow = array_values(array_filter($a['items'], static fn (array $i): bool => $i['item_kind'] === 'discount'))[0];
        self::assertSame([null, null], [$discountRow['list_unit_price'], $discountRow['line_discount_pct']], 'Hlavičková sleva je samostatný řádek, sleva z ceníku se do něj nepromítá.');
    }

    public function testManualPriceChangeKeepsListPriceAndRecomputesDiscount(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [$this->line('Kolo', 1, 900, 1000)]);

        $items = $this->storedItems($id);
        $items[0]['unit_price_without_vat'] = 800;
        $res = $this->updateInvoice($sid, $id, ['items' => $items]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([1000.0, 20.0], $this->listAndPct($id));

        $res = $this->updateInvoice($sid, $id, ['discount_percent' => 5]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([1000.0, 20.0], $this->listAndPct($id), 'Přehrání uložených řádků při změně hlavičkové slevy ceník zachová.');

        $items = $this->storedItems($id);
        unset($items[0]['list_unit_price']);
        $this->updateInvoice($sid, $id, ['items' => $items]);
        self::assertSame([null, null], $this->listAndPct($id), 'Řádek bez ceníkové ceny slevu nemá.');
    }

    // ── kopie dokladu ───────────────────────────────────────────────────────

    public function testCreditNoteCloneAndFinalInvoiceCarryListPrice(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $invoiceId = $this->createInvoice($sid, [$this->line('Helma', 1, 900, 1000)]);
        $this->markIssued($invoiceId);

        $request = $this->request('POST', $sid, ['mode' => 'credit_note', 'reason' => 'Vrácení']);
        $res = $this->container->get(CancelInvoiceAction::class)($request, new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([1000.0, 10.0], $this->listAndPct((int) $this->body($res)['credit_note_id']), 'Dobropis');

        $cloneId = $this->container->get(BulkReissueAction::class)->cloneOne($invoiceId, '2099-06-20', false, $this->userId);
        self::assertSame([1000.0, 10.0], $this->listAndPct($cloneId), 'Kopie faktury');

        $proformaId = $this->createInvoice($sid, [$this->line('Helma', 1, 900, 1000)], 'proforma');
        $this->markIssued($proformaId);
        $finalId = $this->container->get(FinalFromProformaCreator::class)->create($proformaId, $this->userId);
        self::assertSame([1000.0, 10.0], $this->listAndPct($finalId), 'Konečná faktura ze zálohy');
    }

    // ── PDF ─────────────────────────────────────────────────────────────────

    public function testPdfIsUnchangedWhileOptionIsOff(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [$this->line('Kolo', 2, 900, 1000), $this->line('Brzdy', 1, 1050, 1000)]);
        $withList = $this->pdfHtml($id);

        $this->db->pdo()->prepare('UPDATE invoice_items SET list_unit_price = NULL, line_discount_pct = NULL WHERE invoice_id = ?')->execute([$id]);
        self::assertSame($this->pdfHtml($id), $withList, 'Bez volby se PDF nemění.');
        self::assertStringNotContainsString('před slevou', $withList);
    }

    public function testPdfShowsDiscountColumnsForVatPayer(): void
    {
        $sid = $this->createSupplier();
        $this->enableOption($sid);
        $id = $this->createInvoice($sid, [
            $this->line('Kolo', 2, 900, 1000),
            $this->line('Brzdy', 1, 1050, 1000),
            $this->line('Montáž', 1, 500, null),
        ]);

        $text = $this->pdfText($id);
        self::assertStringContainsString('Cena/j před slevou', $text);
        self::assertStringContainsString('Cena/j po slevě', $text);
        self::assertMatchesRegularExpression('/Kolo\s+2\s+ks\s+1 000,00\s+10 %\s+900,00\s+21 %\s+1 800,00\s+2 178,00/u', $text);
        self::assertMatchesRegularExpression('/Brzdy\s+1\s+ks\s+1 050,00\s+21 %/u', $text, 'Přirážka se nerozepisuje, jen výsledná cena.');
        self::assertMatchesRegularExpression('/Montáž\s+1\s+ks\s+500,00\s+21 %/u', $text);
    }

    public function testPdfWithoutAnyDiscountKeepsOriginalLayoutEvenWithOption(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [$this->line('Brzdy', 1, 1050, 1000), $this->line('Kolo', 1, 1000, 1000)]);
        $off = $this->pdfHtml($id);
        $this->enableOption($sid);
        self::assertSame($off, $this->pdfHtml($id));
    }

    public function testPdfShowsDiscountColumnsForNonPayer(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')->execute([$sid]);
        $this->enableOption($sid);
        $id = $this->createInvoice($sid, [$this->line('Kolo', 2, 875, 1000)]);

        $text = $this->pdfText($id);
        self::assertStringContainsString('Cena/j před slevou', $text);
        self::assertMatchesRegularExpression('/Kolo\s+2\s+ks\s+1 000,00\s+12,5 %\s+875,00\s+1 750,00/u', $text);
    }

    public function testPdfListPriceIsNetInPricesIncludingVatMode(): void
    {
        $sid = $this->createSupplier();
        $this->enableOption($sid);
        // Režim „ceny s DPH": řádek i ceník jsou brutto (1 210 = 1 000 + 21 %), PDF tiskne netto.
        $id = $this->createInvoice($sid, [$this->line('Kolo', 1, 1089, 1210)], pricesIncludeVat: true);
        self::assertSame([1210.0, 10.0], $this->listAndPct($id));

        self::assertMatchesRegularExpression('/Kolo\s+1\s+ks\s+1 000,00\s+10 %\s+900,00\s+21 %\s+900,00\s+1 089,00/u', $this->pdfText($id));
    }

    public function testPdfInEnglish(): void
    {
        $sid = $this->createSupplier();
        $this->enableOption($sid);
        $id = $this->createInvoice($sid, [$this->line('Bike', 1, 875.5, 1000)]);
        $this->db->pdo()->prepare("UPDATE invoices SET language = 'en' WHERE id = ?")->execute([$id]);

        $text = $this->pdfText($id);
        self::assertStringContainsString('Unit price before discount', $text);
        self::assertMatchesRegularExpression('/Bike\s+1\s+ks\s+1,000\.00\s+12\.45 %\s+875\.50/u', $text);
    }

    public function testManualLineWithDiscountFromEditorIsSavedAndPrinted(): void
    {
        $sid = $this->createSupplier();
        $this->enableOption($sid);
        // Ruční řádek bez karty: sleva zadaná v editoru pošle ceníkovou cenu i cenu po slevě.
        $id = $this->createInvoice($sid, [
            $this->line('Servis', 1, 874.13, 999),
            $this->line('Dárek', 1, 0, 250),
        ]);

        // Cena po slevě se zaokrouhlí na haléře, uložené procento z ní vyjde 12,499; PDF tiskne dvě místa.
        $items = $this->storedItems($id);
        self::assertSame([[999.0, 12.499], [250.0, 100.0]], array_map(
            static fn (array $i): array => [$i['list_unit_price'], $i['line_discount_pct']],
            $items,
        ));
        $text = $this->pdfText($id);
        self::assertMatchesRegularExpression('/Servis\s+1\s+ks\s+999,00\s+12,5 %\s+874,13\s+21 %/u', $text);
        self::assertMatchesRegularExpression('/Dárek\s+1\s+ks\s+250,00\s+100 %\s+0,00\s+21 %/u', $text);
    }

    // ── exporty a nastavení ─────────────────────────────────────────────────

    public function testIsdocAndPohodaExportsIgnoreListPrice(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [$this->line('Kolo', 2, 900, 1000), $this->line('Brzdy', 1, 1050, 1000)]);
        $this->markIssued($id);
        $isdoc = $this->container->get(IsdocExporter::class)->export([$id])['content'];
        // Hlavička dávky POHODA nese čas exportu, ten se z porovnání vynechá.
        $pohodaXml = fn (): string => (string) preg_replace(
            ['/id="myinvoice-\d+"/', '/note="Export [^"]*"/'],
            '',
            $this->container->get(PohodaXmlExporter::class)->export([$id], $sid)['content'],
        );
        $pohoda = $pohodaXml();

        $this->db->pdo()->prepare('UPDATE invoice_items SET list_unit_price = NULL, line_discount_pct = NULL WHERE invoice_id = ?')->execute([$id]);
        self::assertSame($this->container->get(IsdocExporter::class)->export([$id])['content'], $isdoc);
        self::assertSame($pohodaXml(), $pohoda);
    }

    public function testSettingIsOffByDefaultAndCanBeEnabled(): void
    {
        $sid = $this->createSupplier();
        $settings = $this->container->get(SettingsAction::class);

        $res = $settings->getSupplier($this->request('GET', $sid, null), new Psr7Response());
        self::assertFalse($this->body($res)['invoice_pdf_show_line_discount']);

        $res = $settings->updateSupplier($this->request('PUT', $sid, ['invoice_pdf_show_line_discount' => true]), new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $res = $settings->getSupplier($this->request('GET', $sid, null), new Psr7Response());
        self::assertTrue($this->body($res)['invoice_pdf_show_line_discount']);
    }

    public function testEditorReadsOptionFromSession(): void
    {
        $sid = $this->createSupplier();
        $read = function () use ($sid): bool {
            $request = $this->request('GET', $sid, null)->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true,
            ]);
            $res = $this->container->get(MeAction::class)($request, new Psr7Response());
            $supplier = array_values(array_filter(
                (array) ($this->body($res)['suppliers'] ?? []),
                static fn (array $s): bool => $s['id'] === $sid,
            ))[0];
            return $supplier['invoice_pdf_show_line_discount'];
        };

        self::assertFalse($read());
        $res = $this->container->get(SettingsAction::class)->updateSupplier(
            $this->request('PUT', $sid, ['invoice_pdf_show_line_discount' => true]),
            new Psr7Response(),
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertTrue($read(), 'Editor faktury čte volbu z /auth/me, pole Sleva % ukáže jen s ní.');
    }

    public function testChangingPdfLineDiscountOptionInvalidatesCachedDraft(): void
    {
        $sid = $this->createSupplier();
        $id = $this->createInvoice($sid, [$this->line('Kolo', 1, 900, 1000)]);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET pdf_path = 'phpunit-line-discount-draft.pdf', pdf_generated_at = '2096-05-16 08:00:00' WHERE id = ?"
        )->execute([$id]);

        $res = $this->container->get(SettingsAction::class)->updateSupplier(
            $this->request('PUT', $sid, ['invoice_pdf_show_line_discount' => true]),
            new Psr7Response(),
        );

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $stmt = $this->db->pdo()->prepare('SELECT pdf_path, pdf_generated_at FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($row['pdf_path'], 'Přepnutí rozpisu slev ponechalo draft PDF cache platnou.');
        self::assertNull($row['pdf_generated_at']);
    }
    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function line(string $description, float|int $qty, float|int $unitPrice, float|int|null $listPrice): array
    {
        $line = [
            'description' => $description,
            'quantity' => $qty,
            'unit' => 'ks',
            'unit_price_without_vat' => $unitPrice,
            'vat_rate_id' => $this->vatRateId,
        ];
        if ($listPrice !== null) {
            $line['list_unit_price'] = $listPrice;
        }
        return $line;
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoice(int $supplierId, array $items, string $type = 'invoice', float $discountPercent = 0, bool $pricesIncludeVat = false): int
    {
        $body = [
            'client_id' => $this->client($supplierId),
            'invoice_type' => $type,
            'issue_date' => '2099-06-10',
            'tax_date' => '2099-06-10',
            'due_date' => '2099-06-24',
            'currency_id' => $this->currencyIdFor($supplierId),
            'discount_percent' => $discountPercent,
            'prices_include_vat' => $pricesIncludeVat,
            'items' => $items,
        ];
        $res = $this->container->get(CreateInvoiceAction::class)($this->request('POST', $supplierId, $body), new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return (int) $this->body($res)['id'];
    }

    /** @param array<string,mixed> $body */
    private function updateInvoice(int $supplierId, int $invoiceId, array $body): ResponseInterface
    {
        return $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $supplierId, $body),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );
    }

    private function repo(): InvoiceRepository
    {
        return $this->container->get(InvoiceRepository::class);
    }

    /** @return list<array<string,mixed>> */
    private function storedItems(int $invoiceId): array
    {
        return array_values(array_filter(
            (array) ($this->repo()->find($invoiceId)['items'] ?? []),
            static fn (array $item): bool => ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }

    /** @return array{0:float|null,1:float|null} */
    private function listAndPct(int $invoiceId): array
    {
        $item = $this->storedItems($invoiceId)[0];
        return [$item['list_unit_price'], $item['line_discount_pct']];
    }

    private function markIssued(int $invoiceId): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")
            ->execute(['FV' . random_int(10000000, 99999999), $invoiceId]);
    }

    private function enableOption(int $supplierId): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET invoice_pdf_show_line_discount = 1 WHERE id = ?')->execute([$supplierId]);
    }

    private function pdfHtml(int $invoiceId): string
    {
        return $this->container->get(InvoicePdfRenderer::class)->renderHtml(
            $this->repo()->find($invoiceId),
            includeCss: false,
            includeWorkReport: false,
        );
    }

    /** Text PDF bez značek, bílé znaky (i NBSP mezi tisíci) jako jedna mezera. */
    private function pdfText(int $invoiceId): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $this->pdfHtml($invoiceId))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, int $supplierId, ?array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        return (array) json_decode((string) $res->getBody(), true);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Náhrada položek přijaté faktury nesmí tiše smazat kód položky dodavatele, EAN,
 * Intrastat ani vazbu na skladovou kartu, když je klient API neposlal
 * ({@see \MyInvoice\Service\PurchaseInvoice\StoredPurchaseItemColumns}).
 *
 * BEZ OPRAVY PADÁ: `replaceItems()` je DELETE + INSERT a vynechaný klíč se uložil jako NULL.
 */
#[Group('integration')]
final class PurchaseItemColumnsCarryOverTest extends StockTestCase
{
    private const STORED = [
        'seller_item_code'            => 'DOD-ABC-1',
        'item_ean'                    => '4006381333931',
        'intrastat_cn8_code'          => '84713000',
        'intrastat_country_of_origin' => 'DE',
    ];

    private int $sid = 0;
    private int $vendorId = 0;
    private int $cardId = 0;
    private UpdatePurchaseInvoiceAction $update;
    private SetPurchaseInvoiceItemsAction $setItems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $this->vendorId = $this->client($this->sid, 'Dodavatel přenos sloupců');
        $this->cardId = $this->item($this->sid, 'PRENOS-1');
        $this->update = $this->container->get(UpdatePurchaseInvoiceAction::class);
        $this->setItems = $this->container->get(SetPurchaseInvoiceItemsAction::class);
    }

    /** @return iterable<string,array{string}> */
    public static function endpoints(): iterable
    {
        yield 'PUT dokladu' => ['invoice'];
        yield 'PUT položek' => ['items'];
    }

    #[DataProvider('endpoints')]
    public function testOmittedKeysKeepStoredValues(string $endpoint): void
    {
        $piId = $this->draftWithCodes();

        $this->save($endpoint, $piId, [$this->line('Šroub M8', 2), $this->line('Matice M8', 5)]);

        $rows = $this->rows($piId);
        self::assertSame(['Šroub M8', 'Matice M8'], array_column($rows, 'description'));
        self::assertSame('2.000', number_format((float) $rows[0]['quantity'], 3, '.', ''), 'Položka se opravdu uložila znovu.');
        self::assertSame(self::STORED + ['stock_item_id' => $this->cardId], self::codes($rows[0]),
            'Vynechané klíče převezmou hodnotu uloženého řádku.');
        self::assertSame(['seller_item_code' => 'DOD-XYZ-2', 'item_ean' => null, 'intrastat_cn8_code' => null,
            'intrastat_country_of_origin' => null, 'stock_item_id' => null], self::codes($rows[1]));
    }

    #[DataProvider('endpoints')]
    public function testExplicitNullClears(string $endpoint): void
    {
        $piId = $this->draftWithCodes();

        $line = $this->line('Šroub M8', 1) + array_fill_keys([...array_keys(self::STORED), 'stock_item_id'], null);
        $this->save($endpoint, $piId, [$line, $this->line('Matice M8', 5)]);

        self::assertSame(array_fill_keys([...array_keys(self::STORED), 'stock_item_id'], null), self::codes($this->rows($piId)[0]),
            'Explicitní null je pokyn smazat.');
    }

    public function testMatchesByIdAfterReorder(): void
    {
        $piId = $this->draftWithCodes();
        [$first, $second] = $this->rows($piId);

        $this->save('invoice', $piId, [
            ['id' => (int) $second['id']] + $this->line('Matice M8', 5),
            ['id' => (int) $first['id']] + $this->line('Šroub M8 nerez', 1),
        ]);

        $rows = $this->rows($piId);
        self::assertSame('DOD-XYZ-2', $rows[0]['seller_item_code']);
        self::assertSame(self::STORED + ['stock_item_id' => $this->cardId], self::codes($rows[1]),
            'Podle id se páruje i po přeřazení a změně popisu.');
    }

    public function testPositionWithOtherDescriptionDoesNotInherit(): void
    {
        $piId = $this->draftWithCodes();

        // První řádek smazán, na jeho místo se posune druhý: kód prvního nesmí přeskočit.
        $this->save('invoice', $piId, [$this->line('Matice M8', 5)]);

        $rows = $this->rows($piId);
        self::assertCount(1, $rows);
        self::assertSame(array_fill_keys([...array_keys(self::STORED), 'stock_item_id'], null), self::codes($rows[0]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function draftWithCodes(): int
    {
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId, ['status' => 'draft']);
        $this->save('items', $piId, [
            $this->line('Šroub M8', 1) + self::STORED + ['stock_item_id' => $this->cardId],
            $this->line('Matice M8', 5) + ['seller_item_code' => 'DOD-XYZ-2'],
        ]);
        $rows = $this->rows($piId);
        self::assertSame(self::STORED + ['stock_item_id' => $this->cardId], self::codes($rows[0]),
            'Výchozí stav musí kódy nést, jinak test netestuje nic.');

        return $piId;
    }

    /** @return array<string,mixed> */
    private function line(string $description, int $qty): array
    {
        return [
            'description'            => $description,
            'quantity'               => $qty,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 100,
            'vat_rate_id'            => $this->vatRateId,
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function save(string $endpoint, int $piId, array $items): void
    {
        $action = $endpoint === 'items' ? $this->setItems : $this->update;
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $piId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['items' => $items]);
        $response = $action($request, new Psr7Response(), ['id' => (string) $piId]);
        $response->getBody()->rewind();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $piId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, description, quantity, stock_item_id, seller_item_code, item_ean,
                    intrastat_cn8_code, intrastat_country_of_origin
               FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$piId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function codes(array $row): array
    {
        $out = [];
        foreach ([...array_keys(self::STORED), 'stock_item_id'] as $col) {
            $out[$col] = $col === 'stock_item_id' && $row[$col] !== null ? (int) $row[$col] : $row[$col];
        }

        return $out;
    }
}

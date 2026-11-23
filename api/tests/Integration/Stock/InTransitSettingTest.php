<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InTransitRepository;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * `supplier.stock_in_transit_from` (migrace 1331, rozhodnutí #2) — od kterého
 * stavu objednávky se zboží počítá „na cestě".
 *
 * Sloupec existoval v DB a četl ho `InTransitRepository::inTransitStates()`,
 * ale nešel nastavit jinak než ručním UPDATE v databázi. Tenhle test drží obě
 * strany pohromadě: co uloží nastavení, tím se doopravdy počítá — a naopak
 * `respondSupplier()` musí vracet TÝŽ default (`sent`) jako fallback
 * v repozitáři, jinak by obrazovka tvrdila něco jiného, než podle čeho se
 * počítá.
 */
#[Group('integration')]
final class InTransitSettingTest extends StockTestCase
{
    private SettingsAction $settings;
    private InTransitRepository $inTransit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings  = $this->container->get(SettingsAction::class);
        $this->inTransit = $this->container->get(InTransitRepository::class);
    }

    public function testDefaultIsSentAndMatchesRepositoryFallback(): void
    {
        $sid = $this->createSupplier();

        $get = $this->get($sid);
        self::assertSame(200, $get['status']);
        self::assertSame('sent', $get['body']['stock_in_transit_from'],
            'Nastavení musí vracet týž default jako InTransitRepository::inTransitStates().');
        self::assertSame(['sent', 'confirmed', 'partially_received'], $this->inTransit->inTransitStates($sid));
    }

    public function testSwitchingToConfirmedChangesWhatCountsAsInTransit(): void
    {
        $sid = $this->createSupplier();

        $put = $this->put($sid, ['stock_in_transit_from' => 'confirmed'], 'admin');
        self::assertSame(200, $put['status']);
        self::assertSame('confirmed', $put['body']['stock_in_transit_from']);

        self::assertSame('confirmed', $this->get($sid)['body']['stock_in_transit_from']);
        self::assertSame(['confirmed', 'partially_received'], $this->inTransit->inTransitStates($sid),
            'Uložená hodnota musí okamžitě řídit výpočet „na cestě" — jinak je přepínač jen ozdoba.');

        // A zpátky, ať je jistota, že to není jednosměrka.
        self::assertSame(200, $this->put($sid, ['stock_in_transit_from' => 'sent'], 'admin')['status']);
        self::assertSame(['sent', 'confirmed', 'partially_received'], $this->inTransit->inTransitStates($sid));
    }

    public function testInvalidValueIsRejectedWithValidationErrorNotFatal(): void
    {
        $sid = $this->createSupplier();

        // Cizí hodnota by v strict mode shodila UPDATE na PDOException → 500.
        $put = $this->put($sid, ['stock_in_transit_from' => 'kdyzsemitozachce'], 'admin');
        self::assertSame(400, $put['status']);
        self::assertSame('validation_failed', $put['body']['error']['code']);
        self::assertSame('sent', $this->get($sid)['body']['stock_in_transit_from'], 'Odmítnutá hodnota se nesmí uložit.');
    }

    public function testAccountantMayChangeItLikeTheOtherStockSettings(): void
    {
        $sid = $this->createSupplier();
        // Účetní pracuje jen v přiřazené firmě, stejně jako za SupplierScopeMiddleware.
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$this->userId, $sid]);

        // Stejný cílený bypass guard() jako u stock_enabled/stock_auto_issue:
        // je to skladové, ne firemní nastavení.
        $put = $this->put($sid, ['stock_in_transit_from' => 'confirmed'], 'accountant');
        self::assertSame(200, $put['status']);
        self::assertSame('confirmed', $this->get($sid)['body']['stock_in_transit_from']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{status:int, body:array<string,mixed>} */
    private function get(int $supplierId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        return self::decode($this->settings->getSupplier($req, new Psr7Response()));
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $supplierId, array $body, string $role): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);

        return self::decode($this->settings->updateSupplier($req, new Psr7Response()));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use DI\Container;
use MyInvoice\Action\PurchaseInvoice\Approval\PublicPurchaseApprovalAction;
use MyInvoice\Action\PurchaseInvoice\Approval\PurchaseInvoiceApprovalAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseApprovalNotifier;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Schvalování přijatých dokladů manažerem střediska (F6).
 *
 * Jádro: brána přechodu koncept → přijato (vyžaduje / nevyžaduje, limit, bez
 * konfigurace beze změny), víc středisek = víc schvalovatelů, schválení všemi
 * přijme doklad, zamítnutí ho nechá konceptem, nové kolo po změně, tokenový
 * odkaz z e-mailu (neplatný, prošlý, jednorázový) a izolace schvalovatele.
 *
 * Vše v transakci, kterou tearDown vrací — data jsou syntetická a v DB nezůstanou.
 * E-maily se neposílají: notifikátor nahrazuje záznamník, ze kterého test bere token.
 */
#[Group('integration')]
final class PurchaseInvoiceApprovalTest extends TestCase
{
    private Container $c;
    private Connection $db;
    private object $notifier;

    private int $supplierId = 0;
    private int $accountantId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $vendorId = 0;
    private int $typeId = 0;
    private int $approverA = 0;
    private int $approverB = 0;
    /** @var array<string,int> */
    private array $values = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->c = Bootstrap::buildContainer();
            $this->db = $this->c->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->notifier = new class implements PurchaseApprovalNotifier {
            /** @var list<array{approval_id:int, token:string, reminder:bool}> */
            public array $sent = [];
            public function send(array $row, string $token, bool $isReminder): void
            {
                $this->sent[] = ['approval_id' => (int) $row['id'], 'token' => $token, 'reminder' => $isReminder];
            }
        };
        $this->c->set(PurchaseApprovalNotifier::class, $this->notifier);

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->accountantId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $readonlyRole = (int) ($pdo->query("SELECT id FROM roles WHERE system_key = 'readonly' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->accountantId, $this->vatRateId, $this->currencyId, $czId, $readonlyRole], true)) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Schvalování Test Dodavatel", "Testovací 1", "Praha", "11000", ?, "10000003",
                     "f6-vendor@example.invalid", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        foreach (['approverA', 'approverB'] as $prop) {
            $pdo->prepare(
                "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
                 VALUES (?, 'disabled-test-password', ?, ?, 'cs', 1)"
            )->execute(['f6-' . $prop . '-' . bin2hex(random_bytes(4)) . '@example.invalid', 'Manažer ' . $prop, $readonlyRole]);
            $this->{$prop} = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO dimension_types (supplier_id, code, name, kind, requires_approval, approval_threshold)
             VALUES (?, ?, 'Středisko F6', 'cost_center', 1, NULL)"
        )->execute([$this->supplierId, 'f6t' . bin2hex(random_bytes(3))]);
        $this->typeId = (int) $pdo->lastInsertId();
        foreach (['A' => $this->approverA, 'B' => $this->approverB, 'C' => null] as $code => $approver) {
            $pdo->prepare(
                'INSERT INTO dimension_values (type_id, supplier_id, code, name, responsible_user_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$this->typeId, $this->supplierId, 'F6-' . $code, 'Středisko ' . $code, $approver]);
            $this->values[$code] = (int) $pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    // ── brána přechodu ───────────────────────────────────────────────────────

    public function testWithoutApprovalTypeReceiveIsUnchanged(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_types SET requires_approval = 0 WHERE id = ?')->execute([$this->typeId]);
        $id = $this->createInvoice([1000.0], header: 'A');

        [$status, $body] = $this->transition($id);

        self::assertSame(200, $status);
        self::assertArrayNotHasKey('approval_requested', $body);
        self::assertSame('received', $this->invoiceStatus($id));
        self::assertSame('none', $this->approvalStatus($id));
        self::assertSame(0, $this->approvalRows($id));
        self::assertSame([], $this->notifier->sent);
    }

    public function testBelowThresholdIsReceivedWithoutApproval(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_types SET approval_threshold = 5000 WHERE id = ?')->execute([$this->typeId]);
        $id = $this->createInvoice([1000.0], header: 'A');

        [$status] = $this->transition($id);

        self::assertSame(200, $status);
        self::assertSame('received', $this->invoiceStatus($id));
        self::assertSame(0, $this->approvalRows($id));
    }

    public function testRequiredApprovalKeepsDraftAndApprovalReceives(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_types SET approval_threshold = 5000 WHERE id = ?')->execute([$this->typeId]);
        $id = $this->createInvoice([6000.0], header: 'A');

        [$status, $body] = $this->transition($id);

        self::assertSame(200, $status);
        self::assertTrue($body['approval_requested'] ?? false, 'Přechod musí ohlásit odeslání ke schválení.');
        self::assertSame('draft', $body['status']);
        self::assertSame('pending', $body['approval_status']);
        self::assertCount(1, $body['approvals']);
        self::assertEquals(6000.0, $body['approvals'][0]['amount_czk']);
        self::assertSame($this->approverA, $body['approvals'][0]['approver']['id']);
        self::assertSame('draft', $this->invoiceStatus($id));
        self::assertCount(1, $this->notifier->sent);

        [$status, $decided] = $this->decide($this->approverA, $body['approvals'][0]['id'], 'approve');
        self::assertSame(200, $status, json_encode($decided, JSON_UNESCAPED_UNICODE));
        self::assertSame('approved', $decided['status']);
        self::assertSame('approved', $decided['invoice_approval_status']);
        self::assertSame('received', $this->invoiceStatus($id), 'Schválený doklad se musí přijmout.');
        self::assertNotEmpty($this->varsymbol($id), 'Přijetí po schválení přiděluje interní číslo jako ruční přechod.');
        self::assertGreaterThan(0, $this->auditCount($id, 'purchase_invoice.approval_requested'));
        self::assertGreaterThan(0, $this->auditCount($id, 'purchase_invoice.approval_approved'));
    }

    public function testFullyPaidDraftStaysPendingUntilApprovalThenBecomesPaid(): void
    {
        $id = $this->createInvoice([6000.0], header: 'A');
        $pdo = $this->db->pdo();
        $q = $pdo->prepare('SELECT total_with_vat FROM purchase_invoices WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplierId, $id]);
        $amount = (float) $q->fetchColumn();
        $writer = new \MyInvoice\Service\Migration\Shared\BankStatementImportWriter($this->db, 'synthetic-approval');
        $statement = $writer->createStatement($this->supplierId, 'draft-paid', 'SYN-APPROVAL',
            '1000000005', '0100', 'CZK', '2098-03-15', $this->accountantId);
        $transaction = $writer->insertTransaction($this->supplierId, $statement, 'draft-paid', [
            'source_ref' => 'synthetic-approval', 'posted_at' => '2098-03-15',
            'amount' => number_format(-$amount, 2, '.', ''), 'currency' => 'CZK', 'bank_ref' => null,
        ]);
        $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type)
            VALUES (?,?,?,?,'manual')")->execute([$this->supplierId, $transaction, $id, $amount]);

        [$status, $body] = $this->transition($id);
        self::assertSame(200, $status);
        self::assertSame('draft', $this->invoiceStatus($id));
        self::assertTrue($body['approval_requested']);
        [$status, $decided] = $this->decide($this->approverA, $body['approvals'][0]['id'], 'approve');
        self::assertSame(200, $status, json_encode($decided));
        self::assertSame('paid', $this->invoiceStatus($id));
        $q = $pdo->prepare('SELECT paid_at FROM purchase_invoices WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplierId, $id]);
        self::assertSame('2098-03-15', $q->fetchColumn());
    }

    public function testItemCentersNeedEveryApprover(): void
    {
        $id = $this->createInvoice([1000.0, 2500.0], items: [1 => 'A', 2 => 'B']);

        [, $body] = $this->transition($id);
        self::assertCount(2, $body['approvals']);
        $rows = [];
        foreach ($body['approvals'] as $r) {
            $rows[$r['approver']['id']] = $r;
        }
        self::assertEquals(1000.0, $rows[$this->approverA]['amount_czk']);
        self::assertEquals(2500.0, $rows[$this->approverB]['amount_czk']);

        $this->decide($this->approverA, $rows[$this->approverA]['id'], 'approve');
        self::assertSame('draft', $this->invoiceStatus($id), 'Po prvním schválení ze dvou zůstává koncept.');
        self::assertSame('pending', $this->approvalStatus($id));

        $this->decide($this->approverB, $rows[$this->approverB]['id'], 'approve');
        self::assertSame('received', $this->invoiceStatus($id));
        self::assertSame('approved', $this->approvalStatus($id));
    }

    public function testRejectKeepsDraftAndResubmitStartsNewRound(): void
    {
        $id = $this->createInvoice([1000.0, 2500.0], items: [1 => 'A', 2 => 'B']);
        [, $body] = $this->transition($id);
        $rows = [];
        foreach ($body['approvals'] as $r) {
            $rows[$r['approver']['id']] = $r;
        }

        [$status, $err] = $this->decide($this->approverA, $rows[$this->approverA]['id'], 'reject');
        self::assertSame(422, $status, 'Zamítnutí bez důvodu musí padnout.');
        self::assertSame('reason_required', $err['error']['code'] ?? null);

        [$status] = $this->decide($this->approverA, $rows[$this->approverA]['id'], 'reject', 'Není náš náklad');
        self::assertSame(200, $status);
        self::assertSame('draft', $this->invoiceStatus($id));
        self::assertSame('rejected', $this->approvalStatus($id));
        self::assertSame('cancelled', $this->rowStatus($rows[$this->approverB]['id']),
            'Ostatní čekající schválení zamítnutého dokladu se ruší.');

        [$status, $again] = $this->transition($id);
        self::assertSame(200, $status);
        self::assertTrue($again['approval_requested'] ?? false);
        self::assertSame('pending', $this->approvalStatus($id));
        foreach ($again['approvals'] as $r) {
            self::assertSame(2, $r['round'], 'Opětovné odeslání založí nové kolo.');
            self::assertSame('pending', $r['status']);
        }
    }

    public function testAmountChangeAfterApprovalStartsNewRoundOnlyForAffectedCenter(): void
    {
        $id = $this->createInvoice([1000.0, 2500.0], items: [1 => 'A', 2 => 'B']);
        [, $body] = $this->transition($id);
        $rows = [];
        foreach ($body['approvals'] as $r) {
            $rows[$r['approver']['id']] = $r;
        }
        $this->decide($this->approverA, $rows[$this->approverA]['id'], 'approve');

        // Po schválení A se změní částka položky střediska A.
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoice_items SET total_without_vat = 1800 WHERE purchase_invoice_id = ? AND order_index = (
                SELECT m FROM (SELECT MIN(order_index) AS m FROM purchase_invoice_items WHERE purchase_invoice_id = ?) x)'
        )->execute([$id, $id]);

        $this->decide($this->approverB, $rows[$this->approverB]['id'], 'approve');

        self::assertSame('draft', $this->invoiceStatus($id), 'Změněná částka nesmí projít se starým schválením.');
        self::assertSame('pending', $this->approvalStatus($id));
        $pending = $this->db->pdo()->prepare(
            "SELECT approver_user_id, amount_czk, round FROM purchase_invoice_approvals
              WHERE purchase_invoice_id = ? AND status = 'pending'"
        );
        $pending->execute([$id]);
        $left = $pending->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $left, 'Nové kolo jen pro dotčené středisko.');
        self::assertSame($this->approverA, (int) $left[0]['approver_user_id']);
        self::assertSame(1800.0, (float) $left[0]['amount_czk']);
        self::assertSame(2, (int) $left[0]['round']);
        self::assertSame('approved', $this->rowStatus($rows[$this->approverB]['id']));
    }

    /** Středisko bez schvalovatele se neschvaluje: doklad se přijme hned, bez kola schvalování. */
    public function testCenterWithoutApproverIsReceivedWithoutApproval(): void
    {
        $id = $this->createInvoice([1000.0], header: 'C');

        [$status] = $this->transition($id);

        self::assertSame(200, $status);
        self::assertSame('received', $this->invoiceStatus($id));
        self::assertSame('none', $this->approvalStatus($id));
        self::assertSame(0, $this->approvalRows($id));
    }

    /** Smíšený doklad: schvaluje jen středisko se schvalovatelem, středisko bez něj se přeskočí. */
    public function testMixedCentersOnlyApproverCenterIsAsked(): void
    {
        $id = $this->createInvoice([1000.0, 500.0], items: [1 => 'A', 2 => 'C']);

        [$status] = $this->transition($id);

        self::assertSame(200, $status);
        self::assertSame('draft', $this->invoiceStatus($id));
        self::assertSame('pending', $this->approvalStatus($id));
        self::assertSame(1, $this->approvalRows($id));
    }

    // ── odkaz z e-mailu ──────────────────────────────────────────────────────

    public function testPublicTokenLifecycle(): void
    {
        $id = $this->createInvoice([1000.0], header: 'A');
        $this->transition($id);
        $token = $this->notifier->sent[0]['token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $hash = $this->db->pdo()->query('SELECT token_hash FROM purchase_invoice_approvals WHERE id = '
            . $this->notifier->sent[0]['approval_id'])->fetchColumn();
        self::assertSame(hash('sha256', $token), $hash, 'V DB je jen SHA-256 tokenu.');

        [$status] = $this->publicCall('get', str_repeat('a', 64));
        self::assertSame(404, $status, 'Neznámý token = 404.');

        [$status, $view] = $this->publicCall('get', $token);
        self::assertSame(200, $status);
        self::assertSame('pending', $view['status']);
        self::assertFalse($view['expired']);
        self::assertEquals(1000.0, $view['invoice']['total_without_vat']);
        self::assertCount(1, $view['invoice']['items']);

        [$status, $decided] = $this->publicCall('decide', $token, ['decision' => 'approve']);
        self::assertSame(200, $status, json_encode($decided, JSON_UNESCAPED_UNICODE));
        self::assertSame('approved', $decided['status']);
        self::assertSame('received', $this->invoiceStatus($id));
        self::assertSame('email', $this->db->pdo()->query('SELECT decided_via FROM purchase_invoice_approvals WHERE id = '
            . $this->notifier->sent[0]['approval_id'])->fetchColumn());

        [$status, $second] = $this->publicCall('decide', $token, ['decision' => 'reject', 'comment' => 'pozdě']);
        self::assertSame(409, $status, 'Odkaz je jednorázový.');
        self::assertSame('approval_already_decided', $second['error']['code'] ?? null);

        [$status, $after] = $this->publicCall('get', $token);
        self::assertSame(200, $status, 'Po rozhodnutí odkaz ukazuje výsledek.');
        self::assertSame('approved', $after['status']);
    }

    public function testExpiredTokenCannotDecide(): void
    {
        $id = $this->createInvoice([1000.0], header: 'A');
        $this->transition($id);
        $sent = $this->notifier->sent[0];
        $this->db->pdo()->prepare('UPDATE purchase_invoice_approvals SET token_expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?')
            ->execute([$sent['approval_id']]);

        [$status, $view] = $this->publicCall('get', $sent['token']);
        self::assertSame(200, $status);
        self::assertTrue($view['expired']);

        [$status, $body] = $this->publicCall('decide', $sent['token'], ['decision' => 'approve']);
        self::assertSame(410, $status);
        self::assertSame('token_expired', $body['error']['code'] ?? null);
        self::assertSame('pending', $this->rowStatus($sent['approval_id']));
        self::assertSame('draft', $this->invoiceStatus($id));
    }

    // ── izolace schvalovatele ────────────────────────────────────────────────

    public function testApproverCannotDecideForeignApproval(): void
    {
        $id = $this->createInvoice([1000.0], header: 'A');
        [, $body] = $this->transition($id);
        $approvalId = $body['approvals'][0]['id'];

        [$status] = $this->decide($this->approverB, $approvalId, 'approve');
        self::assertSame(404, $status, 'Cizí schválení nejde rozhodnout ani zjistit.');
        self::assertSame('pending', $this->rowStatus($approvalId));

        [, $mine] = $this->inbox($this->approverB, 'mine');
        self::assertSame([], array_column($mine['data'], 'id'), 'Schránka B neukazuje schválení A.');
        [, $minA] = $this->inbox($this->approverA, 'mine');
        self::assertSame([$approvalId], array_column($minA['data'], 'id'));

        [$status] = $this->inbox($this->approverB, 'all');
        self::assertSame(403, $status, 'Přehled všech schválení je jen pro účetní.');

        $count = $this->action(PurchaseInvoiceApprovalAction::class, 'count', $this->approverA, 'readonly', [], []);
        self::assertSame(1, $count[1]['pending']);
    }

    /**
     * PDF dokladu projde schvalovateli i s rolí, která modul přijatých faktur nemá —
     * ale jen u dokladu, na kterém je schvalovatelem.
     */
    public function testApproverReachesPdfOnlyOfOwnDocument(): void
    {
        $id = $this->createInvoice([1000.0], header: 'A');
        $this->transition($id);

        $role = new \MyInvoice\Security\EffectiveRole(9, 'Schvalovatel', 'staff', true, ['purchase_invoices.approve' => 1]);
        $roles = $this->createStub(\MyInvoice\Security\PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $access = $this->createStub(\MyInvoice\Service\Tenant\SupplierAccessResolver::class);
        $access->method('resolve')->willReturn(new \MyInvoice\Service\Tenant\SupplierAccess($this->supplierId, false, null));
        $middleware = new \MyInvoice\Middleware\PermissionMiddleware(
            new \Slim\Psr7\Factory\ResponseFactory(),
            new \MyInvoice\Security\RoutePermissionMap(),
            $roles,
            new \MyInvoice\Security\PermissionChecker(new \MyInvoice\Security\PermissionCatalog()),
            $access,
            $this->c->get(\MyInvoice\Repository\PurchaseInvoiceApprovalRepository::class),
        );
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(204);
            }
        };
        $pdf = fn (int $userId, string $path): int => $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId]),
            $handler,
        )->getStatusCode();

        self::assertSame(204, $pdf($this->approverA, "/api/purchase-invoices/{$id}/pdf"));
        self::assertSame(403, $pdf($this->approverB, "/api/purchase-invoices/{$id}/pdf"), 'Cizí doklad ne.');
        self::assertSame(403, $pdf($this->approverA, "/api/purchase-invoices/{$id}"), 'Výjimka platí jen pro PDF.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param list<float> $prices základy položek
     * @param array<int,string> $items pořadí položky => kód střediska
     */
    private function createInvoice(array $prices, ?string $header = null, array $items = []): int
    {
        $body = [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'F6-' . bin2hex(random_bytes(4)),
            'document_kind' => 'invoice',
            'issue_date' => '2098-03-01',
            'tax_date' => '2098-03-01',
            'due_date' => '2098-03-29',
            'currency_id' => $this->currencyId,
            'items' => array_map(fn (float $p): array => [
                'description' => 'Služba F6', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => $p, 'vat_rate_id' => $this->vatRateId,
            ], $prices),
        ];
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->accountantId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
        $res = ($this->c->get(CreatePurchaseInvoiceAction::class))($req, new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $id = (int) $this->json($res)['id'];

        $headerDims = $header !== null ? [$this->typeId => $this->values[$header]] : [];
        $itemDims = [];
        foreach ($items as $no => $code) {
            $itemDims[$no] = [$this->typeId => $this->values[$code]];
        }
        (new DimensionAssignmentRepository($this->db))
            ->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $id, $headerDims, $itemDims);
        return $id;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function transition(int $id): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/purchase-invoices/{$id}/transition")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->accountantId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['target' => 'received']);
        $res = ($this->c->get(TransitionPurchaseInvoiceStatusAction::class))($req, new Psr7Response(), ['id' => (string) $id]);
        return [$res->getStatusCode(), $this->json($res)];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function decide(int $userId, int $approvalId, string $decision, ?string $comment = null): array
    {
        $body = ['decision' => $decision] + ($comment !== null ? ['comment' => $comment] : []);
        return $this->action(PurchaseInvoiceApprovalAction::class, 'decide', $userId, 'readonly', $body, ['id' => (string) $approvalId]);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function inbox(int $userId, string $scope): array
    {
        return $this->action(PurchaseInvoiceApprovalAction::class, 'list', $userId, 'readonly', [], [], ['scope' => $scope]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    private function action(string $class, string $method, int $userId, string $role, array $body, array $args, array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoice-approvals')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams($query)
            ->withParsedBody($body);
        $res = $method === 'list' || $method === 'count'
            ? $this->c->get($class)->{$method}($req, new Psr7Response())
            : $this->c->get($class)->{$method}($req, new Psr7Response(), $args);
        return [$res->getStatusCode(), $this->json($res)];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int,1:array<string,mixed>}
     */
    private function publicCall(string $method, string $token, array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method === 'decide' ? 'POST' : 'GET', '/api/public/purchase-approval/' . $token)
            ->withParsedBody($body);
        $res = $this->c->get(PublicPurchaseApprovalAction::class)->{$method}($req, new Psr7Response(), ['token' => $token]);
        return [$res->getStatusCode(), $this->json($res)];
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }

    private function invoiceStatus(int $id): string
    {
        return (string) $this->db->pdo()->query('SELECT status FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
    }

    private function approvalStatus(int $id): string
    {
        return (string) $this->db->pdo()->query('SELECT approval_status FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
    }

    private function varsymbol(int $id): string
    {
        return (string) $this->db->pdo()->query('SELECT varsymbol FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
    }

    private function approvalRows(int $id): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM purchase_invoice_approvals WHERE purchase_invoice_id = ' . $id)->fetchColumn();
    }

    private function rowStatus(int $approvalId): string
    {
        return (string) $this->db->pdo()->query('SELECT status FROM purchase_invoice_approvals WHERE id = ' . $approvalId)->fetchColumn();
    }

    private function auditCount(int $invoiceId, string $action): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE entity_type = 'purchase_invoice' AND entity_id = ? AND action = ?"
        );
        $stmt->execute([$invoiceId, $action]);
        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Approval\PublicApprovalGetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * GET /api/public/approval/{token} u výkazu, o kterém už bylo rozhodnuto.
 *
 * Dřív takový odkaz vracel 404 a stránka na něj reagovala červeným „Odkaz není
 * platný" — jenže schvalovatel si odkaz z e-mailu běžně otevře podruhé a hláška
 * u úspěšně schváleného výkazu vypadá jako porucha. Endpoint teď vrací 200 se
 * `state`, aby stránka mohla poděkovat, resp. shrnout zamítnutí.
 *
 * Nesmyslný / neexistující token musí dál končit 404 — jinak by šlo přes stavovou
 * odpověď zjišťovat, které tokeny existují.
 */
#[Group('integration')]
final class PublicApprovalGetStateTest extends TestCase
{
    private Connection $db;
    private PublicApprovalGetAction $action;
    private InvoiceRepository $repo;
    private int $invoiceId = 0;
    /** @var array<string,mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(PublicApprovalGetAction::class);
            $this->repo   = $c->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->invoiceId = (int) ($pdo->query('SELECT MIN(id) FROM invoices')->fetchColumn() ?: 0);
        if ($this->invoiceId === 0) {
            $this->markTestSkipped('Chybí faktura.');
        }
        $stmt = $pdo->prepare(
            'SELECT approval_status, approval_token, approval_receipt_hash,
                    approval_token_expires_at, approval_decided_at, approval_rejection_reason
               FROM invoices WHERE id = ?'
        );
        $stmt->execute([$this->invoiceId]);
        $this->original = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->invoiceId === 0 || $this->original === []) return;
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = ?, approval_token = ?, approval_receipt_hash = ?,
                    approval_token_expires_at = ?, approval_decided_at = ?, approval_rejection_reason = ?
              WHERE id = ?'
        )->execute([
            $this->original['approval_status'],
            $this->original['approval_token'],
            $this->original['approval_receipt_hash'],
            $this->original['approval_token_expires_at'],
            $this->original['approval_decided_at'],
            $this->original['approval_rejection_reason'],
            $this->invoiceId,
        ]);
        $this->db->close();
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(string $token): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/public/approval/' . $token);
        $response = ($this->action)($request, new Psr7Response(), ['token' => $token]);
        $body = json_decode((string) $response->getBody(), true);
        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    /**
     * Projde skutečným životním cyklem: token se vydá a pak zkonzumuje přes
     * `decideIfRequested`. Ručně nastavený stav by minul právě to, co je na
     * téhle opravě podstatné — že se token nuluje a zůstává po něm jen hash.
     */
    private function decide(string $newStatus, ?string $reason): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET approval_status = 'requested', approval_token = ?,
                    approval_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY),
                    approval_receipt_hash = NULL, approval_decided_at = NULL,
                    approval_rejection_reason = NULL
              WHERE id = ?"
        )->execute([$token, $this->invoiceId]);

        $ok = $this->repo->decideIfRequested($this->invoiceId, $token, $newStatus, null, $reason);
        self::assertTrue($ok, 'decideIfRequested musí projít');

        $stmt = $this->db->pdo()->prepare('SELECT approval_token FROM invoices WHERE id = ?');
        $stmt->execute([$this->invoiceId]);
        self::assertNull($stmt->fetchColumn(), 'token se po konzumaci musí smazat');

        return $token;
    }

    public function testConsumedApprovedTokenReturnsReceiptInsteadOfNotFound(): void
    {
        $token = $this->decide('approved', null);

        [$status, $body] = $this->call($token);

        self::assertSame(200, $status);
        self::assertSame('approved', $body['state'] ?? null);
        self::assertArrayHasKey('rejection_reason', $body);
        self::assertNull($body['rejection_reason']);
        self::assertArrayHasKey('decided_at', $body);
        // Výkaz ani captcha se v této větvi neposílají — stránka je jen shrnutí.
        self::assertArrayNotHasKey('work_report', $body);
    }

    public function testRejectedTokenReturnsReasonBack(): void
    {
        $token = $this->decide('rejected', 'Chybí položka za červen.');

        [$status, $body] = $this->call($token);

        self::assertSame(200, $status);
        self::assertSame('rejected', $body['state'] ?? null);
        self::assertSame('Chybí položka za červen.', $body['rejection_reason'] ?? null);
    }

    /** Stvrzenka je jen ke čtení — rozhodnout se přes zkonzumovaný token nesmí. */
    public function testConsumedTokenCannotDecideAgain(): void
    {
        $token = $this->decide('approved', null);

        self::assertFalse(
            $this->repo->decideIfRequested($this->invoiceId, $token, 'rejected', null, 'pokus'),
            'zkonzumovaný token nesmí přepsat rozhodnutí'
        );
    }

    /**
     * Živý token je oprávnění schválit výkaz za zákazníka (a tím fakturu vystavit
     * a odeslat). Detail faktury ani přehled schvalování ho nesmí vrátit, protože je
     * čte i role jen pro čtení a token se scope `read`. Veřejný odkaz z e-mailu
     * a připomínky (cron) musí s tokenem dál fungovat.
     */
    public function testInvoiceDetailAndInboxDoNotExposeLiveApprovalToken(): void
    {
        $token = bin2hex(random_bytes(24));
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET approval_status = 'requested', approval_token = ?,
                    approval_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY),
                    approval_receipt_hash = NULL, approval_decided_at = NULL
              WHERE id = ?"
        )->execute([$token, $this->invoiceId]);
        $supplierId = (int) $this->db->pdo()->query('SELECT supplier_id FROM invoices WHERE id = ' . $this->invoiceId)->fetchColumn();

        $container = Bootstrap::buildContainer();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices/' . $this->invoiceId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'readonly'])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);
        $response = $container->get(\MyInvoice\Action\Invoice\GetInvoiceAction::class)(
            $request, new Psr7Response(), ['id' => (string) $this->invoiceId],
        );
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $detail = json_decode($body, true);
        self::assertStringNotContainsString($token, $body);
        self::assertArrayNotHasKey('approval_token', $detail);
        self::assertArrayNotHasKey('approval_receipt_hash', $detail);
        self::assertSame('requested', $detail['approval_status']);
        self::assertNotEmpty($detail['approval_token_expires_at'], 'UI dál ukazuje platnost odkazu.');

        $inbox = $container->get(\MyInvoice\Action\Admin\ApprovalListAction::class)(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/approvals')
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId),
            new Psr7Response(),
        );
        self::assertSame(200, $inbox->getStatusCode());
        self::assertStringNotContainsString($token, (string) $inbox->getBody());

        // Legitimní tok: odkaz z e-mailu i připomínky token dál najdou.
        self::assertSame($this->invoiceId, (int) ($this->repo->findByApprovalToken($token)['id'] ?? 0));
        $cron = $this->repo->listForApprovalInbox(supplierId: $supplierId, statusFilter: 'requested', minDaysSince: null, maxReminders: null, page: 1, perPage: 0);
        self::assertContains($token, array_column($cron, 'approval_token'));
    }

    public function testUnknownTokenStillReturnsNotFound(): void
    {
        [$status] = $this->call(bin2hex(random_bytes(24)));
        self::assertSame(404, $status);
    }

    public function testMalformedTokenReturnsNotFound(): void
    {
        [$status] = $this->call('nope');
        self::assertSame(404, $status);
    }
}

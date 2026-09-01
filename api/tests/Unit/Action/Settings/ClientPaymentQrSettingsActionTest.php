<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\ClientPaymentQrSettingsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ClientPaymentQrSettingsActionTest extends TestCase
{
    public function testIssuedSettingChangeInvalidatesInvoicePdfsAndIsAudited(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'invoice_qr_include_due_date' => '1',
            'purchase_invoice_qr_include_due_date' => '1',
        ]);
        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())->method('execute')->with([0, 41])->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnCallback(
            static fn (string $sql): PDOStatement => str_starts_with(ltrim($sql), 'SELECT') ? $select : $update,
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('pdo')->willReturn($pdo);

        $pdf = $this->createMock(InvoicePdfRenderer::class);
        $pdf->expects(self::once())->method('invalidatePaymentQrBySupplier')->with(41)->willReturn(3);

        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with(
            'supplier.payment_qr_settings_updated',
            17,
            'supplier',
            41,
            self::callback(static fn (array $payload): bool => $payload === [
                'changes' => [
                    'invoice_qr_include_due_date' => ['before' => true, 'after' => false],
                ],
                'invalidated_invoice_pdfs' => 3,
            ]),
            '127.0.0.1',
            'PHPUnit',
            41,
        );
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $action = new ClientPaymentQrSettingsAction(
            new SupplierPaymentQrSettingsRepository($connection),
            $pdf,
            $logger,
            $ipMatcher,
        );
        $role = new EffectiveRole(4, 'Klient admin', 'client', true, [
            'settings.company' => AccessLevel::WRITE->value,
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/client/payment-qr', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody(['invoice_qr_include_due_date' => false])
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);

        $response = $action->update($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(false, $body['invoice_qr_include_due_date'] ?? null);
        self::assertSame(true, $body['purchase_invoice_qr_include_due_date'] ?? null);
    }

    public function testPurchaseSettingChangeDoesNotInvalidateInvoicePdfs(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'invoice_qr_include_due_date' => '0',
            'purchase_invoice_qr_include_due_date' => '0',
        ]);
        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())->method('execute')->with([1, 41])->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnCallback(
            static fn (string $sql): PDOStatement => str_starts_with(ltrim($sql), 'SELECT') ? $select : $update,
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('pdo')->willReturn($pdo);
        $pdf = $this->createMock(InvoicePdfRenderer::class);
        $pdf->expects(self::never())->method('invalidatePaymentQrBySupplier');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with(
            'supplier.payment_qr_settings_updated',
            17,
            'supplier',
            41,
            self::callback(static fn (array $payload): bool => $payload === [
                'changes' => [
                    'purchase_invoice_qr_include_due_date' => ['before' => false, 'after' => true],
                ],
                'invalidated_invoice_pdfs' => 0,
            ]),
            '127.0.0.1',
            'PHPUnit',
            41,
        );

        $response = $this->action($connection, $pdf, $logger)->update(
            $this->request(['purchase_invoice_qr_include_due_date' => true]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnchangedIssuedSettingDoesNotInvalidateOrAudit(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'invoice_qr_include_due_date' => '0',
            'purchase_invoice_qr_include_due_date' => '0',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($select);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('pdo')->willReturn($pdo);
        $pdf = $this->createMock(InvoicePdfRenderer::class);
        $pdf->expects(self::never())->method('invalidatePaymentQrBySupplier');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::never())->method('log');

        $response = $this->action($connection, $pdf, $logger)->update(
            $this->request(['invoice_qr_include_due_date' => false]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** @param array<string,bool> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $role = new EffectiveRole(4, 'Klient admin', 'client', true, [
            'settings.company' => AccessLevel::WRITE->value,
        ]);
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/client/payment-qr', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody($body)
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);
    }

    private function action(
        Connection $connection,
        InvoicePdfRenderer $pdf,
        ActivityLogger $logger,
    ): ClientPaymentQrSettingsAction {
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        return new ClientPaymentQrSettingsAction(
            new SupplierPaymentQrSettingsRepository($connection),
            $pdf,
            $logger,
            $ipMatcher,
        );
    }
}

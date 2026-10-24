<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\InstanceAdmin;

use MyInvoice\Action\InstanceAdmin\InstanceAdminAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\InstanceAdmin\InstanceSmtpRepository;
use MyInvoice\Service\InstanceAdmin\NeutralSmtpTestSender;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SmtpAuditCompletionTest extends TestCase
{
    public function testFailedCompletionAuditDoesNotChangeAlreadySentResult(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false, false, true, false);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $failure = new \RuntimeException('Synthetic audit unavailable');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('instance_admin.smtp_completion_audit_failed', ['result' => 'sent']);
        $action = $this->action($pdo, $failure, $logger);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/instance-admin/smtp/test')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1]);
        new \ReflectionMethod($action, 'completeTestAudit')->invoke($action, $request, null, 'sent');
    }

    public function testCompletionAuditCannotSwallowFailureOfAnOuterTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('rollBack');
        $failure = new \PDOException('Synthetic deadlock');
        $failure->errorInfo = ['40001', 1213, 'Synthetic deadlock'];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $action = $this->action($pdo, $failure, $logger);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/instance-admin/smtp/test')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1]);
        $this->expectExceptionObject($failure);
        new \ReflectionMethod($action, 'completeTestAudit')->invoke($action, $request, null, 'sent');
    }

    private function action(PDO $pdo, \Throwable $failure, LoggerInterface $logger): InstanceAdminAction
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $config = new Config([]);
        $managed = new ManagedModeGuard($config);
        $audit = $this->createMock(ActivityLogger::class);
        $audit->expects(self::once())->method('log')->willThrowException($failure);
        return new InstanceAdminAction($db, $config, new TechnicalSupplierAccessRepository($db),
            new InstanceSmtpRepository($db, $config, new SecretEncryption($config), $managed),
            new NeutralSmtpTestSender(), $audit, $managed, $logger);
    }
}

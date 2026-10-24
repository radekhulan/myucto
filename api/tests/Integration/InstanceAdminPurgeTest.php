<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\PayrollUsagePolicy;
use MyInvoice\Service\License\SeatPolicy;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class InstanceAdminPurgeTest extends TestCase
{
    public function testPurgeCascadesDisposableTechnicalStateAndPreservesAuditActorIds(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $db = new Connection($config);
        $pdo = $db->pdo();
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $users = [];
        $supplierId = $currencyId = $targetId = $eventId = 0;
        try {
            $stmt = $pdo->prepare("INSERT INTO users (email,password_hash,name,role,role_id,is_active)
                SELECT ?, ?, 'Synthetic purge user', ?, r.id, ? FROM roles r WHERE r.system_key = ?");
            $stmt->execute(['purge-admin-' . bin2hex(random_bytes(6)) . '@example.invalid', str_repeat('x', 60), 'admin', 1, 'superadmin']);
            self::assertSame(1, $stmt->rowCount());
            $actor = (int) $pdo->lastInsertId();
            $users[] = $actor;
            $stmt->execute(['purge-tech-' . bin2hex(random_bytes(6)) . '@example.invalid', str_repeat('x', 60), 'readonly', 0, 'instance_admin']);
            self::assertSame(1, $stmt->rowCount());
            $technician = (int) $pdo->lastInsertId();
            $users[] = $technician;

            // První firma řeší cyklický FK stejně jako instalační wizard.
            $bootstrapCurrency = (int) $pdo->query('SELECT MIN(id) FROM currencies')->fetchColumn();
            if ($bootstrapCurrency === 0) $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id)
                    VALUES ('Synthetic purge supplier', '', '', '', (SELECT MIN(id) FROM countries),
                    'purge@example.invalid', ?, (SELECT MIN(id) FROM vat_rates))")->execute([$bootstrapCurrency]);
                $supplierId = (int) $pdo->lastInsertId();
                if ($bootstrapCurrency === 0) {
                    $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,name_cs,name_en,symbol)
                        VALUES (?, 'CZK', 'Synthetic CZK', 'Koruna', 'Crown', 'Kč')")->execute([$supplierId]);
                    $currencyId = (int) $pdo->lastInsertId();
                    $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $supplierId]);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $pdo->prepare('INSERT INTO user_technical_access (user_id,all_suppliers) VALUES (?,0)')->execute([$technician]);
            $pdo->prepare('INSERT INTO user_technical_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$technician, $supplierId]);
            $pdo->prepare('INSERT INTO instance_smtp_test_attempts (user_id,attempted_at) VALUES (?,NOW())')->execute([$technician]);
            $pdo->prepare("INSERT INTO instance_admin_events (user_id,supplier_id,action,result)
                VALUES (?,?,'instance_admin.smtp_test','ok')")->execute([$technician, $supplierId]);
            $eventId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO instance_smtp_targets (name,host,port,encryption,username,from_email,test_recipient,created_by)
                VALUES ('Synthetic purge target','smtp.example.invalid',587,'tls','synthetic',
                'from@example.invalid','test@example.invalid',?)")->execute([$technician]);
            $targetId = (int) $pdo->lastInsertId();

            $license = $this->createStub(LicenseService::class);
            $action = new UserAdminAction($db, new ActivityLogger($db), new IpMatcher($config), new PasswordHasher($config),
                new SessionManager($db, $config, new DatabaseSecurityClock()),
                new LicenseCapacityGate($db, $license, new SeatPolicy($db), new PayrollUsagePolicy($db)),
                new PasswordSetupLinkIssuer(), $this->createStub(Mailer::class), $config);
            $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $actor, 'is_superadmin' => true])
                ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
            $response = $action->purge($request, (new ResponseFactory())->createResponse(), ['id' => $technician]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            foreach (['users' => 'id', 'user_technical_access' => 'user_id', 'user_technical_suppliers' => 'user_id',
                'instance_smtp_test_attempts' => 'user_id'] as $table => $column) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
                $stmt->execute([$technician]);
                self::assertSame(0, (int) $stmt->fetchColumn(), $table);
            }
            $stmt = $pdo->prepare('SELECT user_id FROM instance_admin_events WHERE id = ?');
            $stmt->execute([$eventId]);
            self::assertSame($technician, (int) $stmt->fetchColumn(), 'Technická auditní stopa zachová ID smazaného autora.');
            $stmt = $pdo->prepare('SELECT created_by FROM instance_smtp_targets WHERE id = ?');
            $stmt->execute([$targetId]);
            self::assertSame($technician, (int) $stmt->fetchColumn(), 'Neměnný schválený cíl zachová ID svého původce.');
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare('DELETE FROM instance_admin_events WHERE id = ?')->execute([$eventId]);
            $pdo->prepare('DELETE FROM instance_smtp_targets WHERE id = ?')->execute([$targetId]);
            foreach ($users as $id) {
                $pdo->prepare("DELETE FROM activity_log WHERE user_id = ? OR (entity_type = 'user' AND entity_id = ?)")->execute([$id, $id]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$currencyId]);
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
            $db->close();
        }
    }
}

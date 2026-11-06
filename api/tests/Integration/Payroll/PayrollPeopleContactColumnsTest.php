<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\Payroll\People\PayrollPeopleContactColumns;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Sloupce E-mail a Telefon v seznamu zaměstnanců dešifrují kontakty. Smí je
 * dostat jen uživatel s právem na citlivé údaje osoby, v přihlášené relaci,
 * a každé takové zobrazení musí zanechat stopu v auditu.
 */
#[Group('integration')]
final class PayrollPeopleContactColumnsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($source <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
                             VALUES (?, "Syntetická Kontaktní", "employee", 1)')
            ->execute([$this->supplierId]);
        $this->employeeId = (int) $this->pdo->lastInsertId();
        $this->contact('email', 'synteticka@example.invalid');
        $this->contact('phone', '+420 600 000 001');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testUserWithSensitiveReadGetsContactsAndLeavesAuditTrace(): void
    {
        $request = $this->request(['payroll', 'payroll.person.read_sensitive'], 'session');
        $before = $this->auditCount();

        $contacts = $this->columns()->forPage($request, $this->supplierId, [$this->employeeId], null, null);

        self::assertSame('synteticka@example.invalid', $contacts[$this->employeeId]['email']);
        self::assertNotNull($contacts[$this->employeeId]['phone']);
        self::assertSame($before + 1, $this->auditCount(), 'Zobrazení kontaktů se musí zapsat do auditu.');
    }

    public function testUserWithoutSensitiveReadGetsNothing(): void
    {
        $request = $this->request(['payroll'], 'session');

        self::assertFalse(PayrollPeopleContactColumns::allowed($request));
        self::assertSame([], $this->columns()->forPage($request, $this->supplierId, [$this->employeeId], null, null));
    }

    public function testApiTokenGetsNothingEvenWithPermission(): void
    {
        $request = $this->request(['payroll', 'payroll.person.read_sensitive'], 'bearer');

        self::assertSame([], $this->columns()->forPage($request, $this->supplierId, [$this->employeeId], null, null));
    }

    private function columns(): PayrollPeopleContactColumns
    {
        return $this->container->get(PayrollPeopleContactColumns::class);
    }

    /** @param list<string> $permissions */
    private function request(array $permissions, string $method): ServerRequestInterface
    {
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES ('Syntetická role kontaktů', 'staff', 1)")->execute();
        $roleId = (int) $this->pdo->lastInsertId();
        $grant = $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $permission) {
            $grant->execute([$roleId, $permission, AccessLevel::READ->value]);
        }
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
                             VALUES (?, 'disabled-test-password', 'Syntetická účetní', ?, 'cs', 1)")
            ->execute(['contacts-' . bin2hex(random_bytes(6)) . '@example.invalid', $roleId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)')
            ->execute([$userId, $this->supplierId]);

        $authorization = $this->container->get(CrossSupplierAuthorization::class);
        $user = $authorization->activeUser($userId);
        self::assertNotNull($user);
        $scoped = $authorization->scopedRequest(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/payroll/people')
                ->withAttribute(AuthMiddleware::ATTR_USER, $user)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, $method),
            $this->supplierId,
        );
        self::assertNotNull($scoped);

        return $scoped;
    }

    private function contact(string $type, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO payroll_person_contacts
                (supplier_id, employee_id, contact_type, contact_value_ciphertext,
                 contact_value_hash, contact_value_masked, is_primary, is_active)
             VALUES (?, ?, ?, '', ?, '', 1, 1)"
        )->execute([$this->supplierId, $this->employeeId, $type, random_bytes(32)]);
        $id = (int) $this->pdo->lastInsertId();
        $sealed = $this->container->get(PayrollSensitiveData::class)->seal(
            $value,
            $type === 'email' ? PayrollSensitiveField::CONTACT_EMAIL : PayrollSensitiveField::CONTACT_PHONE,
            $this->supplierId,
            $id,
        );
        $this->pdo->prepare(
            'UPDATE payroll_person_contacts
                SET contact_value_ciphertext = ?, contact_value_hash = ?, contact_value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }

    private function auditCount(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'payroll.person_contacts.listed'",
        );
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}

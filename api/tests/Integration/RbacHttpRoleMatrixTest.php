<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

#[Group('integration')]
final class RbacHttpRoleMatrixTest extends TestCase
{
    use OpensslConfigTrait;

    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $roleIds = [];
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $emailProfileIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];
    /** @var list<int> */
    private array $signingProfileIds = [];
    /** @var list<int> */
    private array $supplierIds = [];
    private ?array $originalSigningOutputs = null;
    private array $templateBackups = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->supplierId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0 || $this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Chybí supplier nebo dynamické role.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->templateBackups as [$table, $keys, $row]) {
            $where = implode(' AND ', array_map(static fn ($key) => "$key = ?", array_keys($keys)));
            $pdo->prepare("DELETE FROM $table WHERE $where")->execute(array_values($keys));
            if ($row !== false) {
                $columns = implode(',', array_keys($row));
                $values = implode(',', array_fill(0, count($row), '?'));
                $pdo->prepare("INSERT INTO $table ($columns) VALUES ($values)")->execute(array_values($row));
            }
        }
        foreach ($this->sessionTokens as $token) {
            try { $this->sessions->destroy($token); } catch (\Throwable) {}
        }
        if ($this->emailProfileIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->emailProfileIds), '?'));
            $pdo->prepare("DELETE FROM email_profiles WHERE id IN ({$placeholders})")->execute($this->emailProfileIds);
        }
        if ($this->originalSigningOutputs !== null) {
            $pdo->prepare('DELETE FROM pdf_signature_output_settings WHERE supplier_id = ?')
                ->execute([$this->supplierId]);
            foreach ($this->originalSigningOutputs as $row) {
                $columns = implode(',', array_map(static fn (string $key): string => '`' . $key . '`', array_keys($row)));
                $values = implode(',', array_fill(0, count($row), '?'));
                $pdo->prepare("INSERT INTO pdf_signature_output_settings ({$columns}) VALUES ({$values})")->execute(array_values($row));
            }
        }
        foreach ($this->signingProfileIds as $id) {
            $statement = $pdo->prepare('SELECT supplier_id FROM signing_profiles WHERE id = ?');
            $statement->execute([$id]);
            $supplierId = (int) $statement->fetchColumn();
            $path = RuntimePaths::storage("signing/profiles/supplier-{$supplierId}/profile-{$id}/profile.p12");
            if (is_file($path)) unlink($path);
            $pdo->prepare('DELETE FROM signing_profiles WHERE id = ?')->execute([$id]);
        }
        foreach ($this->supplierIds as $id) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
        if ($this->clientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->clientIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ({$placeholders})")->execute($this->clientIds);
        }
        if ($this->userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ({$placeholders})")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ({$placeholders})")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($this->userIds);
        }
        if ($this->roleIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->roleIds), '?'));
            $pdo->prepare("DELETE FROM roles WHERE id IN ({$placeholders})")->execute($this->roleIds);
        }
        $this->db->close();
    }

    /** @return iterable<string, array{string,string,int,bool}> */
    public static function roleMatrix(): iterable
    {
        yield 'superadmin' => ['superadmin', 'superadmin', 2, true];
        yield 'staff read' => ['staff_read', 'staff', 1, true];
        yield 'staff write' => ['staff_write', 'staff', 2, true];
        yield 'client read' => ['client_read', 'client', 1, true];
        yield 'client write' => ['client_write', 'client', 2, true];
        yield 'without supplier' => ['no_supplier', 'staff', 2, false];
    }

    #[DataProvider('roleMatrix')]
    public function testRealHttpAuthorizationMatrix(
        string $variant,
        string $roleType,
        int $level,
        bool $hasSupplier,
    ): void {
        $userId = $this->createUser($variant, $roleType, $level, $hasSupplier);
        $session = $this->createSession($userId);
        $isSuperadmin = $variant === 'superadmin';
        $tenantBaseAllowed = $hasSupplier || $isSuperadmin;
        $staffModuleAllowed = $tenantBaseAllowed && ($roleType === 'staff' || $isSuperadmin);
        $clientModuleAllowed = $tenantBaseAllowed;
        $writeAllowed = $tenantBaseAllowed && $level === 2;
        $staffSettingsWriteAllowed = $tenantBaseAllowed
            && $level === 2
            && ($roleType === 'staff' || $isSuperadmin);

        foreach ([
            ['GET', '/api/invoices', null, $clientModuleAllowed],
            ['GET', '/api/purchase-invoices', null, $clientModuleAllowed],
            ['GET', '/api/documents', null, $staffModuleAllowed],
            ['GET', '/api/bank-statements', null, $staffModuleAllowed],
            ['GET', '/api/accounting/journal', null, $staffModuleAllowed],
            ['GET', '/api/reports/dph-book?year=2025', null, $staffModuleAllowed],
            ['GET', '/api/settings/supplier', null, $clientModuleAllowed],
            ['GET', '/api/settings/client/email-profiles', null, $writeAllowed],
            ['GET', '/api/settings/client/branding', null, $writeAllowed],
            ['GET', '/api/settings/client/payment-qr', null, $writeAllowed],
            ['GET', '/api/settings/client/invoice-defaults', null, $writeAllowed],
            ['PUT', '/api/settings/supplier', [], $staffSettingsWriteAllowed],
            ['POST', '/api/invoices/999999/send', [], $writeAllowed],
            ['POST', '/api/purchase-invoices', [], $writeAllowed],
            // Účetní profily druhů a vlastní kontace úhrad: samostatné právo other_items,
            // které testovací role nemají (účetnictví ho nezahrnuje).
            ['GET', '/api/accounting/other-items/kinds/999999999/posting-profiles', null, $isSuperadmin],
            ['PUT', '/api/accounting/other-items/kinds/999999999/posting-profiles', [], $isSuperadmin],
            ['PUT', '/api/accounting/other-items/999999999/posting-overrides', [], $isSuperadmin],
        ] as [$method, $path, $body, $allowed]) {
            $response = $this->request($method, $path, $session, $body);
            $this->assertPermissionDecision($response, $allowed, "{$variant}: {$method} {$path}");
        }

        $adminResponse = $this->request('GET', '/api/admin/roles', $session);
        $this->assertPermissionDecision($adminResponse, $isSuperadmin, "{$variant}: superadmin-only endpoint");

        $email = '__test_rbac_matrix_' . bin2hex(random_bytes(5)) . '@example.test';
        $before = $this->clientCount($email);
        $create = $this->request('POST', '/api/clients', $session, [
            'company_name' => '__TEST RBAC matrix', 'street' => 'Testovací 1',
            'city' => 'Praha', 'zip' => '11000', 'main_email' => $email,
        ]);
        $this->assertPermissionDecision($create, $writeAllowed, "{$variant}: POST /api/clients");
        if ($writeAllowed) {
            $id = (int) ($this->json($create)['id'] ?? 0);
            if ($id > 0) $this->clientIds[] = $id;
        } else {
            self::assertSame($before, $this->clientCount($email), 'Zamítnutá mutace nesmí změnit DB.');
        }
    }

    public function testClientOperationalGrantsAreIndependentAndRevocable(): void
    {
        $foreign = $this->db->pdo()->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
            SELECT '__TEST independent operational supplier', street, city, zip, country_id, email, default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?");
        $foreign->execute([$this->supplierId]);
        $foreignId = (int) $this->db->pdo()->lastInsertId();
        $this->supplierIds[] = $foreignId;
        $areas = [
            'settings.email_profiles' => '/api/settings/client/email-profiles',
            'settings.email_templates' => '/api/settings/client/email-templates',
            'settings.branding' => '/api/settings/client/branding',
            'settings.payment_qr' => '/api/settings/client/payment-qr',
            'settings.invoice_defaults' => '/api/settings/client/invoice-defaults',
            'settings.signing_profiles' => '/api/settings/client/signing/profiles',
        ];
        foreach ($areas as $permission => $path) {
            $userId = $this->createUser('isolated_' . $permission, 'client', 2, true);
            $roleId = end($this->roleIds);
            $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key LIKE ? AND permission_key <> ?')
                ->execute([$roleId, 'settings.%', $permission]);
            // Keep the old source grant to prove it cannot restore a revoked area.
            $this->db->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, 2)')
                ->execute([$roleId, 'settings.company']);
            $session = $this->createSession($userId);
            foreach ($areas as $otherPermission => $otherPath) {
                $response = $this->request('GET', $otherPath, $session);
                self::assertSame($permission === $otherPermission ? 200 : 403, $response->getStatusCode(), "$permission: $otherPath " . (string) $response->getBody());
            }
            self::assertSame(403, $this->request('GET', $path, $session, null, [], $foreignId)->getStatusCode(), 'Delegation must not grant another supplier.');
            $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$roleId, $permission]);
            // A fresh app represents the next HTTP request after the role was saved.
            $this->app = null;
            self::assertSame(403, $this->request('GET', $path, $session)->getStatusCode(), "Revoked $permission");
        }
    }

    public function testCompanyWriteCannotBypassRevokedOperationalSettings(): void
    {
        $userId = $this->createUser('staff_revoked_operational', 'staff', 2, true);
        $roleId = end($this->roleIds);
        $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key IN (?, ?)')
            ->execute([$roleId, 'settings.payment_qr', 'settings.branding']);
        $session = $this->createSession($userId);
        $stored = $this->db->pdo()->query('SELECT invoice_qr_include_due_date, purchase_invoice_qr_include_due_date, email_branding_enabled, email_accent_color, invoice_pdf_monochrome FROM supplier WHERE id = ' . $this->supplierId)
            ->fetch(\PDO::FETCH_ASSOC);
        foreach (['invoice_qr_include_due_date', 'purchase_invoice_qr_include_due_date', 'email_branding_enabled', 'invoice_pdf_monochrome'] as $field) {
            $response = $this->request('PUT', '/api/settings/supplier', $session, [$field => !(bool) $stored[$field]]);
            self::assertSame(403, $response->getStatusCode(), $field . ': ' . (string) $response->getBody());
        }
        $response = $this->request('PUT', '/api/settings/supplier', $session, ['email_accent_color' => '#012345' === $stored['email_accent_color'] ? '#543210' : '#012345']);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $after = $this->db->pdo()->query('SELECT invoice_qr_include_due_date, purchase_invoice_qr_include_due_date, email_branding_enabled, email_accent_color, invoice_pdf_monochrome FROM supplier WHERE id = ' . $this->supplierId)
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($stored, $after);
    }

    #[DataProvider('roleMatrix')]
    public function testPdfAppearanceWriteUsesEffectiveBrandingPermission(
        string $variant,
        string $roleType,
        int $level,
        bool $hasSupplier,
    ): void {
        $this->supplierId = $this->createPdfAppearanceSupplier();
        $session = $this->createSession($this->createUser($variant, $roleType, $level, $hasSupplier));
        $before = $this->pdfAppearanceRow($this->supplierId);
        $payload = [
            'invoice_pdf_compact_items' => true,
            'invoice_pdf_show_issued_by' => true,
            'invoice_pdf_show_line_discount' => true,
            'invoice_pdf_show_base_qty' => false,
            'invoice_pdf_bank_in_footer' => true,
            'work_report_display_options' => ['compact' => true, 'rate' => false],
        ];
        $response = $this->request('PUT', '/api/settings/client/branding', $session, $payload);
        $allowed = ($hasSupplier && $level === 2) || $variant === 'superadmin';
        self::assertSame($allowed ? 200 : 403, $response->getStatusCode(), (string) $response->getBody());
        if (!$allowed) {
            self::assertSame($before, $this->pdfAppearanceRow($this->supplierId));
            return;
        }

        $returned = $this->json($response);
        $stored = $this->pdfAppearanceRow($this->supplierId);
        foreach ($payload as $field => $value) {
            if ($field === 'work_report_display_options') continue;
            self::assertSame($value, $returned[$field] ?? null, $field);
            self::assertSame((int) $value, (int) $stored[$field], $field);
        }
        self::assertTrue($returned['work_report_display_options']['compact']);
        self::assertFalse($returned['work_report_display_options']['rate']);
        $options = json_decode($stored['work_report_display_options'], true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($options['compact']);
        self::assertFalse($options['rate']);
    }

    public function testPdfAppearanceRejectsInvalidAndForbiddenFieldsAtomically(): void
    {
        $this->supplierId = $this->createPdfAppearanceSupplier();
        $session = $this->createSession($this->createUser('pdf_validation', 'client', 2, true));
        $before = $this->pdfAppearanceRow($this->supplierId);
        foreach ([
            [['supplier_id' => $this->supplierId], 403, 'field_not_delegable'],
            [['accounting_mode' => 'double_entry'], 403, 'field_not_delegable'],
            [['default_rounding_mode' => 'whole_czk'], 403, 'field_not_delegable'],
            [['invoice_pdf_show_issued_by' => 'yes'], 400, 'validation_failed'],
            [['work_report_display_options' => ['rate' => 'false']], 400, 'validation_failed'],
            [['work_report_display_options' => ['unknown' => true]], 400, 'validation_failed'],
        ] as [$invalid, $status, $code]) {
            $response = $this->request('PUT', '/api/settings/client/branding', $session,
                ['invoice_pdf_compact_items' => true] + $invalid);
            self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($code, $this->json($response)['error']['code'] ?? null);
            self::assertSame($before, $this->pdfAppearanceRow($this->supplierId));
        }
    }

    public function testPdfAppearanceIsIsolatedAndHonorsPerSupplierRoleAndRevocation(): void
    {
        $this->supplierId = $this->createPdfAppearanceSupplier();
        $otherSupplier = $this->createPdfAppearanceSupplier();
        $userId = $this->createUser('pdf_writer', 'client', 2, true);
        $writerRole = (int) end($this->roleIds);
        $session = $this->createSession($userId);
        $beforeOther = $this->pdfAppearanceRow($otherSupplier);
        $payload = ['invoice_pdf_compact_items' => true];
        $path = '/api/settings/client/branding';

        self::assertSame(403, $this->request('PUT', $path, $session, $payload, supplierId: $otherSupplier)->getStatusCode());
        $saved = $this->request('PUT', $path, $session, $payload);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(1, (int) $this->pdfAppearanceRow($this->supplierId)['invoice_pdf_compact_items']);
        self::assertSame($beforeOther, $this->pdfAppearanceRow($otherSupplier));

        $this->createUser('pdf_reader_role', 'client', 1, true);
        $readerRole = (int) end($this->roleIds);
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$userId, $otherSupplier, $readerRole]);
        $this->app = null;
        self::assertSame(403, $this->request('PUT', $path, $session, $payload, supplierId: $otherSupplier)->getStatusCode());
        self::assertSame(200, $this->request('GET', $path, $session)->getStatusCode());
        self::assertSame($beforeOther, $this->pdfAppearanceRow($otherSupplier));

        $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')
            ->execute([$writerRole, 'settings.branding']);
        $this->app = null;
        self::assertSame(403, $this->request('PUT', $path, $session, ['invoice_pdf_compact_items' => false])->getStatusCode());
        self::assertSame(403, $this->request('GET', $path, $session)->getStatusCode());
        self::assertSame(1, (int) $this->pdfAppearanceRow($this->supplierId)['invoice_pdf_compact_items']);
        self::assertSame($beforeOther, $this->pdfAppearanceRow($otherSupplier));
    }

    public function testCompanyAndStockWritesCannotBypassRevokedPdfAppearance(): void
    {
        $this->supplierId = $this->createPdfAppearanceSupplier();
        $userId = $this->createUser('pdf_staff_revoked', 'staff', 2, true);
        $roleId = (int) end($this->roleIds);
        $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')
            ->execute([$roleId, 'settings.branding']);
        $this->db->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, 2)')
            ->execute([$roleId, 'stock']);
        $session = $this->createSession($userId);
        $before = $this->pdfAppearanceRow($this->supplierId);
        foreach ($before as $field => $value) {
            $changed = $field === 'work_report_display_options' ? ['compact' => true] : !(bool) $value;
            $response = $this->request('PUT', '/api/settings/supplier', $session, [$field => $changed]);
            self::assertSame(403, $response->getStatusCode(), $field . ': ' . (string) $response->getBody());
            self::assertSame($before, $this->pdfAppearanceRow($this->supplierId));
        }

        // Starý formulář smí vrátit nezměněné hodnoty; JSON se porovnává podle významu.
        $unchanged = $this->request('PUT', '/api/settings/supplier', $session, [
            'invoice_pdf_compact_items' => false,
            'work_report_display_options' => ['compact' => false, 'date' => true, 'hours' => true, 'rate' => true, 'amount' => true],
        ]);
        self::assertSame(200, $unchanged->getStatusCode(), (string) $unchanged->getBody());
        self::assertSame($before, $this->pdfAppearanceRow($this->supplierId));
    }

    #[DataProvider('roleMatrix')]
    public function testInvoiceDefaultsUseEffectivePermission(string $variant, string $roleType, int $level, bool $hasSupplier): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $session = $this->createSession($this->createUser($variant, $roleType, $level, $hasSupplier));
        $before = $this->invoiceDefaultsRow($this->supplierId);
        $response = $this->request('PUT', '/api/settings/client/invoice-defaults', $session, self::invoiceDefaultsPayload());
        $allowed = ($hasSupplier && $level === 2) || $variant === 'superadmin';
        self::assertSame($allowed ? 200 : 403, $response->getStatusCode(), (string) $response->getBody());
        if (!$allowed) {
            self::assertSame($before, $this->invoiceDefaultsRow($this->supplierId));
            return;
        }
        $returned = $this->json($response);
        foreach (self::invoiceDefaultsPayload() as $field => $value) {
            self::assertSame($value, $returned[$field] ?? null, $field);
        }
        $stored = $this->invoiceDefaultsRow($this->supplierId);
        self::assertSame(21, (int) $stored['default_payment_due_days']);
        self::assertSame('1234.567890', $stored['default_hourly_rate']);
        self::assertSame('Syntetická poznámka', $stored['default_note_below_items_cs']);
        self::assertArrayNotHasKey('default_rounding_mode', $returned);
        self::assertArrayNotHasKey('default_vat_rate_id', $returned);
    }

    public function testInvoiceDefaultsRejectMixedInvalidOrForbiddenPayloadWithoutPartialWrite(): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $session = $this->createSession($this->createUser('defaults_invalid', 'client', 2, true));
        $before = $this->invoiceDefaultsRow($this->supplierId);
        foreach ([
            [['supplier_id' => $this->supplierId], 403, 'field_not_delegable'],
            [['default_currency' => 'EUR'], 403, 'field_not_delegable'],
            [['default_rounding_mode' => 'whole_czk'], 403, 'field_not_delegable'],
            [['default_prices_include_vat' => true], 403, 'field_not_delegable'],
            [['default_vat_rate_id' => 1], 403, 'field_not_delegable'],
            [['default_payment_due_days' => -1], 400, 'validation_failed'],
            [['default_payment_due_days' => 1.5], 400, 'validation_failed'],
            [['default_payment_due_days' => 4294967296], 400, 'validation_failed'],
            [['default_payment_due_days' => []], 400, 'validation_failed'],
            [['default_payment_due_unit' => 'year'], 400, 'validation_failed'],
            [['default_hourly_rate' => '1e999'], 400, 'validation_failed'],
            [['default_hourly_rate' => -1], 400, 'validation_failed'],
            [['default_hourly_rate' => []], 400, 'validation_failed'],
            [['default_note_below_items_enabled' => 'false'], 400, 'validation_failed'],
            [['default_note_below_items_en' => str_repeat('ž', 5001)], 400, 'validation_failed'],
        ] as [$invalid, $status, $code]) {
            $response = $this->request('PUT', '/api/settings/client/invoice-defaults', $session,
                array_replace(self::invoiceDefaultsPayload(), $invalid));
            self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($code, $this->json($response)['error']['code'] ?? null);
            self::assertSame($before, $this->invoiceDefaultsRow($this->supplierId));
        }
    }

    public function testInvoiceDefaultsRespectSupplierMembershipOverrideAndRevocation(): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $other = $this->createInvoiceDefaultsSupplier();
        $user = $this->createUser('defaults_writer', 'client', 2, true);
        $writerRole = (int) end($this->roleIds);
        $session = $this->createSession($user);
        $beforeOther = $this->invoiceDefaultsRow($other);
        $path = '/api/settings/client/invoice-defaults';
        self::assertSame(403, $this->request('PUT', $path, $session, self::invoiceDefaultsPayload(), supplierId: $other)->getStatusCode());
        self::assertSame(200, $this->request('PUT', $path, $session, self::invoiceDefaultsPayload())->getStatusCode());
        self::assertSame($beforeOther, $this->invoiceDefaultsRow($other));
        $this->createUser('defaults_reader_role', 'client', 1, true);
        $readerRole = (int) end($this->roleIds);
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$user, $other, $readerRole]);
        $this->app = null;
        self::assertSame(403, $this->request('PUT', $path, $session, self::invoiceDefaultsPayload(), supplierId: $other)->getStatusCode());
        self::assertSame(200, $this->request('GET', $path, $session)->getStatusCode());
        $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')
            ->execute([$writerRole, 'settings.invoice_defaults']);
        $this->app = null;
        $before = $this->invoiceDefaultsRow($this->supplierId);
        self::assertSame(403, $this->request('PUT', $path, $session, ['default_payment_due_days' => 7])->getStatusCode());
        self::assertSame(403, $this->request('GET', $path, $session)->getStatusCode());
        self::assertSame($before, $this->invoiceDefaultsRow($this->supplierId));
        self::assertSame($beforeOther, $this->invoiceDefaultsRow($other));
    }

    public function testCompanyWriteCannotBypassRevokedInvoiceDefaultsButAcceptsUnchangedForm(): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $user = $this->createUser('defaults_staff_revoked', 'staff', 2, true);
        $role = (int) end($this->roleIds);
        $this->db->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')
            ->execute([$role, 'settings.invoice_defaults']);
        $session = $this->createSession($user);
        $before = $this->invoiceDefaultsRow($this->supplierId);
        foreach (self::invoiceDefaultsPayload() as $field => $value) {
            $response = $this->request('PUT', '/api/settings/supplier', $session, [$field => $value]);
            self::assertSame(403, $response->getStatusCode(), $field . ': ' . (string) $response->getBody());
            self::assertSame($before, $this->invoiceDefaultsRow($this->supplierId));
        }
        $unchanged = array_replace($before, ['default_payment_due_days' => 14, 'default_hourly_rate' => '1 500,000000',
            'default_note_below_items_enabled' => false, 'default_note_below_items_cs' => '', 'default_note_below_items_en' => null]);
        $response = $this->request('PUT', '/api/settings/supplier', $session, $unchanged);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($before, $this->invoiceDefaultsRow($this->supplierId));
    }

    private static function invoiceDefaultsPayload(): array
    {
        return ['default_payment_due_days' => 21, 'default_payment_due_unit' => 'month',
            'default_hourly_rate' => 1234.56789, 'default_note_below_items_enabled' => true,
            'default_note_below_items_cs' => 'Syntetická poznámka', 'default_note_below_items_en' => 'Synthetic note'];
    }

    public static function invoiceDefaultsEndpoints(): iterable
    {
        yield 'delegované nastavení' => ['/api/settings/client/invoice-defaults'];
        yield 'obecné nastavení' => ['/api/settings/supplier'];
    }

    #[DataProvider('invoiceDefaultsEndpoints')]
    public function testInvoiceDefaultsPreserveExistingLongerDuePeriod(string $path): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET default_payment_due_days = 400 WHERE id = ?')
            ->execute([$this->supplierId]);
        $session = $this->createSession($this->createUser('defaults_longer_due', 'staff', 2, true));
        $response = $this->request('PUT', $path, $session, ['default_payment_due_days' => 400]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(400, $this->json($response)['default_payment_due_days']);
        self::assertSame(400, (int) $this->invoiceDefaultsRow($this->supplierId)['default_payment_due_days']);
    }

    #[DataProvider('invoiceDefaultsEndpoints')]
    public function testInvoiceDefaultsLeaveExistingDraftAndIssuedDocumentsUnchanged(string $path): void
    {
        $this->supplierId = $this->createInvoiceDefaultsSupplier();
        $user = $this->createUser('defaults_existing_documents', 'staff', 2, true);
        $session = $this->createSession($user);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT id, '__TEST defaults customer', 'Testovací 1', 'Praha', '11000', country_id,
            'defaults@example.test', default_currency_id FROM supplier WHERE id = ?")->execute([$this->supplierId]);
        $client = (int) $pdo->lastInsertId();
        $invoiceIds = [];
        try {
            foreach (['draft', 'issued'] as $status) {
                $pdo->prepare("INSERT INTO invoices (supplier_id, client_id, issue_date, tax_date, due_date,
                    currency_id, created_by, status, invoice_type, note_below_items)
                    SELECT id, ?, '2096-03-10', '2096-03-10', '2096-03-24', default_currency_id, ?, ?,
                    'invoice', 'Původní syntetická poznámka' FROM supplier WHERE id = ?")
                    ->execute([$client, $user, $status, $this->supplierId]);
                $id = (int) $pdo->lastInsertId();
                $invoiceIds[] = $id;
                $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                    SELECT ?, 'Syntetická hodinová práce', 2, 'h', 1500, default_vat_rate_id, 0, 3000, 0, 3000
                    FROM supplier WHERE id = ?")->execute([$id, $this->supplierId]);
            }
            $documents = $pdo->prepare('SELECT * FROM invoices WHERE supplier_id = ? ORDER BY id');
            $items = $pdo->prepare('SELECT ii.* FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id
                WHERE i.supplier_id = ? ORDER BY ii.id');
            $documents->execute([$this->supplierId]);
            $items->execute([$this->supplierId]);
            $beforeDocuments = $documents->fetchAll(\PDO::FETCH_ASSOC);
            $beforeItems = $items->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(2, $beforeDocuments);
            self::assertCount(2, $beforeItems);
            $response = $this->request('PUT', $path, $session, self::invoiceDefaultsPayload());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $documents->execute([$this->supplierId]);
            $items->execute([$this->supplierId]);
            self::assertSame($beforeDocuments, $documents->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($beforeItems, $items->fetchAll(\PDO::FETCH_ASSOC));
            $defaults = $this->invoiceDefaultsRow($this->supplierId);
            self::assertSame(['value' => 21, 'unit' => 'month'],
                \MyInvoice\Service\Invoice\PaymentDueResolver::resolve(null, null, $defaults));
            self::assertSame('Syntetická poznámka', \MyInvoice\Service\Invoice\DefaultInvoiceNote::forLanguage($defaults, 'cs'));
        } finally {
            foreach ($invoiceIds as $id) $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$client]);
        }
    }

    private function createInvoiceDefaultsSupplier(): int
    {
        $id = $this->createPdfAppearanceSupplier();
        $this->db->pdo()->prepare("UPDATE supplier SET default_payment_due_days = 14, default_payment_due_unit = 'days',
            default_hourly_rate = 1500, default_note_below_items_enabled = 0,
            default_note_below_items_cs = NULL, default_note_below_items_en = NULL WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function invoiceDefaultsRow(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT default_payment_due_days, default_payment_due_unit, default_hourly_rate,
            default_note_below_items_enabled, default_note_below_items_cs, default_note_below_items_en FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function createPdfAppearanceSupplier(): int
    {
        $insert = $this->db->pdo()->prepare("INSERT INTO supplier
            (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
             invoice_pdf_compact_items, invoice_pdf_show_issued_by, invoice_pdf_show_line_discount,
             invoice_pdf_show_base_qty, invoice_pdf_bank_in_footer, work_report_display_options)
            SELECT '__TEST PDF appearance', 'Testovací 1', 'Praha', '11000', country_id,
                   'pdf-appearance@example.test', default_currency_id, default_vat_rate_id,
                   0, 0, 0, 1, 0, NULL FROM supplier WHERE id = ?");
        $insert->execute([$this->supplierId]);
        self::assertSame(1, $insert->rowCount(), 'Test vyžaduje zdrojovou syntetickou firmu.');
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->supplierIds[] = $id;
        return $id;
    }

    private function pdfAppearanceRow(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT invoice_pdf_compact_items, invoice_pdf_show_issued_by,
            invoice_pdf_show_line_discount, invoice_pdf_show_base_qty, invoice_pdf_bank_in_footer,
            work_report_display_options FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    public function testClientOperationalGrantsUseOnlyTheOperationalSettingsAllowlist(): void
    {
        $readerId = $this->createUser('client_operational_settings_read', 'client', 1, true);
        $readerSession = $this->createSession($readerId);
        $readerProfiles = $this->request('GET', '/api/settings/branding-profiles', $readerSession);
        self::assertSame(403, $readerProfiles->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($readerProfiles)['error']['code'] ?? null);
        $readerPaymentQr = $this->request('GET', '/api/settings/client/payment-qr', $readerSession);
        self::assertSame(403, $readerPaymentQr->getStatusCode());

        $userId = $this->createUser('client_operational_settings', 'client', 2, true);
        $session = $this->createSession($userId);
        $secret = '__TEST_SMTP_SECRET_' . bin2hex(random_bytes(8));
        $code = 'client-' . bin2hex(random_bytes(5));

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client delegated profile',
            'code' => $code,
            'from_email' => 'delegated@example.test',
            'transport_type' => 'global',
            'smtp_password' => $secret,
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdBody = $this->json($created);
        $profileId = (int) ($createdBody['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);
        $this->emailProfileIds[] = $profileId;
        self::assertArrayNotHasKey('smtp_password', $createdBody);
        self::assertArrayNotHasKey('imap_password', $createdBody);
        self::assertNull($createdBody['signing_profile_id'] ?? null);
        self::assertTrue($createdBody['client_manageable'] ?? false);

        $audit = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $audit->execute([$userId, 'email_profile.created']);
        self::assertStringNotContainsString($secret, (string) $audit->fetchColumn());

        $sendmail = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST forbidden sendmail',
            'code' => $code . '-sendmail',
            'from_email' => 'delegated@example.test',
            'transport_type' => 'sendmail',
            'sendmail_command' => '/usr/sbin/sendmail -bs',
        ]);
        self::assertSame(403, $sendmail->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($sendmail)['error']['code'] ?? null);

        $broad = $this->request('PUT', '/api/settings/supplier', $session, [
            'company_name' => '__TEST forbidden legal change',
        ]);
        self::assertSame(403, $broad->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($broad)['error']['code'] ?? null);

        $brandingMassAssignment = $this->request('PUT', '/api/settings/client/branding', $session, [
            'company_name' => '__TEST forbidden branding change',
        ]);
        self::assertSame(403, $brandingMassAssignment->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($brandingMassAssignment)['error']['code'] ?? null);

        $paymentQr = $this->request('GET', '/api/settings/client/payment-qr', $session);
        self::assertSame(200, $paymentQr->getStatusCode(), (string) $paymentQr->getBody());
        $beforeQr = $this->json($paymentQr);
        self::assertIsBool($beforeQr['invoice_qr_include_due_date'] ?? null);
        self::assertIsBool($beforeQr['purchase_invoice_qr_include_due_date'] ?? null);

        try {
            // Vydané necháváme beze změny, aby RBAC test neinvalidoval cizí PDF fixture;
            // přijaté přepneme a ověříme skutečný supplier-scoped zápis i audit.
            $updatedQr = $this->request('PUT', '/api/settings/client/payment-qr', $session, [
                'invoice_qr_include_due_date' => $beforeQr['invoice_qr_include_due_date'],
                'purchase_invoice_qr_include_due_date' => !$beforeQr['purchase_invoice_qr_include_due_date'],
            ]);
            self::assertSame(200, $updatedQr->getStatusCode(), (string) $updatedQr->getBody());
            self::assertSame(
                !$beforeQr['purchase_invoice_qr_include_due_date'],
                $this->json($updatedQr)['purchase_invoice_qr_include_due_date'] ?? null,
            );

            $storedQr = $this->db->pdo()->prepare(
                'SELECT invoice_qr_include_due_date, purchase_invoice_qr_include_due_date FROM supplier WHERE id = ?'
            );
            $storedQr->execute([$this->supplierId]);
            $stored = $storedQr->fetch(\PDO::FETCH_ASSOC);
            self::assertSame((int) !$beforeQr['purchase_invoice_qr_include_due_date'], (int) $stored['purchase_invoice_qr_include_due_date']);

            $audit->execute([$userId, 'supplier.payment_qr_settings_updated']);
            $auditPayload = (string) $audit->fetchColumn();
            $decodedAudit = json_decode($auditPayload, true) ?: [];
            $auditChanges = (array) ($decodedAudit['changes'] ?? []);
            self::assertArrayHasKey('purchase_invoice_qr_include_due_date', $auditChanges);
            self::assertArrayNotHasKey('invoice_qr_include_due_date', $auditChanges);
            self::assertSame(0, $decodedAudit['invalidated_invoice_pdfs'] ?? null);

            $qrMassAssignment = $this->request('PUT', '/api/settings/client/payment-qr', $session, [
                'company_name' => '__TEST forbidden QR settings change',
            ]);
            self::assertSame(403, $qrMassAssignment->getStatusCode());
            self::assertSame('field_not_delegable', $this->json($qrMassAssignment)['error']['code'] ?? null);

            $invalidQr = $this->request('PUT', '/api/settings/client/payment-qr', $session, [
                'invoice_qr_include_due_date' => 'yes',
            ]);
            self::assertSame(400, $invalidQr->getStatusCode());
            self::assertSame('validation_failed', $this->json($invalidQr)['error']['code'] ?? null);
        } finally {
            $this->db->pdo()->prepare(
                'UPDATE supplier
                    SET invoice_qr_include_due_date = ?, purchase_invoice_qr_include_due_date = ?
                  WHERE id = ?'
            )->execute([
                $beforeQr['invoice_qr_include_due_date'] ? 1 : 0,
                $beforeQr['purchase_invoice_qr_include_due_date'] ? 1 : 0,
                $this->supplierId,
            ]);
        }
    }

    public function testClientAdministratorManagesCompanySigningAndEmailLinkWithoutStaffAccess(): void
    {
        $reader = $this->createSession($this->createUser('signing_reader', 'client', 1, true));
        self::assertSame(403, $this->request('GET', '/api/settings/client/signing/profiles', $reader)->getStatusCode());
        $userId = $this->createUser('signing_admin', 'client', 2, true);
        $session = $this->createSession($userId);
        $created = $this->request('POST', '/api/settings/client/signing/profiles', $session, [
            'name' => '__TEST company signing', 'code' => 'client-signing-' . bin2hex(random_bytes(5)),
            'allowed_usages' => ['pdf', 'email_smime'], 'default_backend' => 'native', 'owner_user_id' => null,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $profile = $this->json($created);
        $id = (int) $profile['id'];
        $this->signingProfileIds[] = $id;
        self::assertNull($profile['owner_user_id']);

        $updated = $this->request('PUT', "/api/settings/client/signing/profiles/{$id}", $session, ['name' => '__TEST updated signing']);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('__TEST updated signing', $this->json($updated)['name']);
        $list = $this->json($this->request('GET', '/api/settings/client/signing/profiles', $session));
        self::assertContains($id, array_column($list, 'id'));

        $email = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST signed company email', 'code' => 'client-smime-' . bin2hex(random_bytes(5)),
            'from_email' => 'signing@example.test', 'transport_type' => 'global',
            'signing_profile_id' => $id, 'is_default' => false,
        ]);
        self::assertSame(201, $email->getStatusCode(), (string) $email->getBody());
        $emailId = (int) $this->json($email)['id'];
        $this->emailProfileIds[] = $emailId;
        self::assertSame($id, $this->json($email)['signing_profile_id']);
        self::assertTrue($this->json($email)['client_manageable']);
        self::assertSame(200, $this->request('PUT', "/api/settings/client/email-profiles/{$emailId}", $session, ['name' => '__TEST changed signed email'])->getStatusCode());
        self::assertSame(409, $this->request('DELETE', "/api/settings/client/signing/profiles/{$id}", $session)->getStatusCode());
        foreach ([['is_active' => false], ['allowed_usages' => ['pdf']]] as $change) {
            self::assertSame(200, $this->request('PUT', "/api/settings/client/signing/profiles/{$id}", $session, $change)->getStatusCode());
            $detached = $this->request('PUT', "/api/settings/client/email-profiles/{$emailId}", $session, ['signing_profile_id' => null]);
            self::assertSame(200, $detached->getStatusCode(), (string) $detached->getBody());
            self::assertSame(403, $this->request('PUT', "/api/settings/client/email-profiles/{$emailId}", $session, ['signing_profile_id' => $id])->getStatusCode());
            self::assertSame(200, $this->request('PUT', "/api/settings/client/signing/profiles/{$id}", $session, ['is_active' => true, 'allowed_usages' => ['pdf', 'email_smime']])->getStatusCode());
            self::assertSame(200, $this->request('PUT', "/api/settings/client/email-profiles/{$emailId}", $session, ['signing_profile_id' => $id])->getStatusCode());
        }

        foreach (['/api/settings/signing/profiles', '/api/settings/certificates', '/api/settings/pdf-signing/diagnostics'] as $path) {
            self::assertSame(403, $this->request('GET', $path, $session)->getStatusCode(), $path);
        }
        self::assertSame(403, $this->request('PUT', '/api/settings/signing', $session, ['accountant_profiles_enabled' => true])->getStatusCode());
        self::assertSame(200, $this->request('DELETE', "/api/settings/client/email-profiles/{$emailId}", $session)->getStatusCode());
        self::assertSame(200, $this->request('DELETE', "/api/settings/client/signing/profiles/{$id}", $session)->getStatusCode());
        self::assertSame(404, $this->request('GET', "/api/settings/client/signing/profiles/{$id}", $session)->getStatusCode());
    }

    public function testClientSigningRejectsPersonalProtectedAndForeignProfilesAndUnsafeFields(): void
    {
        $userId = $this->createUser('signing_isolation', 'client', 2, true);
        $session = $this->createSession($userId);
        $foreign = $this->db->pdo()->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT '__TEST isolated signing supplier', street, city, zip, country_id, email, default_currency_id, default_vat_rate_id
             FROM supplier WHERE id = ?"
        );
        $foreign->execute([$this->supplierId]);
        $foreignId = (int) $this->db->pdo()->lastInsertId();
        $this->supplierIds[] = $foreignId;
        $protected = [
            $this->createSigningProfile($this->supplierId, $userId),
            $this->createSigningProfile($this->supplierId, null, 'java'),
            $this->createSigningProfile($foreignId),
        ];
        $list = $this->json($this->request('GET', '/api/settings/client/signing/profiles', $session));
        foreach ($protected as $id) {
            self::assertNotContains($id, array_column($list, 'id'));
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                self::assertSame(404, $this->request($method, "/api/settings/client/signing/profiles/{$id}", $session, $method === 'PUT' ? ['name' => '__TEST forbidden'] : null)->getStatusCode());
            }
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                self::assertSame(404, $this->request($method, "/api/settings/client/signing/profiles/{$id}/credentials/certificate", $session, $method === 'PUT' ? ['password' => '__TEST'] : null)->getStatusCode());
            }
            $email = $this->request('POST', '/api/settings/client/email-profiles', $session, [
                'name' => '__TEST forbidden identity', 'code' => 'blocked-' . bin2hex(random_bytes(5)),
                'from_email' => 'signing@example.test', 'signing_profile_id' => $id,
            ]);
            self::assertSame(403, $email->getStatusCode());
        }
        foreach ([['owner_user_id' => $userId], ['default_backend' => 'java'], ['allowed_usages' => ['epo']], ['certificate_path' => '/tmp/private.p12']] as $fields) {
            $response = $this->request('POST', '/api/settings/client/signing/profiles', $session,
                $fields + ['name' => '__TEST forbidden fields', 'code' => 'blocked-' . bin2hex(random_bytes(5))]);
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function testClientSigningUploadsEncryptedCertificateAndConfiguresPdfAtomically(): void
    {
        $userId = $this->createUser('signing_certificate', 'client', 2, true);
        $session = $this->createSession($userId);
        $id = $this->createSigningProfile($this->supplierId);
        $path = "/api/settings/client/signing/profiles/{$id}/credentials/certificate";
        self::assertSame(403, $this->request('PUT', $path, $session, ['passphrase_policy' => 'passphrase_file', 'passphrase_profile_id' => 'private'])->getStatusCode());
        $password = '__TEST_PFX_' . bin2hex(random_bytes(8));
        $openssl = self::opensslConfigArgs();
        $key = openssl_pkey_new(['private_key_bits' => 2048] + $openssl);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Synthetic company', 'emailAddress' => 'signing@example.test'], $key, $openssl);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 2, $openssl);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));
        $upload = new \Slim\Psr7\UploadedFile((new StreamFactory())->createStream($pfx), 'synthetic.pfx', 'application/x-pkcs12', strlen($pfx));
        $uploaded = $this->request('POST', $path, $session, ['password' => $password, 'passphrase_policy' => 'encrypted_store'], ['file' => $upload]);
        self::assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        self::assertStringNotContainsString($password, (string) $uploaded->getBody());
        $credential = $this->db->pdo()->prepare('SELECT encrypted_passphrase, passphrase_policy FROM signing_credentials WHERE profile_id = ?');
        $credential->execute([$id]);
        $stored = $credential->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('encrypted_store', $stored['passphrase_policy']);
        self::assertNotSame($password, $stored['encrypted_passphrase']);
        self::assertNotEmpty($stored['encrypted_passphrase']);

        $outputs = $this->db->pdo()->prepare('SELECT * FROM pdf_signature_output_settings WHERE supplier_id = ?');
        $outputs->execute([$this->supplierId]);
        $this->originalSigningOutputs = $outputs->fetchAll(\PDO::FETCH_ASSOC);
        $data = ['enabled' => true, 'backend' => 'native', 'selection_source' => 'admin_profile_settings', 'default_profile_id' => $id, 'failure_policy' => 'fail_closed'];
        $saved = $this->request('PUT', '/api/settings/client/pdf-signing/output-settings', $session, ['settings' => [
            ['output_type' => 'invoice'] + $data, ['output_type' => 'work_report'] + $data,
        ]]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertCount(2, $this->json($saved)['output_settings']);
        $test = $this->request('POST', '/api/settings/client/pdf-signing/test', $session, ['output_type' => 'invoice']);
        self::assertSame(200, $test->getStatusCode(), (string) $test->getBody());
        self::assertSame('signed', $this->json($test)['status'] ?? null, (string) $test->getBody());
        self::assertSame('Synthetic company', $this->json($test)['certificate_cn'] ?? null);

        $profiles = new SigningProfileRepository($this->db);
        $profiles->upsertOutputSetting($this->supplierId, 'email_invoice_send', [
            'backend' => 'smime', 'default_profile_id' => $id,
            'signature_config' => ['smime_identity_policy' => 'strict_match'],
        ], 'email_smime');
        $emailPath = '/api/settings/client/pdf-signing/output-settings/email_invoice_send';
        $mismatch = $this->request('PUT', $emailPath, $session, ['output_type' => 'invoice', 'enabled' => true]);
        self::assertSame(403, $mismatch->getStatusCode());
        self::assertSame(['smime_identity_policy' => 'strict_match'], $profiles->outputSetting($this->supplierId, 'email_invoice_send', 'email_smime')['signature_config']);
        $roundtrip = $this->request('PUT', $emailPath, $session, ['enabled' => true, 'default_profile_id' => $id]);
        self::assertSame(200, $roundtrip->getStatusCode(), (string) $roundtrip->getBody());
        self::assertSame(['smime_identity_policy' => 'strict_match'], $this->json($roundtrip)['signature_config']);
        $before = $this->json($this->request('GET', '/api/settings/client/pdf-signing', $session));
        $invalidBatch = $this->request('PUT', '/api/settings/client/pdf-signing/output-settings', $session, ['settings' => [
            ['output_type' => 'invoice', 'enabled' => false, 'default_profile_id' => $id],
            ['output_type' => 'work_report', 'default_profile_id' => 2147483647],
        ]]);
        self::assertSame(403, $invalidBatch->getStatusCode());
        self::assertSame($before, $this->json($this->request('GET', '/api/settings/client/pdf-signing', $session)));
        foreach ([
            ['/api/settings/client/pdf-signing/output-settings', ['settings' => []], 400],
            ['/api/settings/client/pdf-signing/output-settings', ['settings' => [null]], 400],
            ['/api/settings/client/pdf-signing/output-settings', ['settings' => [['output_type' => 'unknown']]], 404],
            ['/api/settings/client/pdf-signing/output-settings/unknown', [], 404],
            ['/api/settings/client/pdf-signing/output-settings', ['settings' => [
                ['output_type' => 'invoice'] + $data, ['output_type' => 'invoice'] + $data,
            ]], 400],
        ] as [$settingsPath, $settingsBody, $status]) {
            self::assertSame($status, $this->request('PUT', $settingsPath, $session, $settingsBody)->getStatusCode());
        }
        self::assertSame($before, $this->json($this->request('GET', '/api/settings/client/pdf-signing', $session)));
        $inUse = $this->request('DELETE', "/api/settings/client/signing/profiles/{$id}", $session);
        self::assertSame(409, $inUse->getStatusCode(), (string) $inUse->getBody());
        self::assertSame(200, $this->request('GET', "/api/settings/client/signing/profiles/{$id}", $session)->getStatusCode());
        self::assertSame(200, $this->request('DELETE', $path, $session)->getStatusCode());
        self::assertFileDoesNotExist(RuntimePaths::storage("signing/profiles/supplier-{$this->supplierId}/profile-{$id}/profile.p12"));
    }

    public function testClientEmailTestCannotInvokeProtectedGlobalSigningFallback(): void
    {
        $session = $this->createSession($this->createUser('email_signing_fallback', 'client', 2, true));
        $this->app = Bootstrap::buildApp();
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplateDetailed');
        $this->app->getContainer()->set(Mailer::class, $mailer);
        $outputs = $this->db->pdo()->prepare('SELECT * FROM pdf_signature_output_settings WHERE supplier_id = ?');
        $outputs->execute([$this->supplierId]);
        $this->originalSigningOutputs = $outputs->fetchAll(\PDO::FETCH_ASSOC);
        $protectedId = $this->createSigningProfile($this->supplierId, null, 'java');
        $profiles = new SigningProfileRepository($this->db);
        $profiles->upsertOutputSetting($this->supplierId, 'email_invoice_send', [
            'enabled' => true, 'default_profile_id' => $protectedId, 'backend' => 'smime',
        ], 'email_smime');
        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST plain email', 'code' => 'fallback-' . bin2hex(random_bytes(5)),
            'from_email' => 'signing@example.test', 'transport_type' => 'global', 'is_default' => false,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->json($created)['id'];
        $this->emailProfileIds[] = $id;
        foreach ([
            ["/api/settings/client/email-profiles/{$id}/test", []],
            ['/api/settings/client/email-profiles/test', ['id' => $id, 'profile' => ['name' => '__TEST draft']]],
        ] as [$path, $body]) {
            $tested = $this->request('POST', $path, $session, $body);
            self::assertSame(403, $tested->getStatusCode(), (string) $tested->getBody());
            self::assertSame('profile_not_delegable', $this->json($tested)['error']['code'] ?? null);
        }
        $profiles->upsertOutputSetting($this->supplierId, 'email_invoice_send', [
            'enabled' => true, 'selection_source' => 'logged_in_user', 'backend' => 'smime',
        ], 'email_smime');
        self::assertSame(403, $this->request('POST', "/api/settings/client/email-profiles/{$id}/test", $session, [])->getStatusCode());
    }

    private function createSigningProfile(int $supplierId, ?int $ownerUserId = null, string $backend = 'native'): int
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO signing_profiles (supplier_id, owner_user_id, name, code, allowed_usages_json, default_backend, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $insert->execute([$supplierId, $ownerUserId, '__TEST company signing', 'test-' . bin2hex(random_bytes(5)), '["pdf","email_smime"]', $backend]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->signingProfileIds[] = $id;
        return $id;
    }

    public function testStaffMayUpdatePaymentQrSettingsThroughSupplierSettings(): void
    {
        $userId = $this->createUser('staff_payment_qr_settings', 'staff', 2, true);
        $session = $this->createSession($userId);
        $current = $this->request('GET', '/api/settings/supplier', $session);
        self::assertSame(200, $current->getStatusCode(), (string) $current->getBody());
        $before = $this->json($current);
        $audit = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $auditCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = ?'
        );

        try {
            $updated = $this->request('PUT', '/api/settings/supplier', $session, [
                'purchase_invoice_qr_include_due_date' => !$before['purchase_invoice_qr_include_due_date'],
            ]);
            self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
            $body = $this->json($updated);
            self::assertSame(
                !$before['purchase_invoice_qr_include_due_date'],
                $body['purchase_invoice_qr_include_due_date'] ?? null,
            );
            self::assertSame(
                $before['invoice_qr_include_due_date'],
                $body['invoice_qr_include_due_date'] ?? null,
            );

            $audit->execute([$userId, 'supplier.payment_qr_settings_updated']);
            $payload = json_decode((string) $audit->fetchColumn(), true) ?: [];
            self::assertSame(0, $payload['invalidated_invoice_pdfs'] ?? null);
            self::assertArrayHasKey(
                'purchase_invoice_qr_include_due_date',
                (array) ($payload['changes'] ?? []),
            );

            $auditCount->execute([$userId, 'supplier.payment_qr_settings_updated']);
            $countAfterChange = (int) $auditCount->fetchColumn();
            $unchanged = $this->request('PUT', '/api/settings/supplier', $session, [
                'purchase_invoice_qr_include_due_date' => !$before['purchase_invoice_qr_include_due_date'],
            ]);
            self::assertSame(200, $unchanged->getStatusCode(), (string) $unchanged->getBody());
            $auditCount->execute([$userId, 'supplier.payment_qr_settings_updated']);
            self::assertSame(
                $countAfterChange,
                (int) $auditCount->fetchColumn(),
                'Uložení stejné hodnoty nesmí invalidovat PDF ani vytvořit audit změny.',
            );
        } finally {
            $this->db->pdo()->prepare(
                'UPDATE supplier
                    SET invoice_qr_include_due_date = ?, purchase_invoice_qr_include_due_date = ?
                  WHERE id = ?'
            )->execute([
                $before['invoice_qr_include_due_date'] ? 1 : 0,
                $before['purchase_invoice_qr_include_due_date'] ? 1 : 0,
                $this->supplierId,
            ]);
        }
    }

    /**
     * Deaktivovaný odesílací profil nesmí zablokovat úpravu jiného pole
     * brandingového profilu. Editor posílá profil zpátky celý a nabídka je
     * filtrovaná na aktivní, takže by to nešlo spravit ani ručně.
     */
    public function testStaleEmailProfileLinkDoesNotBlockUnrelatedBrandingEdits(): void
    {
        $userId = $this->createUser('staff_branding_stale', 'staff', 2, true);
        $session = $this->createSession($userId);
        $pdo = $this->db->pdo();

        $flag = $pdo->prepare('SELECT branding_profiles_enabled FROM supplier WHERE id = ?');
        $flag->execute([$this->supplierId]);
        $previousFlag = (int) $flag->fetchColumn();
        $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);

        $brandingId = null;
        try {
            $email = $this->request('POST', '/api/settings/email-profiles', $session, [
                'name' => '__TEST branding link source',
                'code' => 'branding-link-' . bin2hex(random_bytes(5)),
                'from_email' => 'branding-link@example.test',
                'transport_type' => 'global',
                'is_default' => false,
                'is_active' => true,
            ]);
            self::assertSame(201, $email->getStatusCode(), (string) $email->getBody());
            $emailProfileId = (int) ($this->json($email)['id'] ?? 0);
            $this->emailProfileIds[] = $emailProfileId;

            $branding = $this->request('POST', '/api/settings/branding-profiles', $session, [
                'name' => '__TEST branding stale link',
                'code' => 'brand-stale-' . bin2hex(random_bytes(5)),
                'email_profile_id' => $emailProfileId,
            ]);
            self::assertSame(201, $branding->getStatusCode(), (string) $branding->getBody());
            $brandingId = (int) ($this->json($branding)['id'] ?? 0);
            self::assertGreaterThan(0, $brandingId);

            $pdo->prepare('UPDATE email_profiles SET is_active = 0 WHERE id = ?')->execute([$emailProfileId]);

            $kept = $this->request('PUT', "/api/settings/branding-profiles/{$brandingId}", $session, [
                'name' => '__TEST branding stale link renamed',
                'email_profile_id' => $emailProfileId,
            ]);
            self::assertSame(200, $kept->getStatusCode(), (string) $kept->getBody());
            self::assertSame('__TEST branding stale link renamed', $this->json($kept)['name'] ?? null);

            // Přepnout vazbu na JINÝ neaktivní profil se ale dál nesmí.
            $moved = $this->request('PUT', "/api/settings/branding-profiles/{$brandingId}", $session, [
                'email_profile_id' => $emailProfileId + 100000,
            ]);
            self::assertSame(400, $moved->getStatusCode());
            self::assertSame('validation_failed', $this->json($moved)['error']['code'] ?? null);
        } finally {
            if ($brandingId !== null && $brandingId > 0) {
                $pdo->prepare('DELETE FROM branding_profiles WHERE id = ?')->execute([$brandingId]);
            }
            $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = ? WHERE id = ?')
                ->execute([$previousFlag, $this->supplierId]);
        }
    }

    /**
     * Výběr brandingového profilu v kartě klienta čte `/api/branding-profiles`
     * (stačí `profile`), takže ho účetní bez `settings.branding` dál má. Správa
     * profilů včetně jejich úplného výpisu zůstává za `settings.branding`.
     */
    public function testAccountantKeepsBrandingProfilePickerButCannotManageProfiles(): void
    {
        $pdo = $this->db->pdo();
        $accountantRole = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'accountant'")->fetchColumn();
        if ($accountantRole <= 0) self::markTestSkipped('Chybí systémová role účetní.');
        $grants = $pdo->prepare('SELECT permission_key, access_level FROM role_permissions WHERE role_id = ?');
        $grants->execute([$accountantRole]);
        $grants = $grants->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertGreaterThanOrEqual(1, (int) ($grants['clients'] ?? 0), 'Účetní pracuje s klienty.');
        self::assertArrayNotHasKey('settings.branding', $grants, 'Test ověřuje účetní bez práva spravovat branding.');

        $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([
                '__test_rbac_matrix_' . bin2hex(random_bytes(6)) . '@example.test',
                '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
                '__TEST RBAC accountant', 'readonly', $accountantRole, 'cs',
            ]);
        $accountantId = (int) $pdo->lastInsertId();
        $this->userIds[] = $accountantId;
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$accountantId, $this->supplierId]);
        $accountant = $this->createSession($accountantId);
        $admin = $this->createSession($this->createUser('superadmin', 'superadmin', 2, true));

        $flag = $pdo->prepare('SELECT branding_profiles_enabled FROM supplier WHERE id = ?');
        $flag->execute([$this->supplierId]);
        $previousFlag = (int) $flag->fetchColumn();
        $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $brandingId = 0;
        try {
            $created = $this->request('POST', '/api/settings/branding-profiles', $admin, [
                'name' => '__TEST branding picker',
                'code' => 'brand-picker-' . bin2hex(random_bytes(5)),
            ]);
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
            $brandingId = (int) ($this->json($created)['id'] ?? 0);

            $picker = $this->request('GET', '/api/branding-profiles', $accountant);
            self::assertSame(200, $picker->getStatusCode(), (string) $picker->getBody());
            self::assertContains($brandingId, array_map(static fn (array $row): int => (int) $row['id'], $this->json($picker)));

            $denied = [
                ['GET', '/api/settings/branding-profiles', null],
                ['POST', '/api/settings/branding-profiles', ['name' => '__TEST denied', 'code' => 'brand-denied-' . bin2hex(random_bytes(4))]],
                ['PUT', "/api/settings/branding-profiles/{$brandingId}", ['name' => '__TEST denied rename']],
                ['POST', "/api/settings/branding-profiles/{$brandingId}/default", []],
                ['DELETE', "/api/settings/branding-profiles/{$brandingId}", null],
            ];
            foreach ($denied as [$method, $path, $body]) {
                $this->assertPermissionDecision($this->request($method, $path, $accountant, $body), false, "accountant: {$method} {$path}");
            }
            $name = $pdo->prepare('SELECT name FROM branding_profiles WHERE id = ?');
            $name->execute([$brandingId]);
            self::assertSame('__TEST branding picker', $name->fetchColumn());
        } finally {
            if ($brandingId > 0) $pdo->prepare('DELETE FROM branding_profiles WHERE id = ?')->execute([$brandingId]);
            $pdo->prepare("DELETE FROM branding_profiles WHERE supplier_id = ? AND name = '__TEST denied'")->execute([$this->supplierId]);
            $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = ? WHERE id = ?')->execute([$previousFlag, $this->supplierId]);
        }
    }

    /**
     * Zákaz editace staff profilu musí platit i „zvenčí": klient si nesmí
     * vlastním profilem s `is_default` sesadit profil s S/MIME nebo sendmailem.
     */
    public function testClientCannotTakeOverTheDefaultFromASigningProfile(): void
    {
        $userId = $this->createUser('client_default_takeover', 'client', 2, true);
        $session = $this->createSession($userId);

        $protectedId = $this->createStaffOnlyDefaultProfile();
        $this->app = Bootstrap::buildApp();
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplateDetailed');
        $this->app->getContainer()->set(Mailer::class, $mailer);
        $draft = $this->request('POST', '/api/settings/client/email-profiles/test', $session, [
            'id' => $protectedId,
            'profile' => ['signing_profile_id' => null, 'transport_type' => 'global'],
        ]);
        self::assertSame(403, $draft->getStatusCode());
        self::assertSame('profile_not_delegable', $this->json($draft)['error']['code'] ?? null);

        $takeover = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client takeover',
            'code' => 'client-takeover-' . bin2hex(random_bytes(5)),
            'from_email' => 'takeover@example.test',
            'transport_type' => 'global',
            'is_default' => true,
            'is_active' => true,
        ]);
        self::assertSame(403, $takeover->getStatusCode());
        self::assertSame('profile_not_delegable', $this->json($takeover)['error']['code'] ?? null);

        // Bez `is_default` je založení profilu v pořádku — omezení míří na
        // převzetí výchozí pozice, ne na samotné zakládání.
        $plain = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client secondary',
            'code' => 'client-secondary-' . bin2hex(random_bytes(5)),
            'from_email' => 'secondary@example.test',
            'transport_type' => 'global',
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $plain->getStatusCode(), (string) $plain->getBody());
        $this->emailProfileIds[] = (int) ($this->json($plain)['id'] ?? 0);
    }

    /**
     * Prázdné `signing_profile_id` je round-trip celého profilu, ne pokus
     * o delegaci podpisu — API ho nesmí odmítat.
     */
    public function testClientMayRoundTripAnEmptySigningProfileField(): void
    {
        $userId = $this->createUser('client_signing_roundtrip', 'client', 2, true);
        $session = $this->createSession($userId);

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client roundtrip',
            'code' => 'client-roundtrip-' . bin2hex(random_bytes(5)),
            'from_email' => 'roundtrip@example.test',
            'transport_type' => 'global',
            'signing_profile_id' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->emailProfileIds[] = (int) ($this->json($created)['id'] ?? 0);

        $withValue = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client signing attempt',
            'code' => 'client-signing-' . bin2hex(random_bytes(5)),
            'from_email' => 'signing@example.test',
            'transport_type' => 'global',
            'signing_profile_id' => 2147483647,
        ]);
        self::assertSame(403, $withValue->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($withValue)['error']['code'] ?? null);
    }

    /**
     * Uložené SMTP heslo API nevrací — a nesmí ho jít ani „vytáhnout" tím, že si
     * klient přepíše host na vlastní server a pole hesla nechá prázdné.
     */
    public function testClientCannotForwardStoredSmtpSecretToItsOwnServer(): void
    {
        $userId = $this->createUser('client_secret_forward', 'client', 2, true);
        $session = $this->createSession($userId);
        $code = 'client-fwd-' . bin2hex(random_bytes(5));

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client smtp profile',
            'code' => $code,
            'from_email' => 'forward@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-legit.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '__TEST_SMTP_SECRET_' . bin2hex(random_bytes(8)),
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $profileId = (int) ($this->json($created)['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);
        $this->emailProfileIds[] = $profileId;

        $redirectedSave = $this->request('PUT', "/api/settings/client/email-profiles/{$profileId}", $session, [
            'transport_type' => 'smtp',
            'smtp_host' => 'attacker.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '',
        ]);
        self::assertSame(400, $redirectedSave->getStatusCode());
        self::assertSame('validation_failed', $this->json($redirectedSave)['error']['code'] ?? null);

        $redirectedTest = $this->request('POST', '/api/settings/client/email-profiles/test', $session, [
            'id' => $profileId,
            'name' => '__TEST redirected draft',
            'code' => $code . '-draft',
            'from_email' => 'forward@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'attacker.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '',
        ]);
        self::assertSame(400, $redirectedTest->getStatusCode());
        self::assertSame('validation_failed', $this->json($redirectedTest)['error']['code'] ?? null);

        $stored = $this->request('GET', '/api/settings/client/email-profiles', $session);
        self::assertSame(200, $stored->getStatusCode());
        $row = null;
        foreach ($this->json($stored) as $profile) {
            if (is_array($profile) && (int) ($profile['id'] ?? 0) === $profileId) $row = $profile;
        }
        self::assertIsArray($row);
        self::assertSame('smtp-legit.example.test', $row['smtp_host'] ?? null);
        self::assertArrayNotHasKey('smtp_password', $row);
        self::assertTrue($row['has_smtp_password'] ?? false);
    }

    /**
     * Výchozí profil, který klient spravovat nesmí. Přednost má vazba na
     * S/MIME; bez podpisového profilu v instalaci zastoupí systémový sendmail,
     * který spadá pod stejnou pojistku ({@see EmailProfilesAction}).
     */
    private function createStaffOnlyDefaultProfile(): int
    {
        $pdo = $this->db->pdo();
        $signing = $pdo->prepare("SELECT id FROM signing_profiles WHERE supplier_id = ? AND (owner_user_id IS NOT NULL OR default_backend <> 'native') LIMIT 1");
        $signing->execute([$this->supplierId]);
        $signingId = $signing->fetchColumn();

        $pdo->prepare('UPDATE email_profiles SET is_default = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $insert = $pdo->prepare(
            'INSERT INTO email_profiles
                (supplier_id, name, code, from_email, signing_profile_id, transport_type, sendmail_command, is_default, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $insert->execute([
            $this->supplierId,
            '__TEST staff only default',
            'staff-only-' . bin2hex(random_bytes(5)),
            'staff-only@example.test',
            $signingId === false ? null : (int) $signingId,
            $signingId === false ? 'sendmail' : 'global',
            $signingId === false ? '/usr/sbin/sendmail -bs' : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->emailProfileIds[] = $id;
        return $id;
    }

    #[DataProvider('roleMatrix')]
    public function testCompanyEmailTemplateOperationsUseEffectivePermissions(string $variant, string $roleType, int $level, bool $hasSupplier): void
    {
        $session = $this->createSession($this->createUser($variant, $roleType, $level, $hasSupplier));
        $this->backupTemplate('supplier_email_templates', ['supplier_id' => $this->supplierId, 'code' => 'invoice_send', 'locale' => 'cs']);
        $template = ['subject' => 'Synthetic {{ invoice.varsymbol }}', 'body_html' => '<p>Synthetic {{ invoice.varsymbol }}</p>', 'body_text' => 'Synthetic {{ invoice.varsymbol }}'];
        foreach (['/api/settings/email-templates', '/api/settings/client/email-templates'] as $prefix) {
            $allowed = ($variant === 'superadmin') || ($hasSupplier && $level === 2);
            foreach ([['GET', '', null], ['GET', '/invoice_send/cs', null], ['POST', '/invoice_send/cs/preview', $template], ['PUT', '/invoice_send/cs', $template], ['DELETE', '/invoice_send/cs', null]] as [$method, $suffix, $body]) {
                $response = $this->request($method, $prefix . $suffix, $session, $body);
                self::assertSame($allowed ? 200 : 403, $response->getStatusCode(), "$variant $method $prefix$suffix: " . $response->getBody());
            }
        }
        if ($variant === 'superadmin' || ($hasSupplier && $level === 2)) {
            $prefix = $roleType === 'client' ? '/api/settings/client/email-templates' : '/api/settings/email-templates';
            foreach (['GET', 'PUT', 'DELETE', 'POST'] as $method) {
                $suffix = $method === 'POST' ? '/preview' : '';
                self::assertSame(404, $this->request($method, $prefix . '/password_reset/cs' . $suffix, $session, $template)->getStatusCode());
            }
        }
    }

    public function testCompanyEmailTemplateIsolationResetPreviewAndInvalidSave(): void
    {
        $suppliers = array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(2, $suppliers, 'Integration fixtures must include two synthetic companies.');
        [$supplierA, $supplierB] = $suppliers;
        foreach ($suppliers as $sid) $this->backupTemplate('supplier_email_templates', ['supplier_id' => $sid, 'code' => 'invoice_send', 'locale' => 'cs']);
        $this->backupTemplate('email_templates', ['code' => 'invoice_send', 'locale' => 'cs']);
        $session = $this->createSession($this->createUser('superadmin', 'superadmin', 2, true));
        $path = '/api/settings/email-templates/invoice_send/cs';
        $a = ['subject' => 'Company A', 'body_html' => '<p>A {{ invoice.varsymbol }}</p>', 'body_text' => 'A {{ invoice.varsymbol }}'];
        $b = array_replace($a, ['subject' => 'Company B']);
        self::assertSame(200, $this->request('PUT', $path, $session, $a, supplierId: $supplierA)->getStatusCode());
        self::assertSame(200, $this->request('PUT', $path, $session, $b, supplierId: $supplierB)->getStatusCode());
        self::assertSame('Company A', $this->json($this->request('GET', $path, $session, null, supplierId: $supplierA))['subject']);
        self::assertSame('Company B', $this->json($this->request('GET', $path, $session, null, supplierId: $supplierB))['subject']);
        $invalid = array_replace($a, ['subject' => '{% include "_layout.html.twig" %}']);
        self::assertSame(400, $this->request('PUT', $path, $session, $invalid, supplierId: $supplierA)->getStatusCode());
        self::assertSame('Company A', $this->json($this->request('GET', $path, $session, null, supplierId: $supplierA))['subject']);
        $preview = $this->json($this->request('POST', $path . '/preview', $session, array_replace($a, ['subject' => 'Unsaved preview']), supplierId: $supplierA));
        self::assertSame('Unsaved preview', $preview['subject']);
        self::assertStringContainsString('20260001', $preview['body_html']);
        self::assertSame('Company A', $this->json($this->request('GET', $path, $session, null, supplierId: $supplierA))['subject']);
        $global = new \MyInvoice\Repository\EmailTemplateRepository($this->db);
        $global->save('invoice_send', 'cs', 'Global replacement', '<p>Shared</p>', 'Shared', null);
        self::assertSame(200, $this->request('DELETE', $path, $session, null, supplierId: $supplierA)->getStatusCode());
        $inherited = $this->json($this->request('GET', $path, $session, null, supplierId: $supplierA));
        self::assertSame('global', $inherited['source']);
        self::assertSame('Global replacement', $inherited['subject']);
        self::assertFalse($inherited['has_override']);
        self::assertSame('Company B', $this->json($this->request('GET', $path, $session, null, supplierId: $supplierB))['subject']);
        self::assertSame('Global replacement', $global->find('invoice_send', 'cs')['subject']);
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND supplier_id = ? AND action IN ('supplier_email_template.saved', 'supplier_email_template.reset')");
        $audit->execute([$this->userIds[0], $supplierA]);
        self::assertSame(2, (int) $audit->fetchColumn());
    }

    public function testCompanyEmailTemplatesRejectForeignAndStaleSupplierForEveryOperation(): void
    {
        $session = $this->createSession($this->createUser('client_write', 'client', 2, true));
        $foreign = (int) $this->db->pdo()->query('SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $foreign);
        $template = ['subject' => 'Unsafe stale draft', 'body_html' => '<p>Example</p>', 'body_text' => 'Example'];
        foreach ([$foreign, 4294967295] as $sid) {
            foreach ([['GET', ''], ['GET', '/invoice_send/cs'], ['PUT', '/invoice_send/cs'], ['DELETE', '/invoice_send/cs'], ['POST', '/invoice_send/cs/preview']] as [$method, $suffix]) {
                self::assertSame(403, $this->request($method, '/api/settings/client/email-templates' . $suffix, $session, $method === 'GET' ? null : $template, supplierId: $sid)->getStatusCode());
            }
        }
    }

    public function testCompanyTemplateStaffSupplierRoleOverrideIsAuthoritative(): void
    {
        $writer = $this->createUser('writer', 'staff', 2, true);
        $reader = $this->createUser('reader', 'staff', 1, true);
        $writerRole = (int) $this->db->pdo()->query('SELECT role_id FROM users WHERE id = ' . $writer)->fetchColumn();
        $readerRole = (int) $this->db->pdo()->query('SELECT role_id FROM users WHERE id = ' . $reader)->fetchColumn();
        $stmt = $this->db->pdo()->prepare('UPDATE user_suppliers SET role_id = ? WHERE user_id = ? AND supplier_id = ?');
        $stmt->execute([$writerRole, $reader, $this->supplierId]);
        $stmt->execute([$readerRole, $writer, $this->supplierId]);
        $readerSession = $this->createSession($reader);
        $writerSession = $this->createSession($writer);
        self::assertSame(200, $this->request('GET', '/api/settings/email-templates', $readerSession)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/settings/email-templates', $writerSession)->getStatusCode());
    }

    private function backupTemplate(string $table, array $keys): void
    {
        $where = implode(' AND ', array_map(static fn ($key) => "$key = ?", array_keys($keys)));
        $stmt = $this->db->pdo()->prepare("SELECT * FROM $table WHERE $where");
        $stmt->execute(array_values($keys));
        $this->templateBackups[] = [$table, $keys, $stmt->fetch(\PDO::FETCH_ASSOC)];
    }

    private function createUser(string $variant, string $roleType, int $level, bool $hasSupplier): int
    {
        if ($variant === 'superadmin') {
            $roleId = (int) $this->db->pdo()->query("SELECT id FROM roles WHERE system_key = 'superadmin'")->fetchColumn();
        } else {
            $stmt = $this->db->pdo()->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)');
            $stmt->execute(['__TEST RBAC ' . $variant . ' ' . bin2hex(random_bytes(3)), $roleType]);
            $roleId = (int) $this->db->pdo()->lastInsertId();
            $this->roleIds[] = $roleId;
            $keys = $roleType === 'client'
                ? ['clients', 'clients.create', 'invoices', 'invoices.send', 'purchase_invoices', 'purchase_invoices.create', 'settings.company', 'settings.branding', 'settings.email_profiles', 'settings.email_templates', 'settings.payment_qr', 'settings.signing_profiles', 'settings.invoice_defaults']
                : ['clients', 'clients.create', 'invoices', 'invoices.send', 'purchase_invoices', 'purchase_invoices.create', 'documents', 'bank', 'accounting', 'reports', 'settings.company', 'settings.company.write', 'settings.branding', 'settings.email_profiles', 'settings.email_templates', 'settings.payment_qr', 'settings.invoice_defaults'];
            $insert = $this->db->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
            foreach ($keys as $key) $insert->execute([$roleId, $key, $level]);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            '__test_rbac_matrix_' . bin2hex(random_bytes(6)) . '@example.test',
            '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
            '__TEST RBAC matrix',
            $roleType === 'client' ? 'client' : ($variant === 'superadmin' ? 'admin' : 'readonly'),
            $roleId,
            'cs',
        ]);
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $userId;
        if ($hasSupplier && !$this->isSuperadminRole($roleId)) {
            $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')
                ->execute([$userId, $this->supplierId]);
        }
        return $userId;
    }

    private function isSuperadminRole(int $roleId): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM roles WHERE id = ? AND system_key = 'superadmin'");
        $stmt->execute([$roleId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array{token:string,csrf_token:string} */
    private function createSession(int $userId): array
    {
        $session = $this->sessions->create($userId, '127.0.0.1', '__test_rbac_matrix');
        $this->sessionTokens[] = (string) $session['token'];
        return ['token' => (string) $session['token'], 'csrf_token' => (string) $session['csrf_token']];
    }

    private function request(string $method, string $path, array $session, ?array $body = null, array $uploads = [], ?int $supplierId = null): ResponseInterface
    {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', rtrim((string) $this->config->get('app.url', ''), '/'))
            ->withHeader('X-CSRF-Token', $session['csrf_token'])
            ->withHeader('X-Supplier-Id', (string) ($supplierId ?? $this->supplierId));
        if ($uploads !== []) {
            $request = $request->withUploadedFiles($uploads)->withParsedBody($body ?? []);
        } elseif ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(
                (new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)),
            );
        }
        return ($this->app ??= Bootstrap::buildApp())->handle($request);
    }

    private function assertPermissionDecision(ResponseInterface $response, bool $allowed, string $message): void
    {
        $code = $this->json($response)['error']['code'] ?? null;
        if ($allowed) {
            self::assertNotContains($code, ['forbidden_permission', 'forbidden_supplier'], $message . ': ' . (string) $response->getBody());
            return;
        }
        self::assertSame(403, $response->getStatusCode(), $message);
        self::assertContains($code, ['forbidden_permission', 'forbidden_supplier'], $message);
    }

    private function clientCount(string $email): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND main_email = ?');
        $stmt->execute([$this->supplierId, $email]);
        return (int) $stmt->fetchColumn();
    }

    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}

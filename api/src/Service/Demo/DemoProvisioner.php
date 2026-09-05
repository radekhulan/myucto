<?php

declare(strict_types=1);

namespace MyInvoice\Service\Demo;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\JournalEntryTemplateRepository;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateSeeder;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Sample\SampleDataGenerator;
use MyInvoice\Service\Sample\SampleDataService;
use PDO;

final class DemoProvisioner
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly RoleRepository $roles,
        private readonly UserSupplierRepository $userSuppliers,
        private readonly PasswordHasher $passwords,
        private readonly AccountingModeRepository $accountingModes,
        private readonly JournalEntryTemplateRepository $journalTemplates,
        private readonly SampleDataGenerator $sampleGenerator,
        private readonly SampleDataService $sampleData,
    ) {}

    /** @return array{role_id:int,user_id:int,supplier_ids:list<int>,generated:list<int>,refreshed:list<int>,skipped:list<int>} */
    public function provision(bool $refreshSample = false): array
    {
        $this->assertSafeTarget();
        $this->assertSchemaReady();

        $roleId = $this->ensureRole();
        $userId = $this->ensureUser($roleId);
        $supplierIds = [
            $this->ensureSupplier([
                'company_name' => 'Demo obchodní s.r.o.',
                'email' => 'demo-po@tenant.example.invalid',
                'street' => 'Ukázková 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'taxpayer_type' => 'po',
                'is_vat_payer' => true,
            ]),
            $this->ensureSupplier([
                'company_name' => 'Demo OSVČ',
                'email' => 'demo-osvc@tenant.example.invalid',
                'street' => 'Modelová 25',
                'city' => 'Brno',
                'zip' => '602 00',
                'taxpayer_type' => 'fo',
                'is_vat_payer' => false,
            ]),
        ];

        $desiredAssignments = array_map(
            static fn (int $supplierId): array => ['supplier_id' => $supplierId, 'role_id' => $roleId],
            $supplierIds,
        );
        $currentAssignments = $this->userSuppliers->assignmentsForUser($userId);
        $desiredMap = array_fill_keys($supplierIds, $roleId);
        ksort($currentAssignments);
        ksort($desiredMap);
        if ($currentAssignments !== $desiredMap) {
            $this->userSuppliers->replaceForUser($userId, $desiredAssignments);
        }

        $generated = [];
        $refreshed = [];
        $skipped = [];
        foreach ($supplierIds as $supplierId) {
            if ($refreshSample && $this->sampleData->hasSampleData($supplierId)) {
                $this->sampleData->purge($supplierId);
                $refreshed[] = $supplierId;
            }
            if ($this->sampleData->hasSampleData($supplierId)) {
                $skipped[] = $supplierId;
                continue;
            }
            $this->sampleGenerator->generate($supplierId, $userId);
            $generated[] = $supplierId;
        }

        foreach ($supplierIds as $supplierId) {
            if (!$this->accountingModes->hasDoubleEntry($supplierId)) continue;
            $this->journalTemplates->ensurePayrollSeed($supplierId);
            $this->journalTemplates->ensureClosingTemplatesSeed($supplierId);
        }

        $this->ensureDemoBankVisibility($supplierIds);
        $this->disableOutboundAutomation($supplierIds);

        return [
            'role_id' => $roleId,
            'user_id' => $userId,
            'supplier_ids' => $supplierIds,
            'generated' => $generated,
            'refreshed' => $refreshed,
            'skipped' => $skipped,
        ];
    }

    private function assertSafeTarget(): void
    {
        if (!(new \MyInvoice\Service\System\ManagedModeGuard($this->config))->effectiveFlag(
            \MyInvoice\Service\System\ManagedModeGuard::KEY_DEMO_ENABLED,
            $this->config->get('demo.enabled', false) === true,
        )) {
            throw new \RuntimeException('Demo provisioning vyžaduje demo.enabled=true.');
        }
        $expectedDatabase = trim((string) $this->config->get('demo.expected_database', ''));
        $actualDatabase = trim((string) $this->config->get('db.name', ''));
        if ($expectedDatabase === '' || $actualDatabase !== $expectedDatabase) {
            throw new \RuntimeException('Demo provisioning odmítnut: db.name neodpovídá demo.expected_database.');
        }
        $expectedHost = strtolower(trim((string) $this->config->get('demo.expected_host', '')));
        $actualHost = strtolower((string) (parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST) ?: ''));
        if ($expectedHost === '' || $actualHost !== $expectedHost) {
            throw new \RuntimeException('Demo provisioning odmítnut: app.url neodpovídá demo.expected_host.');
        }
    }

    private function assertSchemaReady(): void
    {
        try {
            $this->db->pdo()->query('SELECT 1 FROM roles LIMIT 1');
            $this->db->pdo()->query('SELECT 1 FROM supplier LIMIT 1');
            $this->db->pdo()->query('SELECT 1 FROM sample_data_entries LIMIT 1');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Demo databáze nemá aktuální schéma. Spusťte nejdřív api/bin/migrate.php.', 0, $e);
        }
    }

    private function ensureRole(): int
    {
        $readonly = $this->roles->findBySystemKey('readonly');
        $demo = null;
        foreach ($this->roles->list() as $role) {
            if (mb_strtolower((string) $role['name']) === 'demo') {
                if ($demo !== null) throw new \RuntimeException('V databázi je více rolí se jménem Demo.');
                $demo = $this->roles->find((int) $role['id']);
            }
        }
        if ($readonly === null) throw new \RuntimeException('Systémová role readonly nebyla nalezena.');
        $permissions = (array) $readonly['permissions'];
        $permissions['settings.branding'] = AccessLevel::READ->value;
        // Bez tohohle klíče vrátí GET /settings/currencies 403 a záložka „Měny a účty"
        // v ukázce spadne hned při mountu. Zápis stejně drží DemoReadOnlyMiddleware.
        $permissions['settings.bank_accounts'] = AccessLevel::READ->value;

        if ($demo === null) {
            return (int) $this->roles->create('Demo', 'staff', $permissions)['id'];
        }
        if (($demo['system_key'] ?? null) !== null || ($demo['role_type'] ?? null) !== 'staff') {
            throw new \RuntimeException('Jméno Demo už používá nekompatibilní role.');
        }
        $currentPermissions = (array) $demo['permissions'];
        ksort($permissions);
        ksort($currentPermissions);
        if (!$demo['is_active'] || $currentPermissions !== $permissions) {
            $demo = $this->roles->update(
                (int) $demo['id'],
                'Demo',
                true,
                $permissions,
                (string) $demo['updated_at'],
            );
        }
        return (int) $demo['id'];
    }

    private function ensureUser(int $roleId): int
    {
        $email = mb_strtolower(trim((string) $this->config->get('demo.login_email', '')));
        $password = (string) $this->config->get('demo.login_password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('demo.login_email není platný e-mail.');
        $this->passwords->validate($password);

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, password_hash, name, role_id, locale, is_active, totp_enabled
               FROM users WHERE LOWER(email) = LOWER(?) LIMIT 2'
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) throw new \RuntimeException('Demo e-mail odpovídá více uživatelům.');

        if ($rows === []) {
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, totp_secret, totp_enabled, name, role, role_id, locale, is_active)
                 VALUES (?, ?, NULL, 0, ?, \'readonly\', ?, \'cs\', 1)'
            );
            $insert->execute([$email, $this->passwords->hash($password), 'MyÚčto Demo', $roleId]);
            return (int) $this->db->pdo()->lastInsertId();
        }

        $user = $rows[0];
        if ((string) $user['name'] !== 'MyÚčto Demo' && (int) $user['role_id'] !== $roleId) {
            throw new \RuntimeException('Demo e-mail už používá jiný účet; provisioning jej nepřevezme.');
        }
        $newHash = null;
        if (!$this->passwords->verify($password, (string) $user['password_hash'])
            || $this->passwords->needsRehash((string) $user['password_hash'])
        ) {
            $newHash = $this->passwords->hash($password);
        }
        $changed = $newHash !== null
            || (string) $user['email'] !== $email
            || (string) $user['name'] !== 'MyÚčto Demo'
            || (int) $user['role_id'] !== $roleId
            || (string) $user['locale'] !== 'cs'
            || !(bool) $user['is_active']
            || (bool) $user['totp_enabled'];
        if ($changed) {
            $update = $this->db->pdo()->prepare(
                'UPDATE users
                    SET email = ?, password_hash = COALESCE(?, password_hash), totp_secret = NULL, totp_enabled = 0,
                        name = ?, role = \'readonly\', role_id = ?, locale = \'cs\', is_active = 1
                  WHERE id = ?'
            );
            $update->execute([$email, $newHash, 'MyÚčto Demo', $roleId, (int) $user['id']]);
            foreach (['sessions', 'password_resets', 'login_otps', 'trusted_devices'] as $table) {
                $this->db->pdo()->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([(int) $user['id']]);
            }
        }
        return (int) $user['id'];
    }

    /** @param array{company_name:string,email:string,street:string,city:string,zip:string,taxpayer_type:string,is_vat_payer:bool} $data */
    private function ensureSupplier(array $data): int
    {
        $find = $this->db->pdo()->prepare('SELECT id, company_name FROM supplier WHERE email = ? ORDER BY id');
        $find->execute([$data['email']]);
        $rows = $find->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) throw new \RuntimeException("Více demo firem používá e-mail {$data['email']}.");
        if ($rows !== []) {
            if ((string) $rows[0]['company_name'] !== $data['company_name']) {
                throw new \RuntimeException("Demo e-mail {$data['email']} už používá jiná firma.");
            }
            $supplierId = (int) $rows[0]['id'];
            BankRuleTemplateSeeder::seed($this->db->pdo(), $supplierId);
            return $supplierId;
        }

        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
        if ($countryId === 0 || $vatRateId === 0) throw new \RuntimeException('Chybí základní číselníky zemí nebo DPH.');

        $pdo->beginTransaction();
        $fkSuspended = false;
        try {
            $bootstrapCurrencyId = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
            if ($bootstrapCurrencyId === 0) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $fkSuspended = true;
            }
            $insert = $pdo->prepare(
                'INSERT INTO supplier
                    (company_name, display_name, street, city, zip, country_id, ic, dic, is_vat_payer, is_identified,
                     email, taxpayer_type, default_currency_id, default_vat_rate_id, default_payment_due_days,
                     default_hourly_rate, auto_send_reminders, auto_generate_recurring,
                     payment_thanks_enabled, payment_thanks_auto_send, payment_thanks_default_checked)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, 0, ?, ?, ?, ?, 14, 1500, 0, 0, 0, 0, 0)'
            );
            $insert->execute([
                $data['company_name'], $data['company_name'], $data['street'], $data['city'], $data['zip'],
                $countryId, $data['is_vat_payer'] ? 1 : 0, $data['email'], $data['taxpayer_type'],
                $bootstrapCurrencyId, $vatRateId,
            ]);
            $supplierId = (int) $pdo->lastInsertId();
            $vatHistory = $pdo->prepare(
                'INSERT INTO supplier_vat_status_history
                    (supplier_id, effective_from, is_vat_payer, annual_deduction_percent)
                 VALUES (?, \'1900-01-01\', ?, 100)'
            );
            $vatHistory->execute([$supplierId, $data['is_vat_payer'] ? 1 : 0]);

            $currency = $pdo->prepare(
                'INSERT INTO currencies
                    (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)'
            );
            $currency->execute([$supplierId, 'CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna', 1]);
            $defaultCurrencyId = (int) $pdo->lastInsertId();
            $currency->execute([$supplierId, 'EUR', 'EUR — výchozí', '€', 'Euro', 'Euro', 0]);
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$defaultCurrencyId, $supplierId]);
            BankRuleTemplateSeeder::seed($pdo, $supplierId);
            if ($fkSuspended) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $fkSuspended = false;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($fkSuspended) $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e;
        }
        $this->accountingModes->record($supplierId, '1900-01-01', 'tax_evidence');
        return $supplierId;
    }

    /** @param list<int> $supplierIds */
    private function ensureDemoBankVisibility(array $supplierIds): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT account_number, bank_code
               FROM bank_statements
              WHERE supplier_id = ?
           ORDER BY statement_date, id LIMIT 1'
        );
        $currency = $this->db->pdo()->prepare(
            "UPDATE currencies
                SET account_number = ?, bank_code = ?, bank_name = 'Ukázková banka'
              WHERE supplier_id = ? AND code = 'CZK'"
        );
        foreach ($supplierIds as $supplierId) {
            $statement->execute([$supplierId]);
            $account = $statement->fetch(PDO::FETCH_ASSOC);
            if ($account === false || trim((string) $account['account_number']) === '') continue;
            $currency->execute([
                (string) $account['account_number'],
                $account['bank_code'] !== null ? (string) $account['bank_code'] : null,
                $supplierId,
            ]);
        }
    }

    /** @param list<int> $supplierIds */
    private function disableOutboundAutomation(array $supplierIds): void
    {
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET auto_send_reminders = 0, auto_generate_recurring = 0,
                    payment_thanks_enabled = 0, payment_thanks_auto_send = 0,
                    payment_thanks_default_checked = 0
              WHERE id IN ({$placeholders})"
        )->execute($supplierIds);
        $this->db->pdo()->prepare(
            "UPDATE clients SET auto_send_reminders = 0 WHERE supplier_id IN ({$placeholders})"
        )->execute($supplierIds);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET auto_send_reminders = 0 WHERE supplier_id IN ({$placeholders})"
        )->execute($supplierIds);
        $this->db->pdo()->prepare(
            "UPDATE recurring_invoice_templates SET auto_send_email = 0 WHERE supplier_id IN ({$placeholders})"
        )->execute($supplierIds);
    }
}

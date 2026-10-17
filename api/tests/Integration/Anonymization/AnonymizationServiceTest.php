<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\Anonymization\AnonymizationOptions;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celá anonymizovaná kopie nad plnou strukturou testovací databáze.
 *
 * Zdroj je vlastní klon testovací databáze (sdílená `*_test` se nemění), do kterého
 * test vloží syntetické osobní údaje: partnera, účet, zaměstnance se šifrovaným
 * rodným číslem a auditní záznam. Kopie je nesmí obsahovat a musí dát stejné částky.
 */
#[Group('integration')]
final class AnonymizationServiceTest extends TestCase
{
    private const COMPANY = 'Zkušební Montáže Anonym s.r.o.';
    private const PERSON = 'Radomíra Zkušebnová';
    private const EMAIL = 'kontakt@zkusebni-montaze.example';
    private const ACCOUNT = '1000000005';

    private PDO $server;
    private Config $config;
    private string $source;
    private string $target;
    private string $testDatabase;
    private string $ico;
    private string $birthNumber;
    private int $clientId;
    private int $employeeId;
    private int $identifierId;
    private int $supplierId;
    private int $noticeConfirmationId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integrační databáze není nastavená.');
        }
        $container = Bootstrap::buildContainer();
        $this->config = $container->get(Config::class);
        $testDb = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        $this->testDatabase = $testDb;
        self::assertStringEndsWith('_test', $testDb);
        $this->source = substr($testDb, 0, -5) . '_anonsrc_test';
        $this->target = substr($testDb, 0, -5) . '_anondst_test';

        $this->server = new PDO(
            'mysql:host=' . $this->config->get('db.host', '127.0.0.1') . ';port=' . (int) $this->config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        // Jako v CI: bez striktního režimu projde lokálně i INSERT bez povinného sloupce.
        $this->server->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");
        $this->dropDatabases();
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->dropDatabases();
        }
    }

    public function testCopyContainsNoOriginalIdentifiersAndKeepsAmounts(): void
    {
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($this->testDatabase);
        $cloner->copyTables($plan, $this->source, static function (): void {});
        $cloner->finalize($plan, $this->source);
        $this->seedSource();
        $report = (new AnonymizationService($this->config))->run(
            new AnonymizationOptions(source: $this->source, target: $this->target, seed: 'integration'),
            static function (): void {},
        );

        self::assertGreaterThan(0, array_sum($report['changed_rows']));
        $dump = $this->tableTexts($this->target, ['clients', 'client_bank_accounts', 'payroll_employees', 'activity_log', 'supplier', 'users', 'bank_notice_confirmations']);
        foreach ([self::COMPANY, 'Zkušební Montáže', self::PERSON, 'Zkušebnová', self::EMAIL, self::ACCOUNT, $this->ico, $this->birthNumber, str_replace('/', '', $this->birthNumber)] as $needle) {
            self::assertStringNotContainsString($needle, $dump, "V kopii zůstal originální údaj {$needle}.");
        }

        $client = $this->row($this->target, 'SELECT company_name, ic, dic, main_email, note, email_subject_format, email_attachment_name_format FROM clients WHERE id = ?', [$this->clientId]);
        self::assertStringEndsWith('s.r.o.', (string) $client['company_name']);
        self::assertTrue(Pseudonymizer::isValidIco((string) $client['ic']));
        self::assertSame('CZ' . $client['ic'], $client['dic']);
        self::assertStringEndsWith('@' . Pseudonymizer::EMAIL_DOMAIN, (string) $client['main_email']);
        self::assertStringContainsString((string) $client['ic'], (string) $client['note'], 'IČO v poznámce má dostat tentýž pseudonym.');
        self::assertStringContainsString('{DUZP_MM}/{DUZP_YYYY}', (string) $client['email_subject_format'], 'Zástupné znaky formátu mají zůstat.');
        self::assertStringContainsString('{VS}', (string) $client['email_attachment_name_format']);

        $account = $this->row($this->target, 'SELECT account_number, account_key FROM client_bank_accounts WHERE client_id = ?', [$this->clientId]);
        self::assertTrue(Pseudonymizer::isValidAccountPart((string) $account['account_number']));
        self::assertSame(ltrim((string) $account['account_number'], '0'), $account['account_key']);

        $employee = $this->row($this->target, 'SELECT birth_number, full_name FROM payroll_employees WHERE id = ?', [$this->employeeId]);
        self::assertSame(substr($this->birthNumber, 0, 6), substr((string) $employee['birth_number'], 0, 6));
        self::assertTrue(Pseudonymizer::isValidBirthNumber((string) $employee['birth_number']));
        $sealed = $this->row($this->target, 'SELECT value_ciphertext, value_hash, value_masked FROM payroll_person_identifiers WHERE id = ?', [$this->identifierId]);
        $sensitive = $this->sensitive($this->target);
        $revealed = $sensitive->reveal((string) $sealed['value_ciphertext'], PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $this->identifierId, PayrollRevealPurpose::ANONYMIZATION);
        self::assertSame($employee['birth_number'], $revealed, 'Šifrované a otevřené rodné číslo mají dostat týž pseudonym.');
        self::assertSame($sensitive->lookupHash($revealed, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId), $sealed['value_hash']);

        $notice = $this->row($this->target, 'SELECT notice_source_ref, notice_identity, method, reason, deleted_at FROM bank_notice_confirmations WHERE id = ?', [$this->noticeConfirmationId]);
        self::assertNotSame('synthetic-message:' . self::EMAIL, $notice['notice_source_ref']);
        self::assertSame(hash('sha256', (string) $notice['notice_source_ref']), $notice['notice_identity'], 'Deduplikační stopa smazaného avíza musí po anonymizaci odpovídat nové referenci.');
        self::assertSame('manual', $notice['method']);
        self::assertSame('authoritative_removed', $notice['reason']);
        self::assertNotNull($notice['deleted_at']);

        self::assertSame(
            $this->scalar($this->source, 'SELECT SUM(total) FROM (SELECT COALESCE(SUM(amount), 0) AS total FROM journal_entry_lines UNION ALL SELECT COALESCE(SUM(total_with_vat), 0) FROM invoices) x'),
            $this->scalar($this->target, 'SELECT SUM(total) FROM (SELECT COALESCE(SUM(amount), 0) AS total FROM journal_entry_lines UNION ALL SELECT COALESCE(SUM(total_with_vat), 0) FROM invoices) x'),
        );
        self::assertSame('0', $this->scalar($this->target, 'SELECT COUNT(*) FROM sessions'));
        self::assertSame(
            count(TriggerMetadata::read($this->server, $this->source)),
            count(TriggerMetadata::read($this->server, $this->target)),
        );
        self::assertNotFalse($this->scalar($this->target, "SELECT v FROM app_meta WHERE k = 'anonymized_clone'"));

        $chain = new ActivityLogHashChain(new Connection($this->targetConfig($this->target)));
        self::assertTrue($chain->verify()['ok'], 'Auditní stopa kopie musí být po pseudonymizaci znovu zapečetěná.');

        self::assertSame(self::COMPANY, $this->scalar($this->source, 'SELECT company_name FROM clients WHERE id = ' . $this->clientId), 'Originál se nesmí změnit.');
    }

    public function testRefusesToOverwriteForeignDatabaseAndLiveDatabase(): void
    {
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->source));
        $service = new AnonymizationService($this->config);
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->target));
        try {
            $service->run(new AnonymizationOptions(source: $this->source, target: $this->target, replace: true), static function (): void {});
            self::fail('Databázi bez značky anonymizované kopie přepsat nesmí.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('není anonymizovaná kopie', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->run(new AnonymizationOptions(source: $this->source, target: (string) $this->config->get('db.name')), static function (): void {});
    }

    private function seedSource(): void
    {
        $pdo = $this->server;
        $pdo->exec('USE ' . DatabaseCloner::quote($this->source));
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        $this->supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $this->supplierId, 'Testovací databáze nemá firmu (ci-seed.php).');
        $this->ico = '2718281' . Pseudonymizer::icoCheckDigit('2718281');
        for ($serial = 100;; $serial++) {
            $nine = (int) ('855101' . $serial);
            $check = (11 - (($nine * 10) % 11)) % 11;
            if ($check < 10) {
                $this->birthNumber = '855101/' . $serial . $check;
                break;
            }
        }
        $country = (int) $pdo->query('SELECT MIN(id) FROM countries')->fetchColumn();
        $currency = (int) $pdo->query('SELECT MIN(id) FROM currencies')->fetchColumn();

        // Formáty předmětu a názvu PDF (#277) mívají jméno klienta i dodavatele přímo v textu.
        $pdo->prepare('INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, currency_default_id, main_email, phone, note, email_subject_format, email_attachment_name_format) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, self::COMPANY, $this->ico, 'CZ' . $this->ico, 'Zkušební 12', 'Zkušebnice', '123 45', $country, $currency,
                self::EMAIL, '+420 601 234 567', 'Smlouvu podepsala ' . self::PERSON . ', IČO: ' . $this->ico . ', účet ' . self::ACCOUNT . '/0100',
                self::COMPANY . ' {DUZP_MM}/{DUZP_YYYY} pro ' . self::PERSON, 'Faktura ' . $this->ico . ' {VS}']);
        $this->clientId = (int) $pdo->lastInsertId();
        $noticeReference = 'synthetic-message:' . self::EMAIL;
        $pdo->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_source_ref,notice_identity,state,reason,method,manual_reason,deleted_at) VALUES (?,?,?,'review','authoritative_removed','manual',?,NOW())")
            ->execute([$this->supplierId, $noticeReference, hash('sha256', $noticeReference), self::PERSON . ' ověřila ' . self::COMPANY . ' <' . self::EMAIL . '>']);
        $this->noticeConfirmationId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO client_bank_accounts (supplier_id, client_id, account_number, bank_code, account_key, bank_key) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, $this->clientId, self::ACCOUNT, '0100', self::ACCOUNT, '0100']);

        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, birth_number, address) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, self::PERSON, $this->birthNumber, 'Zkušební 12, 123 45 Zkušebnice']);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payroll_person_identifiers (supplier_id, employee_id, identifier_type, value_ciphertext, value_hash, value_masked) VALUES (?, ?, 'birth_number', '', ?, '')")
            ->execute([$this->supplierId, $this->employeeId, str_repeat("\0", 32)]);
        $this->identifierId = (int) $pdo->lastInsertId();
        $sealed = $this->sensitive($this->source)->seal($this->birthNumber, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $this->identifierId);
        $pdo->prepare('UPDATE payroll_person_identifiers SET value_ciphertext = ?, value_hash = ?, value_masked = ? WHERE id = ?')
            ->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->identifierId]);

        $payload = json_encode(['company_name' => self::COMPANY, 'ic' => $this->ico, 'contact' => self::PERSON . ' <' . self::EMAIL . '>'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO activity_log (supplier_id, action, entity_type, entity_id, payload) VALUES (?, 'client.update', 'client', ?, ?)")
            ->execute([$this->supplierId, $this->clientId, $payload]);
        $chain = new ActivityLogHashChain(new Connection($this->targetConfig($this->source)));
        $chain->seal((int) $pdo->lastInsertId());
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
    }

    /** @param list<string> $tables */
    private function tableTexts(string $schema, array $tables): string
    {
        $out = '';
        foreach ($tables as $table) {
            foreach ($this->server->query('SELECT * FROM ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote($table))->fetchAll() as $row) {
                $out .= implode("\x1F", array_map(static fn (mixed $v): string => (string) $v, $row)) . "\n";
            }
        }

        return $out;
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $schema, string $sql, array $params): array
    {
        $this->server->exec('USE ' . DatabaseCloner::quote($schema));
        $stmt = $this->server->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function scalar(string $schema, string $sql): string|false
    {
        $this->server->exec('USE ' . DatabaseCloner::quote($schema));
        $value = $this->server->query($sql)->fetchColumn();

        return $value === false ? false : (string) $value;
    }

    private function sensitive(string $schema): PayrollSensitiveData
    {
        $config = $this->targetConfig($schema);

        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }

    private function targetConfig(string $schema): Config
    {
        $data = $this->config->all();
        $data['db']['name'] = $schema;

        return new Config($data, $this->config->dataDir());
    }

    private function dropDatabases(): void
    {
        foreach ([$this->source, $this->target] as $schema) {
            $this->server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($schema));
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Pohoda;

use MyInvoice\Action\Payroll\PayrollPohodaOicImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Pohoda\PohodaPersonnelOicImportService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use MyInvoice\Tests\Unit\Payroll\Import\Pohoda\PohodaPersonnelFixture;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Import OIČ z exportu Personalistiky POHODY nad izolovanou firmou v transakci.
 *
 * Jana je připravená k zápisu, Petr má stejné OIČ uložené, Eva jiné, Karlovo
 * OIČ ze souboru patří v evidenci Lence a Tomáš má jen archivovaný vztah.
 * Ostatní řádky pokrývají chyby souboru. Všechna čísla jsou syntetická.
 */
#[Group('integration')]
final class PohodaPersonnelOicImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const START = '2020-01-01';

    private Connection $db;
    private ContainerInterface $container;
    private PohodaPersonnelOicImportService $service;
    private PayrollRegistrationIdentityService $identities;
    private int $supplierId;
    private int $userId;
    /** @var array<string,array{employee_id:int,employment_id:int}> */
    private array $people = [];
    /** @var array{name:string,content:string,sha256:string,extension:string} */
    private array $file;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $this->container->get(Connection::class);
        $service = $this->container->get(PohodaPersonnelOicImportService::class);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        if (!$db instanceof Connection
            || !$service instanceof PohodaPersonnelOicImportService
            || !$identities instanceof PayrollRegistrationIdentityService
        ) {
            throw new \RuntimeException('Služba importu OIČ není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        $this->identities = $identities;
        foreach (['payroll_person_external_ids', 'payroll_person_identifiers'] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);

        foreach ([
            'jana' => ['Jana Testovací', '1990-05-01', 'female', 1, 'active'],
            'petr' => ['Petr Zkušební', '1985-03-12', 'male', 2, 'active'],
            'eva' => ['Eva Pokusná', '1988-07-20', 'female', 3, 'active'],
            'karel' => ['Karel Vzorový', '1979-11-02', 'male', 4, 'active'],
            'lenka' => ['Lenka Ukázková', '1992-02-14', 'female', 5, 'active'],
            'tomas' => ['Tomáš Archivní', '1970-10-10', 'male', 6, 'archived'],
        ] as $key => [$name, $birthDate, $sex, $sequence, $status]) {
            $this->people[$key] = $this->createEmployment($name, 'ZAM-' . strtoupper($key), $status);
            $this->insertBirthNumber($this->people[$key]['employee_id'], AttendanceFixture::birthNumber($birthDate, $sex, $sequence));
        }
        $this->storeOic('petr', RegistrationXmlFixtures::oic(2));
        $this->storeOic('eva', RegistrationXmlFixtures::oic(3));
        $this->storeOic('lenka', RegistrationXmlFixtures::oic(5));

        $validSeven = RegistrationXmlFixtures::oic(7);
        $this->file = PohodaPersonnelFixture::file([
            self::person('Testovací', 'Jana', AttendanceFixture::birthNumber('1990-05-01', 'female', 1), 'P01', RegistrationXmlFixtures::oic(1)),
            self::person('Zkušební', 'Petr', AttendanceFixture::birthNumber('1985-03-12', 'male', 2), 'P02', RegistrationXmlFixtures::oic(2)),
            self::person('Pokusná', 'Eva', AttendanceFixture::birthNumber('1988-07-20', 'female', 3), 'P03', RegistrationXmlFixtures::oic(4)),
            self::person('Vzorový', 'Karel', AttendanceFixture::birthNumber('1979-11-02', 'male', 4), 'P04', RegistrationXmlFixtures::oic(5)),
            self::person('Neznámá', 'Olga', AttendanceFixture::birthNumber('1995-09-09', 'female', 8), 'P05', RegistrationXmlFixtures::oic(6)),
            self::person('Chybná', 'Irena', '123', 'P06', RegistrationXmlFixtures::oic(9)),
            self::person('Překlep', 'Filip', AttendanceFixture::birthNumber('1991-01-15', 'male', 9), 'P07', substr($validSeven, 0, 9) . (((int) $validSeven[9] + 1) % 10)),
            self::person('Testovací', 'Jana', AttendanceFixture::birthNumber('1990-05-01', 'female', 1), 'P08', null),
            self::person('Testovací', 'Jana', AttendanceFixture::birthNumber('1990-05-01', 'female', 1), 'P09', RegistrationXmlFixtures::oic(1)),
            self::person('Archivní', 'Tomáš', AttendanceFixture::birthNumber('1970-10-10', 'male', 6), 'P10', RegistrationXmlFixtures::oic(10)),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPreviewAssignsStatusesWithoutWritingOrLeakingBirthNumbers(): void
    {
        $before = $this->storedCount();
        $preview = $this->service->preview($this->supplierId, [$this->file], 'production');

        self::assertSame($before, $this->storedCount(), 'Náhled nesmí nic zapsat.');
        self::assertSame([
            'P01' => 'ready',
            'P02' => 'already_stored',
            'P03' => 'conflict',
            'P04' => 'oic_owned_by_other',
            'P05' => 'not_found',
            'P06' => 'invalid_birth_number',
            'P07' => 'invalid_oic',
            'P08' => 'no_oic',
            'P09' => 'duplicate',
            'P10' => 'no_employment',
        ], array_column($preview['rows'], 'status', 'personal_number'));
        self::assertSame(
            ['total' => 9, 'ready' => 1, 'already_stored' => 1, 'conflict' => 1, 'blocked' => 6, 'without_oic' => 1],
            $preview['summary'],
        );

        $rows = array_column($preview['rows'], null, 'personal_number');
        self::assertTrue($rows['P01']['selectable']);
        self::assertFalse($rows['P03']['selectable']);
        self::assertSame($this->people['jana']['employment_id'], $rows['P01']['employment_id']);
        self::assertSame(self::START, $rows['P01']['valid_from']);
        self::assertSame('Jana Testovací', $rows['P01']['employee_name']);
        self::assertStringContainsString('kartě pracovního vztahu', (string) $rows['P03']['message']);

        $json = json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $birth = AttendanceFixture::birthNumber('1990-05-01', 'female', 1);
        self::assertStringNotContainsString($birth, $json);
        self::assertStringNotContainsString((string) preg_replace('/\D/', '', $birth), $json);
        self::assertStringNotContainsString(RegistrationXmlFixtures::oic(1), $json);
        self::assertNotSame('', (string) $rows['P01']['birth_number_masked']);
        foreach ($preview['rows'] as $row) {
            foreach (array_keys($row) as $key) {
                self::assertStringStartsNotWith('_', (string) $key);
            }
        }

        // OIČ se vede po prostředích: v testovacím Petr uložené číslo nemá.
        $test = $this->service->preview($this->supplierId, [$this->file], 'test');
        self::assertSame('ready', array_column($test['rows'], 'status', 'personal_number')['P02']);
    }

    public function testApplyWritesOnlyReadyRowsAndRepeatIsIdempotent(): void
    {
        $keys = array_column($this->service->preview($this->supplierId, [$this->file], 'production')['rows'], 'key');
        $before = $this->storedCount();

        $first = $this->service->apply($this->supplierId, [$this->file], 'production', $keys, true, $this->userId);

        self::assertSame(['applied' => 1, 'failed' => 0, 'skipped' => 9], $first['summary']);
        self::assertSame($before + 1, $this->storedCount());
        $jana = $this->people['jana'];
        self::assertTrue($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $jana['employee_id'],
            'production',
            RegistrationXmlFixtures::oic(1),
        ));
        $stored = $this->db->pdo()->prepare(
            'SELECT source_kind, valid_from FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ? AND environment = "production"',
        );
        $stored->execute([$this->supplierId, $jana['employee_id']]);
        self::assertSame(
            [['source_kind' => 'verified_manual_import', 'valid_from' => self::START]],
            $stored->fetchAll(\PDO::FETCH_ASSOC),
        );

        $second = $this->service->apply($this->supplierId, [$this->file], 'production', $keys, true, $this->userId);
        self::assertSame(['applied' => 0, 'failed' => 0, 'skipped' => 10], $second['summary']);
        self::assertSame($before + 1, $this->storedCount());
        $again = $this->service->preview($this->supplierId, [$this->file], 'production');
        self::assertSame('already_stored', array_column($again['rows'], 'status', 'personal_number')['P01']);
    }

    public function testConflictAndForeignOicAreNeverWritten(): void
    {
        $preview = $this->service->preview($this->supplierId, [$this->file], 'production');
        $keys = array_column($preview['rows'], 'key', 'personal_number');

        $result = $this->service->apply(
            $this->supplierId,
            [$this->file],
            'production',
            [$keys['P03'], $keys['P04']],
            true,
            $this->userId,
        );

        self::assertSame(['skipped', 'skipped'], array_column($result['results'], 'status'));
        self::assertTrue($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->people['eva']['employee_id'],
            'production',
            RegistrationXmlFixtures::oic(3),
        ), 'Uložené OIČ Evy zůstává.');
        self::assertNull($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->people['karel']['employee_id'],
            'production',
            RegistrationXmlFixtures::oic(5),
        ), 'Karel nedostane OIČ, které patří Lence.');
    }

    public function testApplyRequiresEvidenceConfirmation(): void
    {
        $keys = array_column($this->service->preview($this->supplierId, [$this->file], 'production')['rows'], 'key');
        $before = $this->storedCount();
        try {
            $this->service->apply($this->supplierId, [$this->file], 'production', $keys, false, $this->userId);
            self::fail('Bez potvrzení se nesmí nic zapsat.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ePortálu ČSSZ', $e->getMessage());
        }
        self::assertSame($before, $this->storedCount());
    }

    public function testActionPreviewsAndRejectsApplyWithoutConfirmation(): void
    {
        $action = $this->container->get(PayrollPohodaOicImportAction::class);
        self::assertInstanceOf(PayrollPohodaOicImportAction::class, $action);
        $files = [['name' => $this->file['name'], 'content_base64' => base64_encode($this->file['content'])]];

        $preview = $action->preview($this->request('/api/payroll/imports/pohoda-oic/preview', ['files' => $files]), new Response());
        self::assertSame(200, $preview->getStatusCode(), $this->body($preview));
        $body = $this->body($preview);
        self::assertStringNotContainsString(AttendanceFixture::birthNumber('1990-05-01', 'female', 1), $body);
        $keys = array_column(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['rows'] ?? [], 'key');
        self::assertCount(10, $keys);

        $apply = $action->apply($this->request('/api/payroll/imports/pohoda-oic/apply', [
            'files' => $files,
            'environment' => 'production',
            'keys' => $keys,
            'evidence_confirmed' => false,
        ]), new Response());
        self::assertSame(422, $apply->getStatusCode());
    }

    /** @return array{last:string,first:string,birth:string,personal:string,oic:?string} */
    private static function person(string $last, string $first, string $birth, string $personal, ?string $oic): array
    {
        return ['last' => $last, 'first' => $first, 'birth' => $birth, 'personal' => $personal, 'oic' => $oic];
    }

    /** @return array{employee_id:int,employment_id:int} */
    private function createEmployment(string $name, string $code, string $status): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", ?, ?, ?, 4200000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code, $status, self::START, self::START]);

        return ['employee_id' => $employeeId, 'employment_id' => (int) $pdo->lastInsertId()];
    }

    private function insertBirthNumber(int $employeeId, string $value): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:pending", ?, "")',
        )->execute([$this->supplierId, $employeeId, random_bytes(32)]);
        $id = (int) $pdo->lastInsertId();
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $sealed = $sensitive->seal($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $id);
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }

    private function storeOic(string $person, string $oic): void
    {
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $this->people[$person]['employment_id'],
            'production',
            $oic,
            null,
            self::START,
            'synthetic:manual-card',
            true,
            $this->userId,
        );
    }

    private function storedCount(): int
    {
        $statement = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_person_external_ids WHERE supplier_id = ?');
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $body */
    private function request(string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $uri)
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dřívější příjmení (REGZEC `name/@ona`, ID 10064) se skládají z historie
 * jména: příjmení starších záznamů bez aktuálního a bez rodného příjmení.
 */
#[Group('integration')]
final class PayrollRegistrationPreviousSurnamesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRegistrationIdentityRepository $repository;
    private PayrollRegistrationIdentityService $service;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $this->db = $db;
        $this->repository = new PayrollRegistrationIdentityRepository($db);
        $this->service = new PayrollRegistrationIdentityService($this->repository, $sensitive, \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry::defaults());

        $pdo = $db->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    /**
     * REGZEC25-client.name.ona-04, REGZEC25-client.name.sur-05: EDV 1.4.0.6
     * („vyjma rodného") a Zásady REGZEC 1.4.6 („bez aktuálního příjmení
     * a rodného"). Rodné příjmení nese `birth/@nam`, do `ona` nepatří, ani
     * když ho evidence doplnila až k pozdějšímu záznamu.
     */
    public function testCollectsEarlierSurnamesNewestFirstWithoutCurrentAndBirthSurname(): void
    {
        $this->history('2019-01-01', '2020-12-31', 'Nguyen Quoc', null);
        $this->history('2021-01-01', '2023-12-31', 'Dvořáková', null);
        $this->history('2024-01-01', null, 'Novotná', 'Nováková');
        $this->history('2018-01-01', '2018-12-31', 'Nováková', null);

        self::assertSame(
            'Dvořáková, Nguyen Quoc',
            $this->repository->previousSurnames(
                $this->supplierId,
                $this->employeeId,
                '2026-08-04',
                'Novotná',
            ),
        );
        // K dřívějšímu dni se budoucí příjmení nepočítá.
        self::assertSame(
            'Nguyen Quoc',
            $this->repository->previousSurnames(
                $this->supplierId,
                $this->employeeId,
                '2022-06-01',
                'Dvořáková',
            ),
        );
    }

    /** Jediné dřívější příjmení shodné s rodným znamená, že `ona` se nevyplní. */
    public function testOnlyBirthSurnameInHistoryLeavesNoPreviousSurnames(): void
    {
        $this->history('2015-01-01', '2023-12-31', 'Malá', 'Malá');
        $this->history('2024-01-01', null, 'Velká', 'Malá');

        self::assertNull($this->repository->previousSurnames(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
            'Velká',
        ));
    }

    public function testNoHistoryMeansNoKeyInTheFrozenIdentity(): void
    {
        $this->history('2024-01-01', null, 'Novotná', null);

        self::assertNull($this->repository->previousSurnames(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
            'Novotná',
        ));
        $identity = $this->service->sensitiveIdentityAt(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
        )['identity'];
        self::assertArrayNotHasKey('previous_surnames', $identity);
    }

    public function testIdentityFromTheServiceCarriesThePreviousSurnames(): void
    {
        $this->history('2020-01-01', '2023-12-31', 'Dvořáková', null);
        $this->history('2024-01-01', null, 'Novotná', null);

        $identity = $this->service->sensitiveIdentityAt(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
        )['identity'];

        self::assertSame('Dvořáková', $identity['previous_surnames']);
    }

    /** Schéma pustí nejvýš 100 znaků; příjmení se nikdy neuřízne, nejstarší vypadne. */
    public function testListLongerThanOneHundredCharactersDropsTheOldestWholeNames(): void
    {
        $this->history('2010-01-01', '2012-12-31', str_repeat('A', 40), null);
        $this->history('2013-01-01', '2016-12-31', str_repeat('B', 40), null);
        $this->history('2017-01-01', '2020-12-31', str_repeat('C', 40), null);
        $this->history('2021-01-01', null, 'Novotná', null);

        $previous = $this->repository->previousSurnames(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
            'Novotná',
        );

        self::assertSame(str_repeat('C', 40) . ', ' . str_repeat('B', 40), $previous);
        self::assertLessThanOrEqual(100, mb_strlen((string) $previous));
    }

    private function history(string $from, ?string $to, string $lastName, ?string $birthSurname): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, effective_from, effective_to)
             VALUES (?, ?, "Syntetická osoba", "Jana", ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $lastName,
            $birthSurname,
            $from,
            $to,
        ]);
    }
}

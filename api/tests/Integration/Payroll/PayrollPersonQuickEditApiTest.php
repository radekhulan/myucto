<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPersonQuickEditAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPersonQuickEditApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonQuickEditAction $action;
    private PayrollPersonProfileRepository $profiles;
    private PayrollPersonProfileValidator $profileValidator;
    private PayrollEmploymentRepository $employments;
    private PayrollEmploymentValidator $employmentValidator;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        foreach ([
            'payroll_employee_profiles',
            'payroll_person_identity_history',
            'payroll_employments',
            'payroll_employment_terms',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->action = $container->get(PayrollPersonQuickEditAction::class);
        $this->profiles = $container->get(PayrollPersonProfileRepository::class);
        $this->profileValidator = $container->get(PayrollPersonProfileValidator::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->employmentValidator = $container->get(PayrollEmploymentValidator::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'HLAVNI', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id)
             VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        )->execute([
            $this->supplierId,
            'Původní Zaměstnanec',
            'employee',
            'hpp',
        ]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testQuickEditWithoutMonthlyGrossPreservesHourlyTerms(): void
    {
        $profile = $this->profiles->save(
            $this->supplierId,
            $this->employeeId,
            $this->profileValidator->validate($this->profilePayload('Původní Zaměstnanec')),
            0,
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $employment = $this->employments->create(
            $this->supplierId,
            $this->employeeId,
            $this->employmentValidator->create([
                'code' => 'SYN-HOD',
                'relation_type' => 'employment',
                'terms' => array_replace($this->termsPayload('2026-01-01', '40'), [
                    'remuneration_type' => 'hourly',
                    'hourly_rate_minor' => 20000,
                    'remuneration_basis_kind' => 'wage_statement',
                    'remuneration_basis' => 'Syntetický výměr',
                ]),
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $payload = [
            'profile' => [
                ...$this->profilePayload('Původní Zaměstnanec'),
                'row_version' => $profile['row_version'],
                'identity_history' => [[
                    'id' => $profile['identity_history'][0]['id'],
                    'full_name' => 'Původní Zaměstnanec',
                    'first_name' => 'Původní',
                    'last_name' => 'Zaměstnanec',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
            ],
            'employment' => [
                'id' => $employment['id'],
                'row_version' => $employment['row_version'],
                'terms' => $this->termsPayload('2026-08-04', '37.5'),
            ],
        ];
        $response = $this->put($payload);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $terms = $this->json($response)['employment']['terms'][0];
        self::assertSame('hourly', $terms['remuneration_type']);
        self::assertSame(20000, $terms['hourly_rate_minor']);
        self::assertSame('Syntetický výměr', $terms['remuneration_basis']);
        self::assertNull($terms['monthly_gross_minor']);
        $saved = $this->json($response);
        $payload['profile']['row_version'] = $saved['profile']['row_version'];
        $payload['employment']['row_version'] = $saved['employment']['row_version'];
        $payload['employment']['terms']['effective_from'] = '2026-09-01';
        $payload['employment']['monthly_gross_minor'] = null;
        $denied = $this->put($payload);
        self::assertSame(422, $denied->getStatusCode(), (string) $denied->getBody());
        self::assertStringContainsString('U hodinového vztahu', (string) $denied->getBody());
    }

    public function testProfileTermsAndGrossAreSavedAtomicallyAndKeepHistory(): void
    {
        $profile = $this->profiles->save(
            $this->supplierId,
            $this->employeeId,
            $this->profileValidator->validate($this->profilePayload('Původní Zaměstnanec')),
            0,
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $employment = $this->employments->create(
            $this->supplierId,
            $this->employeeId,
            $this->employmentValidator->create([
                'code' => 'SYN-HPP',
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4_200_000,
                'terms' => $this->termsPayload('2026-01-01', '40'),
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );

        $success = $this->put([
            'profile' => [
                ...$this->profilePayload('Nová Zaměstnankyně'),
                'row_version' => $profile['row_version'],
                'identity_history' => [[
                    'id' => $profile['identity_history'][0]['id'],
                    'full_name' => 'Nová Zaměstnankyně',
                    'first_name' => 'Nová',
                    'last_name' => 'Zaměstnankyně',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
            ],
            'employment' => [
                'id' => $employment['id'],
                'row_version' => $employment['row_version'],
                'monthly_gross_minor' => 4_500_000,
                'terms' => $this->termsPayload('2026-08-04', '37.5'),
            ],
        ]);

        self::assertSame(200, $success->getStatusCode(), (string) $success->getBody());
        $saved = $this->json($success);
        self::assertSame('Nová Zaměstnankyně', $saved['profile']['full_name']);
        self::assertSame(4_500_000, $saved['employment']['monthly_gross_minor']);
        self::assertSame('37.50', $saved['employment']['terms'][0]['weekly_hours']);
        self::assertSame(
            '554782',
            $saved['employment']['terms'][0]['jmhz_workplace_municipality_code'],
        );
        self::assertSame('yes', $saved['employment']['terms'][0]['jmhz_apz_contribution_status']);
        self::assertSame('2', $saved['employment']['terms'][0]['jmhz_apz_instrument_code']);
        self::assertSame('no', $saved['employment']['terms'][0]['jmhz_functional_benefits_status']);
        self::assertSame(
            'unverified',
            $saved['employment']['terms'][0]['jmhz_temporary_assignment_status'],
        );
        self::assertCount(2, $saved['employment']['terms']);
        self::assertArrayHasKey(
            'monthly_gross_minor',
            $saved['employment']['timeline'][0]['diff'],
        );

        $profileVersion = (int) $saved['profile']['row_version'];
        $staleEmploymentVersion = (int) $employment['row_version'];
        $conflict = $this->put([
            'profile' => [
                ...$this->profilePayload('Nesmí se uložit'),
                'row_version' => $profileVersion,
                'identity_history' => [[
                    'id' => $saved['profile']['identity_history'][0]['id'],
                    'full_name' => 'Nesmí se uložit',
                    'first_name' => 'Nesmí',
                    'last_name' => 'se uložit',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
            ],
            'employment' => [
                'id' => $employment['id'],
                'row_version' => $staleEmploymentVersion,
                'monthly_gross_minor' => 4_700_000,
                'terms' => $this->termsPayload('2026-09-01', '36'),
            ],
        ]);

        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame('row_version_conflict', $this->json($conflict)['error']['code']);
        $afterConflict = $this->profiles->get($this->supplierId, $this->employeeId);
        self::assertIsArray($afterConflict);
        self::assertSame('Nová Zaměstnankyně', $afterConflict['full_name']);
        self::assertSame($profileVersion, $afterConflict['row_version']);
        $employmentAfterConflict = $this->employments->listForEmployee(
            $this->supplierId,
            $this->employeeId,
        )[0];
        self::assertSame(4_500_000, $employmentAfterConflict['monthly_gross_minor']);
        self::assertCount(2, $employmentAfterConflict['terms']);
    }

    /**
     * Osobní číslo (kód vztahu) se v rychlé úpravě mění přejmenováním —
     * samotná změna čísla nesmí založit novou verzi sjednaných podmínek.
     */
    public function testPersonalNumberChangesWithoutNewTermsVersion(): void
    {
        $profile = $this->profiles->save(
            $this->supplierId,
            $this->employeeId,
            $this->profileValidator->validate($this->profilePayload('Původní Zaměstnanec')),
            0,
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $employment = $this->employments->create(
            $this->supplierId,
            $this->employeeId,
            $this->employmentValidator->create([
                'code' => 'ZAM-1',
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4_200_000,
                'terms' => $this->termsPayload('2026-01-01', '40'),
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $profilePayload = [
            ...$this->profilePayload('Původní Zaměstnanec'),
            'row_version' => $profile['row_version'],
            'identity_history' => [[
                'id' => $profile['identity_history'][0]['id'],
                'full_name' => 'Původní Zaměstnanec',
                'first_name' => 'Původní',
                'last_name' => 'Zaměstnanec',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
        ];

        $saved = $this->put([
            'profile' => $profilePayload,
            'employment' => null,
            'employment_code' => ['employment_id' => $employment['id'], 'code' => 'Z0042'],
        ]);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('Z0042', $this->json($saved)['employment']['code']);
        $stored = $this->employments->listForEmployee($this->supplierId, $this->employeeId)[0];
        self::assertSame('Z0042', $stored['code']);
        self::assertCount(1, $stored['terms']);

        $invalid = $this->put([
            'profile' => [...$profilePayload, 'row_version' => (int) $this->json($saved)['profile']['row_version']],
            'employment' => null,
            'employment_code' => ['employment_id' => $employment['id'], 'code' => 'os. č. 1'],
        ]);
        self::assertNotSame(200, $invalid->getStatusCode());
        self::assertSame(
            'Z0042',
            $this->employments->listForEmployee($this->supplierId, $this->employeeId)[0]['code'],
        );
    }

    public function testQuickEditRequiresSessionAndBothWritePermissions(): void
    {
        $personOnly = new EffectiveRole(
            71,
            'Personalista',
            'staff',
            true,
            ['payroll.person.write' => AccessLevel::WRITE->value],
        );
        $forbidden = $this->put([], $personOnly);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $bearer = $this->put([], null, 'bearer');
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    public function testNewIdentityVersionCanInheritBirthSurnameWithoutExposingIt(): void
    {
        $initial = $this->profilePayload('Jana Původní');
        $initial['identity_history'][0]['birth_surname'] = 'Příkladová';
        $profile = $this->profiles->save(
            $this->supplierId,
            $this->employeeId,
            $this->profileValidator->validate($initial),
            0,
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
        $sourceId = (int) $profile['identity_history'][0]['id'];
        $effectiveFrom = date('Y-m-d');
        $effectiveTo = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));

        $response = $this->put([
            'profile' => [
                ...$this->profilePayload('Jana Nová'),
                'row_version' => $profile['row_version'],
                'identity_history' => [
                    [
                        'id' => $sourceId,
                        'full_name' => 'Jana Původní',
                        'first_name' => 'Jana',
                        'last_name' => 'Původní',
                        'effective_from' => '2026-01-01',
                        'effective_to' => $effectiveTo,
                    ],
                    [
                        'full_name' => 'Jana Nová',
                        'first_name' => 'Jana',
                        'last_name' => 'Nová',
                        'birth_surname_source_id' => $sourceId,
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                    ],
                ],
            ],
            'employment' => null,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $statement = $this->db->pdo()->prepare(
            'SELECT birth_surname
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$this->supplierId, $this->employeeId]);
        self::assertSame('Příkladová', $statement->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function profilePayload(string $fullName): array
    {
        [$firstName, $lastName] = explode(' ', $fullName, 2);

        return [
            'row_version' => 0,
            'profile_status' => 'setup',
            'payout_method' => 'cash',
            'cash_allocation_basis_points' => 10000,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [],
            'accounts' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function termsPayload(string $effectiveFrom, string $weeklyHours): array
    {
        return [
            'office_id' => null,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => '2025-12-15',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => null,
            'weekly_hours' => $weeklyHours,
            'workload_basis_points' => 10000,
            'work_place' => 'Hlavní město Praha',
            'regular_workplace' => 'Praha',
            'jmhz_workplace_municipality_code' => '554782',
            'jmhz_workplace_country_code' => 'CZ',
            'jmhz_apz_contribution_status' => 'yes',
            'jmhz_apz_instrument_code' => '2',
            'jmhz_functional_benefits_status' => 'no',
            'jmhz_temporary_assignment_status' => 'unverified',
            'cz_isco_code' => null,
            'activity_code' => '1',
            'jmhz_relationship_detail_code' => '1',
            'social_insurance_participation' => 'automatic',
            'health_insurance_participation' => 'automatic',
            'tax_regime' => 'advance',
            'foreign_legislation_country_code' => null,
            'a1_certificate_until' => null,
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => true,
            'change_reason' => 'Rychlá editace běžných údajů',
        ];
    }

    /** @param array<string,mixed> $body */
    private function put(
        array $body,
        ?EffectiveRole $effectiveRole = null,
        string $authMethod = 'session',
    ): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'PUT',
                "/api/payroll/people/{$this->employeeId}/quick-edit",
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
        $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        if ($effectiveRole !== null) {
            $request = $request->withAttribute('auth.effective_role', $effectiveRole);
        }

        return $this->action->put(
            $request,
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

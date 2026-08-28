<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPersonProfileAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPersonProfileApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonProfileAction $action;
    private PayrollPersonProfileValidator $validator;
    private PayrollRegistrationIdentityRepository $registrationIdentities;
    private PayrollRegistrationIdentityService $registrationIdentityService;
    private PayrollSensitiveData $sensitiveData;
    private PayrollEmployeeRepository $employees;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPersonProfileAction::class);
            $this->validator = $container->get(PayrollPersonProfileValidator::class);
            $this->registrationIdentities = $container->get(PayrollRegistrationIdentityRepository::class);
            $this->registrationIdentityService = $container->get(PayrollRegistrationIdentityService::class);
            $this->sensitiveData = $container->get(PayrollSensitiveData::class);
            $this->employees = $container->get(PayrollEmployeeRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        foreach ([
            'payroll_person_identity_history',
            'payroll_person_addresses',
            'payroll_person_contacts',
            'payroll_person_identifiers',
            'payroll_person_accounts',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1191 neproběhla.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $insertEmployee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        );
        $insertEmployee->execute([
            $this->supplierId,
            'Původní Testovací',
            'employee',
            'hpp',
            42_000,
        ]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $insertEmployee->execute([
            $this->otherSupplierId,
            'Cizí Testovací',
            'employee',
            'hpp',
            38_000,
        ]);
        $this->otherEmployeeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testMaskedProfilePersistsEncryptedSecretsAndEffectiveHistory(): void
    {
        $empty = $this->get($this->supplierId, $this->employeeId);
        self::assertSame(200, $empty->getStatusCode());
        self::assertSame(0, $this->json($empty)['profile']['row_version']);

        $response = $this->put(
            $this->supplierId,
            $this->employeeId,
            $this->completePayload(),
        );
        self::assertSame(200, $response->getStatusCode());
        $profile = $this->json($response)['profile'];
        self::assertSame(1, $profile['row_version']);
        self::assertSame('Jana Testovací', $profile['full_name']);
        self::assertSame('Jana', $profile['identity_history'][0]['first_name']);
        self::assertSame(
            'Testovací',
            $profile['identity_history'][0]['last_name'],
        );
        self::assertSame('bank', $profile['payout_method']);
        self::assertCount(1, $profile['identity_history']);
        self::assertCount(1, $profile['addresses']);
        self::assertCount(1, $profile['contacts']);
        self::assertCount(1, $profile['identifiers']);
        self::assertCount(1, $profile['accounts']);

        $json = json_encode($profile, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('123456789', $json);
        self::assertStringNotContainsString('1000000005/0100', $json);
        self::assertStringNotContainsString('jana.testovaci@example.invalid', $json);
        self::assertStringNotContainsString('Testovací 1', $json);
        self::assertStringNotContainsString('Příkladová', $json);
        self::assertArrayHasKey('birth_surname_masked', $profile['identity_history'][0]);
        self::assertArrayNotHasKey('birth_surname', $profile['identity_history'][0]);
        self::assertArrayHasKey('address_masked', $profile['addresses'][0]);
        self::assertArrayNotHasKey('street_line', $profile['addresses'][0]);
        self::assertStringContainsString('example.invalid', $profile['contacts'][0]['value_masked']);
        // Maska rodného čísla ukazuje jen dvě číslice. Se čtyřmi bylo celé RČ
        // odvoditelné z `birth_date` a `sex`, které jdou ve stejné odpovědi.
        self::assertStringEndsWith('89', $profile['identifiers'][0]['value_masked']);
        self::assertStringNotContainsString('6789', $profile['identifiers'][0]['value_masked']);
        self::assertStringEndsWith('5/0100', $profile['accounts'][0]['bank_account_masked']);
        self::assertArrayHasKey('verification_source', $profile['accounts'][0]);
        self::assertArrayHasKey('verified_on', $profile['accounts'][0]);
        self::assertArrayHasKey('verified_by', $profile['accounts'][0]);
        self::assertNull($profile['accounts'][0]['verification_source']);
        self::assertNull($profile['accounts'][0]['verified_on']);
        self::assertNull($profile['accounts'][0]['verified_by']);

        $identifier = $this->db->pdo()->query(
            'SELECT value_ciphertext, value_hash FROM payroll_person_identifiers'
            . ' WHERE supplier_id = ' . $this->supplierId
            . ' AND employee_id = ' . $this->employeeId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($identifier);
        self::assertStringStartsWith('enc:v2:', (string) $identifier['value_ciphertext']);
        self::assertSame(32, strlen((string) $identifier['value_hash']));
        self::assertStringNotContainsString('123456789', (string) $identifier['value_ciphertext']);

        $contact = $this->db->pdo()->query(
            'SELECT contact_value_ciphertext, contact_value_hash FROM payroll_person_contacts'
            . ' WHERE supplier_id = ' . $this->supplierId
            . ' AND employee_id = ' . $this->employeeId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($contact);
        self::assertStringStartsWith('enc:v2:', (string) $contact['contact_value_ciphertext']);
        self::assertSame(32, strlen((string) $contact['contact_value_hash']));
        self::assertStringNotContainsString(
            'jana.testovaci',
            (string) $contact['contact_value_ciphertext'],
        );

        $account = $this->db->pdo()->query(
            'SELECT bank_account_ciphertext, bank_account_hash FROM payroll_person_accounts'
            . ' WHERE supplier_id = ' . $this->supplierId
            . ' AND employee_id = ' . $this->employeeId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($account);
        self::assertStringStartsWith('enc:v2:', (string) $account['bank_account_ciphertext']);
        self::assertSame(32, strlen((string) $account['bank_account_hash']));
        self::assertStringNotContainsString('1000000005', (string) $account['bank_account_ciphertext']);
    }

    public function testRegistrationIdentityFactsAreSavedInHistoryAndReadableAtEmploymentStart(): void
    {
        $employmentId = $this->createEmployment($this->employeeId, 'employment');
        $payload = $this->completePayload();
        $payload['identity_history'][0] += [
            'title_prefix' => 'Ing.',
            'title_suffix' => 'Ph.D.',
            'birth_date' => '1990-02-03',
            'birth_place' => 'Brno',
            'birth_country_code' => 'cz',
            'citizenship_country_code' => 'sk',
            'sex' => 'female',
        ];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $identity = $this->json($response)['profile']['identity_history'][0];
        self::assertSame('Ing.', $identity['title_prefix']);
        self::assertSame('Ph.D.', $identity['title_suffix']);
        self::assertSame('1990-02-03', $identity['birth_date']);
        self::assertSame('Brno', $identity['birth_place']);
        self::assertSame('CZ', $identity['birth_country_code']);
        self::assertSame('SK', $identity['citizenship_country_code']);
        self::assertSame('female', $identity['sex']);

        $registrationIdentity = $this->registrationIdentities->identityAt(
            $this->supplierId,
            $this->employeeId,
            '2026-01-01',
        );
        self::assertIsArray($registrationIdentity);
        self::assertSame('SK', $registrationIdentity['citizenship_country_code']);
        self::assertSame('1990-02-03', $registrationIdentity['birth_date']);

        $snapshotSource = $this->registrationIdentityService->sensitiveSnapshotSourceAt(
            $this->supplierId,
            $this->employeeId,
            $employmentId,
            'test',
            '2026-01-01',
        );
        self::assertSame('SK', $snapshotSource['identity']['citizenship_country_code']);
        self::assertSame('CZ', $snapshotSource['identity']['birth_country_code']);
        self::assertSame('female', $snapshotSource['identity']['sex']);

        $legacyClientResponse = $this->put($this->supplierId, $this->employeeId, [
            'row_version' => 1,
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'id' => $identity['id'],
                'full_name' => $identity['full_name'],
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'effective_from' => $identity['effective_from'],
                'effective_to' => $identity['effective_to'],
            ]],
        ]);
        self::assertSame(200, $legacyClientResponse->getStatusCode());
        $preservedIdentity = $this->json($legacyClientResponse)['profile']['identity_history'][0];
        self::assertSame('SK', $preservedIdentity['citizenship_country_code']);
        self::assertSame('1990-02-03', $preservedIdentity['birth_date']);
    }

    /**
     * Formulář „Běžné údaje zaměstnance“ posílá e-mail i telefon jako NÁHRADU vždy,
     * když je pole vyplněné — i s nezměněnou hodnotou. Starý řádek se přitom
     * deaktivuje a zakládá se nový, jenže `uq_payroll_contact_value` je nad
     * (supplier, employee, contact_type, hash) a `is_active` v něm není, takže
     * druhý řádek s touž hodnotou skončil na 23000 a karta nešla uložit vůbec.
     */
    public function testResavingUnchangedContactRecyclesRowInsteadOfDuplicating(): void
    {
        self::assertSame(200, $this->put($this->supplierId, $this->employeeId, $this->completePayload())->getStatusCode());
        $profile = $this->json($this->get($this->supplierId, $this->employeeId))['profile'];
        self::assertCount(1, $profile['contacts']);
        $contactId = (int) $profile['contacts'][0]['id'];

        $payload = $this->completePayload();
        $payload['row_version'] = (int) $profile['row_version'];
        // Přesně tvar z formuláře: stávající řádek na neaktivní, k tomu nový se STEJNOU hodnotou.
        $payload['contacts'] = [
            ['id' => $contactId, 'contact_type' => 'email', 'is_primary' => false, 'is_active' => false],
            ['contact_type' => 'email', 'value' => 'jana.testovaci@example.invalid', 'is_primary' => true, 'is_active' => true],
        ];
        $payload['identity_history'] = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'full_name' => $row['full_name'], 'first_name' => $row['first_name'],
            'last_name' => $row['last_name'], 'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to'],
        ], $profile['identity_history']);
        $payload['addresses'] = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'address_type' => $row['address_type'],
            'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to'],
        ], $profile['addresses']);
        $payload['identifiers'] = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'identifier_type' => $row['identifier_type'],
        ], $profile['identifiers']);
        $payload['accounts'] = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'label' => $row['label'],
            'allocation_basis_points' => $row['allocation_basis_points'],
            'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to'],
            'is_active' => $row['is_active'],
        ], $profile['accounts']);

        $response = $this->put($this->supplierId, $this->employeeId, $payload);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Uložení nezměněného kontaktu nesmí spadnout na duplicitu: ' . (string) $response->getBody(),
        );

        $saved = $this->json($response)['profile'];
        $emails = array_values(array_filter($saved['contacts'], static fn (array $c): bool => $c['contact_type'] === 'email'));
        self::assertCount(1, $emails, 'Řádek se stejnou hodnotou se recykluje, nezakládá se druhý.');
        self::assertSame($contactId, (int) $emails[0]['id']);
        self::assertTrue((bool) $emails[0]['is_active']);
        self::assertTrue((bool) $emails[0]['is_primary']);
    }

    public function testExistingSecretsCanBePreservedWithoutRevealOrPlaintextRoundTrip(): void
    {
        $created = $this->json($this->put(
            $this->supplierId,
            $this->employeeId,
            $this->completePayload(),
        ))['profile'];
        $before = $this->secretStorage();
        $created['row_version'] = 1;
        $created['secure_delivery_channel'] = 'paper';
        $created['identity_history'][0]['birth_surname'] = null;
        $response = $this->put($this->supplierId, $this->employeeId, $created);

        self::assertSame(200, $response->getStatusCode());
        $saved = $this->json($response)['profile'];
        self::assertSame(2, $saved['row_version']);
        self::assertSame('paper', $saved['secure_delivery_channel']);
        self::assertSame($before, $this->secretStorage());

        $legacy = $this->employees->find($this->supplierId, $this->employeeId);
        self::assertIsArray($legacy);
        self::assertSame('Jana Testovací', $legacy['full_name']);
    }

    public function testChangingAccountThroughProfileClearsPreviousVerification(): void
    {
        $created = $this->json($this->put(
            $this->supplierId,
            $this->employeeId,
            $this->completePayload(),
        ))['profile'];
        $accountId = (int) $created['accounts'][0]['id'];
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = "user_verified",
                    verified_on = "2026-01-02",
                    verified_by = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $this->userId,
            $this->supplierId,
            $this->employeeId,
            $accountId,
        ]);

        $verified = $this->json(
            $this->get($this->supplierId, $this->employeeId),
        )['profile'];
        self::assertSame(
            'user_verified',
            $verified['accounts'][0]['verification_source'],
        );

        $verified['row_version'] = 1;
        $verified['accounts'][0]['bank_account'] =
            'CZ6508000000192000145399';
        $response = $this->put(
            $this->supplierId,
            $this->employeeId,
            $verified,
        );

        self::assertSame(200, $response->getStatusCode());
        $savedAccount = $this->json($response)['profile']['accounts'][0];
        self::assertNull($savedAccount['verification_source']);
        self::assertNull($savedAccount['verified_on']);
        self::assertNull($savedAccount['verified_by']);
        self::assertStringEndsWith('5399', $savedAccount['bank_account_masked']);
    }

    public function testMaskedValuesCannotBeStoredAsPlaintext(): void
    {
        $created = $this->json($this->put(
            $this->supplierId,
            $this->employeeId,
            $this->completePayload(),
        ))['profile'];
        $before = $this->secretStorage();

        foreach ([
            ['identity_history', 'birth_surname', 'P••••••••'],
            ['contacts', 'value', 'j••••@example.invalid'],
            ['identifiers', 'value', '••••3456'],
            ['accounts', 'bank_account', '•••••5/0100'],
        ] as [$collection, $key, $masked]) {
            $payload = $created;
            $payload['row_version'] = 1;
            $payload[$collection][0][$key] = $masked;
            $response = $this->put($this->supplierId, $this->employeeId, $payload);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame($before, $this->secretStorage());
        }

        $address = $created;
        $address['row_version'] = 1;
        $address['addresses'][0] += [
            'street_line' => '••••••',
            'city' => 'Praha',
            'postal_code' => '100 00',
            'country_code' => 'CZ',
        ];
        self::assertSame(
            422,
            $this->put($this->supplierId, $this->employeeId, $address)->getStatusCode(),
        );
        self::assertSame($before, $this->secretStorage());
    }

    public function testStoredOverlapAndStaleVersionAreRejectedWithoutMutation(): void
    {
        $this->put($this->supplierId, $this->employeeId, $this->completePayload());

        $overlap = $this->put($this->supplierId, $this->employeeId, [
            'row_version' => 1,
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => 'Jana Překryv',
                'first_name' => 'Jana',
                'last_name' => 'Překryv',
                'effective_from' => '2026-06-01',
            ]],
        ]);
        self::assertSame(422, $overlap->getStatusCode());
        self::assertSame('validation_failed', $this->json($overlap)['error']['code']);
        self::assertSame(1, $this->json($this->get($this->supplierId, $this->employeeId))['profile']['row_version']);
        self::assertCount(
            1,
            $this->json($this->get($this->supplierId, $this->employeeId))['profile']['identity_history'],
        );

        $stale = $this->put($this->supplierId, $this->employeeId, [
            'row_version' => 0,
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
        ]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(1, $this->json($stale)['error']['current_row_version']);
    }

    public function testPersonalIdentifierIsUniqueAcrossPeopleInTenant(): void
    {
        self::assertSame(
            200,
            $this->put(
                $this->supplierId,
                $this->employeeId,
                $this->completePayload(),
            )->getStatusCode(),
        );
        $secondEmployeeId = $this->createEmployee($this->supplierId, 'Druhý Testovací');

        $duplicate = $this->put(
            $this->supplierId,
            $secondEmployeeId,
            $this->completePayload(),
        );

        self::assertSame(422, $duplicate->getStatusCode());
        self::assertSame('validation_failed', $this->json($duplicate)['error']['code']);
        self::assertSame(0, $this->profileRowCount($secondEmployeeId));
    }

    public function testReadyRejectsForeignTaxIdentifierAsPersonalIdentity(): void
    {
        $payload = $this->completePayload();
        $payload['profile_status'] = 'ready';
        $payload['identifiers'] = [[
            'identifier_type' => 'foreign_tax_identifier',
            'value' => 'DE:SYNTHETIC123',
        ]];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'RČ, EČP nebo VČP',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->profileRowCount($this->employeeId));
    }

    #[DataProvider('supportedCzechPersonalIdentifiers')]
    public function testReadyAcceptsEcpAndVcp(string $type, string $value): void
    {
        $payload = $this->completePayload();
        $payload['profile_status'] = 'ready';
        $payload['identifiers'] = [[
            'identifier_type' => $type,
            'value' => $value,
        ]];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ready', $this->json($response)['profile']['profile_status']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function supportedCzechPersonalIdentifiers(): iterable
    {
        yield 'EČP' => ['ecp', '123456789'];
        yield 'VČP' => ['vcp', '654321987'];
    }

    public function testReadyAcceptsStructurallyValidBirthNumber(): void
    {
        $payload = $this->completePayload();
        $payload['profile_status'] = 'ready';
        $payload['identifiers'] = [[
            'identifier_type' => 'birth_number',
            'value' => '000101/0009',
        ]];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ready', $this->json($response)['profile']['profile_status']);
    }

    public function testPayoutAllocationIsValidatedAtEveryFutureBoundary(): void
    {
        $payload = $this->completePayload();
        $payload['accounts'] = [
            [
                'label' => 'První interval',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 10000,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-06-30',
                'is_active' => true,
            ],
            [
                'label' => 'Druhý interval',
                'bank_account' => 'CZ6508000000192000145399',
                'allocation_basis_points' => 10000,
                'effective_from' => '2026-08-01',
                'is_active' => true,
            ],
        ];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            '2026-07-01',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->profileRowCount($this->employeeId));
    }

    public function testPartnerSettlementIsRefusedForOrdinaryEmployee(): void
    {
        $this->createEmployment($this->employeeId, 'employment');
        $payload = $this->completePayload();
        $payload['payout_method'] = 'partner_settlement';
        $payload['partner_settlement_account_code'] = '365.100';
        $payload['cash_allocation_basis_points'] = 0;
        $payload['accounts'] = [];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'Zápočtem na účet společníka',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->profileRowCount($this->employeeId));
    }

    public function testPartnerSettlementIsAcceptedForPartnerIncome(): void
    {
        $this->createEmployment($this->employeeId, 'partner_dependent');
        $payload = $this->completePayload();
        $payload['payout_method'] = 'partner_settlement';
        $payload['partner_settlement_account_code'] = '365.100';
        $payload['cash_allocation_basis_points'] = 0;
        $payload['accounts'] = [];

        $response = $this->put($this->supplierId, $this->employeeId, $payload);

        self::assertSame(200, $response->getStatusCode());
        $profile = $this->json($response)['profile'];
        self::assertSame('partner_settlement', $profile['payout_method']);
        self::assertSame('365.100', $profile['partner_settlement_account_code']);
    }

    public function testAuditFailureRollsBackWholeProfileMutation(): void
    {
        $employeeId = $this->createEmployee($this->supplierId, 'Audit Testovací');
        $repository = new PayrollPersonProfileRepository(
            $this->db,
            $this->sensitiveData,
            new FailingPayrollActivityLogger($this->db),
        );
        $activityBefore = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM activity_log'
        )->fetchColumn();

        try {
            $repository->save(
                $this->supplierId,
                $employeeId,
                $this->validator->validate($this->completePayload()),
                0,
                $this->userId,
                '127.0.0.1',
                'synthetic-test',
            );
            self::fail('Selhání auditu musí shodit celou mutaci.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic audit failure', $e->getMessage());
        }

        self::assertSame(0, $this->profileRowCount($employeeId));
        foreach ([
            'payroll_person_identity_history',
            'payroll_person_addresses',
            'payroll_person_contacts',
            'payroll_person_identifiers',
            'payroll_person_accounts',
        ] as $table) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employee_id = ?"
            );
            $stmt->execute([$this->supplierId, $employeeId]);
            self::assertSame(0, (int) $stmt->fetchColumn(), $table);
        }
        self::assertSame(
            $activityBefore,
            (int) $this->db->pdo()->query('SELECT COUNT(*) FROM activity_log')->fetchColumn(),
        );
        self::assertSame(
            'Audit Testovací',
            $this->employees->find($this->supplierId, $employeeId)['full_name'] ?? null,
        );
    }

    public function testTenantBoundaryBearerAndDisabledPayrollFailClosed(): void
    {
        $otherCreated = $this->json($this->put(
            $this->otherSupplierId,
            $this->otherEmployeeId,
            $this->completePayload(),
        ))['profile'];

        $foreignEmployee = $this->get($this->supplierId, $this->otherEmployeeId);
        self::assertSame(404, $foreignEmployee->getStatusCode());

        $foreignChild = $this->put($this->supplierId, $this->employeeId, [
            'row_version' => 0,
            'profile_status' => 'setup',
            'payout_method' => 'cash',
            'cash_allocation_basis_points' => 10000,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identifiers' => [[
                'id' => $otherCreated['identifiers'][0]['id'],
                'identifier_type' => 'ecp',
            ]],
        ]);
        self::assertSame(422, $foreignChild->getStatusCode());

        $bearer = $this->action->get(
            $this->request(
                'GET',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/profile",
                null,
                'accountant',
                'bearer',
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabled = $this->get($this->supplierId, $this->employeeId);
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabled)['error']['code']);
    }

    /** @return array<string,mixed> */
    private function completePayload(): array
    {
        return [
            'row_version' => 0,
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => 'Jana Testovací',
                'first_name' => 'Jana',
                'last_name' => 'Testovací',
                'birth_surname' => 'Příkladová',
                'effective_from' => '2026-01-01',
            ]],
            'addresses' => [[
                'address_type' => 'residence',
                'street_line' => 'Testovací 1',
                'city' => 'Praha',
                'postal_code' => '100 00',
                'country_code' => 'CZ',
                'effective_from' => '2026-01-01',
            ]],
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'jana.testovaci@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => '123456789',
            ]],
            'accounts' => [[
                'label' => 'Syntetický účet',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 10000,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ]],
        ];
    }

    private function get(int $supplierId, int $employeeId): Response
    {
        return $this->action->get(
            $this->request(
                'GET',
                $supplierId,
                "/api/payroll/people/{$employeeId}/profile",
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed> $payload */
    private function put(int $supplierId, int $employeeId, array $payload): Response
    {
        return $this->action->put(
            $this->request(
                'PUT',
                $supplierId,
                "/api/payroll/people/{$employeeId}/profile",
                $payload,
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        int $supplierId,
        string $path,
        ?array $body = null,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createEmployee(int $supplierId, string $fullName): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        );
        $stmt->execute([$supplierId, $fullName, 'employee', 'hpp', 32_000]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createEmployment(int $employeeId, string $relationType): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, ?, ?, "active", 0, "2026-01-01")'
        );
        $stmt->execute([
            $this->supplierId,
            $employeeId,
            'SYN-' . bin2hex(random_bytes(4)),
            $relationType,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function profileRowCount(int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employee_profiles'
            . ' WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$this->supplierId, $employeeId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,string> */
    private function secretStorage(): array
    {
        $identity = $this->db->pdo()->prepare(
            'SELECT COALESCE(birth_surname, \'\') FROM payroll_person_identity_history'
            . ' WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$this->supplierId, $this->employeeId]);
        $address = $this->db->pdo()->prepare(
            'SELECT CONCAT_WS(\'|\', street_line, city, postal_code, country_code)'
            . ' FROM payroll_person_addresses WHERE supplier_id = ? AND employee_id = ?'
        );
        $address->execute([$this->supplierId, $this->employeeId]);
        $contact = $this->db->pdo()->prepare(
            'SELECT contact_value_ciphertext FROM payroll_person_contacts'
            . ' WHERE supplier_id = ? AND employee_id = ?'
        );
        $contact->execute([$this->supplierId, $this->employeeId]);
        $identifier = $this->db->pdo()->prepare(
            'SELECT value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $identifier->execute([$this->supplierId, $this->employeeId]);
        $account = $this->db->pdo()->prepare(
            'SELECT bank_account_ciphertext
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $account->execute([$this->supplierId, $this->employeeId]);

        return [
            'birth_surname' => (string) $identity->fetchColumn(),
            'address' => (string) $address->fetchColumn(),
            'contact' => (string) $contact->fetchColumn(),
            'identifier' => (string) $identifier->fetchColumn(),
            'account' => (string) $account->fetchColumn(),
        ];
    }
}

final class FailingPayrollActivityLogger extends ActivityLogger
{
    /** @param array<array-key,mixed>|null $payload */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $supplierId = null,
    ): void {
        parent::log(
            $action,
            $userId,
            $entityType,
            $entityId,
            $payload,
            $ip,
            $userAgent,
            $supplierId,
        );

        throw new \RuntimeException('synthetic audit failure');
    }
}

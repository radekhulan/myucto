<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPayoutRulesAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * CRUD výplatních pravidel přes API.
 *
 * Tabulka `payroll_payout_rules` byla do teď bez zapisovací cesty, takže
 * PayoutAllocationService neměl co alokovat a plný mzdový modul neuměl vyrobit
 * závazek čisté mzdy. Test pokrývá zápisovou cestu včetně tří vlastností, na
 * kterých stojí její bezpečnost: optimistický zámek, tenant izolace a záruka
 * jediného aktivního zbytkového pravidla.
 */
#[Group('integration')]
final class PayrollPayoutRulesApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPayoutRulesAction $action;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $userId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollPayoutRulesAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceUser = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        self::assertInstanceOf(\PDOStatement::class, $sourceUser);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        $this->userId = (int) $sourceUser->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->foreignSupplierId]);
        $this->employeeId = $this->createEmployee($this->supplierId);
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

    public function testCreateListUpdateAndDeactivateRoundTrip(): void
    {
        $created = $this->json($this->action->create(
            $this->request('POST', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'priority_no' => 20,
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertArrayHasKey('rule', $created);
        $rule = $created['rule'];
        self::assertIsArray($rule);
        self::assertSame('cash', $rule['destination_kind']);
        self::assertSame('remainder', $rule['allocation_kind']);
        self::assertNull($rule['destination_reference']);
        self::assertTrue($rule['is_active']);
        self::assertSame(1, $rule['row_version']);
        self::assertNotSame('', $rule['allocation_reference']);

        $list = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertIsArray($list['rules']);
        self::assertCount(1, $list['rules']);

        $updated = $this->json($this->action->update(
            $this->request('PUT', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'priority_no' => 30,
                'row_version' => 1,
            ]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $rule['id'],
            ],
        ));
        self::assertSame(30, $updated['rule']['priority_no']);
        self::assertSame(2, $updated['rule']['row_version']);

        $deactivated = $this->json($this->action->deactivate(
            $this->request('DELETE', ['row_version' => 2]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $rule['id'],
            ],
        ));
        self::assertFalse($deactivated['rule']['is_active']);

        // Deaktivace není smazání — řádek musí zůstat viditelný, aby uživatel
        // věděl, že pravidlo existuje a je jen vypnuté.
        $afterList = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertCount(1, $afterList['rules']);
        self::assertFalse($afterList['rules'][0]['is_active']);
    }

    public function testStaleRowVersionIsRejectedWithCurrentVersion(): void
    {
        $ruleId = $this->createRule(['destination_kind' => 'cash']);
        $this->action->update(
            $this->request('PUT', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'priority_no' => 50,
                'row_version' => 1,
            ]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $ruleId,
            ],
        );

        $response = $this->action->update(
            $this->request('PUT', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'priority_no' => 60,
                'row_version' => 1,
            ]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $ruleId,
            ],
        );

        self::assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('row_version_conflict', $body['error']['code']);
        self::assertSame(2, $body['error']['current_row_version']);
    }

    /**
     * PayoutAllocationService vyžaduje PRÁVĚ JEDEN zbytek — se dvěma není
     * rozdělení jednoznačné. Aplikace to musí říct česky, ne až chybou 1062.
     */
    public function testSecondActiveRemainderIsRefusedWithReadableMessage(): void
    {
        $this->createRule(['destination_kind' => 'cash']);

        $response = $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_payout_rule', $body['error']['code']);
        self::assertStringContainsString(
            'zbytek výplaty',
            (string) $body['error']['message'],
        );
        self::assertSame(1, $this->activeRuleCount($this->supplierId));
    }

    /**
     * Poslední instance je databáze: index migrace 1378 nesmí pustit druhý
     * aktivní zbytek ani ručním SQL, které aplikaci obejde úplně.
     */
    public function testDatabaseIndexBlocksSecondRemainderInsertedByRawSql(): void
    {
        $this->createRule(['destination_kind' => 'cash']);

        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, allocation_kind, priority_no, is_active)
             VALUES (?, ?, ?, "cash", "remainder", 90, 1)'
        );

        try {
            $statement->execute([
                $this->supplierId,
                $this->employeeId,
                'raw-sql-second-remainder',
            ]);
            self::fail('Databáze musí druhý aktivní zbytek odmítnout.');
        } catch (\PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
            self::assertStringContainsString(
                'uq_payroll_payout_rule_single_remainder',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Neaktivní pravidla index neomezuje (NULL v unikátním indexu nekoliduje),
     * jinak by se pravidlo nedalo vyměnit za jiné.
     */
    public function testDeactivatedRemainderFreesTheSlotForANewOne(): void
    {
        $firstId = $this->createRule(['destination_kind' => 'cash']);
        $this->action->deactivate(
            $this->request('DELETE', ['row_version' => 1]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $firstId,
            ],
        );

        $response = $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->activeRuleCount($this->supplierId));
    }

    public function testForeignTenantCanNeitherSeeNorChangeTheRule(): void
    {
        $ruleId = $this->createRule(['destination_kind' => 'cash']);

        $listed = $this->action->list(
            $this->request('GET')->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->foreignSupplierId,
            ),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        // Osoba do cizí firmy nepatří, takže ani seznam pravidel neexistuje.
        self::assertSame(404, $listed->getStatusCode());

        $updated = $this->action->update(
            $this->request('PUT', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'row_version' => 1,
            ])->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->foreignSupplierId,
            ),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $ruleId,
            ],
        );
        self::assertSame(404, $updated->getStatusCode());
        self::assertSame(1, $this->rowVersion($ruleId));
    }

    /**
     * Bankovní cíl musí ukazovat na účet TÉHOŽ zaměstnance. Bez kontroly na
     * employee_id by šlo mzdu poslat na účet kolegy ze stejné firmy.
     */
    public function testBankRuleCannotTargetAnotherEmployeesAccount(): void
    {
        $colleagueId = $this->createEmployee($this->supplierId);
        $colleagueAccountId = $this->createAccount($colleagueId, verified: true);

        $response = $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'bank',
                'destination_reference' => "account:{$colleagueAccountId}",
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->activeRuleCount($this->supplierId));
    }

    /**
     * Neověřený účet zápis pravidla NEBLOKUJE — pravidlo musí jít připravit
     * dřív, než účetní ověření stihne. Uživatel se o tom ale musí dozvědět
     * hned, ne až při přípravě plateb nad zmrazenou revizí, kde už z toho vede
     * jen opravná revize.
     */
    public function testBankRuleOnUnverifiedAccountIsSavedButCarriesAWarning(): void
    {
        $accountId = $this->createAccount($this->employeeId, verified: false);

        $created = $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'bank',
                'destination_reference' => "account:{$accountId}",
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $body = $this->json($created);
        self::assertFalse($body['rule']['destination_verified']);
        self::assertCount(1, $body['warnings']);
        self::assertSame('unverified_destination', $body['warnings'][0]['code']);
        self::assertSame($body['rule']['id'], $body['warnings'][0]['rule_id']);
        self::assertSame($accountId, $body['warnings'][0]['account_id']);
        self::assertStringContainsString(
            'není ověřený',
            (string) $body['warnings'][0]['message'],
        );

        // Varování je funkce stavu, ne události zápisu — musí ho vidět i prosté
        // načtení karty, ne jen ten, kdo pravidlo právě uložil.
        $listed = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertCount(1, $listed['warnings']);
        self::assertFalse($listed['rules'][0]['destination_verified']);
    }

    public function testWarningDisappearsOnceTheAccountIsVerified(): void
    {
        $accountId = $this->createAccount($this->employeeId, verified: false);
        $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'bank',
                'destination_reference' => "account:{$accountId}",
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        $this->verifyAccount($accountId);

        $listed = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertSame([], $listed['warnings']);
        self::assertTrue($listed['rules'][0]['destination_verified']);
    }

    /**
     * U hotovosti a zápočtu na účet společníka ověření nedává smysl, proto NULL
     * a nikdy varování — `false` by se četlo jako vada, kterou nelze odstranit.
     */
    public function testCashAndPartnerSettlementRulesNeverCarryVerificationState(): void
    {
        $this->createEmployment($this->employeeId, 'partner_dependent');
        $cash = $this->json($this->action->create(
            $this->request('POST', [
                'destination_kind' => 'cash',
                'allocation_kind' => 'remainder',
                'priority_no' => 10,
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        $settlement = $this->json($this->action->create(
            $this->request('POST', [
                'destination_kind' => 'partner_settlement',
                'destination_reference' => '365.100',
                'allocation_kind' => 'percentage',
                'basis_points' => 2500,
                'priority_no' => 20,
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertNull($cash['rule']['destination_verified']);
        self::assertNull($settlement['rule']['destination_verified']);
        self::assertSame([], $cash['warnings']);
        self::assertSame([], $settlement['warnings']);

        $listed = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertSame([], $listed['warnings']);
        self::assertSame(
            [null, null],
            array_column($listed['rules'], 'destination_verified'),
        );
    }

    /** Vypnuté pravidlo do výplaty nevstupuje, takže se na neověřený účet nestěžuje. */
    public function testDeactivatedRuleStopsWarningAboutItsUnverifiedAccount(): void
    {
        $accountId = $this->createAccount($this->employeeId, verified: false);
        $ruleId = (int) $this->json($this->action->create(
            $this->request('POST', [
                'destination_kind' => 'bank',
                'destination_reference' => "account:{$accountId}",
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ))['rule']['id'];

        $deactivated = $this->json($this->action->deactivate(
            $this->request('DELETE', ['row_version' => 1]),
            new Response(),
            [
                'employeeId' => (string) $this->employeeId,
                'ruleId' => (string) $ruleId,
            ],
        ));

        self::assertFalse($deactivated['rule']['is_active']);
        self::assertSame([], $deactivated['warnings']);
        // Příznak cíle zůstává vypovídající i u vypnutého pravidla — jen se
        // z něj nedělá varování.
        self::assertFalse($deactivated['rule']['destination_verified']);
    }

    public function testProposalForCashProfileIsApplicableAndCreatesRealRow(): void
    {
        $this->setProfile('cash');

        $before = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));
        self::assertTrue($before['proposal']['available']);
        self::assertTrue($before['proposal']['applicable']);
        self::assertSame([
            [
                'destination_kind' => 'cash',
                'destination_reference' => null,
                'allocation_kind' => 'remainder',
                'amount_minor' => null,
                'basis_points' => null,
                'priority_no' => 100,
            ],
        ], $before['proposal']['rules']);

        $applied = $this->action->applyDefaults(
            $this->request('POST'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(201, $applied->getStatusCode(), (string) $applied->getBody());
        $body = $this->json($applied);
        self::assertCount(1, $body['rules']);
        self::assertSame('cash', $body['rules'][0]['destination_kind']);
        self::assertSame('remainder', $body['rules'][0]['allocation_kind']);
        // Pravidlo vzniklo jako SKUTEČNÝ řádek, ne jako dopočet při výplatě.
        self::assertSame(1, $this->activeRuleCount($this->supplierId));
    }

    public function testProposalForBankProfileUsesTheSingleVerifiedAccount(): void
    {
        $this->setProfile('bank');
        $accountId = $this->createAccount($this->employeeId, verified: true);

        $body = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertTrue($body['proposal']['available']);
        self::assertSame(
            "account:{$accountId}",
            $body['proposal']['rules'][0]['destination_reference'],
        );
    }

    public function testProposalForBankProfileBlocksWithoutVerifiedAccount(): void
    {
        $this->setProfile('bank');
        $this->createAccount($this->employeeId, verified: false);

        $body = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertFalse($body['proposal']['available']);
        self::assertSame([], $body['proposal']['rules']);
        self::assertStringContainsString(
            'ověřený výplatní účet',
            (string) $body['proposal']['blocked_reason'],
        );

        $applied = $this->action->applyDefaults(
            $this->request('POST'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(409, $applied->getStatusCode());
        self::assertSame(0, $this->activeRuleCount($this->supplierId));
    }

    public function testProposalForBankProfileBlocksWithSeveralVerifiedAccounts(): void
    {
        $this->setProfile('bank');
        $this->createAccount($this->employeeId, verified: true);
        $this->createAccount($this->employeeId, verified: true);

        $body = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertFalse($body['proposal']['available']);
        self::assertStringContainsString(
            'vyberte cílový účet ručně',
            (string) $body['proposal']['blocked_reason'],
        );
    }

    public function testProposalForMixedProfileRequiresManualSetup(): void
    {
        $this->setProfile('mixed');

        $body = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertFalse($body['proposal']['available']);
        self::assertStringContainsString(
            'Rozdělenou výplatu je nutné zadat ručně',
            (string) $body['proposal']['blocked_reason'],
        );
    }

    public function testProposalForPartnerSettlementUsesCardAccountCode(): void
    {
        $this->setProfile('partner_settlement', '365.100');
        $this->createEmployment($this->employeeId, 'partner_dependent');

        $body = $this->json($this->action->list(
            $this->request('GET'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        ));

        self::assertTrue($body['proposal']['available']);
        self::assertSame(
            'partner_settlement',
            $body['proposal']['rules'][0]['destination_kind'],
        );
        self::assertSame(
            '365.100',
            $body['proposal']['rules'][0]['destination_reference'],
        );

        $applied = $this->action->applyDefaults(
            $this->request('POST'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(201, $applied->getStatusCode(), (string) $applied->getBody());
    }

    /**
     * Zápočet proti účtu společníka smí použít jen společník či statutár —
     * běžný zaměstnanec ne. Kontrola musí padnout už při zápisu pravidla, ne
     * až nad zmrazenou revizí.
     */
    public function testPartnerSettlementRuleIsRefusedForOrdinaryEmployee(): void
    {
        $this->setProfile('partner_settlement', '365.100');
        $this->createEmployment($this->employeeId, 'employment');

        $response = $this->action->create(
            $this->request('POST', [
                'destination_kind' => 'partner_settlement',
                'destination_reference' => '365.100',
                'allocation_kind' => 'remainder',
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString(
            'Zápočtem na účet společníka',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->activeRuleCount($this->supplierId));
    }

    /** Ruční zadání má vždycky přednost — výchozí sada nic nepřepisuje. */
    public function testApplyDefaultsNeverOverwritesManualRules(): void
    {
        $this->setProfile('cash');
        $this->createRule([
            'destination_kind' => 'cash',
            'priority_no' => 77,
        ]);

        $response = $this->action->applyDefaults(
            $this->request('POST'),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->activeRuleCount($this->supplierId));
        $remaining = $this->db->pdo()->prepare(
            'SELECT priority_no FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $remaining->execute([$this->supplierId, $this->employeeId]);
        self::assertSame(77, (int) $remaining->fetchColumn());
    }

    public function testBulkDefaultsPreviewAndApplyDeriveRulesFromCards(): void
    {
        $this->setProfile('cash');
        $bankEmployee = $this->createEmployee($this->supplierId);
        $this->setProfileFor($bankEmployee, 'bank');
        $this->createAccount($bankEmployee, verified: false);
        $manualEmployee = $this->createEmployee($this->supplierId);
        $this->setProfileFor($manualEmployee, 'cash');
        $this->createRule(['destination_kind' => 'cash', 'priority_no' => 77]);
        $this->db->pdo()->prepare('UPDATE payroll_payout_rules SET employee_id = ? WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$manualEmployee, $this->supplierId, $this->employeeId]);
        $foreignEmployee = $this->createEmployee($this->foreignSupplierId);
        $ids = [$this->employeeId, $bankEmployee, $manualEmployee, $foreignEmployee];

        $preview = $this->action->previewDefaultsBulk(
            $this->request('POST', ['employee_ids' => $ids]),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $people = array_column($this->json($preview)['people'], null, 'employee_id');
        self::assertSame([$this->employeeId, $bankEmployee, $manualEmployee], array_values(array_intersect(array_keys($people), $ids)));
        self::assertArrayNotHasKey($foreignEmployee, $people);
        self::assertTrue($people[$this->employeeId]['applicable']);
        self::assertSame('cash', $people[$this->employeeId]['destination_kind']);
        self::assertFalse($people[$bankEmployee]['applicable']);
        self::assertStringContainsString('ověřený výplatní účet', (string) $people[$bankEmployee]['blocked_reason']);
        self::assertFalse($people[$manualEmployee]['applicable']);
        self::assertSame(1, $this->activeRuleCount($this->supplierId));

        $applied = $this->action->applyDefaultsBulk(
            $this->request('POST', ['employee_ids' => $ids]),
            new Response(),
        );
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getBody());
        $body = $this->json($applied);
        self::assertSame([$this->employeeId], $body['applied']);
        self::assertEqualsCanonicalizing([$bankEmployee, $manualEmployee], array_column($body['skipped'], 'employee_id'));
        self::assertSame(2, $this->activeRuleCount($this->supplierId));
        self::assertSame(0, $this->activeRuleCount($this->foreignSupplierId));
    }

    public function testBulkPreviewMatchesSinglePreviewWithConstantQueryCount(): void
    {
        $this->setProfile('cash');
        $people = [$this->employeeId];
        foreach (['bank_one', 'bank_two', 'bank_none', 'mixed', 'manual', 'no_card'] as $variant) {
            $employeeId = $this->createEmployee($this->supplierId);
            $people[] = $employeeId;
            if ($variant === 'no_card') {
                continue;
            }
            $this->setProfileFor($employeeId, $variant === 'mixed' ? 'mixed' : ($variant === 'manual' ? 'cash' : 'bank'));
            if ($variant === 'bank_one' || $variant === 'bank_two') {
                $this->createAccount($employeeId, verified: true);
            }
            if ($variant === 'bank_two') {
                $this->createAccount($employeeId, verified: true);
            }
            if ($variant === 'manual') {
                $this->db->pdo()->prepare(
                    'INSERT INTO payroll_payout_rules
                        (supplier_id, employee_id, allocation_reference, destination_kind, allocation_kind, priority_no, is_active)
                     VALUES (?, ?, ?, "cash", "remainder", 5, 1)',
                )->execute([$this->supplierId, $employeeId, 'manual-' . $employeeId]);
            }
        }

        $preview = function (array $ids): array {
            $before = $this->questions();
            $response = $this->action->previewDefaultsBulk(
                $this->request('POST', ['employee_ids' => $ids]),
                new Response(),
            );
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

            return [array_column($this->json($response)['people'], null, 'employee_id'), $this->questions() - $before];
        };
        $preview($people);
        [, $smallQueries] = $preview(array_slice($people, 0, 2));
        [$rows, $allQueries] = $preview($people);
        self::assertSame($smallQueries, $allQueries);

        foreach ($people as $employeeId) {
            $single = $this->json($this->action->list(
                $this->request('GET'),
                new Response(),
                ['employeeId' => (string) $employeeId],
            ))['proposal'];
            self::assertSame($single['available'] && $single['applicable'], $rows[$employeeId]['applicable'], (string) $employeeId);
            self::assertSame($single['blocked_reason'], $rows[$employeeId]['blocked_reason'], (string) $employeeId);
            self::assertSame($single['payout_method'], $rows[$employeeId]['payout_method'], (string) $employeeId);
            self::assertSame($single['rules'][0]['destination_kind'] ?? null, $rows[$employeeId]['destination_kind'], (string) $employeeId);
        }
        self::assertTrue($rows[$people[1]]['applicable']);
        self::assertStringContainsString('vyberte cílový účet ručně', (string) $rows[$people[2]]['blocked_reason']);
        self::assertStringContainsString('ověřený výplatní účet', (string) $rows[$people[3]]['blocked_reason']);
        self::assertFalse($rows[$people[5]]['applicable']);
        self::assertStringContainsString('osobní kartu', (string) $rows[$people[6]]['blocked_reason']);
    }

    public function testPreviewWithoutSelectionOffersPeopleWithoutRules(): void
    {
        $employ = function (int $employeeId, string $status): void {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date)
                 VALUES (?, ?, ?, "dpp", ?, "2026-01-01")',
            )->execute([$this->supplierId, $employeeId, 'PR-' . $employeeId, $status]);
        };
        $this->setProfile('cash');
        $employ($this->employeeId, 'active');
        $withRule = $this->createEmployee($this->supplierId);
        $this->setProfileFor($withRule, 'cash');
        $employ($withRule, 'active');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference, destination_kind, allocation_kind, priority_no, is_active)
             VALUES (?, ?, "manual", "cash", "remainder", 5, 1)',
        )->execute([$this->supplierId, $withRule]);
        $ended = $this->createEmployee($this->supplierId);
        $employ($ended, 'ended');
        $withoutEmployment = $this->createEmployee($this->supplierId);
        $foreign = $this->createEmployee($this->foreignSupplierId);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "PR-F", "dpp", "active", "2026-01-01")',
        )->execute([$this->foreignSupplierId, $foreign]);

        $response = $this->action->previewDefaultsBulk($this->request('POST', []), new Response());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertFalse($body['truncated']);
        self::assertSame([$this->employeeId], array_column($body['people'], 'employee_id'));
        self::assertTrue($body['people'][0]['applicable']);
        self::assertNotContains($withoutEmployment, array_column($body['people'], 'employee_id'));
    }

    private function questions(): int
    {
        return (int) ($this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
    }

    public function testBulkDefaultsRejectEmptySelection(): void
    {
        $response = $this->action->applyDefaultsBulk(
            $this->request('POST', ['employee_ids' => []]),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->activeRuleCount($this->supplierId));
    }

    public function testBearerTokenCannotReachThePayoutRules(): void
    {
        $response = $this->action->list(
            $this->request('GET')->withAttribute(
                AuthMiddleware::ATTR_METHOD,
                'bearer',
            ),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($response)['error']['code'],
        );
    }

    /** @param array<string,mixed> $overrides */
    private function createRule(array $overrides): int
    {
        $response = $this->action->create(
            $this->request('POST', [
                'allocation_kind' => 'remainder',
                ...$overrides,
            ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['rule']['id'];
    }

    private function createEmployee(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická výplatní osoba", "employee", 1)'
        )->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createEmployment(int $employeeId, string $relationType): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, ?, ?, "active", 0, "2026-01-01")'
        )->execute([
            $this->supplierId,
            $employeeId,
            'SYN-' . bin2hex(random_bytes(4)),
            $relationType,
        ]);
    }

    private function createAccount(int $employeeId, bool $verified): int
    {
        $hash = hash('sha256', 'synthetic-payout-rule:' . bin2hex(random_bytes(8)));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, allocation_basis_points,
                 effective_from, is_active, row_version,
                 verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic", UNHEX(?),
                     "••••0000", 10000, "2026-01-01", 1, 1, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employeeId,
            $hash,
            $verified ? 'user_verified' : null,
            $verified ? '2026-01-05' : null,
            $verified ? $this->userId : null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Doplní účtu kompletní ověření — tak, jak to dělá „Ověřit účet" na kartě. */
    private function verifyAccount(int $accountId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = "user_verified",
                    verified_on = "2026-01-05",
                    verified_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([
            $this->userId,
            $this->supplierId,
            $this->employeeId,
            $accountId,
        ]);
    }

    private function setProfile(string $payoutMethod, ?string $accountCode = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status, payout_method,
                 partner_settlement_account_code, cash_allocation_basis_points,
                 payout_effective_on, secure_delivery_channel)
             VALUES (?, ?, "ready", ?, ?, 10000, "2026-01-01", "portal")
             ON DUPLICATE KEY UPDATE payout_method = VALUES(payout_method),
                 partner_settlement_account_code =
                     VALUES(partner_settlement_account_code)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $payoutMethod,
            $accountCode,
        ]);
    }

    private function setProfileFor(int $employeeId, string $payoutMethod): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status, payout_method,
                 partner_settlement_account_code, cash_allocation_basis_points,
                 payout_effective_on, secure_delivery_channel)
             VALUES (?, ?, "ready", ?, NULL, 10000, "2026-01-01", "portal")'
        )->execute([$this->supplierId, $employeeId, $payoutMethod]);
    }

    private function activeRuleCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_payout_rules
              WHERE supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function rowVersion(int $ruleId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_payout_rules
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $ruleId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method = 'GET',
        ?array $body = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                "/api/payroll/people/{$this->employeeId}/payout-rules",
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $value = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('API odpověď není objekt.');
        }

        return $value;
    }
}

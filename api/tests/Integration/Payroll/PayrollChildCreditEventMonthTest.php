<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementClaimMonths;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * § 35c odst. 10 ZDP od karty dítěte až po výpočet: měsíc narození, osvojení,
 * převzetí do péče a zahájení studia patří do nároku, i když vyživování začalo
 * v průběhu měsíce; měsíc, ve kterém vyživování skončí (konec studia, úmrtí),
 * patří do nároku celý.
 *
 * Každý scénář jde cestou, kterou jde účetní: karta dítěte → uplatnění →
 * zmrazený snímek zákonné evidence (z něj čte měsíční výpočet, měsíční
 * hlášení i potvrzení § 38j) → měsíční zvýhodnění a roční zúčtování.
 */
#[Group('integration')]
final class PayrollChildCreditEventMonthTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MONTHLY_FIRST_CHILD = 126_700;

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PayrollDependantAction $action;
    private PayrollPersonStatutoryEvidenceRepository $statutory;
    private PayrollAnnualSettlementRepository $settlements;
    private int $userId;
    private int $supplierId;
    private int $employeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollDependantAction::class);
            $this->statutory = $container->get(PayrollPersonStatutoryEvidenceRepository::class);
            $this->settlements = $container->get(PayrollAnnualSettlementRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_dependants')) {
            $this->markTestSkipped('Migrace 1312 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        )->execute([$this->supplierId, 'Syntetický Poplatník', 'employee', 'hpp', 40_000]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to, evidence_reference)
             VALUES (?, ?, 'signed', '2020-01-01', NULL, 'document:tax-declaration')"
        )->execute([$this->supplierId, $this->employeeId]);
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

    public function testChildBornOnFifteenthOfMayCarriesTheCreditForMay(): void
    {
        $child = $this->createChild([
            'birth_date' => '2026-05-15',
            'existence_from' => '2026-05-15',
        ]);

        $april = $this->postClaim($child, $this->claim(['effective_from' => '2026-04-01']));
        self::assertSame(422, $april->getStatusCode(), 'Před narozením nárok nevzniká.');

        $may = $this->postClaim($child, $this->claim(['effective_from' => '2026-05-01']));
        self::assertSame(200, $may->getStatusCode(), (string) $may->getBody());
        self::assertSame(
            [],
            $this->json($may)['dependants'][0]['claims'][0]['blockers'],
        );

        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-05-31'));
        self::assertSame([5, 6, 7, 8, 9, 10, 11, 12], $this->annualMonths()['claimed']);
    }

    public function testEndOfStudiesMidMonthKeepsTheWholeMonthAndNoMore(): void
    {
        $child = $this->createChild([
            'birth_date' => '2004-03-03',
            'existence_from' => '2004-03-03',
            'existence_to' => '2026-06-15',
            'student' => true,
        ]);

        $july = $this->postClaim($child, $this->claim(['effective_to' => '2026-07-31']));
        self::assertSame(422, $july->getStatusCode(), 'Po měsíci konce studia nárok nepokračuje.');

        $june = $this->postClaim($child, $this->claim(['effective_to' => '2026-06-30']));
        self::assertSame(200, $june->getStatusCode(), (string) $june->getBody());

        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-06-30'));
        self::assertSame(0, $this->monthlyChildCredit('2026-07-31'));
        self::assertSame([1, 2, 3, 4, 5, 6], $this->annualMonths()['claimed']);
    }

    public function testDeathOfChildMidMonthKeepsTheWholeMonth(): void
    {
        $child = $this->createChild([
            'birth_date' => '2018-02-02',
            'existence_from' => '2018-02-02',
            'existence_to' => '2026-08-10',
        ]);

        $response = $this->postClaim($child, $this->claim(['effective_to' => '2026-08-31']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-08-31'));
        self::assertCount(8, $this->annualMonths()['claimed']);
    }

    public function testStudyStartMidMonthOpensThatMonthOnlyWithTheReason(): void
    {
        $child = $this->createChild([
            'birth_date' => '2007-03-03',
            'existence_from' => '2026-10-20',
            'student' => true,
        ]);

        $withoutReason = $this->postClaim($child, $this->claim(['effective_from' => '2026-10-01']));
        self::assertSame(422, $withoutReason->getStatusCode());
        self::assertStringContainsString(
            '2026-11-01',
            (string) $this->json($withoutReason)['error']['message'],
        );

        $response = $this->postClaim($child, $this->claim([
            'claim_reason' => 'study_start',
            'effective_from' => '2026-10-01',
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-10-31'));
        self::assertSame([10, 11, 12], $this->annualMonths()['claimed']);
    }

    /**
     * ZTP/P vlastní výjimku nemá (dvojnásobek až od měsíce, na jehož počátku
     * byl nárok na průkaz přiznán), ale dítě s už přiznaným ZTP/P osvojené
     * v průběhu měsíce má za měsíc osvojení dvojnásobné zvýhodnění.
     */
    public function testZtpPChildAdoptedMidMonthHasDoubleCreditForTheAdoptionMonth(): void
    {
        $child = $this->createChild([
            'relation' => 'child_adopted',
            'birth_date' => '2020-02-02',
            'existence_from' => '2026-05-15',
            'ztp_p' => true,
        ]);

        $response = $this->postClaim($child, $this->claim([
            'claim_reason' => 'adoption',
            'ztp_p' => true,
            'effective_from' => '2026-05-01',
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame(2 * self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-05-31'));
        $annual = $this->annualMonths();
        self::assertSame([5, 6, 7, 8, 9, 10, 11, 12], $annual['claimed']);
        self::assertSame([5, 6, 7, 8, 9, 10, 11, 12], $annual['ztp_p']);
    }

    /**
     * DAN-7: průkaz ZTP/P přiznaný 12. května. Dvojnásobek náleží až za
     * červen, první měsíc, na jehož počátku průkaz platil (§ 35c odst. 7
     * a 10 ZDP). Účetní zadá den přiznání na kartě dítěte a jeden nárok se
     * ZTP/P od ledna; zápis ho rozdělí a výpočet i roční zúčtování dostanou
     * květen v základní výši. Dřív šlo pravidlo jen z nápovědy a nárok od
     * ledna dal dvojnásobek i za měsíce před přiznáním.
     */
    public function testZtpPGrantedMidMonthDoublesTheCreditOnlyFromTheNextMonth(): void
    {
        $child = $this->createChild([
            'birth_date' => '2019-02-02',
            'existence_from' => '2019-02-02',
            'ztp_p' => true,
            'ztp_p_granted_on' => '2026-05-12',
        ]);

        $response = $this->postClaim($child, $this->claim(['ztp_p' => true]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $dependant = $this->json($response)['dependants'][0];
        self::assertSame('2026-05-12', $dependant['ztp_p_granted_on']);
        self::assertSame('2026-06-01', $dependant['ztp_p_double_from']);
        $claims = array_map(
            static fn (array $claim): array => [$claim['effective_from'], $claim['effective_to'], $claim['ztp_p'], $claim['blockers']],
            $dependant['claims'],
        );
        usort($claims, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([
            ['2026-01-01', '2026-05-31', false, []],
            ['2026-06-01', null, true, []],
        ], $claims);

        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-05-31'));
        self::assertSame(2 * self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-06-30'));
        $annual = $this->annualMonths();
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], $annual['claimed']);
        self::assertSame([6, 7, 8, 9, 10, 11, 12], $annual['ztp_p']);
    }

    /** Průkaz platný od prvního dne měsíce otevírá dvojnásobek už za ten měsíc. */
    public function testZtpPGrantedOnTheFirstDayDoublesThatMonth(): void
    {
        $child = $this->createChild([
            'birth_date' => '2019-02-02',
            'existence_from' => '2019-02-02',
            'ztp_p' => true,
            'ztp_p_granted_on' => '2026-05-01',
        ]);

        $response = $this->postClaim($child, $this->claim(['ztp_p' => true, 'effective_from' => '2026-05-01']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->json($response)['dependants'][0]['claims']);
        self::assertSame(2 * self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-05-31'));
    }

    public function testZtpPGrantDateWithoutTheCardIsRejected(): void
    {
        $response = $this->action->create(
            $this->request("/api/payroll/people/{$this->employeeId}/dependants", [
                'relation' => 'child_own',
                'full_name' => 'Syntetické Dítě',
                'birth_date' => '2019-02-02',
                'birth_number' => null,
                'ztp_p' => false,
                'ztp_p_granted_on' => '2026-05-12',
                'student' => false,
                'existence_from' => '2019-02-02',
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testChildMovingInMidMonthWithoutStartEventStartsNextMonth(): void
    {
        $child = $this->createChild([
            'relation' => 'child_of_spouse',
            'birth_date' => '2015-02-02',
            'existence_from' => '2026-05-15',
        ]);

        $response = $this->postClaim($child, $this->claim(['effective_from' => '2026-05-01']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            200,
            $this->postClaim($child, $this->claim(['effective_from' => '2026-06-01']))->getStatusCode(),
        );
        self::assertSame(0, $this->monthlyChildCredit('2026-05-31'));
    }

    /** Převod z předchozího mzdového systému nesmí měsíc narození odsunout. */
    public function testTakeoverOfChildBornMidMonthClaimsTheBirthMonth(): void
    {
        $writer = $this->container->get(PayrollTakeoverPersonWriter::class);
        $writer->children(
            $this->supplierId,
            $this->employeeId,
            new PayrollTakeoverPerson(
                'synthetic-person',
                children: [[
                    'order' => 1,
                    'code' => '1',
                    'reference' => 'document:takeover-child',
                    'given_name' => 'Syntetické',
                    'family_name' => 'Dítě',
                    'birth_number' => '260515/0009',
                    'from' => '2026-05-15',
                    'to' => null,
                ]],
                firstSignedPeriod: '2026-01',
            ),
            $this->userId,
            new PayrollTakeoverPolicy('synthetic', 'Syntetický zdroj'),
        );

        self::assertSame(self::MONTHLY_FIRST_CHILD, $this->monthlyChildCredit('2026-05-31'));
        self::assertSame([5, 6, 7, 8, 9, 10, 11, 12], $this->annualMonths()['claimed']);
    }

    // --- pomocníci ---------------------------------------------------------

    /** Měsíční zvýhodnění ze zmrazeného snímku evidence k danému měsíci. */
    private function monthlyChildCredit(string $monthEnd): int
    {
        $snapshot = $this->statutory->snapshot($this->supplierId, $this->employeeId, $monthEnd);
        self::assertIsArray($snapshot);
        $claims = array_map(
            static fn (array $row): TaxChildClaim => new TaxChildClaim(
                (string) $row['child_reference'],
                (int) $row['child_order'],
                (bool) $row['ztp_p'],
                (string) $row['effective_from'],
                $row['effective_to'] === null ? null : (string) $row['effective_to'],
                TaxEvidenceStatus::from((string) $row['evidence_status']),
                (bool) $row['shared_household_confirmed'],
                (bool) $row['other_claimant_excluded'],
                $row['evidence_reference'] === null ? null : (string) $row['evidence_reference'],
            ),
            $snapshot['income_tax']['child_claims'],
        );

        $calculator = new MonthlyEmploymentIncomeTaxCalculator(new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate(PayrollRulesetDomain::IncomeTax, $monthEnd),
        ]));
        $result = $calculator->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: $monthEnd,
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'employment',
                'synthetic-payer',
                EmploymentRelationshipKind::Employment,
                [new IncomeTaxComponent('synthetic-income', 4_000_000)],
            )],
            declarations: [new TaxDeclarationEvidence(
                TaxDeclarationStatus::Signed,
                '2026-01-01',
                null,
                'document:tax-declaration',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2026-01-01',
                null,
                'document:tax-residence',
            ),
            childClaims: $claims,
        ));

        return $result->claimedChildCreditMinorUnits;
    }

    /** @return array{claimed:list<int>,ztp_p:list<int>} */
    private function annualMonths(): array
    {
        $result = (new AnnualSettlementClaimMonths())->children(
            $this->settlements->childClaimsForYear($this->supplierId, $this->employeeId, 2026),
            2026,
        );
        self::assertSame([], $result['blockers']);
        self::assertCount(1, $result['children']);

        return [
            'claimed' => $result['children'][0]->claimedMonths,
            'ztp_p' => $result['children'][0]->ztpPClaimedMonths,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function createChild(array $overrides): int
    {
        $response = $this->action->create(
            $this->request("/api/payroll/people/{$this->employeeId}/dependants", $overrides + [
                'relation' => 'child_own',
                'full_name' => 'Syntetické Dítě',
                'birth_number' => null,
                'ztp_p' => false,
                'student' => false,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $dependants = $this->json($response)['dependants'];

        return (int) $dependants[count($dependants) - 1]['id'];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function claim(array $overrides = []): array
    {
        return $overrides + [
            'child_order' => 1,
            'claim_reason' => 'own_household',
            'evidence_status' => 'verified',
            'evidence_reference' => 'document:child-claim',
            'shared_household_confirmed' => true,
            'other_claimant_excluded' => true,
            'other_household_caregiver_status' => 'none',
            'ztp_p' => false,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function postClaim(int $dependantId, array $payload): Response
    {
        return $this->action->createClaim(
            $this->request(
                "/api/payroll/people/{$this->employeeId}/dependants/{$dependantId}/claims",
                $payload,
            ),
            new Response(),
            ['id' => (string) $this->employeeId, 'dependantId' => (string) $dependantId],
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
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

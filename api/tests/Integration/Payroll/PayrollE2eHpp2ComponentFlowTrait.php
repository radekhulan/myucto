<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollTravelAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Společný tok scénářů mzdových složek HPP: firma s VÝCHOZÍM číselníkem
 * složek, vstupy přes API mzdových vstupů (koncept, schválení), běh až po
 * schválení, příprava a test měsíčního hlášení.
 *
 * Všechny osoby: HPP 40 h týdně, podepsané prohlášení (sleva na poplatníka
 * 2 570 Kč). Pravidla ručních přepočtů (sada 2026): SP zaměstnanec 7,1 %
 * (z. č. 589/1992 Sb., vyměřovací základ § 5d a pojistné § 7 odst. 3 na
 * koruny nahoru), ZP 13,5 % na koruny nahoru (z. č. 592/1992 Sb. § 2),
 * zaměstnanec třetina; záloha 15 % ze základu zaokrouhleného na stokoruny
 * nahoru (§ 38h odst. 2 ZDP).
 */
trait PayrollE2eHpp2ComponentFlowTrait
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $sequence = 0;

    private function bootComponentFlow(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('SLO', 'Syntetická účtárna složek', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $components = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $components->ensureDefaults($this->supplierId);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        bool $taxDeclarationSigned = true,
        string $periodStart = self::PERIOD_START,
    ): array {
        $sequence = ++$this->sequence;
        $period = substr($periodStart, 0, 7);
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            $taxDeclarationSigned,
            $periodStart,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays($period));
        $this->createApprovedAverage($person['employment_id'], 2);

        return $person + ['sequence' => $sequence];
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, string $period = self::PERIOD): void
    {
        $response = $this->approveTimeMonth($employmentId, $period, $workedDates);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $response->getBody());
    }

    /**
     * Mzdový vstup tak, jak ho zadá účetní: koncept a schválení přes API.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array<string,mixed> schválený vstup
     */
    private function input(array $person, string $code, int $amountMinor, string $externalId, string $period = self::PERIOD): array
    {
        return $this->inputById($person, $this->componentId($code), $amountMinor, $externalId, $period);
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array<string,mixed>
     */
    private function inputById(array $person, int $componentId, int $amountMinor, string $externalId, string $period = self::PERIOD): array
    {
        $response = $this->inputResponse($person, $componentId, $amountMinor, $externalId, $period);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení mzdového vstupu. ' . (string) $response->getBody());

        return $this->json($response)['input'];
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function inputResponse(array $person, int $componentId, int $amountMinor, string $externalId, string $period = self::PERIOD): ResponseInterface
    {
        $inputs = $this->inputs();
        $created = $inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody([
                'employee_id' => $person['employee_id'],
                'employment_id' => $person['employment_id'],
                'component_id' => $componentId,
                'period' => $period,
                'source_period' => null,
                'amount_minor' => $amountMinor,
                'quantity_milliunits' => null,
                'source_kind' => 'manual',
                'external_id' => $externalId,
            ]),
            new Response(),
        );
        if ($created->getStatusCode() !== 201) {
            return $created;
        }
        $input = $this->json($created)['input'];

        return $inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$input['id']}/approve")
                ->withParsedBody(['row_version' => $input['row_version']]),
            new Response(),
            ['id' => (string) $input['id']],
        );
    }

    /** Vlastní odměna založená v číselníku složek přes API. */
    private function createBonusComponent(string $code, string $frequency): int
    {
        $response = $this->components()->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => $code,
                'name' => 'Syntetická odměna ' . $code,
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => $frequency,
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'included',
                'enforcement_treatment' => 'included',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'exemption_basket' => null,
                'exemption_basis' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), 'Zaseknutí: založení složky. ' . (string) $response->getBody());

        return (int) $this->json($response)['component']['id'];
    }

    /**
     * Posouzení výchozí benefitní složky účetní: nepeněžní plnění z
     * nedaňových zdrojů, osvobozené v rámci zákonného koše.
     */
    private function classifyAsExemptBasket(int $componentId): void
    {
        $repository = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $repository);
        $component = $repository->find($this->supplierId, $componentId);
        self::assertIsArray($component);
        $response = $this->components()->update(
            $this->request('PUT', "/api/payroll/components/{$componentId}")->withParsedBody([
                'code' => $component['code'],
                'name' => $component['name'],
                'component_kind' => $component['component_kind'],
                'value_kind' => $component['value_kind'],
                'frequency_kind' => $component['frequency_kind'],
                'tax_treatment' => 'exempt',
                'social_participation_treatment' => 'excluded',
                'social_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => $component['statistics_treatment'],
                'accounting_debit_code' => $component['accounting_debit_code'] ?? null,
                'accounting_credit_code' => $component['accounting_credit_code'] ?? null,
                'annual_limit_minor' => null,
                'exemption_basket' => $component['exemption_basket'],
                'exemption_basis' => 'benefit_basket',
                'valid_from' => $component['valid_from'],
                'valid_to' => $component['valid_to'] ?? null,
                'is_active' => true,
                'row_version' => $component['row_version'],
            ]),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: posouzení benefitní složky. ' . (string) $response->getBody());
    }

    /**
     * Vyúčtování pracovní cesty založené a schválené přes API.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function approvedTrip(array $person, array $overrides): array
    {
        $travel = $this->travel();
        $created = $travel->create(
            $this->request('POST', '/api/payroll/travel/trips')->withParsedBody($overrides + [
                'employee_id' => $person['employee_id'],
                'employment_id' => $person['employment_id'],
                'country_code' => 'CZ',
                'timezone' => 'Europe/Prague',
                'origin_place' => 'Praha',
                'destination_place' => 'Brno',
                'purpose' => 'Syntetické jednání',
                'transport_mode' => 'public_transport',
                'meal_rate_band_1' => null,
                'advance' => null,
                'settlement_period' => self::PERIOD,
                'items' => [],
                'free_meals' => [],
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), 'Zaseknutí: pracovní cesta. ' . (string) $created->getBody());
        $trip = $this->json($created)['trip'];
        $approved = $travel->approve(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$trip['id']}/approve")
                ->withParsedBody(['row_version' => $trip['row_version']]),
            new Response(),
            ['id' => (string) $trip['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: schválení cesty. ' . (string) $approved->getBody());

        return $this->json($approved)['trip'];
    }

    /**
     * Promítne schválené vyúčtování do mzdových vstupů a schválí je.
     *
     * @return array<string,int> kód složky => částka v haléřích
     */
    private function materializeTrip(int $tripId, int $employmentId): array
    {
        $response = $this->travel()->materialize(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/materialize"),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: promítnutí cesty do mezd. ' . (string) $response->getBody());
        $statement = $this->db->pdo()->prepare(
            'SELECT input.id, input.row_version, input.status, input.amount_minor, component.code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.source_kind = "travel"
              ORDER BY component.code',
        );
        $statement->execute([$this->supplierId, $employmentId]);
        $amounts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $amounts[(string) $row['code']] = (int) $row['amount_minor'];
            if ($row['status'] === 'approved') {
                continue;
            }
            $approved = $this->inputs()->approve(
                $this->approverRequest('POST', "/api/payroll/inputs/{$row['id']}/approve")
                    ->withParsedBody(['row_version' => (int) $row['row_version']]),
                new Response(),
                ['id' => (string) $row['id']],
            );
            self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: schválení cestovní náhrady. ' . (string) $approved->getBody());
        }

        return $amounts;
    }

    /**
     * Běh měsíce až po schválení bez blokací a varování.
     *
     * @return array{person:array<string,mixed>,result:array<string,mixed>,revision_id:int,run:array<string,mixed>}
     */
    private function approvedRun(string $scenario, string $periodStart = self::PERIOD_START, string $payday = self::PAYDAY): array
    {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "hpp2-slozky-{$scenario}");
        $this->dump("{$scenario}-calculated.json", CanonicalJson::encode($run['calculated']->revision['result_snapshot']));
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $this->dump("{$scenario}-result.json", CanonicalJson::encode($run['approved']->revision['result_snapshot']));

        return [
            'person' => $run['approved']->revision['result_snapshot']['statutory']['people'][0],
            'result' => $run['approved']->revision['result_snapshot'],
            'revision_id' => (int) $run['approved']->revision['id'],
            'run' => $run['approved']->run,
        ];
    }

    /**
     * Příjem první osoby, ze kterého běh počítá srážky ze mzdy.
     *
     * @param array<string,mixed> $result výsledek revize
     * @return array{garnishable:int,excluded:int}
     */
    private function garnishableIncome(array $result): array
    {
        $income = $result['people'][0]['enforcement']['input']['income'];
        self::assertSame('supported', $income['status'], CanonicalJson::encode($income));

        return [
            'garnishable' => $income['garnishable_minor_units'],
            'excluded' => $income['excluded_minor_units'],
        ];
    }

    /** Příprava hlášení, test XML (XSD + katalog kontrol); vrací XML bez mezer. */
    private function dryRunXml(int $revisionId, string $scenario): string
    {
        $preparation = $this->prepareJmhz($revisionId, "hpp2-slozky-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], 'Zaseknutí: test hlášení. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);
        $this->dump("{$scenario}.xml", (string) $tested['body']['xml']);

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array{taxable:int,social_base:int,health_base:int,social:int,health_employee:int,health_employer:int,advance:int,cash_income:int,non_cash_income:int,net:int} $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        $actual = [
            'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
            'social_base' => $person['social_insurance']['capped_assessment_base_minor_units'],
            'health_base' => $person['health_insurance']['assessment_base_minor_units'],
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'cash_income' => $person['net_pay']['cash_income_minor_units'],
            'non_cash_income' => $person['net_pay']['non_cash_income_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ];
        self::assertSame($expected, $actual, 'Výpočet nesouhlasí s ručním přepočtem.');
    }

    private function approverRequest(string $method, string $uri): ServerRequestInterface
    {
        return $this->request($method, $uri)->withAttribute('auth.effective_role', new EffectiveRole(
            42,
            'Schvalovatel mezd',
            'staff',
            true,
            [
                'payroll' => AccessLevel::WRITE->value,
                'payroll.approve' => AccessLevel::WRITE->value,
                'payroll.inputs.write' => AccessLevel::WRITE->value,
            ],
        ));
    }

    /** Ladicí výpis mimo repozitář, jen když je nastavená proměnná prostředí. */
    private function dump(string $file, string $content): void
    {
        $directory = getenv('MYUCTO_E2E_HPP2_DUMP');
        if (is_string($directory) && $directory !== '' && is_dir($directory)) {
            file_put_contents($directory . '/' . $file, $content);
        }
    }

    private function inputs(): PayrollInputsAction
    {
        $inputs = $this->container->get(PayrollInputsAction::class);
        self::assertInstanceOf(PayrollInputsAction::class, $inputs);

        return $inputs;
    }

    private function components(): PayrollComponentsAction
    {
        $components = $this->container->get(PayrollComponentsAction::class);
        self::assertInstanceOf(PayrollComponentsAction::class, $components);

        return $components;
    }

    private function travel(): PayrollTravelAction
    {
        $travel = $this->container->get(PayrollTravelAction::class);
        self::assertInstanceOf(PayrollTravelAction::class, $travel);

        return $travel;
    }

    private function mappings(): PayrollComponentJmhzMappingRepository
    {
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);

        return $mappings;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunPeopleAction;
use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunPersonSummary;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Přehled osob ve spočítaném běhu a velikost odpovědí běhu.
 *
 * ── Co se opravovalo ────────────────────────────────────────────────────────
 * Detail běhu nesl výsledky všech osob (u 150 lidí 2,8 MB) a odpověď každého
 * příkazu běhu celou revizi s oběma snímky (přes 10 MB). Obrazovka z toho
 * ukázala jednu osobu ve výběru; tabulka osob s řazením neexistovala.
 *
 * ── Co tenhle test hlídá ────────────────────────────────────────────────────
 *  1. přehled osob stránkuje, řadí (česky podle příjmení, podle částek)
 *     a hledá (jméno, osobní číslo) na serveru a jeho částky sedí s ručním
 *     přepočtem čtyř dohod (stejná data jako PayrollE2eNavDppManualCalculationTest);
 *  2. detail jedné osoby vrací tentýž výsledek, jaký nese snímek revize;
 *  3. detail běhu a odpověď příkazu výsledky osob ani snímky nenesou;
 *  4. částky ve sloupcích zapisuje výpočet stejně, jako je starým řádkům
 *     doplňuje migrace (totéž pro souhrn běhu).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunPeopleApiTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-15';
    private const WORKED = ['2026-04-04', '2026-04-11', '2026-04-18'];

    private PayrollRunPeopleAction $people;
    private PayrollRunsAction $runsAction;
    /** @var array<string,array{employee_id:int,employment_id:int,name:string}> */
    private array $persons = [];
    private int $runId;
    private int $revisionId;
    /** @var array<string,mixed> */
    private array $commandResponse;
    private int $commandResponseBytes;
    /** @var array<string,mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->people = $this->container->get(PayrollRunPeopleAction::class);
        $this->runsAction = $this->container->get(PayrollRunsAction::class);

        $officeId = $this->createOffice('PPL', 'Syntetická účtárna přehledu', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('ODMENA_DPP_PPL', 'base_wage', 'regular');
        $this->container->get(PayrollComponentJmhzMappingRepository::class)
            ->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);
        // Příjmení volená kvůli české abecedě: Č za C, Ch za H, Š za S.
        $this->persons = [
            'A' => $this->hire($officeId, $componentId, 1, 'Adam', 'Šafránek', false, 360_100),
            'B' => $this->hire($officeId, $componentId, 2, 'Běla', 'Černá', true, 360_000),
            'C' => $this->hire($officeId, $componentId, 3, 'Cyril', 'Dvořák', false, 1_500_000),
            'D' => $this->hire($officeId, $componentId, 4, 'Dana', 'Chalupová', true, 1_500_000),
        ];

        // Výpočet jde přes HTTP akci, aby test viděl skutečnou odpověď příkazu.
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $this->runId = (int) $run['id'];
        $response = $this->runsAction->command(
            $this->request('POST', "/api/payroll/runs/{$this->runId}/commands/lock_and_calculate")
                ->withParsedBody(['row_version' => (int) $run['row_version']])
                ->withHeader('Idempotency-Key', 'ppl-lock-and-calculate'),
            new Response(),
            ['id' => (string) $this->runId, 'command' => 'lock_and_calculate'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->commandResponseBytes = strlen((string) $response->getBody());
        $this->commandResponse = $this->json($response);
        $this->revisionId = (int) $this->commandResponse['revision']['id'];
        self::assertSame([], $this->blockingValidations($this->revisionId));
        $revision = $this->container->get(\MyInvoice\Repository\Payroll\PayrollRunRepository::class)
            ->revision($this->supplierId, $this->revisionId);
        self::assertIsArray($revision);
        $this->snapshot = $revision['result_snapshot'];
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testPeoplePageMatchesManualCalculationAndSortsByCzechSurname(): void
    {
        $page = $this->page([]);

        self::assertSame(4, $page['total']);
        self::assertSame($this->runId, $page['run_id']);
        self::assertSame($this->revisionId, $page['revision_id']);
        self::assertSame('calculated', $page['revision_status']);
        self::assertSame(['name', 'asc'], [$page['sort'], $page['dir']]);
        // Česká abeceda: Černá, Dvořák, Chalupová, Šafránek.
        self::assertSame(
            [$this->employeeIdOf('B'), $this->employeeIdOf('C'), $this->employeeIdOf('D'), $this->employeeIdOf('A')],
            array_column($page['items'], 'employee_id'),
        );

        // [hrubá, pojistné zaměstnance, daň, čistá, k výplatě] v haléřích, ruční přepočet:
        // A 3 601 Kč bez prohlášení: srážková daň 15 % = 540,15 → 540.
        // B 3 600 Kč s prohlášením: záloha 540 − sleva 2 570 → 0.
        // C 15 000 Kč bez prohlášení: SP 7,1 % = 1 065, ZP třetina z 13,5 % = 675, záloha 2 250.
        // D 15 000 Kč s prohlášením: SP 1 065, ZP 675, záloha po slevě 0.
        $expected = [
            'A' => [360_100, 0, 54_000, 306_100, 306_100],
            'B' => [360_000, 0, 0, 360_000, 360_000],
            'C' => [1_500_000, 174_000, 225_000, 1_101_000, 1_101_000],
            'D' => [1_500_000, 174_000, 0, 1_326_000, 1_326_000],
        ];
        $byEmployee = array_column($page['items'], null, 'employee_id');
        foreach ($expected as $key => $amounts) {
            $item = $byEmployee[$this->employeeIdOf($key)];
            self::assertSame(
                $amounts,
                [$item['gross_minor'], $item['insurance_minor'], $item['tax_minor'], $item['net_minor'], $item['payable_minor']],
                "Osoba {$key} se liší od ručního přepočtu.",
            );
            self::assertSame('calculated', $item['result_status']);
            self::assertSame($this->persons[$key]['employment_id'], $item['employments'][0]['id']);
            self::assertSame('dpp', $item['employments'][0]['relation_type']);
        }
        self::assertSame(54_000, $byEmployee[$this->employeeIdOf('A')]['withholding_tax_minor']);
        self::assertSame(0, $byEmployee[$this->employeeIdOf('A')]['advance_tax_minor']);
        self::assertSame(225_000, $byEmployee[$this->employeeIdOf('C')]['advance_tax_minor']);
        self::assertSame('Běla Černá', $byEmployee[$this->employeeIdOf('B')]['full_name']);
        self::assertSame(
            [
                'gross_minor' => 3_720_100,
                'insurance_minor' => 348_000,
                'tax_minor' => 279_000,
                'tax_bonus_minor' => 0,
                'net_minor' => 3_093_100,
                'payable_minor' => 3_093_100,
            ],
            $page['totals'],
        );
        // Součet přehledu je součet snímku revize, ne jiný výpočet.
        self::assertSame($this->snapshot['totals']['cash_payable_minor'], $page['totals']['gross_minor']);
        self::assertSame($this->snapshot['totals']['payable_after_enforcement_minor'], $page['totals']['payable_minor']);
    }

    public function testSortingPagingAndSearchRunOnTheServer(): void
    {
        $first = $this->page(['sort' => 'net_minor', 'dir' => 'desc', 'limit' => '2']);
        self::assertSame(4, $first['total']);
        self::assertSame([$this->employeeIdOf('D'), $this->employeeIdOf('C')], array_column($first['items'], 'employee_id'));
        $second = $this->page(['sort' => 'net_minor', 'dir' => 'desc', 'limit' => '2', 'offset' => '2']);
        self::assertSame([$this->employeeIdOf('B'), $this->employeeIdOf('A')], array_column($second['items'], 'employee_id'));

        // Stejná částka: pořadí drží příjmení (Dvořák před Chalupová).
        $gross = $this->page(['sort' => 'gross_minor', 'dir' => 'asc']);
        self::assertSame(
            [$this->employeeIdOf('B'), $this->employeeIdOf('A'), $this->employeeIdOf('C'), $this->employeeIdOf('D')],
            array_column($gross['items'], 'employee_id'),
        );
        $tax = $this->page(['sort' => 'tax_minor', 'dir' => 'desc', 'limit' => '1']);
        self::assertSame([$this->employeeIdOf('C')], array_column($tax['items'], 'employee_id'));
        // A i B mají pojistné nulové, rozhoduje příjmení (Černá před Šafránek).
        $insurance = $this->page(['sort' => 'insurance_minor', 'dir' => 'asc', 'limit' => '2']);
        self::assertSame([$this->employeeIdOf('B'), $this->employeeIdOf('A')], array_column($insurance['items'], 'employee_id'));
        $byNameDescending = $this->page(['sort' => 'name', 'dir' => 'desc', 'limit' => '1']);
        self::assertSame([$this->employeeIdOf('A')], array_column($byNameDescending['items'], 'employee_id'));

        // Hledání podle jména (bez ohledu na velikost písmen) zužuje i součty.
        $byName = $this->page(['q' => 'dvoř']);
        self::assertSame([$this->employeeIdOf('C')], array_column($byName['items'], 'employee_id'));
        self::assertSame(1, $byName['total']);
        self::assertSame(1_500_000, $byName['totals']['gross_minor']);
        // Hledání podle osobního čísla vztahu.
        $code = (string) $this->scalar(
            'SELECT code FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->persons['D']['employment_id']],
        );
        self::assertNotSame('', $code);
        $byCode = $this->page(['q' => $code]);
        self::assertContains($this->employeeIdOf('D'), array_column($byCode['items'], 'employee_id'));
        self::assertSame($code, array_column($byCode['items'], null, 'employee_id')[$this->employeeIdOf('D')]['employments'][0]['code']);
        // Zástupné znaky jsou hledaný text.
        self::assertSame(0, $this->page(['q' => '%'])['total']);
        self::assertSame(0, $this->page(['q' => 'neexistuje'])['total']);

        foreach ([['sort' => 'full_name'], ['dir' => 'sideways'], ['q' => str_repeat('a', 101)]] as $invalid) {
            $response = $this->people->list(
                $this->request('GET', "/api/payroll/runs/{$this->runId}/people")->withQueryParams($invalid),
                new Response(),
                ['id' => (string) $this->runId],
            );
            self::assertSame(422, $response->getStatusCode(), json_encode($invalid, JSON_THROW_ON_ERROR));
        }
        // Strop stránky nejde zvednout parametrem.
        self::assertSame(100, $this->page(['limit' => '100000'])['limit']);
    }

    public function testFindingFlagFollowsRunValidationsAndFilterNarrowsToFlaggedPeople(): void
    {
        $all = array_column($this->page([])['items'], null, 'employee_id');
        // Bez podepsaného prohlášení jsou A a C; kontrola běhu je uvádí jako dotčené.
        $unsigned = $this->scalar(
            'SELECT subject_ids_json FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND code = "tax_declaration_not_signed_summary"',
            [$this->supplierId, $this->revisionId],
        );
        self::assertIsString($unsigned, 'Souhrn nepodepsaných prohlášení v běhu chybí.');
        $subjects = array_map('intval', json_decode($unsigned, true, flags: JSON_THROW_ON_ERROR));
        sort($subjects);
        $expected = [$this->employeeIdOf('A'), $this->employeeIdOf('C')];
        sort($expected);
        self::assertSame($expected, $subjects);

        foreach (['A', 'C'] as $key) {
            self::assertTrue($all[$this->employeeIdOf($key)]['has_finding'], "Osoba {$key} má mít příznak nálezu.");
            self::assertGreaterThanOrEqual(1, $all[$this->employeeIdOf($key)]['findings']['warnings']);
        }
        foreach (['B', 'D'] as $key) {
            self::assertFalse($all[$this->employeeIdOf($key)]['has_finding'], "Osoba {$key} nález nemá.");
            self::assertSame(['blockers' => 0, 'warnings' => 0], $all[$this->employeeIdOf($key)]['findings']);
        }

        $flagged = $this->page(['findings' => '1']);
        self::assertSame(2, $flagged['total']);
        $ids = array_column($flagged['items'], 'employee_id');
        sort($ids);
        self::assertSame($expected, $ids);
        self::assertSame(1_860_100, $flagged['totals']['gross_minor']);
    }

    public function testPersonDetailReturnsTheSameResultAsTheRevisionSnapshot(): void
    {
        $employeeId = $this->employeeIdOf('C');
        $response = $this->people->detail(
            $this->request('GET', "/api/payroll/runs/{$this->runId}/people/{$employeeId}"),
            new Response(),
            ['id' => (string) $this->runId, 'employeeId' => (string) $employeeId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $fromSnapshot = array_column($this->snapshot['people'], null, 'employee_id')[$employeeId];

        self::assertSame($this->revisionId, $body['revision_id']);
        self::assertSame($employeeId, $body['person']['employee_id']);
        self::assertSame('Cyril Dvořák', $body['person']['full_name']);
        self::assertSame($this->persons['C']['employment_id'], $body['person']['employments'][0]['id']);
        self::assertEquals($fromSnapshot, $body['result']);
        self::assertSame(225_000, $body['result']['statutory']['net_pay']['advance_tax_minor_units']);
        self::assertArrayHasKey('payslip_document', $body['result']);

        // Osoba mimo běh a neexistující běh.
        $foreign = $this->people->detail(
            $this->request('GET', "/api/payroll/runs/{$this->runId}/people/999999999"),
            new Response(),
            ['id' => (string) $this->runId, 'employeeId' => '999999999'],
        );
        self::assertSame(404, $foreign->getStatusCode());
        $missingRun = $this->people->list(
            $this->request('GET', '/api/payroll/runs/999999999/people'),
            new Response(),
            ['id' => '999999999'],
        );
        self::assertSame(404, $missingRun->getStatusCode());
    }

    public function testRunDetailAndCommandResponseCarryNoPeopleOrSnapshots(): void
    {
        // Odpověď příkazu: hlavička revize a souhrn, žádný snímek.
        $revision = $this->commandResponse['revision'];
        foreach (['input_snapshot', 'input_snapshot_json', 'result_snapshot_json'] as $key) {
            self::assertArrayNotHasKey($key, $revision);
        }
        self::assertArrayNotHasKey('people', $revision['result_snapshot']);
        self::assertArrayNotHasKey('people', $revision['result_snapshot']['statutory']);
        self::assertSame(4, $revision['result_snapshot']['person_count']);
        self::assertSame($this->snapshot['totals'], $revision['result_snapshot']['totals']);
        self::assertSame('calculated', $revision['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $revision['result_snapshot_hash']);
        foreach (array_keys($this->commandResponse['run']) as $key) {
            self::assertStringStartsNotWith('summary_', (string) $key);
        }
        $snapshotBytes = strlen(json_encode($this->snapshot, JSON_THROW_ON_ERROR));
        self::assertLessThan(
            $snapshotBytes / 4,
            $this->commandResponseBytes,
            'Odpověď příkazu nesmí růst s počtem osob.',
        );

        $response = $this->runsAction->detail(
            $this->request('GET', "/api/payroll/runs/{$this->runId}"),
            new Response(),
            ['id' => (string) $this->runId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = $this->json($response)['run'];
        self::assertArrayNotHasKey('people', $run['result_snapshot']);
        self::assertArrayNotHasKey('people', $run['result_snapshot']['statutory']);
        self::assertSame(4, $run['result_snapshot']['person_count']);
        self::assertSame($this->snapshot['totals'], $run['result_snapshot']['totals']);
        self::assertSame(
            $this->snapshot['statutory']['employer_social_minor_units'],
            $run['result_snapshot']['statutory']['employer_social_minor_units'],
        );
        self::assertTrue($run['payment_materialization_supported']);
        self::assertContains(
            'tax_declaration_not_signed_summary',
            array_column($run['validations'], 'code'),
            'Detail běhu nese nálezy kontrol.',
        );
        self::assertSame(0, $run['hidden_validation_count']);
        self::assertLessThan($snapshotBytes / 4, strlen((string) $response->getBody()));

        // Seznam běhů bere součty ze souhrnu uloženého u běhu.
        $list = $this->runsAction->list(
            $this->request('GET', '/api/payroll/runs')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        $listed = $this->json($list)['runs'][0];
        self::assertSame(
            $this->snapshot['totals']['payable_after_enforcement_minor'],
            $listed['result_snapshot']['totals']['payable_after_enforcement_minor'],
        );
        self::assertSame(
            $this->snapshot['totals']['cash_payable_minor'],
            $listed['result_snapshot']['totals']['cash_payable_minor'],
        );
    }

    /**
     * Výpočet zapisuje sloupce osob a souhrn běhu; migrace je doplňuje starým
     * řádkům čtením JSON. Obojí musí dát totéž: sloupce se vynulují, pustí se
     * doslovný příkaz z migrace a výsledek se porovná s tím, co zapsal výpočet.
     */
    public function testCalculationStoresTheSameSummariesAsTheMigrationBackfill(): void
    {
        $pdo = $this->db->pdo();
        $personColumns = implode(', ', PayrollRunPersonSummary::COLUMNS);
        $readPersons = fn (): array => $this->rows(
            "SELECT employee_id, {$personColumns} FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? ORDER BY employee_id",
            [$this->supplierId, $this->revisionId],
        );
        $readRun = fn (): array => $this->rows(
            'SELECT summary_revision_id, summary_person_count, summary_payment_materialization,
                    summary_cash_payable_minor, summary_enforcement_withheld_minor,
                    summary_payable_after_enforcement_minor
               FROM payroll_runs WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
        );
        $storedPersons = $readPersons();
        $storedRun = $readRun();
        self::assertCount(4, $storedPersons);
        self::assertSame((string) $this->revisionId, (string) $storedRun[0]['summary_revision_id']);
        self::assertSame('4', (string) $storedRun[0]['summary_person_count']);
        self::assertSame(
            (string) $this->snapshot['totals']['payable_after_enforcement_minor'],
            (string) $storedRun[0]['summary_payable_after_enforcement_minor'],
        );

        $pdo->prepare(
            'UPDATE payroll_run_persons SET ' . implode(', ', array_map(
                static fn (string $column): string => "{$column} = NULL",
                PayrollRunPersonSummary::COLUMNS,
            )) . ' WHERE supplier_id = ? AND revision_id = ?',
        )->execute([$this->supplierId, $this->revisionId]);
        $pdo->prepare(
            'UPDATE payroll_runs
                SET summary_revision_id = NULL, summary_person_count = NULL,
                    summary_payment_materialization = NULL, summary_cash_payable_minor = NULL,
                    summary_enforcement_withheld_minor = NULL,
                    summary_payable_after_enforcement_minor = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->runId]);
        self::assertNotEquals($storedPersons, $readPersons());

        foreach ($this->migrationBackfillStatements() as $statement) {
            $pdo->exec($statement);
        }

        self::assertEquals($storedPersons, $readPersons(), 'Sloupce osob z migrace a z výpočtu se liší.');
        self::assertEquals($storedRun, $readRun(), 'Souhrn běhu z migrace a z výpočtu se liší.');
    }

    /**
     * Datové příkazy migrace souhrnů (`UPDATE …`), bez `ALTER TABLE`: DDL by
     * v testu ukončilo transakci. Soubor se hledá podle jména bez čísla, aby
     * test přežil přečíslování migrace při slučování větví.
     *
     * @return list<string>
     */
    private function migrationBackfillStatements(): array
    {
        $files = glob(Bootstrap::rootDir() . '/db/migrations/*_payroll_run_summaries.sql') ?: [];
        self::assertCount(1, $files, 'Migrace souhrnů mzdového běhu chybí.');
        $sql = (string) preg_replace('/^--.*$/m', '', (string) file_get_contents($files[0]));
        $statements = array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $statement): bool => str_starts_with($statement, 'UPDATE '),
        ));
        self::assertCount(2, $statements);

        return $statements;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function page(array $query): array
    {
        $response = $this->people->list(
            $this->request('GET', "/api/payroll/runs/{$this->runId}/people")->withQueryParams($query),
            new Response(),
            ['id' => (string) $this->runId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function employeeIdOf(string $key): int
    {
        return $this->persons[$key]['employee_id'];
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(
        int $officeId,
        int $componentId,
        int $seq,
        string $firstName,
        string $lastName,
        bool $declaration,
        int $amountMinor,
    ): array {
        $female = $seq % 2 === 0;
        $person = $this->createEmployment(
            $officeId,
            "{$firstName} {$lastName}",
            $seq,
            'dpp',
            'dpp',
            10,
            2_500,
            $declaration,
            self::PERIOD_START,
        );
        $birthDate = sprintf('19%02d-%02d-%02d', 85 + $seq, 2 + $seq, 3 + $seq);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $female ? 'female' : 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $seq),
        ]);
        $this->publishShifts($person['employment_id'], self::WORKED);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $componentId, $amountMinor, 'ppl-' . $person['employment_id'], self::PERIOD_START);

        return $person;
    }
}

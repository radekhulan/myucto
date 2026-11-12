<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Počet SQL dotazů přípravy JMHZ a testu hlášení na pracovní vztah.
 *
 * Příprava i test hlášení musí načítat podklady vztahů dávkově; dotaz za
 * každý vztah (N+1) se u stovek brigádníků projeví sekundami. Test měří
 * přírůstek dotazů mezi menší a větší firmou a hlídá strop na vztah.
 *
 * S proměnnou prostředí MYUCTO_JMHZ_BUDGET_PEOPLE (počet vztahů) a
 * MYUCTO_JMHZ_BUDGET_DUMP (adresář) uloží XML testu hlášení a počty dotazů
 * pro porovnání mimo repozitář.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzPreparationQueryBudgetTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';
    private const PREPARATION_PER_EMPLOYMENT = 18.0;
    private const DRY_RUN_PER_EMPLOYMENT = 0.5;

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    private int $statements = 0;
    /** @var array<string,int> */
    private array $statementLog = [];
    /** @var array{people:int,preparation:int,dry_run:int}|null */
    private static ?array $small = null;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_BUDGET', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /** @return iterable<string,array{int}> */
    public static function sizes(): iterable
    {
        yield 'small' => [3];
        yield 'large' => [max(4, (int) (getenv('MYUCTO_JMHZ_BUDGET_PEOPLE') ?: 12))];
    }

    #[DataProvider('sizes')]
    public function testPreparationAndDryRunQueriesDoNotGrowPerEmployment(int $people): void
    {
        $label = self::$small === null ? 'small' : 'large';
        $measured = $this->measure($people, $label);
        if (self::$small === null) {
            self::$small = $measured;
            self::assertGreaterThan(0, $measured['preparation']);

            return;
        }
        $small = self::$small;
        self::$small = null;
        $added = $measured['people'] - $small['people'];
        $perPreparation = ($measured['preparation'] - $small['preparation']) / $added;
        $perDryRun = ($measured['dry_run'] - $small['dry_run']) / $added;
        arsort($this->statementLog);
        $this->dump('queries.json', CanonicalJson::encode([
            'small' => $small,
            'large' => $measured,
            'per_employment' => ['preparation' => $perPreparation, 'dry_run' => $perDryRun],
            'statements' => $this->statementLog,
        ]));

        self::assertLessThanOrEqual(
            self::PREPARATION_PER_EMPLOYMENT,
            $perPreparation,
            'Příprava JMHZ přidává na vztah ' . $perPreparation . ' dotazů.',
        );
        self::assertLessThanOrEqual(
            self::DRY_RUN_PER_EMPLOYMENT,
            $perDryRun,
            'Test hlášení přidává na vztah ' . $perDryRun . ' dotazů.',
        );
    }

    /** @return array{people:int,preparation:int,dry_run:int} */
    private function measure(int $people, string $label): array
    {
        $first = $this->sequence + 1;
        for ($i = 0; $i < $people; $i++) {
            $person = $this->hire($label);
            $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
            $this->createApprovedInput(
                $person,
                $this->baseComponentId,
                3_000_000 + 1_000 * ($this->sequence - $first),
                'base-' . $person['employment_id'],
                self::PERIOD_START,
            );
        }
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "budget-{$label}");
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']));
        $revisionId = (int) $run['approved']->revision['id'];

        $this->countStatements(true);
        $preparation = $this->prepareJmhz($revisionId, "budget-{$label}");
        $preparationQueries = $this->countStatements(false);
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));

        $this->countStatements(true);
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        $dryRunQueries = $this->countStatements(false);
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'] ?? null,
            CanonicalJson::encode($tested['body']['blockers'] ?? $tested['body']),
        );
        $xml = (string) $tested['body']['xml'];
        foreach ($tested['body']['packages'] ?? [] as $package) {
            $xml .= "\n" . (string) $package['xml'];
        }
        $this->dump("{$label}.xml", (string) preg_replace(
            [
                '/[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}/i',
                '/<datumVyplneni>[^<]*</',
            ],
            ['GUID', '<datumVyplneni>DATUM<'],
            $xml,
        ));

        return ['people' => $people, 'preparation' => $preparationQueries, 'dry_run' => $dryRunQueries];
    }

    private function countStatements(bool $start): int
    {
        $pdo = $this->db->pdo();
        if ($start) {
            $this->statements = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")?->fetch(\PDO::FETCH_NUM)[1];
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [PayrollQueryBudgetStatement::class, []]);
            PayrollQueryBudgetStatement::$log = [];

            return 0;
        }
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $now = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")?->fetch(\PDO::FETCH_NUM)[1];
        foreach (PayrollQueryBudgetStatement::$log as $sql => $count) {
            $this->statementLog[$sql] = ($this->statementLog[$sql] ?? 0) + $count;
        }

        return $now - $this->statements - 1;
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hire(string $label): array
    {
        $sequence = ++$this->sequence;
        $name = sprintf('Syntetický %s%03d', ucfirst($label), $sequence);
        $sex = $sequence % 2 === 0 ? 'female' : 'male';
        $birthDate = sprintf('19%02d-%02d-%02d', 60 + $sequence % 40, 1 + $sequence % 12, 1 + $sequence % 28);
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
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
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['sequence' => $sequence];
    }

    private function dump(string $file, string $content): void
    {
        $directory = getenv('MYUCTO_JMHZ_BUDGET_DUMP');
        if (!is_string($directory) || $directory === '') {
            return;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($directory . '/' . $file, $content);
    }
}

/** Počítá provedení SQL podle textu dotazu (jen pro {@see PayrollJmhzPreparationQueryBudgetTest}). */
final class PayrollQueryBudgetStatement extends \PDOStatement
{
    /** @var array<string,int> */
    public static array $log = [];

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        $sql = (string) preg_replace('/\s+/', ' ', $this->queryString);
        self::$log[$sql] = (self::$log[$sql] ?? 0) + 1;

        return parent::execute($params);
    }
}

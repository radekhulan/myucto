<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog;
use MyInvoice\Service\Payroll\Run\PayrollHourlyAverageEarningLink;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWholeCrownInputs;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerRemediationPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Každá blokace a nález mzdového běhu i přípravy JMHZ má text a odkaz, který
 * opravdu někam vede.
 *
 * Odkaz je platný, jen když (1) jeho cesta existuje v routeru frontendu
 * (`web/src/router/*.ts`, čte se ze souborů, ne z opsaného seznamu),
 * (2) cílová stránka (nebo komponenta, kterou importuje) parametry z adresy
 * opravdu čte a zná jejich hodnotu (záložku, panel, pole, kotvu) a (3) nález
 * vázaný na měsíc nese období. Dřív tu žily odkazy na filtr, který stránka
 * vůbec nečetla, a nález s druhem nápravy, pro který server žádnou cestu
 * nesložil.
 *
 * Kódy se sbírají ze zdrojů (katalog JMHZ, pravidla kontroly běhu, volání
 * konstruktorů nálezů, issue zákonného vstupu), takže nový kód bez textu nebo
 * s mrtvým odkazem tu spadne sám.
 */
#[Group('architecture')]
final class PayrollE2eHppRemediationLinkCoverageTest extends TestCase
{
    private const PERIOD_START = '2026-08-01';
    private const PERIOD = '2026-08';

    /** Druhy nápravy, u kterých není kam jít (zopakovat, zpracovat ručně, správce). */
    private const LINKLESS_KINDS = ['retry', 'manual', 'support'];

    /** Druh nápravy => parametry období, které odkaz musí nést. */
    private const PERIOD_QUERY = [
        'absences' => ['period' => self::PERIOD],
        'time' => ['period' => self::PERIOD],
        'runs' => ['period' => self::PERIOD],
        'ordinary_evidence' => ['period' => self::PERIOD],
        'averages' => ['year' => '2026', 'quarter' => '3'],
        // Roční zúčtování v hlášení patří předchozímu zdaňovacímu období
        // (JmhzAnnualEvidenceService: tax_year = rok hlášení - 1).
        'annual_settlement' => ['year' => '2025'],
    ];

    private const ENTITY_TYPES = ['employment', 'person', 'employee', 'component', 'run', 'office', ''];

    /**
     * Issue zákonného vstupu bez vlastní věty v PayrollRunIssueGuidance. Jde
     * o poškozený tvar uloženého snímku, který účetní sama neopraví; obecná
     * věta „předejte správci běh a revizi" s odkazem na podporu je správně.
     */
    private const SUPPORT_ONLY_ISSUES = [
        'unsupported_snapshot_schema' => 'neznámá verze uloženého snímku',
        'snapshot_shape_invalid' => 'poškozený tvar uloženého snímku',
    ];

    /**
     * Cesta nálezu, kterou test nevidí jako literál, a kde se ověřuje.
     *
     * @var array<string,string>
     */
    private const DYNAMIC_PATHS = [
        'count($entities)===1?$entities[0][\'remediation_path\']:$guidance[\'remediation_path\']' => 'PayrollRunIssueGuidance::describe(payout_rule_missing): kryje testDynamicPathHelpersLeadToExistingPages přes katalog návodů',
        '$blocker[\'remediation\']' => 'PayrollHourlyWageMaterializer: literály kryje testEveryPathLiteralLeadsToAnExistingPage',
        '$hourlyLink' => 'literály v PayrollRunSnapshotBuilder',
        'self::hourlyBlockerLink((string) $blocker[\'code\'], $periodStart, $employmentId, $hourlyLink, (array) ($blocker[\'context\'] ?? []))' => 'testDynamicPathHelpersLeadToExistingPages',
        '$riskySavingsPath' => 'testDynamicPathHelpersLeadToExistingPages',
        'self::dimensionSplitPath($employmentId)' => 'testDynamicPathHelpersLeadToExistingPages',
        'self::hourlyAverageMinimumLink($periodStart, $employmentId)' => 'testDynamicPathHelpersLeadToExistingPages',
        '$validation->remediationPath' => 'jen předává hotovou cestu validace',
        '$e->remediationPath' => 'jen předává cestu PayrollRunSnapshotFixableException',
        'null' => 'readiness_check_failed: nález bez místa opravy',
        '' => 'validace bez odkazu',
    ];

    /**
     * Cesta k měsíční agendě bez období, kterou kontrola před během ukazuje
     * bez doplnění. Odkaz z karty běhu i kontroly doplní období ve frontendu
     * (`runRemediationHref`), u ostatních stránek ho musí nést server.
     */
    private const FRONTEND_PERIOD_PATHS = '#^/payroll/(runs|time|quick-inputs|insolvency|submissions)(/[a-z_]+)?(\?|$)#';

    /** @var array<string,list<array{regex:string,file:?string,source:string}>>|null */
    private static ?array $routes = null;

    /** @var array<string,string> */
    private static array $sources = [];

    public function testEveryCatalogBlockerLinksToAnExistingPageWithItsPeriod(): void
    {
        $problems = [];
        $checked = 0;
        foreach (JmhzBlockerCatalog::codes() as $code) {
            foreach (self::ENTITY_TYPES as $type) {
                $kind = JmhzBlockerCatalog::remediation($code, $type)['kind'];
                $path = JmhzBlockerRemediationPath::forBlocker(
                    new JmhzScenario1Blocker($code, $type, $type === '' ? null : 22),
                    self::PERIOD_START,
                );
                $context = "forBlocker({$code}, {$type}) [{$kind}]";
                if (in_array($kind, self::LINKLESS_KINDS, true)) {
                    continue;
                }
                if ($path === null) {
                    $problems[] = "{$context}: druh nápravy {$kind} nemá odkaz";
                    continue;
                }
                ++$checked;
                $this->checkLink($context, $path, $problems);
                $this->checkPeriod($context, $path, self::PERIOD_QUERY[$kind] ?? [], $problems);
            }
        }

        self::assertGreaterThan(1500, $checked, 'Sken katalogu je podezřele krátký.');
        self::assertSame([], array_values(array_unique($problems)));
    }

    public function testEveryReadinessCodeLinksToAnExistingPageWithItsPeriod(): void
    {
        $codes = [
            ...JmhzBlockerCatalog::codes(),
            'component_jmhz_mapping_missing',
            'component_jmhz_manual_review',
            'component_jmhz_treatment_invalid',
            'component_jmhz_bonus_regularity_unverified',
        ];
        $problems = [];
        foreach ($codes as $code) {
            foreach (self::ENTITY_TYPES as $type) {
                $path = JmhzBlockerRemediationPath::forReadinessCode(
                    $code,
                    $type === '' ? null : 22,
                    self::PERIOD_START,
                    $type,
                );
                if ($path === null) {
                    continue;
                }
                $kind = str_starts_with($code, 'component_') ? 'components' : JmhzBlockerCatalog::remediation($code, $type)['kind'];
                $context = "forReadinessCode({$code}, {$type}) [{$kind}]";
                $this->checkLink($context, $path, $problems);
                $this->checkPeriod($context, $path, self::PERIOD_QUERY[$kind] ?? [], $problems);
            }
        }

        self::assertSame([], array_values(array_unique($problems)));
    }

    /**
     * Server a frontend skládají cíl z druhu nápravy každý zvlášť (409 ze
     * zmrazení nese cestu, test hlášení jen druh). Druh, který jedna strana
     * nezná, by na ní skončil bez odkazu.
     */
    public function testEveryRemediationKindHasADestinationOnBothSides(): void
    {
        $resolve = new \ReflectionMethod(JmhzBlockerRemediationPath::class, 'resolve');
        $frontend = self::source('web/src/pages/payroll/jmhzBlockerRemediation.ts');
        $problems = [];
        foreach (JmhzBlockerCatalog::KINDS as $kind) {
            if (!str_contains($frontend, "case '{$kind}':")) {
                $problems[] = "jmhzBlockerRemediation.ts: chybí case '{$kind}'";
            }
            if (in_array($kind, self::LINKLESS_KINDS, true)) {
                continue;
            }
            foreach (['employment' => 22, 'person' => 11] as $type => $id) {
                $path = $resolve->invoke(null, 'jmhz_test_code', $kind, null, $type, $id, self::PERIOD_START);
                if ($path === null) {
                    $problems[] = "JmhzBlockerRemediationPath::resolve: druh {$kind} ({$type}) nemá cestu";
                }
            }
        }

        self::assertSame([], array_values(array_unique($problems)));
    }

    public function testEveryRunIssueRuleHasGuidanceAndAnExistingDestination(): void
    {
        $generic = PayrollRunIssueGuidance::describe('payroll_e2e_unknown_issue')['message'];
        $problems = [];
        $count = 0;
        foreach (self::issueRuleCodes() as $code) {
            ++$count;
            foreach ([[null, null], [7, null], [7, 9], [null, 9]] as [$employee, $employment]) {
                $detail = PayrollRunIssueGuidance::describe("income_tax:{$code}", $employee, $employment);
                $context = "PayrollRunIssueGuidance({$code}, {$employee}, {$employment})";
                if ($detail['message'] === $generic || mb_strlen($detail['message']) < 40) {
                    $problems[] = "{$context}: chybí srozumitelný text";
                }
                $this->checkLink($context, (string) $detail['remediation_path'], $problems);
            }
        }
        $component = PayrollRunIssueGuidance::describe('income_tax:component_treatment_unverified:component:5');
        $this->checkLink('component reference', (string) $component['remediation_path'], $problems);

        self::assertGreaterThan(150, $count);
        self::assertSame([], array_values(array_unique($problems)));
    }

    /**
     * Issue zákonného vstupu bez vlastní věty by v kartě běhu skončilo
     * obecným „předejte správci" a odkazem na podporu, i když jde o údaj,
     * který účetní doplní sama.
     */
    public function testEveryStatutoryInputIssueHasItsOwnGuidance(): void
    {
        $rules = array_flip(self::issueRuleCodes());
        $codes = [];
        foreach (self::callArguments('api/src/Service/Payroll/Run/PayrollRunStatutoryInputAssembler.php', '->issue') as $arguments) {
            foreach (self::literals($arguments[1] ?? []) as $code) {
                if (preg_match('/^[a-z][a-z0-9_]+$/', $code) === 1) {
                    $codes[$code] = true;
                }
            }
        }
        foreach (self::callArguments('api/src/Service/Payroll/Run/PayrollRunStatutoryInputAssembler.php', '->invalidSnapshot') as $arguments) {
            foreach (self::literals($arguments[0] ?? []) as $code) {
                $codes[$code] = true;
            }
        }
        $missing = [];
        foreach (array_keys($codes) as $code) {
            if (!isset($rules[$code]) && !isset(self::SUPPORT_ONLY_ISSUES[$code])) {
                $missing[] = $code;
            }
        }
        foreach (array_keys(self::SUPPORT_ONLY_ISSUES) as $code) {
            if (!isset($codes[$code])) {
                $missing[] = "SUPPORT_ONLY_ISSUES: {$code} už assembler nevydává";
            }
        }

        self::assertGreaterThan(60, count($codes));
        self::assertSame([], $missing);
    }

    /**
     * Nálezy kontroly před během a validace běhu: kód, text a odkaz u každého
     * volání `new PayrollRunValidation`, `new PayrollRunSnapshotFixableException`
     * a `self::finding` v kontrole před během.
     */
    public function testEveryRunFindingHasCodeTextAndALiveLink(): void
    {
        $calls = [
            'PayrollRunValidation' => [1, 4, 5],
            'PayrollRunSnapshotFixableException' => [0, 1, 2],
        ];
        $files = self::phpFiles('api/src/Service/Payroll');
        $problems = [];
        $codes = [];
        foreach ($files as $file) {
            foreach ($calls as $class => [$codeAt, $messageAt, $pathAt]) {
                foreach (self::callArguments($file, 'new ' . $class) as $line => $arguments) {
                    $this->checkFinding("{$file}:{$line}", $arguments, $codeAt, $messageAt, $pathAt, $codes, $problems);
                }
            }
        }
        foreach (self::callArguments('api/src/Service/Payroll/Run/PayrollRunReadinessService.php', 'self::finding') as $line => $arguments) {
            $this->checkFinding("PayrollRunReadinessService.php:{$line}", $arguments, 0, 1, 2, $codes, $problems);
        }

        self::assertSame([], array_values(array_unique($problems)));
        self::assertGreaterThan(35, count($codes), 'Sken nálezů je podezřele krátký.');
    }

    /** Každý literál cesty ve zdrojích nálezů vede na existující stránku. */
    public function testEveryPathLiteralLeadsToAnExistingPage(): void
    {
        $files = [
            ...self::phpFiles('api/src/Service/Payroll/Run'),
            ...self::phpFiles('api/src/Service/Payroll/Time'),
            'api/src/Service/Payroll/PayrollHourlyWageMaterializer.php',
            'api/src/Service/Payroll/Submission/Jmhz/JmhzBlockerRemediationPath.php',
        ];
        $problems = [];
        $count = 0;
        foreach ($files as $file) {
            foreach (\PhpToken::tokenize(self::source($file)) as $token) {
                if (!$token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                    continue;
                }
                $text = trim($token->text, '\'"');
                if (preg_match('#^/(payroll|admin)(/[a-z0-9_/-]*)?([?\#]|$)#', $text) !== 1) {
                    continue;
                }
                ++$count;
                $this->checkLink("{$file}:{$token->line}", self::sample($text), $problems, false);
            }
        }

        self::assertGreaterThan(60, $count);
        self::assertSame([], array_values(array_unique($problems)));
    }

    public function testDynamicPathHelpersLeadToExistingPages(): void
    {
        $problems = [];
        $hourly = new \ReflectionMethod(PayrollRunSnapshotBuilder::class, 'hourlyBlockerLink');
        foreach (['hourly_average_earning_missing', 'hourly_time_missing', 'hourly_premium_evidence_missing', 'hourly_other'] as $code) {
            $path = $hourly->invoke(null, $code, self::PERIOD_START, 22, '/payroll/people?person=11&employment=22&panel=employment_terms', []);
            $this->checkLink("hourlyBlockerLink({$code})", $path, $problems);
        }
        $this->checkLink('PayrollHourlyAverageEarningLink', PayrollHourlyAverageEarningLink::path(22, 2026, 3, self::PERIOD_START), $problems);
        $risky = new \ReflectionMethod(PayrollRunSnapshotBuilder::class, 'riskySavingsPath');
        $this->checkLink('riskySavingsPath', $risky->invoke(null, 11, 22), $problems);
        $split = new \ReflectionMethod(PayrollRunSnapshotBuilder::class, 'dimensionSplitPath');
        $this->checkLink('dimensionSplitPath', $split->invoke(null, 22), $problems);
        $this->checkLink('dimensionSplitPath(0)', $split->invoke(null, 0), $problems);
        $this->checkLink('hourlyAverageMinimumLink', PayrollRunSnapshotBuilder::hourlyAverageMinimumLink('2026-05-01', 22), $problems);

        self::assertSame([], array_values(array_unique($problems)));
    }

    public function testWholeCrownFindingHasTextAndLinksToTheInputsOfItsMonth(): void
    {
        $validations = PayrollRunWholeCrownInputs::validations(
            [['employment_id' => 22, 'full_name' => 'Syntetická osoba']],
            [22 => [[
                'amount_minor' => 1_234_550,
                'component_snapshot_json' => json_encode(['component_id' => 5, 'name' => 'Mzda', 'jmhz_treatment' => 'included']),
            ]]],
            self::PERIOD_START,
        );
        self::assertCount(1, $validations);
        $problems = [];
        $this->checkLink('inputs_not_whole_crowns', (string) $validations[0]->remediationPath, $problems);
        $this->checkPeriod('inputs_not_whole_crowns', (string) $validations[0]->remediationPath, ['period' => self::PERIOD], $problems);
        self::assertSame([], array_values(array_unique($problems)));
        self::assertStringContainsString('celé koruny', $validations[0]->message);
    }

    public function testEveryPersonDataGapLinksToAnExistingPanelAndField(): void
    {
        $problems = [];
        foreach (PayrollPersonDataGapCatalog::keys() as $key) {
            $gap = PayrollPersonDataGapCatalog::describe($key);
            $query = ['person' => 11];
            if ($gap['panel'] !== null) $query['panel'] = $gap['panel'];
            if ($gap['field'] !== null) $query['field'] = $gap['field'];
            $this->checkLink("data gap {$key}", '/payroll/people?' . http_build_query($query), $problems);
        }

        self::assertSame([], array_values(array_unique($problems)));
    }

    /**
     * @param array<int,list<\PhpToken>> $arguments
     * @param array<string,true> $codes
     * @param list<string> $problems
     */
    private function checkFinding(
        string $context,
        array $arguments,
        int $codeAt,
        int $messageAt,
        int $pathAt,
        array &$codes,
        array &$problems,
    ): void {
        $codeTokens = $arguments[$codeAt] ?? [];
        foreach (self::literals($codeTokens) as $code) {
            $codes[$code] = true;
            if (preg_match('/^[a-z][a-z0-9_]+$/', $code) !== 1) {
                $problems[] = "{$context}: kód {$code} není kanonický";
            }
        }
        $message = $arguments[$messageAt] ?? [];
        $hasText = false;
        foreach ($message as $token) {
            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && mb_strlen(trim($token->text, '\'"')) >= 20) {
                $hasText = true;
            }
            if ($token->is(T_VARIABLE) || $token->is(T_STRING)) {
                // Text skládaný jinde (zpráva výjimky, nálezu docházky): ověřují ho jejich testy.
                $hasText = true;
            }
        }
        if (!$hasText) {
            $problems[] = "{$context}: nález bez textu";
        }
        $pathTokens = $arguments[$pathAt] ?? [];
        $expression = self::expression($pathTokens);
        $literals = array_values(array_filter(
            self::literals($pathTokens, true),
            static fn (string $part): bool => str_starts_with($part, '/'),
        ));
        if ($literals === []) {
            $known = array_map(static fn (string $key): string => preg_replace('/\s+/', '', $key) ?? $key, array_keys(self::DYNAMIC_PATHS));
            if (!in_array(preg_replace('/\s+/', '', $expression), $known, true)) {
                $problems[] = "{$context}: odkaz {$expression} test neumí ověřit; doplňte ho do DYNAMIC_PATHS i s místem ověření";
            }

            return;
        }
        foreach (self::branches($pathTokens) as $branch) {
            $this->checkLink($context, $branch, $problems);
            if (preg_match('#^/payroll/(absences|components\?tab=inputs)#', $branch) === 1
                && !str_contains($branch, 'tab=averages')
            ) {
                $this->checkPeriod($context, $branch, ['period' => self::PERIOD], $problems);
            }
            if (preg_match('#^/payroll/(time|quick-inputs|runs|submissions)#', $branch) === 1
                && preg_match(self::FRONTEND_PERIOD_PATHS, $branch) !== 1
            ) {
                $problems[] = "{$context}: {$branch} nedostane období ani ve frontendu";
            }
        }
    }

    /**
     * @param array<string,string> $expected
     * @param list<string> $problems
     */
    private function checkPeriod(string $context, string $path, array $expected, array &$problems): void
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        foreach ($expected as $key => $value) {
            if ((string) ($query[$key] ?? '') !== $value) {
                $problems[] = "{$context}: {$path} nenese {$key}={$value}";
            }
        }
    }

    /** @param list<string> $problems */
    private function checkLink(string $context, string $url, array &$problems, bool $strictValues = true): void
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            $problems[] = "{$context}: {$url} není cesta aplikace";
            return;
        }
        $route = self::route($path);
        if ($route === null) {
            $problems[] = "{$context}: cesta {$path} v routeru frontendu neexistuje";
            return;
        }
        if ($route['file'] === null) {
            $problems[] = "{$context}: cesta {$path} je jen přesměrování";
            return;
        }
        $sources = self::pageSources($route['file']);
        parse_str($parts['query'] ?? '', $query);
        foreach ($query as $key => $value) {
            $key = (string) $key;
            $value = is_string($value) ? $value : '';
            $quoted = preg_quote($key, '/');
            $reads = '/(?:[qQ]uery\.' . $quoted . '\b|query\[\s*\'' . $quoted . '\'\s*\]|query,\s*\'' . $quoted . '\'\s*\)'
                . '|queryParam\(\s*\'' . $quoted . '\'\s*\)|queryString\(\s*query,\s*\'' . $quoted . '\'\s*\))/';
            if (preg_match($reads, $sources) !== 1) {
                $problems[] = "{$context}: {$route['file']} nečte parametr {$key} z {$url}";
                continue;
            }
            if (!$strictValues && $value === '1') {
                continue;
            }
            $problem = self::valueProblem($key, $value, $sources);
            if ($problem !== null) {
                $problems[] = "{$context}: {$url}: {$problem}";
            }
        }
        if (isset($parts['fragment']) && !str_contains(self::payrollFrontend(), 'id="' . $parts['fragment'] . '"')) {
            $problems[] = "{$context}: kotva #{$parts['fragment']} na stránce není";
        }
        if (preg_match('#^/payroll/submissions/([a-z_]+)$#', $path, $tab) === 1
            && !str_contains($sources, "'{$tab[1]}'")
        ) {
            $problems[] = "{$context}: záložka podání {$tab[1]} neexistuje";
        }
    }

    private static function valueProblem(string $key, string $value, string $sources): ?string
    {
        return match ($key) {
            'period' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? null : "období {$value} není RRRR-MM",
            'year' => preg_match('/^\d{4}$/', $value) === 1 ? null : "rok {$value} není platný",
            'quarter' => in_array($value, ['1', '2', '3', '4'], true) ? null : "čtvrtletí {$value} není platné",
            'person', 'employment', 'component', 'case', 'employee' => preg_match('/^[1-9]\d*$/', $value) === 1 ? null : "{$key}={$value} není id",
            'tab', 'panel', 'bulk', 'filter', 'jmhz', 'status', 'state' => str_contains($sources, "'{$value}'")
                ? null : "stránka nezná hodnotu {$key}={$value}",
            'field' => self::fieldExists($value) ? null : "pole {$value} na stránce není",
            default => null,
        };
    }

    private static function fieldExists(string $field): bool
    {
        if (str_starts_with($field, 'statutory.')) {
            return str_contains(
                self::source('web/src/pages/payroll/statutoryEvidenceForm.ts'),
                "key: '" . substr($field, strlen('statutory.')) . "'",
            );
        }

        return preg_match('/[\'"`]' . preg_quote($field, '/') . '[\'"`]/', self::payrollFrontend()) === 1;
    }

    /** Doplní vzorové hodnoty za proměnné v literálu cesty. */
    private static function sample(string $path): string
    {
        $path = preg_replace('/\{\$[^}]+\}/', '1', $path) ?? $path;

        return preg_replace('/=(?=&|$|#)/', '=1', $path) ?? $path;
    }

    /**
     * Větve výrazu cesty (`$a ? '/x' : '/y'`, spojování s proměnnými) jako
     * vzorové adresy.
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function branches(array $tokens): array
    {
        $branches = [[]];
        $depth = 0;
        foreach ($tokens as $token) {
            if (in_array($token->text, ['(', '['], true)) $depth++;
            if (in_array($token->text, [')', ']'], true)) $depth--;
            if ($depth === 0 && in_array($token->text, ['?', ':'], true)) {
                $branches[] = [];
                continue;
            }
            $branches[count($branches) - 1][] = $token;
        }
        $result = [];
        foreach ($branches as $branch) {
            $url = '';
            $depth = 0;
            foreach ($branch as $token) {
                if (in_array($token->text, ['(', '['], true)) $depth++;
                if (in_array($token->text, [')', ']'], true)) $depth--;
                if ($token->is(T_CONSTANT_ENCAPSED_STRING) && $depth === 0) {
                    $url .= trim($token->text, '\'"');
                } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                    $url .= $token->text;
                } elseif ($token->is(T_VARIABLE) && $depth === 0) {
                    $url .= str_ends_with($url, 'period=') ? self::PERIOD : '1';
                } elseif ($token->is(T_STRING) && $token->text === 'substr') {
                    $url .= self::PERIOD;
                }
            }
            if (str_starts_with($url, '/')) {
                $result[] = preg_replace('/\{\$[^}]+\}/', '1', $url) ?? $url;
            }
        }

        return $result;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function literals(array $tokens, bool $withInterpolated = false): array
    {
        $result = [];
        foreach ($tokens as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $result[] = trim($token->text, '\'"');
            } elseif ($withInterpolated && $token->is(T_ENCAPSED_AND_WHITESPACE)) {
                $result[] = $token->text;
            }
        }

        return $result;
    }

    /** @param list<\PhpToken> $tokens */
    private static function expression(array $tokens): string
    {
        $text = '';
        foreach ($tokens as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $text .= $token->text;
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Argumenty všech volání `$call` v souboru (po řádcích volání).
     *
     * @return array<string,array<int,list<\PhpToken>>>
     */
    private static function callArguments(string $file, string $call): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize(self::source($file)),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $needle = preg_split('/(->|::)|\s+/', $call, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!self::matchesCall($tokens, $i, $needle)) {
                continue;
            }
            $j = $i + count($needle);
            if (($tokens[$j]->text ?? '') !== '(') {
                continue;
            }
            $depth = 0;
            $arguments = [[]];
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]->text;
                if (in_array($text, ['(', '[', '{'], true) || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                    if ($depth === 1) continue;
                }
                if (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) break;
                }
                if ($depth === 1 && $text === ',') {
                    $arguments[] = [];
                    continue;
                }
                $arguments[count($arguments) - 1][] = $tokens[$k];
            }
            $result[$tokens[$i]->line . '#' . $i] = $arguments;
        }

        return $result;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param list<string> $needle
     */
    private static function matchesCall(array $tokens, int $at, array $needle): bool
    {
        foreach ($needle as $offset => $part) {
            if (!isset($tokens[$at + $offset])) return false;
            $text = $tokens[$at + $offset]->text;
            if ($part === 'new') {
                if (!$tokens[$at + $offset]->is(T_NEW)) return false;
                continue;
            }
            if ($needle[0] === 'new') {
                $parts = explode('\\', $text);
                if (end($parts) !== $part) return false;
                continue;
            }
            if ($text !== $part) return false;
        }

        return true;
    }

    /** @return list<string> */
    private static function issueRuleCodes(): array
    {
        $rules = (new \ReflectionClassConstant(PayrollRunIssueGuidance::class, 'RULES'))->getValue();
        $codes = [];
        foreach (array_keys($rules) as $key) {
            foreach (explode('|', $key) as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @return array{regex:string,file:?string,source:string}|null */
    private static function route(string $path): ?array
    {
        $fallback = null;
        foreach (self::routes() as $route) {
            if (preg_match($route['regex'], $path) !== 1) continue;
            if ($route['file'] !== null) return $route;
            $fallback ??= $route;
        }

        return $fallback;
    }

    /** @return list<array{regex:string,file:?string,source:string}> */
    private static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes['all'];
        }
        $routes = [];
        foreach (glob(self::root() . '/web/src/router/*.ts') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/path:\\s*'([^']*)'/", $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $index => [$path, $offset]) {
                // Záchytná routa (NotFound) by „pokryla" i cestu, která neexistuje.
                if (str_contains($path, '(.*)')) continue;
                $end = $matches[0][$index + 1][1] ?? strlen($source);
                $chunk = substr($source, $offset, $end - $offset);
                $component = preg_match("#import\\('@/([^']+\\.vue)'\\)#", $chunk, $import) === 1
                    ? 'web/src/' . $import[1] : null;
                $pattern = preg_replace_callback(
                    '/:[A-Za-z]+(\(((?:[^()]|\([^()]*\))*)\))?/',
                    static fn (array $m): string => isset($m[2]) && $m[2] !== '' ? '(?:' . str_replace('\\\\', '\\', $m[2]) . ')' : '[^/]+',
                    ltrim($path, '/'),
                );
                $routes[] = [
                    'regex' => '#^/' . $pattern . '$#',
                    'file' => $component,
                    'source' => basename($file),
                ];
            }
        }
        self::$routes = ['all' => $routes];

        return $routes;
    }

    /** Zdroj stránky a místních modulů, které importuje (parametry čtou i její komponenty). */
    private static function pageSources(string $file): string
    {
        $key = 'page:' . $file;
        if (isset(self::$sources[$key])) {
            return self::$sources[$key];
        }
        $seen = [];
        $queue = [[$file, 0]];
        $text = '';
        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if (isset($seen[$current])) continue;
            $seen[$current] = true;
            $content = self::source($current);
            $text .= "\n" . $content;
            if ($depth >= 3) continue;
            preg_match_all("/(?:from|import\\()\\s*'((?:@\\/|\\.{1,2}\\/)[^']+)'/", $content, $imports);
            foreach ($imports[1] as $import) {
                $resolved = str_starts_with($import, '@/')
                    ? 'web/src/' . substr($import, 2)
                    : self::normalize(dirname($current) . '/' . $import);
                if (preg_match('#^web/src/(pages/payroll|components/payroll|composables|utils)/#', $resolved) !== 1) continue;
                foreach (['', '.ts', '.vue', '/index.ts'] as $suffix) {
                    if (is_file(self::root() . '/' . $resolved . $suffix)) {
                        $queue[] = [$resolved . $suffix, $depth + 1];
                        break;
                    }
                }
            }
        }

        return self::$sources[$key] = $text;
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') array_pop($parts);
            elseif ($part !== '.' && $part !== '') $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /** Zdroje mzdového frontendu bez testů (hledají se v nich pole a kotvy). */
    private static function payrollFrontend(): string
    {
        if (isset(self::$sources['payroll-frontend'])) {
            return self::$sources['payroll-frontend'];
        }
        $text = '';
        foreach (['web/src/pages/payroll', 'web/src/components/payroll'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['vue', 'ts'], true)
                    && !str_contains($file->getPathname(), '__tests__')
                ) {
                    $text .= "\n" . file_get_contents($file->getPathname());
                }
            }
        }

        return self::$sources['payroll-frontend'] = $text;
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private static function source(string $file): string
    {
        return self::$sources[$file] ??= (string) file_get_contents(self::root() . '/' . $file);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}

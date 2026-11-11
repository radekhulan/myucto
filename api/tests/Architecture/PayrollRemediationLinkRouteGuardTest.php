<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerRemediationPath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odkazy z blokací a nálezů mezd vedou na routu, kterou frontend má, a nesou
 * jen parametry, které cílová stránka opravdu čte.
 *
 * Backend skládá odkazy nápravy (`remediation_path`) jako řetězce a frontend
 * je jen otevře. Nic dřív nehlídalo, že cílová stránka parametr zná: odkaz
 * z kontroly před během `/payroll/people?filter=blocking_data` otevřel seznam
 * aktivních (filtr se z adresy nečetl) a souhrnný nález složek
 * `/payroll/components?tab=catalog&jmhz=missing` celý číselník.
 *
 * Test posbírá odkazy ze všech míst, která je skládají (katalog blokací JMHZ,
 * pravidla validací běhu, katalog mezer osob, literály ve službách běhu),
 * a pro každý ověří:
 *  - routa existuje ve `web/src/router/workspaceRoutes.ts`,
 *  - každý parametr dotazu stránka čte (`route.query.x`, `payrollQueryValue(route.query, 'x')` …),
 *  - hodnota `panel` u osob je panel, na který karta umí doskočit,
 *  - hodnota `tab` je na cílové stránce známý literál,
 *  - kotva `#…` na stránce existuje.
 */
#[Group('architecture')]
final class PayrollRemediationLinkRouteGuardTest extends TestCase
{
    private const PERIOD = '2026-02-01';

    /** Literály odkazů ve službách, které skládají nálezy a hlášky mezd. */
    private const LITERAL_SCAN = [
        'api/src/Service/Payroll/Run',
        'api/src/Service/Payroll/People',
        'api/src/Repository/Payroll/PayrollInputLockGuidance.php',
    ];

    public function testEveryRemediationLinkTargetsAnExistingRouteThatReadsItsParameters(): void
    {
        $routes = self::routes();
        self::assertNotEmpty($routes, 'Nepodařilo se načíst routy mezd.');
        $problems = [];
        foreach (self::links() as $link => $origin) {
            $parts = parse_url($link);
            $path = (string) ($parts['path'] ?? '');
            $page = self::pageFor($routes, $path);
            if ($page === null) {
                $problems[] = "{$link} ({$origin}): routa {$path} ve frontendu neexistuje.";
                continue;
            }
            $source = self::source($page);
            parse_str((string) ($parts['query'] ?? ''), $query);
            foreach ($query as $key => $value) {
                if (!self::readsParameter($source, (string) $key)) {
                    $problems[] = "{$link} ({$origin}): stránka {$page} parametr `{$key}` nečte.";
                    continue;
                }
                if ($key === 'panel' && $path === '/payroll/people' && !str_contains(self::peoplePanels($source), "'{$value}'")) {
                    $problems[] = "{$link} ({$origin}): karta osoby na panel `{$value}` doskočit neumí.";
                }
                if ($key === 'tab' && is_string($value) && !str_contains($source, "'{$value}'")) {
                    $problems[] = "{$link} ({$origin}): stránka {$page} záložku `{$value}` nezná.";
                }
            }
            $fragment = $parts['fragment'] ?? null;
            if (is_string($fragment) && !self::anchorExists($fragment)) {
                $problems[] = "{$link} ({$origin}): kotva #{$fragment} ve frontendu neexistuje.";
            }
        }

        self::assertSame([], array_values(array_unique($problems)));
    }

    /** Sběr musí opravdu něco najít, jinak by test svítil zeleně naprázdno. */
    public function testCollectorFindsTheKnownLinkShapes(): void
    {
        $links = array_keys(self::links());
        foreach ([
            '/payroll/people?filter=blocking_data',
            '/payroll/components?tab=catalog&jmhz=missing',
            '/payroll/absences?tab=averages&year=2026&quarter=1',
            '/payroll/people?employment=42&panel=jmhz_identity&field=jmhz.employment_external_identifier',
        ] as $expected) {
            self::assertContains($expected, $links);
        }
        self::assertGreaterThan(40, count($links));
    }

    /** @return array<string,string> odkaz => odkud pochází */
    private static function links(): array
    {
        $links = [];
        foreach (JmhzBlockerCatalog::codes() as $code) {
            foreach (['', 'employment', 'employee', 'component'] as $entityType) {
                foreach ([42, null] as $entityId) {
                    $path = JmhzBlockerRemediationPath::forReadinessCode($code, $entityId, self::PERIOD, $entityType);
                    if ($path !== null) {
                        $links[$path] ??= "JMHZ {$code}";
                    }
                }
            }
        }
        $rules = (new \ReflectionClassConstant(PayrollRunIssueGuidance::class, 'RULES'))->getValue();
        foreach (array_keys($rules) as $codes) {
            $code = explode('|', $codes)[0];
            foreach ([[7, 9], [7, null], [null, 9]] as [$employee, $employment]) {
                $links[PayrollRunIssueGuidance::describe("income_tax:{$code}", $employee, $employment)['remediation_path']] ??= "běh {$code}";
            }
        }
        foreach (PayrollPersonDataGapCatalog::keys() as $key) {
            $gap = PayrollPersonDataGapCatalog::describe($key);
            if ($gap['panel'] !== null) {
                $links['/payroll/people?' . http_build_query(['person' => 1, 'panel' => $gap['panel']] + ($gap['field'] === null ? [] : ['field' => $gap['field']]))] ??= "mezera {$key}";
            }
        }
        $root = self::root();
        foreach (self::LITERAL_SCAN as $relative) {
            $files = is_dir("{$root}/{$relative}")
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$relative}", \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo("{$root}/{$relative}")];
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all("~'(/payroll(?:/[a-z0-9_-]+)*(?:\\?[a-z0-9_=&.%-]+)?(?:#[a-z0-9_-]+)?)'~", (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $literal) {
                    $links[$literal] ??= basename($file->getPathname());
                }
            }
        }
        unset($links['']);

        return $links;
    }

    /** @return array<string,string> cesta routy (regex) => soubor stránky */
    private static function routes(): array
    {
        $source = (string) file_get_contents(self::root() . '/web/src/router/workspaceRoutes.ts');
        preg_match_all(
            "~path: '(payroll[^']*)'[^\\n]*?(?:import\\('@/(pages/[^']+\\.vue)'\\)|redirect: \\{ path: '([^']+)')~",
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        $routes = [];
        foreach ($matches as $match) {
            $pattern = '~^/' . preg_replace(['~:[a-z]+\\(([^)]*)\\)~i', '~:[a-z]+~i'], ['($1)', '[^/]+'], $match[1]) . '$~';
            $routes[$pattern] = ($match[2] ?? '') !== '' ? $match[2] : 'redirect:' . $match[3];
        }
        // Přesné routy mají přednost před parametrickými.
        uksort($routes, static fn (string $a, string $b): int => (int) str_contains($a, '(') <=> (int) str_contains($b, '('));

        return $routes;
    }

    /** @param array<string,string> $routes */
    private static function pageFor(array $routes, string $path): ?string
    {
        foreach ($routes as $pattern => $page) {
            if (preg_match($pattern, $path) === 1) {
                return str_starts_with($page, 'redirect:')
                    ? self::pageFor($routes, (string) parse_url(substr($page, 9), PHP_URL_PATH))
                    : $page;
            }
        }

        return null;
    }

    /**
     * Zdroj stránky včetně přímo importovaných mzdových modulů a komponent:
     * filtry vstupů čte `payrollInputFilters.ts`, výběr osoby u rizikových
     * úspor jejich panel.
     */
    private static function source(string $page): string
    {
        $base = self::root() . '/web/src/';
        $source = (string) file_get_contents($base . $page);
        preg_match_all("~from '(@/[^']+|\\./[^']+)'~", $source, $imports);
        foreach ($imports[1] as $import) {
            $relative = str_starts_with($import, '@/')
                ? substr($import, 2)
                : dirname($page) . '/' . substr($import, 2);
            if (!preg_match('~^(pages/payroll|components/payroll|composables)/~', $relative)) {
                continue;
            }
            foreach (['', '.ts', '.vue'] as $extension) {
                if (is_file($base . $relative . $extension) && !is_dir($base . $relative . $extension)) {
                    $source .= "\n" . (string) file_get_contents($base . $relative . $extension);
                    break;
                }
            }
        }

        return $source;
    }

    private static function readsParameter(string $source, string $key): bool
    {
        $quoted = preg_quote($key, '~');
        if ($key === 'period' && str_contains($source, 'payrollQueryPeriod(')) {
            return true;
        }

        return preg_match(
            "~query\\.{$quoted}\\b|query\\[['\"]{$quoted}['\"]\\]|query, ['\"]{$quoted}['\"]|routeQuery\\.{$quoted}\\b~",
            $source,
        ) === 1;
    }

    private static function peoplePanels(string $source): string
    {
        return preg_match('~const FOCUSABLE_PANELS = \\[(.*?)\\] as const~s', $source, $match) === 1 ? $match[1] : '';
    }

    private static function anchorExists(string $anchor): bool
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/web/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'vue'
                && str_contains((string) file_get_contents($file->getPathname()), $anchor)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}

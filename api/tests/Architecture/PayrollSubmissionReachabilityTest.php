<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Každá třída v `Service/Payroll/Submission/` musí mít cestu z HTTP Action
 * nebo z CLI příkazu.
 *
 * Proč zrovna tenhle test: jádro PREZEC/REGZEC bylo hotové, otestované,
 * ověřené proti připnutému XSD — a nikdy se nespustilo. Žádná Action, žádná
 * routa, žádné zapojení. Testy byly zelené, protože si třídy volaly samy.
 * Mrtvý kód, který vypadá živě, je horší než jeho absence: tváří se jako
 * splněná povinnost. V tomhle projektu se to stalo víc než jednou.
 *
 * Test staví graf závislostí z `use` deklarací a jde z kořenů (Action + cmd)
 * do hloubky. Co graf nezachytí, není zapojené — a musí buď dostat cestu,
 * nebo zmizet.
 */
final class PayrollSubmissionReachabilityTest extends TestCase
{
    /**
     * ZNÁMÝ DLUH, ne povolení. Seznam smí jen zkracovat — každá nová položka
     * znamená, že vzniklo další jádro bez cesty do běhu. Test je právě proto
     * západka: co tu není, musí být zapojené hned.
     *
     * Nález je sám o sobě výsledek: tahle vada není jen v registraci. Celý
     * REGZEL most (příprava doplňujících údajů zaměstnavatele) je hotový
     * a nikdy se nespustí.
     *
     * @var array<string,string>
     */
    private const ALLOWED_UNREACHABLE = [
        // REGZEL: most do MZ-19 je hotový, ale žádná Action ho nevolá —
        // `PayrollRegzelAction` končí u `EmployerRegistrationService`.
        'MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionBridgeService' => 'most REGZEL do MZ-19 nemá Action',
        'MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionPayload' => 'používá jen mrtvý most REGZEL',
        'MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionPayloadAssembler' => 'používá jen mrtvý most REGZEL',
        // Ověřování proti oficiálním příkladům ČSSZ: nástroj kvality, který
        // se zatím pouští jen z testů.
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleClassification' => 'ověřování oficiálních příkladů běží jen v testech',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleEvidence' => 'ověřování oficiálních příkladů běží jen v testech',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleSourceCatalog' => 'ověřování oficiálních příkladů běží jen v testech',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleValidationResult' => 'ověřování oficiálních příkladů běží jen v testech',
        // Nahrazeno `JmhzScenario1DocumentService`; zbylo po refaktoru.
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver' => 'nahrazeno JmhzScenario1DocumentService',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution' => 'nahrazeno JmhzScenario1DocumentService',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer' => 'volá se přes JmhzScenario1XmlValidator',
        'MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroReportProfile' => 'nulové hlášení nemá workflow',
        // Registrace staví snapshot přímo builderem: tahle služba váže
        // snapshot na revizi mzdového běhu, která u přihlášky před nástupem
        // z podstaty neexistuje. Zapojit ji znamená rozvázat tu vazbu i v SQL.
        'MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService' => 'váže snapshot na revizi mzdového běhu, kterou registrace nemá',
    ];

    public function testEverySubmissionClassIsReachableFromActionOrCommand(): void
    {
        $graph = $this->dependencyGraph();
        $reachable = $this->reachableFromRoots($graph);
        $unreachable = [];
        foreach ($this->submissionClasses() as $class => $file) {
            if (isset($reachable[$class])
                || isset(self::ALLOWED_UNREACHABLE[$class])
            ) {
                continue;
            }
            $unreachable[$class] = $file;
        }

        self::assertSame(
            [],
            $unreachable,
            "Tyhle třídy podání nemají cestu z Action ani z CLI příkazu, "
                . "takže se v běhu aplikace nikdy nespustí. Buď je zapoj, "
                . "nebo smaž:\n  - "
                . implode("\n  - ", array_keys($unreachable)),
        );
    }

    /**
     * Doplňková pojistka: kořeny musí existovat. Kdyby se změnila struktura
     * adresářů, test výš by prošel s prázdným grafem a nic by nehlídal.
     */
    public function testGraphRootsExist(): void
    {
        self::assertNotEmpty($this->roots());
        self::assertNotEmpty($this->submissionClasses());
    }

    /**
     * Graf si nesmí sám tiše ubrat třídy. `dg/bypass-finals` maže `final`
     * ze zdrojáků na svém allowlistu i pro obyčejné čtení souboru, takže
     * deklarace přijde jako `" class Config"` — s odsazením. Dokud to
     * {@see classNameIn()} netolerovalo, vypadlo z grafu 97 tříd a jejich
     * sousedé se hlásili jako mrtvý kód.
     *
     * Kotvíme na `Config` a `Connection`: obě jsou `final`, obě jsou na
     * allowlistu v `tests/bootstrap.php` a obě používá celá aplikace.
     */
    public function testBypassFinalsDoesNotHideClassesFromGraph(): void
    {
        $classes = $this->classesIn(dirname(__DIR__, 2) . '/src');

        self::assertArrayHasKey(
            'MyInvoice\Infrastructure\Config\Config',
            $classes,
            'final třída z allowlistu bypass-finals vypadla z grafu — '
                . 'regex na deklaraci neunese odsazení po odstranění `final`',
        );
        self::assertArrayHasKey(
            'MyInvoice\Infrastructure\Database\Connection',
            $classes,
        );
    }

    /**
     * @param array<string,list<string>> $graph
     * @return array<string,true>
     */
    private function reachableFromRoots(array $graph): array
    {
        $seen = [];
        $queue = array_merge(
            array_keys($this->roots()),
            $this->scriptRootImports(),
        );
        while ($queue !== []) {
            $class = array_pop($queue);
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            foreach ($graph[$class] ?? [] as $dependency) {
                if (!isset($seen[$dependency])) {
                    $queue[] = $dependency;
                }
            }
        }

        return $seen;
    }

    /**
     * Kořeny běhu: HTTP Action vrstva a CLI příkazy. Nic jiného aplikace
     * sama od sebe nespustí — testy se za kořen záměrně nepovažují.
     *
     * `api/bin` je plnohodnotný kořen: tam leží PHP entrypointy cronu
     * a údržbových úloh. `cmd/` jsou jen tenké obálky (.cmd/.sh), které
     * je spouštějí, takže samotné `cmd/` graf nezachytí — bez `api/bin`
     * by test hlásil jako mrtvou každou službu volanou výhradně cronem.
     *
     * @return array<string,string>
     */
    private function roots(): array
    {
        $root = dirname(__DIR__, 2);

        return array_merge(
            $this->classesIn($root . '/src/Action'),
            $this->classesIn($root . '/bin'),
            $this->classesIn(dirname($root) . '/cmd'),
        );
    }

    /**
     * Entrypointy cronu a údržbových úloh v `api/bin` jsou prosté skripty —
     * mají `use` importy, ale žádný jmenný prostor ani třídu, takže je
     * `classesIn()` přeskočí. Jejich importy jsou přitom stejně platná
     * spouštěcí hrana jako konstruktor Action. Bez tohohle kroku by test
     * hlásil jako mrtvou každou službu, kterou volá výhradně cron.
     *
     * @return list<string>
     */
    private function scriptRootImports(): array
    {
        $root = dirname(__DIR__, 2);
        $imports = [];
        foreach ([$root . '/bin', dirname($root) . '/cmd'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS,
                ),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo
                    || $file->getExtension() !== 'php'
                ) {
                    continue;
                }
                $source = (string) file_get_contents(
                    (string) $file->getRealPath(),
                );
                if (preg_match('/^namespace\s+/m', $source) === 1) {
                    continue;
                }
                if (preg_match_all(
                    '/^use\s+(MyInvoice\\\\[^\s;]+)\s*;/m',
                    $source,
                    $matches,
                ) >= 1) {
                    foreach ($matches[1] as $import) {
                        $imports[] = $import;
                    }
                }
            }
        }

        return array_values(array_unique($imports));
    }

    /** @return array<string,string> */
    private function submissionClasses(): array
    {
        return $this->classesIn(
            dirname(__DIR__, 2) . '/src/Service/Payroll/Submission',
        );
    }

    /**
     * Graf nad celým `src/` (+ `cmd/`): cesta z Action do podání vede skrz
     * jiné služby, takže se musí procházet všechno, ne jen dva adresáře.
     *
     * @return array<string,list<string>>
     */
    private function dependencyGraph(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            $this->classesIn($root . '/src'),
            $this->classesIn($root . '/bin'),
            $this->classesIn(dirname($root) . '/cmd'),
        );
        $known = [];
        foreach (array_keys($files) as $class) {
            $known[$class] = true;
        }
        $graph = [];
        foreach ($files as $class => $file) {
            $graph[$class] = $this->dependenciesOf((string) $file, $known);
        }

        return $graph;
    }

    /**
     * @param array<string,true> $known
     * @return list<string>
     */
    private function dependenciesOf(string $file, array $known): array
    {
        $source = (string) file_get_contents($file);
        $found = [];
        if (preg_match_all(
            '/^use\s+(MyInvoice\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+\s*)?;/m',
            $source,
            $matches,
        ) === false) {
            return [];
        }
        foreach ($matches[1] as $use) {
            $found[$use] = true;
        }
        // Plně kvalifikovaná volání bez `use` (statické tovární metody,
        // `new \MyInvoice\...`) by jinak z grafu vypadla a udělala z živé
        // třídy mrtvou.
        if (preg_match_all(
            '/\\\\(MyInvoice\\\\[A-Za-z0-9_\\\\]+)/',
            $source,
            $inline,
        ) !== false) {
            foreach ($inline[1] as $use) {
                $found[$use] = true;
            }
        }
        // Sousedi ve stejném jmenném prostoru se `use` neuvádějí. Bez tohohle
        // kroku by graf prohlásil za mrtvé skoro každý value object a enum,
        // které vedle sebe používají třídy jedné agendy — a test by hlásil
        // stovku nálezů, mezi kterými by ten skutečný zanikl.
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1
            ? trim($ns[1])
            : null;
        if ($namespace !== null && preg_match_all(
            '/\b([A-Z][A-Za-z0-9_]*)\b/',
            $source,
            $bare,
        ) !== false) {
            foreach ($bare[1] as $name) {
                $candidate = $namespace . '\\' . $name;
                if (isset($known[$candidate])) {
                    $found[$candidate] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** @return array<string,string> */
    private function classesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        $classes = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo
                || $file->getExtension() !== 'php'
            ) {
                continue;
            }
            $path = (string) $file->getRealPath();
            $class = $this->classNameIn($path);
            if ($class !== null) {
                $classes[$class] = $path;
            }
        }
        ksort($classes);

        return $classes;
    }

    /**
     * `[ \t]*` na začátku není kosmetika. `dg/bypass-finals` (zapnutý v
     * {@see /api/tests/bootstrap.php}) registruje wrapper nad `file://` a ze
     * zdrojáků na svém allowlistu maže klíčové slovo `final` — takže i prosté
     * `file_get_contents()` tady vrátí `" class Config"` s mezerou tam, kde na
     * disku stojí `final class Config`. Bez téhle tolerance regex řádek mine,
     * třída z grafu vypadne a její SOUSEDI VE STEJNÉM JMENNÉM PROSTORU se pak
     * tváří jako mrtvý kód. Přesně tohle test hlásil u čtyř tříd podání, které
     * `PayrollSubmissionService` normálně používá.
     *
     * Ticho je tu ta nebezpečná část: guard proti mrtvému kódu, který si sám
     * zmenší graf, dává falešné nálezy i falešné jistoty.
     */
    private function classNameIn(string $file): ?string
    {
        $source = (string) file_get_contents($file);
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }
        if (preg_match(
            '/^[ \t]*(?:final\s+|abstract\s+|readonly\s+)*'
                . '(?:class|interface|trait|enum)\s+(\w+)/m',
            $source,
            $name,
        ) !== 1) {
            return null;
        }

        return trim($namespace[1]) . '\\' . $name[1];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronCatalog;
use PHPUnit\Framework\TestCase;

final class CronCompletenessTest extends TestCase
{
    public function testEveryCronEntrypointHasCatalogEntryAndBothWrappers(): void
    {
        $entrypoints = $this->scriptsFromGlob('api/bin/cron-*.php');

        $catalog = CronCatalog::scripts();
        self::assertCount(count(array_unique($catalog)), $catalog, 'CronCatalog nesmí obsahovat duplicitní názvy.');
        sort($catalog);

        self::assertSame($entrypoints, $catalog, 'CronCatalog musí přesně odpovídat api/bin/cron-*.php.');
        self::assertSame($entrypoints, $this->scriptsFromGlob('cmd/cron-*.cmd'), 'Windows wrappery musí přesně odpovídat cron entrypointům.');
        self::assertSame($entrypoints, $this->scriptsFromGlob('cmd/cron-*.sh'), 'Linux wrappery musí přesně odpovídat cron entrypointům.');
    }

    /**
     * Každý cron entrypoint musí zapsat heartbeat přes {@see \MyInvoice\Service\Cron\CronRun}.
     *
     * Bez toho UI „Systém → Plánované úlohy" hlásí úlohu navždy jako „Neběželo",
     * i když se poctivě spouští každou minutu — přesně to se stalo mzdovým
     * workerům: wrapper nastavil `MYINVOICE_CRON_SCRIPT`, ale cílový skript tu
     * konstantu nikde nečetl, takže se do `cron_heartbeat` nikdy nic nezapsalo.
     * Tichá úloha, kterou provozní přehled nedokáže odlišit od nespuštěné, je
     * horší než chybějící úloha.
     *
     * Kontrola jde i do souboru, který wrapper `require`uje — právě tam ta
     * logika u workerů žije.
     */
    public function testEveryCronEntrypointRecordsHeartbeat(): void
    {
        foreach (glob($this->root() . '/api/bin/cron-*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $sources = [$source];
            if (preg_match_all("~require(?:_once)?\s+__DIR__\s*\.\s*'(/[^']+\.php)'~", $source, $matches) > 0) {
                foreach ($matches[1] as $relative) {
                    $included = $this->root() . '/api/bin' . $relative;
                    if (is_file($included)) {
                        $sources[] = (string) file_get_contents($included);
                    }
                }
            }

            self::assertStringContainsString(
                'CronRun',
                implode("\n", $sources),
                sprintf(
                    'Cron entrypoint %s nezapisuje heartbeat přes CronRun — v UI zůstane navždy „Neběželo".',
                    basename($path),
                ),
            );
        }
    }

    public function testEveryReadmeCronSectionMatchesEntrypointsInBothDirections(): void
    {
        $entrypoints = $this->scriptsFromGlob('api/bin/cron-*.php');
        $readme = file_get_contents($this->root() . '/cmd/README.md');
        self::assertIsString($readme);

        $sections = [
            'přehled plánovaných úloh' => ['### Cron — plánované úlohy', '### Docker — vývoj v kontejnerech'],
            'doporučené frekvence' => ['## Cron — doporučené frekvence', '### Windows — Task Scheduler'],
            'Windows Task Scheduler' => ['### Windows — Task Scheduler', '### Linux — crontab'],
            'Linux crontab' => ['### Linux — crontab', '### Manuální spuštění (debug)'],
        ];

        foreach ($sections as $label => [$startHeading, $endHeading]) {
            $documented = $this->scriptsFromReadmeSection($readme, $startHeading, $endHeading);
            self::assertSame(
                $entrypoints,
                $documented,
                "Sekce README „{$label}“ musí obsahovat právě všechny cron entrypointy.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function scriptsFromGlob(string $pattern): array
    {
        $paths = glob($this->root() . '/' . $pattern);
        self::assertIsArray($paths);

        $scripts = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
        sort($scripts);

        return $scripts;
    }

    /**
     * @return list<string>
     */
    private function scriptsFromReadmeSection(string $readme, string $startHeading, string $endHeading): array
    {
        $start = strpos($readme, $startHeading);
        self::assertNotFalse($start, "V cmd/README.md chybí nadpis {$startHeading}.");
        $start += strlen($startHeading);

        $end = strpos($readme, $endHeading, $start);
        self::assertNotFalse($end, "V cmd/README.md chybí koncový nadpis {$endHeading}.");

        $matched = preg_match_all('/\bcron-[a-z0-9-]+\b/', substr($readme, $start, $end - $start), $matches);
        self::assertNotFalse($matched);

        $scripts = array_values(array_unique($matches[0]));
        sort($scripts);

        return $scripts;
    }

    private function root(): string
    {
        return dirname(__DIR__, 5);
    }
}

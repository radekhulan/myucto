<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\AccidentInsuranceRateSchedule;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Sazebník musí být v repozitáři **kompletní**, ne jen přítomný — stejný důvod
 * jako u {@see CzIscoCodebookIntegrityTest}. Osekaný soubor by se jinak projevil
 * jako „v příloze nic není" a účetní by sazbu opsala odjinud, aniž by věděla, že
 * jí aplikace zamlčela 97 řádků.
 */
final class AccidentInsuranceRateScheduleIntegrityTest extends TestCase
{
    private const EXPECTED_GROUPS = 8;
    private const EXPECTED_ACTIVITIES = 98;

    /** @var array<string,int> */
    private const EXPECTED_BY_GROUP = [
        'rate-50-40' => 3,
        'rate-9-80' => 11,
        'rate-8-40' => 35,
        'rate-7-00' => 1,
        'rate-4-20' => 37,
        'rate-2-80' => 11,
        'rate-10-50' => 0,
        'rate-5-60' => 0,
    ];

    public function testManifestHasExpectedNumberOfEntries(): void
    {
        $payload = $this->payload();

        self::assertSame(self::EXPECTED_GROUPS, count($payload['groups']));
        self::assertSame(self::EXPECTED_GROUPS, $payload['counts']['groups']);
        self::assertSame(self::EXPECTED_ACTIVITIES, $payload['counts']['activities']);
        self::assertSame(self::EXPECTED_BY_GROUP, $payload['counts']['activities_by_group']);
        self::assertSame(
            self::EXPECTED_ACTIVITIES,
            array_sum(self::EXPECTED_BY_GROUP),
            'Součet skupin se musí rovnat počtu činností.',
        );
    }

    public function testManifestHashMatchesPinnedValue(): void
    {
        $manifest = $this->manifest();

        self::assertSame(
            AccidentInsuranceRateSchedule::DEFAULT_MANIFEST_SHA256,
            $manifest['manifest_sha256'],
        );
        AccidentInsuranceRateSchedule::validateManifest($manifest, true);
    }

    public function testSha256sumsCoversEveryPinnedFile(): void
    {
        $root = $this->resourceRoot();
        $lines = file($root . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertNotSame([], $lines);

        $seen = [];
        foreach ($lines as $line) {
            self::assertSame(1, preg_match('/\A([0-9a-f]{64})\s+(\S.*)\z/D', $line, $m), $line);
            $path = $root . '/' . str_replace('\\', '/', $m[2]);
            self::assertFileExists($path);
            self::assertSame($m[1], hash_file('sha256', $path), "Otisk {$m[2]} nesedí.");
            $seen[basename($m[2])] = true;
        }
        self::assertArrayHasKey('manifest.json', $seen);
    }

    public function testTamperedManifestIsRejected(): void
    {
        $manifest = $this->manifest();
        $manifest['payload']['groups'][0]['rate_per_mille'] = '1.00';

        $this->expectException(\UnexpectedValueException::class);
        AccidentInsuranceRateSchedule::validateManifest($manifest);
    }

    public function testEmptyScheduleIsRejectedInsteadOfSilentlyAcceptingNothing(): void
    {
        $manifest = $this->manifest();
        $manifest['payload']['groups'] = [];

        $this->expectException(\UnexpectedValueException::class);
        AccidentInsuranceRateSchedule::validateManifest($manifest);
    }

    /**
     * Skupiny bez kódu jsou to, co dělá ze sazebníku podklad místo výčtu:
     * činnost, která v tabulce není, spadne do 5,6 ‰ nebo — je-li nebezpečná —
     * do 10,5 ‰. Kdyby je někdo z dat vyhodil jako „prázdné řádky", vypadal by
     * sazebník jako uzavřený seznam kódů.
     */
    public function testScheduleWithoutResidualGroupIsRejected(): void
    {
        $manifest = $this->manifest();
        $manifest['payload']['groups'] = array_values(array_filter(
            $manifest['payload']['groups'],
            static fn (array $group): bool
                => $group['kind'] !== AccidentInsuranceRateSchedule::KIND_RESIDUAL,
        ));

        $this->expectException(\UnexpectedValueException::class);
        AccidentInsuranceRateSchedule::validateManifest($manifest);
    }

    /**
     * Minimum 100 Kč žije na dvou místech: v kalkulátoru jako konstanta a
     * v sazebníku jako údaj přílohy. Buď se to sjednotí, nebo se to hlídá —
     * tohle je ta hlídka.
     */
    public function testMinimumPremiumMatchesTheCalculatorConstant(): void
    {
        $schedule = new AccidentInsuranceRateSchedule();

        self::assertSame(
            PayrollAccidentInsuranceCalculator::MINIMUM_QUARTERLY_PREMIUM_MINOR,
            $schedule->minimumQuarterlyPremiumCzk() * 100,
        );
    }

    /**
     * Formulář si sazby přílohy zrcadlí, aby uměl bez dotazu na server
     * upozornit na hodnotu mimo sazebník. Zrcadlo se nesmí rozejít s originálem.
     */
    public function testFrontendMirrorOfAnnexRatesStaysInSync(): void
    {
        $path = dirname(__DIR__, 4)
            . '/web/src/pages/payroll/AccidentInsuranceRateSettings.vue';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertSame(
            1,
            preg_match('/const ANNEX_RATES = \[([^\]]*)\]/', $source, $match),
            'Ve formuláři chybí ANNEX_RATES.',
        );

        $mirror = array_map(
            static fn (string $value): float => (float) trim($value),
            explode(',', $match[1]),
        );
        sort($mirror);
        $expected = array_map(
            static fn (string $rate): float => (float) $rate,
            (new AccidentInsuranceRateSchedule())->rates(),
        );
        sort($expected);

        self::assertSame($expected, $mirror);
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function manifest(): array
    {
        $json = file_get_contents(
            $this->resourceRoot() . '/annex-2-2002-01-01/manifest.json',
        );
        self::assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['manifest_sha256'] ?? null);
        self::assertIsArray($decoded['payload'] ?? null);

        /** @var array{manifest_sha256:string,payload:array<string,mixed>} $decoded */
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return $this->manifest()['payload'];
    }

    private function resourceRoot(): string
    {
        return dirname(__DIR__, 3) . '/resources/payroll/accident-insurance-rates';
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Service\System\EnvironmentCheckService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Převody, na kterých stojí verdikt auditu prostředí. Špatně přečtená hodnota
 * `php.ini` znamená falešné varování — a diagnostika, která varuje zbytečně,
 * přestane být čtená.
 */
final class EnvironmentCheckServiceTest extends TestCase
{
    public function testManagedReportDropsDiskSpaceIncludingItsEffectOnVerdict(): void
    {
        $report = EnvironmentCheckService::withoutDiskSpace([
            'summary' => ['status' => 'fail', 'ok' => 1, 'warn' => 0, 'fail' => 1, 'skip' => 0],
            'checks'  => [
                ['id' => 'php_version', 'status' => 'ok'],
                EnvironmentCheckService::diskSpaceCheck(1024, 2048),
            ],
            'facts'   => ['storage' => ['free_bytes' => 1024, 'total_bytes' => 2048, 'log_bytes' => 5]],
        ]);

        self::assertSame(['php_version'], array_column($report['checks'], 'id'));
        self::assertSame(['status' => 'ok', 'ok' => 1, 'warn' => 0, 'fail' => 0, 'skip' => 0], $report['summary']);
        self::assertSame(['log_bytes' => 5], $report['facts']['storage']);
    }

    public function testDiskSpaceShowsFreeAndTotalAndOnlyFreeDecidesStatus(): void
    {
        $gb = 1024 ** 3;
        $check = EnvironmentCheckService::diskSpaceCheck(40 * $gb, 100 * $gb);
        self::assertSame('ok', $check['status']);
        self::assertSame(EnvironmentCheckService::humanBytes(40 * $gb), $check['actual']);
        self::assertSame(EnvironmentCheckService::humanBytes(100 * $gb), $check['info']);

        self::assertSame('warn', EnvironmentCheckService::diskSpaceCheck($gb, 100 * $gb)['status']);
        self::assertSame('fail', EnvironmentCheckService::diskSpaceCheck(1024 ** 2, 100 * $gb)['status']);

        $unknown = EnvironmentCheckService::diskSpaceCheck(null, null);
        self::assertSame(['skip', '?', ''], [$unknown['status'], $unknown['actual'], $unknown['info']]);
    }

    #[DataProvider('iniValueProvider')]
    public function testIniBytesParsesPhpNotation(string $input, int $expected): void
    {
        self::assertSame($expected, EnvironmentCheckService::iniBytes($input));
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function iniValueProvider(): array
    {
        return [
            'megabajty'      => ['256M', 256 * 1024 * 1024],
            'gigabajty'      => ['2G', 2 * 1024 * 1024 * 1024],
            'kilobajty'      => ['512K', 512 * 1024],
            'malé písmeno'   => ['128m', 128 * 1024 * 1024],
            'bez jednotky'   => ['1048576', 1048576],
            'bez limitu'     => ['-1', -1],
            'prázdné'        => ['', 0],
            's mezerou'      => [' 64M ', 64 * 1024 * 1024],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testNumericVersionStripsVendorSuffix(string $input, string $expected): void
    {
        self::assertSame($expected, EnvironmentCheckService::numericVersion($input));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function versionProvider(): array
    {
        return [
            'mariadb'      => ['11.8.8-MariaDB', '11.8.8'],
            'mariadb log'  => ['11.8.2-MariaDB-1:11.8.2+maria~ubu2404-log', '11.8.2'],
            'mysql'        => ['8.0.36-0ubuntu0.22.04.1', '8.0.36'],
            'holá verze'   => ['11.8', '11.8'],
        ];
    }

    /**
     * Porovnání verze musí fungovat i pro dvousegmentovou baseline `11.8`,
     * jinak by se `11.8.8` vyhodnotilo jako starší.
     */
    public function testVersionComparisonAgainstBaseline(): void
    {
        self::assertTrue(version_compare(EnvironmentCheckService::numericVersion('11.8.8-MariaDB'), '11.8', '>='));
        self::assertTrue(version_compare(EnvironmentCheckService::numericVersion('12.0.1-MariaDB'), '11.8', '>='));
        self::assertFalse(version_compare(EnvironmentCheckService::numericVersion('11.4.5-MariaDB'), '11.8', '>='));
        self::assertFalse(version_compare(EnvironmentCheckService::numericVersion('10.11.2-MariaDB'), '11.8', '>='));
    }

    #[DataProvider('humanBytesProvider')]
    public function testHumanBytes(int $input, string $expected): void
    {
        self::assertSame($expected, EnvironmentCheckService::humanBytes($input));
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function humanBytesProvider(): array
    {
        return [
            'bajty'     => [512, '512 B'],
            'kilobajty' => [2048, '2 kB'],
            'megabajty' => [25 * 1024 * 1024, '25 MB'],
            'gigabajty' => [2 * 1024 * 1024 * 1024, '2 GB'],
            'nula'      => [0, '0 B'],
        ];
    }

    /**
     * Seznam povinných rozšíření je smlouva s `composer.json`. Když se rozejdou,
     * buď diagnostika hlásí chybějící rozšíření, které composer nevyžaduje,
     * nebo mlčí o tom, které vyžaduje — obojí je matoucí.
     */
    public function testRequiredExtensionsMatchComposerRequire(): void
    {
        $composerPath = dirname(__DIR__, 4) . '/composer.json';
        self::assertFileExists($composerPath);

        $composer = json_decode((string) file_get_contents($composerPath), true);
        self::assertIsArray($composer);

        $declared = [];
        foreach (array_keys($composer['require'] ?? []) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $declared[] = strtolower(substr((string) $package, 4));
            }
        }
        sort($declared);

        $checked = array_map('strtolower', EnvironmentCheckService::REQUIRED_EXTENSIONS);
        sort($checked);

        self::assertSame($declared, $checked);
    }

    /** Volitelná rozšíření se nesmí překrývat s povinnými. */
    public function testOptionalExtensionsAreNotAlsoRequired(): void
    {
        $required = array_map('strtolower', EnvironmentCheckService::REQUIRED_EXTENSIONS);
        $optional = array_map('strtolower', array_keys(EnvironmentCheckService::OPTIONAL_EXTENSIONS));

        self::assertSame([], array_intersect($required, $optional));
    }

    /**
     * `ext-sodium` musí zůstat volitelné — bez něj se použije čistě PHP
     * fallback `paragonie/sodium_compat`. Kdyby se přesunulo mezi povinná,
     * instalace bez libsodia by přestala jít nainstalovat.
     */
    public function testSodiumIsOptionalBecauseFallbackExists(): void
    {
        self::assertArrayHasKey('sodium', EnvironmentCheckService::OPTIONAL_EXTENSIONS);
        self::assertNotContains('sodium', array_map('strtolower', EnvironmentCheckService::REQUIRED_EXTENSIONS));
        self::assertTrue(
            class_exists(\ParagonIE_Sodium_Compat::class),
            'paragonie/sodium_compat musí být nainstalovaný, jinak fallback v LicenseTokenVerifier nefunguje.'
        );
    }
}

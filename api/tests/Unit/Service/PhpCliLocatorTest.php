<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\PhpCliLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Worker se pod php-fpm (oficiální Docker image) spouštěl s binárkou `php-fpm`
 * místo CLI php — php-fpm CLI skript nepochopí, vypíše usage a skončí, takže
 * import zůstal navždy „queued". `PhpCliLocator` proto musí ne-CLI SAPI binárky
 * (php-fpm, php-cgi, php-win, phpdbg) odmítnout.
 */
final class PhpCliLocatorTest extends TestCase
{
    /** @return list<array{string}> */
    public static function nonCliNames(): array
    {
        return [
            ['php-fpm'],
            ['php-fpm8'],
            ['php-fpm8.5'],
            ['/usr/local/sbin/php-fpm'],
            ['php-cgi'],
            ['php-cgi.exe'],
            ['php-win.exe'],
            ['phpdbg'],
            ['phpdbg.exe'],
            ['PHP-FPM'], // case-insensitivní
        ];
    }

    #[DataProvider('nonCliNames')]
    public function testRejectsNonCliSapiBinaries(string $path): void
    {
        self::assertTrue(
            PhpCliLocator::isNonCliSapiName(basename($path)),
            "$path má být odmítnuto jako ne-CLI SAPI"
        );
    }

    /** @return list<array{string}> */
    public static function cliNames(): array
    {
        return [
            ['php'],
            ['php.exe'],
            ['php8.5'],
            ['/usr/local/bin/php'],
            ['C:\\Program Files\\PHP\\php.exe'],
        ];
    }

    #[DataProvider('cliNames')]
    public function testAcceptsCliBinaries(string $path): void
    {
        self::assertFalse(
            PhpCliLocator::isNonCliSapiName(basename($path)),
            "$path má být přijato jako CLI php"
        );
    }

    public function testResolveNeverReturnsNonCliBinary(): void
    {
        PhpCliLocator::forget();
        $resolved = PhpCliLocator::resolve();
        if ($resolved === null) {
            self::markTestSkipped('Žádné php nenalezeno v tomto prostředí.');
        }
        self::assertFalse(
            PhpCliLocator::isNonCliSapiName(basename($resolved)),
            "resolve() nesmí vrátit ne-CLI SAPI binárku, vráceno: $resolved"
        );
    }

    /**
     * ⚠️ Regrese ze sdíleného hostingu s víc verzemi PHP.
     *
     * Web běžel pod `/usr/bin/php-cgi8.5`, ale holé `php` bylo 7.4. Hledání
     * skončilo právě u něj, worker composer autoload na první řádce zabil
     * hláškou o nesplněné platformě — a protože `nohup … &` vrátí úspěch,
     * kontrola aktivace účetnictví zůstala navždy ve stavu „Čeká".
     *
     * Správný protějšek k `php-cgi8.5` je `php8.5`, ne `php`.
     *
     * @return list<array{string,string}>
     */
    public static function cliCounterparts(): array
    {
        return [
            ['php-cgi8.5', 'php8.5'],
            ['php-cgi7.4', 'php7.4'],
            ['php-fpm8.5', 'php8.5'],
            ['php-cgi', 'php'],
            ['php-fpm', 'php'],
            ['php-cgi.exe', 'php.exe'],
            ['php-win.exe', 'php.exe'],
            ['php8.5', 'php8.5'],
            ['php', 'php'],
        ];
    }

    #[DataProvider('cliCounterparts')]
    public function testDerivesVersionedCliCounterpart(string $sapi, string $expected): void
    {
        self::assertSame($expected, PhpCliLocator::cliNameFor($sapi));
    }

    /**
     * ⚠️ Nalezená binárka musí umět rozběhnout aplikaci. Starší verze worker
     * jen zabije a job zůstane viset — je lepší nenajít nic (a job rovnou
     * selže s hláškou) než najít php, na kterém nic nepoběží.
     */
    public function testResolvedBinaryMeetsTheMinimumVersion(): void
    {
        PhpCliLocator::forget();
        $resolved = PhpCliLocator::resolve();
        if ($resolved === null) {
            self::markTestSkipped('Žádné php nenalezeno v tomto prostředí.');
        }

        // Sonda bez uvozovek a bez `|` — na Windows by je escapeshellarg()
        // rozbil, viz PhpCliLocator::probe().
        $out = [];
        $rc  = 1;
        exec(escapeshellarg($resolved) . ' -r ' . escapeshellarg('echo PHP_SAPI, PHP_EOL, PHP_VERSION;'), $out, $rc);
        self::assertSame(0, $rc, "resolve() vrátil nespustitelnou binárku: $resolved");

        [$sapi, $version] = [trim($out[0]), trim($out[1])];
        self::assertSame('cli', $sapi);
        self::assertTrue(
            version_compare($version, PhpCliLocator::MIN_VERSION, '>='),
            "resolve() vrátil php $version, aplikace potřebuje aspoň " . PhpCliLocator::MIN_VERSION
        );
    }
}

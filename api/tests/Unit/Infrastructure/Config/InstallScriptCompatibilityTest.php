<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\TestCase;

/**
 * Pojistka na instalační PowerShell skripty.
 *
 * Skripty vyžadují PowerShell 7, ale ve Windows PowerShellu 5.1 (pořád výchozí
 * `powershell.exe` na Win 11) se to uživatel musí dozvědět z hlášky — ne z chyby
 * parseru. To klade dvě podmínky, které jdou proti sobě jen zdánlivě:
 *
 *   1) Skript MUSÍ jít naparsovat i pod 5.1, jinak se kontrola verze nikdy
 *      nevypíše. Proto žádná PS7-only syntaxe (`?.`, `??`, ternární `? :`)
 *      a proto čisté ASCII: PS 5.1 čte `.ps1` bez BOM jako ANSI, takže z UTF-8
 *      em-dashe vznikne v CP1250 znak U+201D, který parser bere jako uvozovku
 *      a rozbije zbytek souboru.
 *   2) Skript MUSÍ verzi opravdu zkontrolovat a skončit.
 *
 * Historicky selhalo obojí najednou (issue: zákazník na Win 11 + Docker Desktop),
 * což skončilo tiše nevygenerovaným cfg.docker.php a nekonečným redirectem na /login.
 */
final class InstallScriptCompatibilityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function scriptProvider(): array
    {
        return [
            'docker-ghcr.ps1'    => ['/cmd/docker-ghcr.ps1'],
            'docker-install.ps1' => ['/cmd/docker-install.ps1'],
            'docker-update.ps1'  => ['/cmd/docker-update.ps1'],
        ];
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 5) . $relativePath;
        self::assertFileExists($path, "{$relativePath} not found");
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scriptProvider')]
    public function testScriptIsPureAsciiSoWindowsPowerShellCanParseIt(string $relativePath): void
    {
        $contents = self::read($relativePath);

        $offending = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $i => $line) {
            if (preg_match('/[^\x00-\x7F]/', $line)) {
                $offending[] = sprintf('L%d: %s', $i + 1, trim($line));
            }
        }

        self::assertSame([], $offending, sprintf(
            "%s obsahuje non-ASCII znaky. PowerShell 5.1 čte .ps1 bez BOM jako ANSI a "
            . "rozbije se na nich parser, takže se nevypíše ani kontrola verze.\n%s",
            $relativePath,
            implode("\n", $offending),
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scriptProvider')]
    public function testScriptAvoidsPowerShell7OnlySyntax(string $relativePath): void
    {
        $contents = self::read($relativePath);

        // Null-conditional / null-coalescing. V PS 5.1 je to chyba parseru CELÉHO
        // souboru, takže skript neprovede ani první řádek.
        self::assertDoesNotMatchRegularExpression(
            '/\)\s*\?\./',
            $contents,
            "{$relativePath} používá null-conditional operátor `?.` (PS7-only).",
        );
        self::assertDoesNotMatchRegularExpression(
            '/\?\?/',
            $contents,
            "{$relativePath} používá null-coalescing operátor `??` (PS7-only).",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scriptProvider')]
    public function testScriptRefusesToRunOnWindowsPowerShell(string $relativePath): void
    {
        $contents = self::read($relativePath);

        self::assertMatchesRegularExpression(
            '/if \(\$PSVersionTable\.PSVersion\.Major -lt 7\) \{/',
            $contents,
            "{$relativePath} nemá kontrolu verze PowerShellu.",
        );
        self::assertMatchesRegularExpression(
            '/-lt 7\) \{[\s\S]{0,900}?exit 1/',
            $contents,
            "{$relativePath} kontrolu verze má, ale neskončí (`exit 1`).",
        );
    }

    /**
     * Cookie s prefixem `__Host-` vyžaduje `Secure`, takže ji prohlížeč přes plain
     * HTTP zahodí. Docker default běží na `http://localhost`, takže instalátor musí
     * přepsat OBĚ cookie — session i „důvěryhodné zařízení" (MFA).
     *
     * @return array<string, array{string}>
     */
    public static function generatorProvider(): array
    {
        return [
            'docker-ghcr.ps1'    => ['/cmd/docker-ghcr.ps1'],
            'docker-install.ps1' => ['/cmd/docker-install.ps1'],
            'docker-ghcr.sh'     => ['/cmd/docker-ghcr.sh'],
            'docker-install.sh'  => ['/cmd/docker-install.sh'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('generatorProvider')]
    public function testGeneratorRewritesEveryHostPrefixedCookieForPlainHttp(string $relativePath): void
    {
        $contents = self::read($relativePath);

        foreach (['myinvoice_session', 'myinvoice_td'] as $cookie) {
            self::assertStringContainsString(
                "'{$cookie}'",
                $contents,
                sprintf(
                    '%s nepřepisuje `__Host-%s` na verzi bez prefixu — přes HTTP ji prohlížeč zahodí.',
                    $relativePath,
                    $cookie,
                ),
            );
        }
    }

    /**
     * Instalátor dřív natvrdo zapisoval `app.env = development` a vypnutou Secure
     * cookie. Výchozí musí být produkce, vývojový režim jen na výslovnou volbu a
     * vypnutí Secure cookie jen pro HTTP adresu.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('generatorProvider')]
    public function testGeneratorDefaultsToProductionAndDerivesSecureCookieFromScheme(string $relativePath): void
    {
        $contents = self::read($relativePath);

        self::assertStringNotContainsString(
            "=> 'development',",
            $contents,
            "{$relativePath} zapisuje app.env = development natvrdo.",
        );
        self::assertMatchesRegularExpression(
            '/APP_ENV="production"|\$appEnv = \'production\'/',
            $contents,
            "{$relativePath} nemá produkční výchozí app.env.",
        );
        self::assertMatchesRegularExpression(
            '/(if \[\[ "\$APP_URL" == http:\/\/\* \]\]|if \(\$appIsHttp\) \{)[\s\S]{0,400}?\'cookie_secure\' => false,/',
            $contents,
            "{$relativePath} vypíná Secure cookie bez ohledu na schéma app.url.",
        );
    }

    /**
     * Nová instalace dostane vlastní klíč mzdových otisků a všechna tajemství
     * vznikají z kryptograficky bezpečného zdroje (ne z Get-Random).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('generatorProvider')]
    public function testGeneratorCreatesStrongPayrollHashKey(string $relativePath): void
    {
        $contents = self::read($relativePath);

        self::assertStringContainsString("'payroll_hash_key' => '',", $contents);
        self::assertMatchesRegularExpression(
            '/PAYROLL_HASH_KEY=\$\(openssl rand -base64 32\)|\$payrollHashKey = New-Base64Key/',
            $contents,
        );
        self::assertStringNotContainsString('Get-Random', $contents);
    }

    /**
     * `Set-Content -Encoding UTF8` píše v PS 5.1 BOM. BOM před `<?php` v
     * cfg.docker.php se vypíše na výstup ještě před hlavičkami a znemožní
     * odeslání session cookie — přihlášení projde, ale aplikace hned odhlásí.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scriptProvider')]
    public function testScriptNeverWritesFilesWithBom(string $relativePath): void
    {
        // Komentáře smí `Set-Content -Encoding UTF8` zmiňovat — právě jimi je
        // vysvětlené, proč se nepoužívá.
        $codeOnly = implode("\n", array_filter(
            preg_split('/\R/', self::read($relativePath)) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        ));

        self::assertDoesNotMatchRegularExpression(
            '/(Set-Content|Out-File)[^\r\n]*-Encoding\s+UTF8\b/i',
            $codeOnly,
            "{$relativePath} zapisuje přes `-Encoding UTF8`, což v PS 5.1 přidá BOM. "
            . 'Použij `[System.IO.File]::WriteAllText` s `UTF8Encoding($false)`.',
        );
    }
}

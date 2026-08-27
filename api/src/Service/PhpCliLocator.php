<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * Najde CLI `php` binárku pro spouštění detached workerů (import, cron).
 *
 * Pod IIS / FastCGI je `PHP_BINARY` typicky `php-cgi.exe`, a pod **php-fpm**
 * (oficiální Docker image) je `PHP_BINARY` přímo binárka `php-fpm` — obojí CLI
 * skripty (`if (PHP_SAPI !== 'cli') exit;`) spustí špatně. php-fpm navíc CLI
 * argumenty/skript nepochopí, vypíše usage a skončí, takže worker se nikdy
 * nespustí (job zůstane navždy „queued"). Tento helper takové ne-CLI SAPI
 * binárky přeskočí a vrátí skutečnou cestu k CLI php (sibling, běžné cesty, …).
 *
 * ⚠️ NAJÍT „nějaké php" NESTAČÍ. Na sdíleném hostingu s víc verzemi PHP
 * (Debian/ISPConfig) je `/usr/bin/php` často stará systémová verze: web běžel
 * pod `php-cgi8.5`, ale holé `php` bylo 7.4. Worker se „spustil", composer
 * autoload ho na první řádce zabil hláškou o nesplněné platformě — a protože
 * `nohup … &` vrátí úspěch, aplikace o tom nevěděla. Kontrola aktivace
 * účetnictví tak zůstala navždy ve stavu „Čeká".
 *
 * Proto se každý kandidát OVĚŘUJE spuštěním: musí hlásit SAPI `cli`
 * a verzi aspoň {@see self::MIN_VERSION}.
 */
final class PhpCliLocator
{
    /**
     * Nejnižší verze, pod kterou se worker nerozběhne (`composer.json`
     * požaduje `^8.5`). Kandidáty se starší verzí je nutné zahodit —
     * spuštěný worker by na nich stejně umřel.
     */
    public const MIN_VERSION = '8.5';

    /** @var string|null|false false = ještě neurčeno */
    private static string|null|false $cached = false;

    /**
     * @return string|null Cesta / příkaz CLI php, nebo null pokud nic nenalezeno.
     */
    public static function resolve(): ?string
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        foreach (self::candidates() as $candidate) {
            if (self::isUsableCli($candidate)) {
                return self::$cached = $candidate;
            }
        }

        return self::$cached = null;
    }

    /** Zapomene zapamatovaný výsledek — jen pro testy. */
    public static function forget(): void
    {
        self::$cached = false;
    }

    /**
     * Kandidáti v pořadí od nejpravděpodobnějšího.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        $out = [];
        $binary = PHP_BINARY;
        if ($binary !== '') {
            $out[] = $binary;
            $dir  = dirname($binary);
            $sep  = DIRECTORY_SEPARATOR;
            // ⚠️ Sourozenec se JMÉNEM ODVOZENÝM od aktuální binárky, ne holé
            // „php". Na Debianu/ISPConfigu nese jméno verzi (`php-cgi8.5`),
            // takže správný CLI protějšek je `php8.5` — kdežto `php` je tam
            // systémová (a klidně o generaci starší) verze.
            $out[] = $dir . $sep . self::cliNameFor(basename($binary));
            $out[] = $dir . $sep . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $out[] = 'C:\inetpub\php\php.exe';
            $out[] = 'C:\Program Files\PHP\php.exe';
            $out[] = 'php.exe';
        } else {
            // Verzované cesty před holými: na víceverzovém hostingu je holé
            // `php` to nejméně spolehlivé, co tu je.
            $suffix = self::versionSuffix(basename($binary));
            if ($suffix !== '') {
                $out[] = '/usr/bin/php' . $suffix;
                $out[] = '/usr/local/bin/php' . $suffix;
            }
            $out[] = '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $out[] = '/usr/bin/php';
            $out[] = '/usr/local/bin/php';
            $out[] = 'php';
        }

        $seen = [];
        $unique = [];
        foreach ($out as $c) {
            if ($c === '' || isset($seen[$c]) || self::isNonCliSapiName(basename($c))) {
                continue;
            }
            $seen[$c] = true;
            $unique[] = $c;
        }

        return $unique;
    }

    /**
     * CLI protějšek k názvu SAPI binárky: `php-cgi8.5` → `php8.5`,
     * `php-fpm` → `php`, `php-cgi.exe` → `php.exe`.
     */
    public static function cliNameFor(string $name): string
    {
        return (string) preg_replace('/^php-(?:cgi|fpm|win)/i', 'php', $name);
    }

    /** Verzová přípona názvu binárky: `php-cgi8.5` → `8.5`, `php` → ``. */
    private static function versionSuffix(string $name): string
    {
        return preg_match('/(\d+\.\d+)$/', $name, $m) === 1 ? $m[1] : '';
    }

    /**
     * Spustí kandidáta a ověří, že je to CLI v použitelné verzi.
     *
     * ⚠️ Existence souboru se ZÁMĚRNĚ nebere jako podmínka. Pod `open_basedir`
     * (typicky ISPConfig) `is_file('/usr/bin/php8.5')` vrátí false jen proto,
     * že cesta leží mimo povolený strom — a přitom tu binárku umíme spustit.
     * Dokud se kandidáti takhle zahazovali, propadlo hledání až na holé `php`.
     */
    private static function isUsableCli(string $candidate): bool
    {
        $version = self::probe($candidate);

        return $version !== null && version_compare($version, self::MIN_VERSION, '>=');
    }

    /**
     * @return string|null verze, nebo null když kandidát není spustitelné CLI php
     */
    private static function probe(string $candidate): ?string
    {
        if (!function_exists('exec')) {
            // Bez spouštění procesů stejně žádný worker nepoběží; ať se aspoň
            // nechováme, jako by kandidát byl ověřený.
            return null;
        }

        // ⚠️ Sonda nesmí obsahovat uvozovky ani `|`. Na Windows `escapeshellarg()`
        // obaluje dvojitými uvozovkami a vnitřní `"` zahazuje, takže se kód
        // rozpadl a `cmd` si `|` vyložil jako rouru — každý kandidát pak
        // vypadal nespustitelně a nenašlo se vůbec nic. Dvě řádky výstupu
        // žádný oddělovač nepotřebují.
        $cmd = escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI, PHP_EOL, PHP_VERSION;')
            . ' 2>' . self::nullDevice();
        $out = [];
        $rc  = 1;
        @exec($cmd, $out, $rc);
        if ($rc !== 0 || count($out) < 2) {
            return null;
        }

        return trim($out[0]) === 'cli' ? trim($out[1]) : null;
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /**
     * Je `$name` (basename binárky) ne-CLI SAPI, kterou nelze použít pro spuštění
     * CLI workeru? Pokrývá php-cgi / php-win / phpdbg a — klíčové pod php-fpm —
     * `php-fpm` (tam je `PHP_BINARY` právě tato binárka).
     */
    public static function isNonCliSapiName(string $name): bool
    {
        $name = strtolower($name);

        return str_starts_with($name, 'php-cgi')
            || str_starts_with($name, 'php-win')
            || str_starts_with($name, 'phpdbg')
            || str_starts_with($name, 'php-fpm');
    }
}

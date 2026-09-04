<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Řetězec v SQL musí mít vždy určenou collation — jinak si ji vezme z prostředí.
 *
 * PROČ TO EXISTUJE
 * ---------------------------------------------------------------------------
 * V provozu spadl export mzdových plateb na
 * „Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT)
 *  and (utf8mb4_general_ci,IMPLICIT) for operation '<>'".
 * Tabulky mají sloupce v `utf8mb4_unicode_ci`, ale `DECLARE x VARCHAR(16)`
 * v těle triggeru žádnou collation neurčuje a zdědí VÝCHOZÍ COLLATION DATABÁZE.
 * Databáze založená bez výslovné collation ji od MariaDB dostane
 * `utf8mb4_general_ci` — a první porovnání proměnné se sloupcem skončí chybou
 * 1267. Celá tabulka je pak na takové instalaci nezapisovatelná.
 *
 * Zrádné na tom je, že se to NEPROJEVÍ TAM, KDE SE VYVÍJÍ: vývojová i testovací
 * databáze `unicode_ci` má, takže projdou všechny testy i celá CI. Chyba čeká
 * jen na instalaci, kterou někdo založil bez `COLLATE`. Proto je brána statická
 * nad SOUBORY migrací — na databázi, kde běží, by nic nenašla.
 *
 * Ani `SET collation_connection`, ani `ALTER DATABASE` s tím nehnou: collation
 * proměnné se zapeče do uložené definice v okamžiku, kdy trigger vznikne.
 * Jediná spolehlivá cesta je napsat ji rovnou k deklaraci.
 */
final class MigrationDeclaredCollationTest extends TestCase
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/db/migrations/*.sql');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'Nenašly se žádné migrace — brána by tiše prošla.');

        return array_values($files);
    }

    public function testEveryStringVariableDeclaresItsCollation(): void
    {
        $offenders = [];
        foreach (self::migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all(
                '/DECLARE\s+\w+\s+(?:CHAR|VARCHAR|TEXT|ENUM)\b[^;]*;/i',
                $sql,
                $matches,
            );
            foreach ($matches[0] as $declaration) {
                if (stripos($declaration, 'COLLATE') === false) {
                    $offenders[] = basename($file) . ': ' . trim($declaration);
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Řetězcová proměnná bez COLLATE zdědí collation databáze a porovnání\n"
                . "se sloupcem pak na instalaci s jinou výchozí collation spadne na\n"
                . "chybu 1267. Doplň „COLLATE %s\" za typ:\n%s",
            self::COLLATION,
            implode("\n", $offenders),
        ));
    }

    /**
     * Totéž o patro výš: tabulka bez výslovné collation ji vezme z databáze,
     * takže na jedné instalaci vznikne `unicode_ci` a na druhé `general_ci` —
     * a JOIN mezi ní a tabulkou, která collation určenou má, spadne stejně.
     */
    public function testEveryTableDeclaresItsCollation(): void
    {
        $offenders = [];
        foreach (self::migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(.*?\)\s*ENGINE\b[^;]*;/is',
                $sql,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $tail = substr($match[0], (int) strripos($match[0], 'ENGINE'));
                if (stripos($tail, 'COLLATE') === false) {
                    $offenders[] = basename($file) . ': ' . $match[1];
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Tabulka bez výslovné collation. Doplň „COLLATE=%s\" za CHARSET:\n%s",
            self::COLLATION,
            implode("\n", $offenders),
        ));
    }
}

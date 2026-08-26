<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Datové migrace nad tabulkami se strážcem `row_version` musí verzi navyšovat.
 *
 * Issue #40: migrace 1539 měnila `payroll_employer_policies` prostým UPDATE bez
 * `row_version = row_version + 1`. Trigger z migrace 1276 takový zápis odmítá
 * (SQLSTATE 45000), takže migrace spadla na každé instalaci, která měla aspoň
 * jednu politiku se zapnutým schvalováním čtyřma očima — a protože migrátor
 * neúspěšnou migraci nezapíše, zastavilo to i všechny následující.
 *
 * Proč to neodhalily testy ani CI: `payroll_employer_policies` je v testovací
 * databázi PRÁZDNÁ, takže UPDATE nepotkal žádný řádek a trigger se nespustil.
 * Selhání tedy závisí na DATECH, ne na schématu — a tím je pro běžný běh testů
 * neviditelné. Tenhle guard proto nečte databázi, ale samotné SQL migrací: hlídá
 * TVAR zápisu, který se rozbít nemůže podle toho, co je zrovna v tabulkách.
 */
final class MigrationRowVersionGuardTest extends TestCase
{
    public function testDataMigrationsBumpRowVersionOnGuardedTables(): void
    {
        $dir = \dirname(__DIR__, 3) . '/db/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        self::assertNotEmpty($files, 'Adresář migrací je prázdný — guard by neměřil nic.');

        $guarded = self::guardedTables($files);
        self::assertNotEmpty($guarded, 'Nenašel jsem žádný row_version trigger — guard by neměřil nic.');

        $offenders = [];
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            // Těla triggerů ven: jejich vlastní UPDATE běží pod jinými pravidly
            // a `NEW.row_version` v podmínce není zápis.
            $body = (string) preg_replace('/CREATE\s+TRIGGER.*?END\s*(?:\/\/|;)/is', '', $sql);

            foreach (self::updateStatements($body) as [$table, $statement]) {
                if (!isset($guarded[strtolower($table)])) {
                    continue;
                }
                if (preg_match('/row_version\s*=\s*row_version\s*\+/i', $statement) === 1) {
                    continue;
                }
                $offenders[] = basename($file) . ' → UPDATE ' . $table;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Migrace mění tabulku se strážcem `row_version`, ale verzi nenavyšuje — trigger takový\n"
            . "zápis odmítne a migrace spadne (issue #40). Doplň `row_version = row_version + 1`:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * Tabulky, nad kterými existuje trigger vyžadující růst `row_version`.
     *
     * Záměrně se NEPARSUJE tělo triggeru: končí `END//` a uvnitř má vnořené `END IF;`,
     * takže non-greedy match se láme a guard by tiše neměřil nic. Stačí hrubší pravidlo —
     * soubor, který nad tabulkou zakládá BEFORE UPDATE trigger a zároveň někde nese
     * hlášku o růstu `row_version`, tu tabulku strážcem opatřuje. Přestřelit směrem
     * k přísnosti je tu bezpečné: falešný nález se opraví doplněním `row_version + 1`,
     * což je stejně vždycky správně.
     *
     * @param  list<string> $files
     * @return array<string,true>
     */
    private static function guardedTables(array $files): array
    {
        $guarded = [];
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            if (preg_match('/row[_ ]version\s+must\s+(increase|advance)/i', $sql) !== 1) {
                continue;
            }
            if (preg_match_all('/BEFORE\s+UPDATE\s+ON\s+`?(\w+)`?/i', $sql, $m) !== false) {
                foreach ($m[1] as $table) {
                    $guarded[strtolower($table)] = true;
                }
            }
        }

        return $guarded;
    }

    /**
     * @return list<array{0:string,1:string}>  [tabulka, celý statement]
     */
    private static function updateStatements(string $sql): array
    {
        $found = [];
        if (preg_match_all('/\bUPDATE\s+`?(\w+)`?\b(.*?);/is', $sql, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $m) {
                $found[] = [$m[1], $m[0]];
            }
        }

        return $found;
    }
}

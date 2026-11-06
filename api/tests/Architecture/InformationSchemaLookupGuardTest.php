<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Dotaz do `information_schema.KEY_COLUMN_USAGE` nebo `TABLE_CONSTRAINTS` musí omezit
 * schéma přes `TABLE_SCHEMA`.
 *
 * MariaDB podle `TABLE_SCHEMA` (a `TABLE_NAME`) pozná, které definice tabulek má
 * otevřít. Podmínku na `CONSTRAINT_SCHEMA` vyhodnotí až nad hotovými řádky, takže
 * nejdřív projde tabulky VŠECH databází na serveru. Výsledek je tentýž (omezení patří
 * schématu své tabulky), čas ne: na serveru s 48 databázemi trval jeden takový dotaz
 * při odebrání ukázkových dat 4–5 s, s `TABLE_SCHEMA` 0,2 s. Na sdíleném hostingu
 * s desítkami instancí je poměr ještě horší.
 */
final class InformationSchemaLookupGuardTest extends TestCase
{
    private const VIEWS = '/information_schema\s*\.\s*(KEY_COLUMN_USAGE|TABLE_CONSTRAINTS)\b/i';

    public function testConstraintViewsAreFilteredByTableSchema(): void
    {
        $offenders = [];
        foreach (['src', 'bin'] as $directory) {
            foreach (SourceCorpus::sources(dirname(__DIR__, 2) . '/' . $directory) as $rel => $code) {
                foreach (token_get_all($code) as $token) {
                    if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        continue;
                    }
                    if (preg_match(self::VIEWS, $token[1]) === 1 && stripos($token[1], 'TABLE_SCHEMA') === false) {
                        $offenders[] = $directory . '/' . $rel . ' (řádek ' . $token[2] . ')';
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Dotaz do information_schema.KEY_COLUMN_USAGE / TABLE_CONSTRAINTS bez podmínky na TABLE_SCHEMA\n"
                . "prochází definice tabulek všech databází na serveru. Omez ho `TABLE_SCHEMA = DATABASE()`.\n  "
                . implode("\n  ", $offenders),
        );
    }
}

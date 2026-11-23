<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Report\DphPriznaniBuilder;
use PHPUnit\Framework\TestCase;

/**
 * `DphPriznaniBuilder::USER_SELECTABLE_LINES` (whitelist pro validaci klasifikací DPH)
 * se nesmí rozejít s `$lineMap` uvnitř `build()`.
 *
 * Mapa je lokální proměnná, takže se čte ze zdrojáku — guard je statický a při
 * reformátování degraduje, ale drift whitelistu je jinak neviditelný až do chvíle,
 * kdy uživateli zmizí částka z přiznání.
 *
 * Hlídá dvě věci:
 *   1. každý povolený řádek MUSÍ v mapě být (jinak by validace pustila hodnotu,
 *      kterou generátor stejně zahodí),
 *   2. tři druhy klíčů z mapy MUSÍ zůstat zakázané:
 *      - '34' — jeho `base` slot nese DAŇ (plní ho interní injekce § 74b);
 *        uživatelem nastavená '34' by tam poslala základ → tiše chybná hodnota v XML,
 *      - '33' — totéž na věřitelské straně (§ 46, injekce DphPriznaniBuilder::correctionEntries),
 *      - '40k'/'41k'/'42k' — krácený odpočet § 76, klíče si tvoří VatLedgerService sám.
 */
final class DphLineWhitelistGuardTest extends TestCase
{
    /** Klíče, které v $lineMap jsou, ale uživatel je nastavovat NESMÍ. */
    private const FORBIDDEN_FOR_USER = ['33', '34', '40k', '41k', '42k', '43k', '44k'];

    public function testWhitelistMatchesLineMap(): void
    {
        $mapKeys = $this->lineMapKeys();
        self::assertNotSame([], $mapKeys, 'Nepodařilo se vyčíst $lineMap ze zdrojáku — guard by mlčel.');

        $notInMap = array_values(array_diff(DphPriznaniBuilder::USER_SELECTABLE_LINES, $mapKeys));
        self::assertSame([], $notInMap, sprintf(
            'Whitelist povoluje řádky, které $lineMap nezná (generátor je zahodí): %s',
            implode(', ', $notInMap),
        ));

        $leaked = array_values(array_intersect(DphPriznaniBuilder::USER_SELECTABLE_LINES, self::FORBIDDEN_FOR_USER));
        self::assertSame([], $leaked, sprintf(
            'Whitelist pustil interní klíče, které uživatel nastavovat nesmí: %s',
            implode(', ', $leaked),
        ));

        // Opačný směr je jen informativní: mapa smí mít klíče navíc (interní), ale nový
        // uživatelsky smysluplný řádek se má do whitelistu doplnit vědomě.
        $unlisted = array_values(array_diff($mapKeys, DphPriznaniBuilder::USER_SELECTABLE_LINES, self::FORBIDDEN_FOR_USER));
        self::assertSame([], $unlisted, sprintf(
            "V \$lineMap je řádek mimo whitelist i mimo seznam interních: %s\n"
                . 'Doplň ho do USER_SELECTABLE_LINES, nebo (je-li interní) do FORBIDDEN_FOR_USER.',
            implode(', ', $unlisted),
        ));
    }

    /**
     * @return list<string>
     */
    private function lineMapKeys(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Report/DphPriznaniBuilder.php');
        $start = strpos($src, '$lineMap = [');
        if ($start === false) {
            return [];
        }
        $end = strpos($src, "\n        ];", $start);
        $block = substr($src, $start, $end === false ? 4000 : $end - $start);

        preg_match_all("/'([0-9]+[a-z]?)'\s*=>\s*\['veta'/", $block, $m);
        return array_values(array_unique($m[1]));
    }
}

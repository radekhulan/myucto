<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Co uložení faktury z editoru NEPOŠLE, to v databázi po uložení není.
 *
 * `InvoiceRepository::replaceItems()` je DELETE + INSERT: staré řádky se smažou a založí
 * znovu z payloadu. Každý OSS sloupec, který ten INSERT zapisuje, tedy MUSÍ být v mapě
 * položky v `InvoiceEditor.vue` — jinak ho první uložení dokladu z UI tiše vynuluje.
 *
 * Naměřeno to bylo na `oss_needs_manual_review` (migrace 1293): backendový round-trip byl
 * hotový, sloupec se zapisoval i četl, a příznak se přesto po prvním doteku faktury
 * ztrácel, protože ho payload neobsahoval. U migrace 1 670 dokladů tím mizela celá
 * kategorie „nedokázali jsme určit místo plnění", a to v okamžiku, kdy je report importu
 * dávno zavřený — po ztrátě nezbyla stopa ani v datech, ani v reportu.
 *
 * Guard proto nečte seznam sloupců z vlastní konstanty (ta by zestárla přesně tak jako
 * payload), ale ze SSOT — z `replaceItems()` samotného. Nový OSS sloupec tak rozsvítí
 * tenhle test dřív, než se stihne ztratit uživateli.
 *
 * Sesterský běhový důkaz je
 * {@see \MyInvoice\Tests\Integration\Invoice\OssManualReviewEditorApiTest}: ten ukazuje,
 * že payload bez pole příznak skutečně zhasne a že si ho backend nemá odkud domyslet.
 */
#[Group('architecture')]
final class InvoiceEditorOssPayloadContractTest extends TestCase
{
    private const REPOSITORY = '/api/src/Repository/InvoiceRepository.php';
    private const EDITOR     = '/web/src/pages/invoices/InvoiceEditor.vue';

    /**
     * Sloupce se čtou z `replaceItems()`, ne z konstanty v testu — dvě kopie seznamu by
     * se rozešly a guard by hlídal minulost.
     */
    public function testEveryOssColumnWrittenByReplaceItemsIsSentBackByTheEditor(): void
    {
        $columns = self::ossColumnsWrittenByReplaceItems(self::read(self::REPOSITORY));
        // Kdyby se slicování rozbilo, guard by nad prázdným seznamem prošel vždycky.
        self::assertGreaterThanOrEqual(
            10,
            count($columns),
            'Ze replaceItems() se nepodařilo přečíst seznam OSS sloupců — guard by pak netvrdil nic.',
        );
        self::assertContains('oss_needs_manual_review', $columns,
            'Příznak k ručnímu posouzení zmizel ze zápisu položek (migrace 1293).');

        $payload = self::editorItemPayloadRegion(self::read(self::EDITOR));
        self::assertStringContainsString('oss_applicable', $payload,
            'V payloadu editoru nejsou OSS pole vůbec — pravděpodobně se rozbilo vyříznutí mapy položky.');

        foreach ($columns as $column) {
            self::assertStringContainsString(
                $column . ':',
                $payload,
                sprintf(
                    'Mapa položky v InvoiceEditor.vue neposílá „%s". replaceItems() je DELETE + INSERT, '
                        . 'takže první uložení faktury z UI tenhle sloupec vynuluje.',
                    $column,
                ),
            );
        }
    }

    /**
     * OSS sloupce, které `replaceItems()` skutečně zapisuje. Čte se úsek od sestavení
     * `$ossColumns` po `prepare()`, aby se do seznamu nedostaly názvy z jiných dotazů
     * (SELECT v `find()` je má taky).
     *
     * @return list<string>
     */
    private static function ossColumnsWrittenByReplaceItems(string $php): array
    {
        $start = strpos($php, '$ossColumns = $supportsOss');
        if ($start === false) {
            return [];
        }
        $end = strpos($php, '$stmt = $pdo->prepare(', $start);
        if ($end === false) {
            return [];
        }

        preg_match_all("/'(oss_[a-z_]+)'/", substr($php, $start, $end - $start), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Mapa položky z `submit()` — od `items: form.value.items.map(` po uzavření téhož
     * volání (počítají se závorky, ne řádky). Komentáře se odstraní PŘED hledáním:
     * guard, kterému stačí výskyt řetězce v komentáři, nekontroluje nic.
     */
    private static function editorItemPayloadRegion(string $vue): string
    {
        $vue = self::stripLineComments($vue);
        // Payload skládá funkce nad předaným seznamem položek (uložení i náhled zápisu).
        $anchor = strpos($vue, 'items: items.map(');
        if ($anchor === false) {
            $anchor = strpos($vue, 'items: form.value.items.map(');
        }
        if ($anchor === false) {
            return '';
        }
        $open = strpos($vue, '(', $anchor);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($vue);
        for ($i = $open; $i < $length; $i++) {
            if ($vue[$i] === '(') {
                $depth++;
            } elseif ($vue[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($vue, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    /** Řádkové komentáře pryč; uvozovka před `//` znamená, že jde o obsah řetězce (URL). */
    private static function stripLineComments(string $source): string
    {
        $out = [];
        foreach (explode("\n", $source) as $line) {
            $pos = strpos($line, '//');
            if ($pos !== false && !preg_match('/[\'"`]/', substr($line, 0, $pos))) {
                $line = substr($line, 0, $pos);
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}

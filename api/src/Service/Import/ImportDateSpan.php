<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Oss\OssItemDeriver;

/**
 * Rozsah dat, do kterých importovaná dávka spadá — od nejstaršího po nejnovější doklad.
 *
 * Existuje kvůli účetním obdobím. Import sám do deníku NEÚČTUJE (celý namespace
 * `Service\Import` se posting vrstvy nedotýká), doklady vznikají nezaúčtované a na
 * chybějící období narazí uživatel až ve chvíli, kdy u dokladu klikne „Zaúčtovat" —
 * tedy dávno po importu, u jednoho dokladu z tisíce a bez souvislosti s tím, co dělal.
 * Migrace historických dat z jiného systému přitom typicky sahá roky před datum, od
 * kterého má firma v MyÚčto aktivované účetnictví. Rozsah dávky je jediný levný způsob,
 * jak se na to zeptat JEDNOU za běh místo u každého dokladu zvlášť.
 *
 * Rozhoduje EFEKTIVNÍ datum plnění — DUZP s fallbackem na datum vystavení, shodně
 * s {@see InvoiceImportService::processOne()}. Jiná hodnota by se ptala na jiné období.
 *
 * SMĚR DOKLADU SE ZÁMĚRNĚ NEROZLIŠUJE. Vydaná i přijatá faktura se jednou budou účtovat
 * a obě potřebují otevřené období; zúžení na jednu větev by u dávky přijatých faktur
 * tichou vadu jen přesunulo jinam. Z téhož důvodu se nepočítá jen s doklady, které
 * projdou — doklad odmítnutý až při zápisu se po opravě zdroje doimportuje do TÉHOŽ
 * období, takže ho má rozsah pokrýt taky.
 */
final class ImportDateSpan
{
    /**
     * @param  list<array<string,mixed>> $parsed výstup {@see InvoiceImportService::parseRaw()}
     *                                          za celý balík (položky s `error` se přeskočí)
     * @return array{0:string,1:string}|null    ['Y-m-d' nejstarší, 'Y-m-d' nejnovější],
     *                                          nebo `null`, když v dávce není jediné čitelné datum
     */
    public static function of(array $parsed): ?array
    {
        $min = null;
        $max = null;

        foreach ($parsed as $entry) {
            if (!is_array($entry) || isset($entry['error'])) {
                continue;
            }
            foreach ($entry['invoices'] ?? [] as $inv) {
                if (!is_array($inv) || isset($inv['__error'])) {
                    continue;
                }
                $date = OssItemDeriver::canonicalDate(
                    ($inv['tax_date'] ?? null) ?: ($inv['issue_date'] ?? null)
                );
                if ($date === null) {
                    continue;
                }
                // Kanonický tvar 'Y-m-d' se porovnává jako řetězec — u pevné šířky
                // s vodicími nulami je to totéž pořadí jako u dat.
                if ($min === null || $date < $min) {
                    $min = $date;
                }
                if ($max === null || $date > $max) {
                    $max = $date;
                }
            }
        }

        return $min !== null && $max !== null ? [$min, $max] : null;
    }
}

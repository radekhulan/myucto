<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

/**
 * Předpona dotazu, která vypne cache závislých poddotazů (`subquery_cache`).
 *
 * Strážci použití zápisu (SVJ, NNO, alokace) skládají desítky `EXISTS` nad řádky
 * jednoho dokladu. MariaDB pro každý závislý poddotaz zakládá dočasnou tabulku cache,
 * jejíž založení roste s `tmp_memory_table_size` (při 256 MB kolem 8 ms na poddotaz).
 * Pro pár řádků cache nic neušetří, takže by přeúčtování dokladu zpomalila i u firem,
 * které tyto agendy nemají. Výsledek dotazu předpona nemění.
 */
final class SubqueryCache
{
    public const OFF = "SET STATEMENT optimizer_switch='subquery_cache=off' FOR ";
}

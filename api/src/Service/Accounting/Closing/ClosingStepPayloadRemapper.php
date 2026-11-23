<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use UnexpectedValueException;

/**
 * Přemapuje ID dokladů, zápisů a období v záznamu kroku uzávěrky (`accounting_closing_steps.payload`)
 * při převodu firmy z jiné instalace MyÚčta ({@see \MyInvoice\Service\Migration\Myucto\MyuctoImporter}).
 * Záznam musí po přenosu odkazovat na data nové firmy; staré id by mířilo na cizí nebo
 * neexistující řádek.
 *
 * - Dohadné položky a Časové rozlišení: asistované zápisy `entries` a jejich storna `reversed`
 *   (storno asistovaného zápisu je hledá podle id zápisu).
 * - Uzavření knih a Otevření dalšího období: zápis kroku, převzaté zápisy `taken_over_entry_ids`
 *   (revert a znovuotevření podle nich poznají zápis, který krok jen převzal) a další období;
 *   u Uzavření knih i doklady a uživatel výjimky pro nezaúčtované doklady `unposted_override`
 *   (stejně jako auditní záznam téže události). Zápisy zrušení kurzového přecenění a rozpuštění
 *   (`*_release_entry_id`, `fx_reversal_entry_id`) převod nepřijímá, proto se nepřemapují.
 * - Odkaz na řádek, který v přenášených datech není (asistovaný zápis smazaný po přerušené
 *   uzávěrce), se nepřenese a vrátí se varování: asistovaný zápis bez zápisu se z `entries`
 *   vynechá, id ze seznamu vypadne, ostatní odkazy zůstanou s `null`.
 * - Rezervy a opravné položky: jen `entries` (pohledávka a zápis) a chybějící odkaz přenos
 *   zastaví. Záznamy § 504 nesou v otisku rozhodnutí i id zdrojového zápisu
 *   (`legal_decision.source_entry_id`), takže `reversed` a rozpracované operace se tu záměrně
 *   nepřemapují; přemapovaný jen jeden z dvojice id by párování rezerv rozbil. Ze stejného
 *   důvodu zůstává beze změny důkaz přenosu § 504 (`carry`), který váže verzi zápisu,
 *   otisk podkladu a id řádků, i rozhodnutí o rozdělení výsledku (`result_decision`). Takový
 *   důkaz po převodu neprokáže shodu se zápisem nové firmy, stejně jako bez přemapování.
 * - Rozpad odkladů Časového rozlišení (`*_accrual.items` s řádky faktur i ostatních pohledávek
 *   a závazků, `item_costs`) se čte jen u období se zápisem odkladu a převod takové zápisy
 *   nepřijímá, proto zůstává beze změny (drží to MyuctoImportTest::testClosingDeferralEntryIsRejectedBeforeAnyWrite).
 * - Ostatní kroky se nemění.
 */
final class ClosingStepPayloadRemapper
{
    /** Kroky, jejichž záznam nese id dokladů a zápisů firmy; ostatní záznamy se přenášejí beze změny. */
    public const STEPS = ['estimates', 'deferrals', 'provisions', 'close_books', 'open_next'];

    private const ASSISTED_REFERENCES = ['entry_id' => 'journal_entries', 'reversal_entry_id' => 'journal_entries'];

    /** Odkazy v kořeni záznamu uzávěrkových kroků. */
    private const BOOKS_REFERENCES = [
        'close_books' => ['entry_id' => 'journal_entries'],
        'open_next' => ['entry_id' => 'journal_entries', 'next_period_id' => 'accounting_periods'],
    ];

    /** Výjimka uzavření knih pro nezaúčtované doklady: seznamy dokladů a uživatel. */
    private const UNPOSTED_OVERRIDE_LISTS = ['invoice_ids' => 'invoices', 'purchase_ids' => 'purchase_invoices'];

    /**
     * @param callable(string, mixed): ?int $resolve nové id řádku tabulky (i `users`), null = řádek v přenosu není
     * @return array{0: mixed, 1: list<string>} přemapovaný záznam a varování o nepřenesených odkazech
     * @throws UnexpectedValueException chybí pohledávka nebo zápis opravné položky
     */
    public static function remap(string $stepKey, mixed $payload, callable $resolve): array
    {
        if (!is_array($payload)) {
            return [$payload, []];
        }
        $warnings = [];
        $lookup = static function (string $path, string $table, mixed $old) use ($stepKey, $resolve, &$warnings): ?int {
            $new = $resolve($table, $old);
            if ($new === null) {
                $warnings[] = "Záznam kroku uzávěrky {$stepKey}.{$path}: {$table} #{$old} v přenášených datech není, odkaz se nepřenesl.";
            }
            return $new;
        };
        switch ($stepKey) {
            case 'provisions':
                foreach (is_array($payload['entries'] ?? null) ? $payload['entries'] : [] as $index => $entry) {
                    foreach (['invoice_id' => 'invoices', 'entry_id' => 'journal_entries'] as $key => $table) {
                        if (is_array($entry) && !empty($entry[$key])) {
                            $payload['entries'][$index][$key] = $resolve($table, $entry[$key])
                                ?? throw new UnexpectedValueException('Chybí mapování opravné položky uzávěrky (' . $table . ' #' . $entry[$key] . ').');
                        }
                    }
                }
                break;
            case 'estimates':
            case 'deferrals':
                $payload = self::assisted($payload, $lookup);
                break;
            case 'close_books':
            case 'open_next':
                $payload = self::books($payload, self::BOOKS_REFERENCES[$stepKey], $lookup);
                if ($stepKey === 'close_books' && is_array($payload['unposted_override'] ?? null)) {
                    $override = $payload['unposted_override'];
                    foreach (self::UNPOSTED_OVERRIDE_LISTS as $key => $table) {
                        if (is_array($override[$key] ?? null)) {
                            $override[$key] = self::idList($override[$key], 'unposted_override.' . $key, $table, $lookup);
                        }
                    }
                    if (!empty($override['overridden_by'])) {
                        $override['overridden_by'] = $lookup('unposted_override.overridden_by', 'users', $override['overridden_by']);
                    }
                    $payload['unposted_override'] = $override;
                }
                break;
        }
        return [$payload, $warnings];
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(string, string, mixed): ?int $lookup
     * @return array<string,mixed>
     */
    private static function assisted(array $payload, callable $lookup): array
    {
        foreach (['entries', 'reversed'] as $list) {
            if (!is_array($payload[$list] ?? null)) {
                continue;
            }
            $kept = [];
            foreach ($payload[$list] as $record) {
                if (is_array($record)) {
                    foreach (self::ASSISTED_REFERENCES as $key => $table) {
                        if (!empty($record[$key])) {
                            $record[$key] = $lookup($list . '.' . $key, $table, $record[$key]);
                            // Asistovaný zápis, který ve firmě není, nemá co stornovat ani zobrazit.
                            if ($record[$key] === null && $list === 'entries' && $key === 'entry_id') {
                                continue 2;
                            }
                        }
                    }
                }
                $kept[] = $record;
            }
            $payload[$list] = $kept;
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $references
     * @param callable(string, string, mixed): ?int $lookup
     * @return array<string,mixed>
     */
    private static function books(array $payload, array $references, callable $lookup): array
    {
        foreach ($references as $key => $table) {
            if (!empty($payload[$key])) {
                $payload[$key] = $lookup($key, $table, $payload[$key]);
            }
        }
        if (is_array($payload['taken_over_entry_ids'] ?? null)) {
            $payload['taken_over_entry_ids'] = self::idList($payload['taken_over_entry_ids'], 'taken_over_entry_ids', 'journal_entries', $lookup);
        }
        return $payload;
    }

    /**
     * @param array<mixed> $ids
     * @param callable(string, string, mixed): ?int $lookup
     * @return list<int> nová id; id, které v přenosu není, vypadne
     */
    private static function idList(array $ids, string $path, string $table, callable $lookup): array
    {
        $kept = [];
        foreach ($ids as $old) {
            $new = $lookup($path, $table, $old);
            if ($new !== null) {
                $kept[] = $new;
            }
        }
        return $kept;
    }
}

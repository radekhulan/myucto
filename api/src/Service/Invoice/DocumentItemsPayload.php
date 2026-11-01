<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Jak se v těle requestu čte klíč `items` — jeden zdroj pravdy pro vydané i přijaté doklady.
 *
 * ── Co to opravuje ──────────────────────────────────────────────────────────────────
 * Obě aktualizační akce volaly `replaceItems($id, (array) ($body['items'] ?? []))`
 * NEPODMÍNĚNĚ. Částečný PUT bez klíče `items` (oprava DUZP, poznámky) tím dokladu smazal
 * VŠECHNY řádky a nechal ho na nule. Editor to nevyvolá — položky posílá vždycky — takže
 * se na to běžným používáním nepřijde a dopadá to jen na integrace a skripty.
 *
 * Pravidlo proto žije na jednom VOLATELNÉM místě. Jako `private` helper uvnitř jedné akce
 * by se okopírovalo rychleji, než kdyby neexistovalo.
 *
 * ── Pravidla ────────────────────────────────────────────────────────────────────────
 * 1. Klíč `items` v těle NENÍ → položky se nemění. PUT sice sémanticky znamená úplnou
 *    náhradu, ale tiché smazání řádků účetního dokladu je nevratná ztráta dat kvůli
 *    chybějícímu klíči; shovívavost je tu levná a integrace, které dnes data ničí,
 *    začnou fungovat správně. `items: null` se počítá jako „klíč nepřišel" — je to
 *    typický artefakt serializace částečného těla, ne pokyn k mazání.
 * 2. Explicitně poslané PRÁZDNÉ pole na dokladu, který položky MÁ, je vada požadavku,
 *    ne příkaz k vyprázdnění ({@see emptiesExisting()}). Součty dokladu se počítají
 *    výhradně z řádků ({@see InvoiceCalculator::recompute()}, {@see PurchaseInvoiceCalculator}),
 *    takže by vznikl doklad na nulu — v seznamu k nerozeznání od záměrně vystaveného
 *    a v žádném výkazu. Týž závěr si vynutil import vydaných dokladů, který doklad bez
 *    jediné položky rovnou odmítá ({@see \MyInvoice\Service\Import\InvoiceImportService}).
 * 3. Prázdné pole na dokladu, který žádné položky nemá, je no-op — nemá co zničit.
 *
 * ODLIŠNOSTI, které jsou ZÁMĚR:
 *  - ZALOŽENÍ dokladu (POST) tenhle guard nemá: nemá co smazat a oba endpointy zakládají
 *    `draft`, kde je prázdný doklad pracovní stav (týž výklad, jaký import používá pro
 *    přijaté faktury). Doklad bez řádků se zastaví až tam, kde by se stal účetním faktem.
 *  - Dedikovaný `PUT /purchase-invoices/{id}/items` prázdné pole PŘIJÍMÁ: tam je `items`
 *    celý obsah požadavku, takže `[]` je jednoznačný pokyn, a endpoint jede jen nad
 *    draftem. Naopak CHYBĚJÍCÍ klíč je tam vada požadavku, ne „neměň" — viz
 *    {@see \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction}.
 */
final class DocumentItemsPayload
{
    /**
     * Týž kód, jakým vystavení odmítá doklad bez řádků
     * ({@see \MyInvoice\Action\Invoice\IssueInvoiceAction}) — je to jeden koncept
     * („doklad bez položky"), takže klient nemá mít dva kódy k obsluze.
     */
    public const EMPTY_ERROR_CODE = 'no_items';

    /**
     * Nese tělo pokyn k náhradě položek? Chybějící klíč (i hodnota `null`) znamená
     * „položky neměň".
     *
     * @param array<string,mixed> $body
     */
    public static function replaces(array $body): bool
    {
        return array_key_exists('items', $body) && $body['items'] !== null;
    }

    /**
     * Explicitně poslané prázdné pole na dokladu, který nějaké položky má → 422.
     *
     * @param array<string,mixed>        $body
     * @param array<int,mixed>           $existingItems položky uloženého dokladu
     */
    public static function emptiesExisting(array $body, array $existingItems): bool
    {
        return self::replaces($body)
            && is_array($body['items'])
            && $body['items'] === []
            && $existingItems !== [];
    }

    /** Hláška k {@see EMPTY_ERROR_CODE} — stejná na obou stranách evidence. */
    public static function emptyErrorMessage(): string
    {
        return 'Doklad by zůstal bez jediné položky. Součty se počítají z řádků, takže by z něj '
            . 'byl doklad na nulu — v seznamu k nerozeznání od záměrně vystaveného a v žádném '
            . 'výkazu. Pokud jste položky měnit nechtěli, klíč „items" v požadavku vynechte; '
            . 'pokud má doklad zaniknout, smažte ho.';
    }

    /**
     * Liší se položky OBSAHEM? Porovnává uživatelsky ZADANÁ pole (popis, množství,
     * jednotka, cena, sazba a celý OSS blok) — ne pouhou přítomnost klíče a ne dopočtené
     * sloupce, které doplňuje až server (klasifikace DPH, totály, order_index).
     *
     * Čísla se porovnávají NUMERICKY: uložený řádek přichází z DB jako float (`castItem()`),
     * kdežto tělo requestu nese cokoliv od `1` po `"1.000"` — string compare by z formátové
     * neshody udělal účetní změnu a zablokoval legitimní `force_mode=notes_only`.
     *
     * Klasifikace DPH se dopočítává, jen když v těle chybí. Poslaná je rozhodnutí o řádku
     * přiznání, takže její změna je změna položky ({@see self::classificationChanged()}).
     *
     * @param array<int,mixed> $old
     * @param array<int,mixed> $new
     */
    public static function changed(array $old, array $new): bool
    {
        return self::fingerprint($old) !== self::fingerprint($new)
            || self::classificationChanged($old, $new);
    }

    /**
     * Přesune poslaný kód řádek v přiznání a KH? Vynechaný klíč nic neříká, kód se dopočítá
     * ze zadaných polí a ta hlídá otisk. Řádky se párují pořadím, přidaný nebo ubraný řádek
     * otisk zachytí sám.
     *
     * @param array<int,mixed> $old
     * @param array<int,mixed> $new
     */
    private static function classificationChanged(array $old, array $new): bool
    {
        $old = array_values($old);
        foreach (array_values($new) as $i => $item) {
            if (!is_array($item) || InvoiceItemKind::isText($item)
                || !array_key_exists('vat_classification_code', $item)) {
                continue;
            }
            $stored = is_array($old[$i] ?? null) ? ($old[$i]['vat_classification_code'] ?? '') : '';
            if ((string) ($item['vat_classification_code'] ?? '') !== (string) $stored) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,mixed> $items
     *
     * @return list<array<int,mixed>>
     */
    private static function fingerprint(array $items): array
    {
        return array_map(
            static function (mixed $item): array {
                if (!is_array($item)) {
                    return [];
                }
                // Textový řádek se ukládá vynulovaný ({@see InvoiceItemKind::normalize()}),
                // obsahem je jen jeho text. Jednotka, sazba ani množství z editoru nic nemění.
                if (InvoiceItemKind::isText($item)) {
                    return [InvoiceItemKind::TEXT, trim((string) ($item['description'] ?? ''))];
                }
                try {
                    $duration = TimeBilling::durationMinutes($item);
                    $durationKey = $duration !== null ? ['minutes', $duration] : null;
                } catch (\InvalidArgumentException) {
                    $durationKey = ['invalid_minutes', (string) ($item['duration_minutes'] ?? '')];
                }

                return [
                    trim((string) ($item['description'] ?? '')),
                    $durationKey ?? ['quantity', round((float) ($item['quantity'] ?? 0), 6)],
                    trim((string) ($item['unit'] ?? '')),
                    round((float) ($item['unit_price_without_vat'] ?? 0), 6),
                    (int) ($item['vat_rate_id'] ?? 0),
                    ...self::ossFingerprint($item),
                    // Vybrané kusy (2016): konkrétní sériové číslo je obsah položky.
                    array_key_exists('tracking_json', $item)
                        ? \MyInvoice\Service\Stock\InvoiceItemTracking::payloadJson($item)
                        : \MyInvoice\Service\Stock\InvoiceItemTracking::storedJson($item),
                ];
            },
            array_values($items),
        );
    }

    /**
     * OSS část otisku — patří do něj celá, protože rozhoduje o MÍSTĚ PLNĚNÍ.
     *
     * Bez ní projde jako „jen poznámková" i editace, která vystavenému dokladu přepíše
     * zemi spotřeby nebo typ sazby: řádek se přesune do jiného řádku OSS podání a DPH
     * se zaúčtuje jinam (345.100, migrace 1295), takže se doklad rozejde s deníkem
     * i s hlášením. Sloupce a jejich kanonizaci drží
     * {@see \MyInvoice\Repository\InvoiceRepository::ossItemParams()} — to je zápisová
     * strana téhož páru, otisk se s ní musí shodovat.
     *
     * VYNECHÁNO ZÁMĚRNĚ — `oss_needs_manual_review` a `oss_document_contradiction`:
     * to nejsou uživatelská pole, ale SERVEREM dopočtené příznaky
     * ({@see \MyInvoice\Service\Oss\OssItemPlanner::flagContradiction()},
     * {@see \MyInvoice\Service\Oss\OssDocumentCoherence}), které se přepočítávají při
     * KAŽDÉM uložení — v otisku by z běžného průchodu plánovačem udělaly „změnu položek"
     * a notes_only by přestal fungovat. Věcně to sedí i bez toho: příznak je pracovní
     * poznámka „tohle má někdo prověřit", nemění částku, místo plnění ani zaúčtování,
     * takže jeho zhasnutí je přesně ta neúčetní editace, kterou notes_only povolovat má.
     *
     * @param array<string,mixed> $item
     *
     * @return list<mixed>
     */
    private static function ossFingerprint(array $item): array
    {
        // Zhasnutý přepínač zapíše replaceItems() jako samé NULL, ať v těle zůstalo
        // cokoliv. Otisk to musí kolabovat stejně, jinak by osiřelá země u vypnutého OSS
        // vypadala jako změna, kterou uložení stejně zahodí.
        if (empty($item['oss_applicable'])) {
            return [0, null, null, null, null, null, null, null, null];
        }

        return [
            1,
            self::textOrNull($item['oss_consumer_country'] ?? null, true),
            self::textOrNull($item['oss_rate_type'] ?? null),
            self::textOrNull($item['oss_supply_type'] ?? null),
            self::numberOrNull($item['oss_exchange_rate'] ?? null),
            self::textOrNull($item['oss_exchange_rate_date'] ?? null),
            self::numberOrNull($item['oss_taxable_amount_return'] ?? null),
            self::numberOrNull($item['oss_vat_amount_return'] ?? null),
            self::textOrNull($item['oss_original_period'] ?? null, true),
        ];
    }

    /** Prázdný řetězec je totéž co NULL — tak ho zapíše i repozitář. */
    private static function textOrNull(mixed $value, bool $upper = false): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        return $upper ? strtoupper($text) : $text;
    }

    /**
     * NULL a 0 se u ručních OSS částek nesmí slít: NULL = „dopočti z dokladu",
     * 0 = „do podání jde nula" ({@see \MyInvoice\Service\Oss\OssLedgerService}).
     */
    private static function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 6) : null;
    }
}

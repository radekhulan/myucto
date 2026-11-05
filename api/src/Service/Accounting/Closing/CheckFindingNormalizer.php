<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

/**
 * Sjednocení tvaru nálezů kontrol (`ClosingService::buildChecks()`) do jediného kontraktu.
 *
 * ── Proč to vzniklo ─────────────────────────────────────────────────────────────────
 * Kontroly vracely nálezy v devíti různých tvarech: `id` vs `doc_id`, `doc_type` vs
 * `source_type` vs `match_kind`, `partner_name` vs `partner`, `saldo` vs `residual` vs
 * `bal`. Frontend proto nemohl mít jeden renderer a byl napsaný jako WHITELIST podle klíče
 * kontroly — takže každá nová kontrola v `buildChecks()` automaticky spadla do
 * `JSON.stringify`. Z 33 kontrol jich takhle končilo 16 na stránce měsíční kontroly
 * a 29 na uzávěrkové. Nešlo o přehlédnutý případ, ale o konstrukční vadu: renderer
 * podle klíče nemůže být úplný, protože klíčů přibývá.
 *
 * ── Druhý problém: velikost ─────────────────────────────────────────────────────────
 * Žádná kontrola neměla strop. U firmy s 326 fakturami měl payload kroku `precheck`
 * 20 kB; u velké firmy vychází 0,7–2 MB (dominuje `payment_match_audit` ~447 B na
 * položku). A ten payload se posílá při KAŽDÉM načtení uzávěrkové stránky. Proto se
 * seznam ořezává na {@see CAP} a nese příznak `truncated`.
 *
 * Ořez se dělá až TADY, nad hotovým polem — ne LIMITem v SQL. Díky tomu zůstává `count`
 * skutečným počtem nálezů. Kdyby se limitovalo v dotazu, muselo by se počítat odděleným
 * `COUNT(*)`, jinak by se z „1 843 nálezů" stalo „50 nálezů" a účetní by viděla lež.
 * Úspora paměti na serveru je tím menší, zato je vyloučená celá třída chyb; LIMIT v SQL
 * je pak výkonová optimalizace navíc, ne podmínka správnosti.
 *
 * ── Kontrakt ────────────────────────────────────────────────────────────────────────
 * Nález dokladu: `{doc_type, doc_id, doc_no, partner_name, amount, note}`
 * Nález účtu:    `{account_id, account_code, name, amount}`
 * Skalární hodnoty (zůstatky, rozdíly, počty) zůstávají beze změny.
 */
final class CheckFindingNormalizer
{
    /** Kolik nálezů se posílá na klienta. Zbytek je za odkazem „zobrazit vše" / CSV. */
    public const CAP = 50;

    /** Kolik nálezů se ukládá do payloadu kroku — ten je auditní snímek, ne datový sklad. */
    public const SNAPSHOT_CAP = 10;

    /**
     * Strop pro detail JEDNÉ kontroly načtený na vyžádání.
     *
     * Snímek kroku nese jen {@see SNAPSHOT_CAP} nálezů, takže detail postavený z něj
     * ukazoval „21 nálezů" a v tabulce 10 řádků. Uživatel ale otevírá detail právě proto,
     * aby nálezy VIDĚL — useknutý seznam z něj dělá zavádějící sestavu. Detail se proto
     * načítá živě a strop je řádově vyšší: platí pro jednu kontrolu, ne pro všech 33
     * najednou, takže i 500 řádků je zlomek toho, co dřív chodilo v každém načtení stránky.
     * Nad stropem zůstává příznak `truncated` a odkaz na CSV — mlčky useknout se nesmí.
     */
    public const DETAIL_CAP = 500;

    /**
     * Typ dokladu pro kontroly, kde je jednotný pro celý seznam. U ostatních ho nese
     * sama položka (`doc_type` / `source_type` / `match_kind`).
     *
     * @var array<string,string>
     */
    private const STATIC_DOC_TYPE = [
        'unposted_invoices'         => 'invoice',
        'unposted_purchases'        => 'purchase_invoice',
        'paid_invoices_open_saldo'  => 'invoice',
        'paid_purchases_open_saldo' => 'purchase_invoice',
        'paid_proformas_no_advance' => 'invoice',
        'paid_advances_no_payment'  => 'purchase_invoice',
        'drafts_in_period'          => 'journal_entry',
        'vat_clearing_stale'        => 'journal_entry',
    ];

    /** Klíče, pod kterými bývá v položce částka. Pořadí = priorita. */
    private const AMOUNT_KEYS = ['amount', 'saldo', 'residual', 'bal', 'impact_czk', 'balance', 'booked', 'unreleased'];

    /** Klíče, pod kterými bývá v položce protistrana. */
    private const PARTNER_KEYS = ['partner_name', 'partner', 'counterparty_name', 'vendor_name', 'client_name'];

    /** Klíče, pod kterými bývá číslo dokladu. */
    private const DOC_NO_KEYS = ['doc_no', 'doc_number', 'varsymbol', 'vendor_invoice_number', 'number'];

    /** Klíče, pod kterými bývá datum dokladu / transakce. Pořadí = priorita. */
    private const DATE_KEYS = ['doc_date', 'issue_date', 'tax_date', 'tx_posted_at', 'posted_at', 'entry_date', 'date', 'due_date'];

    /**
     * Normalizuje celý seznam kontrol. `$cap` = 0 znamená bez ořezu (CSV export).
     *
     * @param list<array<string,mixed>> $checks
     * @return list<array<string,mixed>>
     */
    public function normalizeAll(array $checks, int $cap = self::CAP): array
    {
        return array_map(fn (array $c): array => $this->normalize($c, $cap), $checks);
    }

    /**
     * Přeřízne UŽ NORMALIZOVANÉ kontroly na menší strop. Používá se pro auditní snímek
     * do payloadu kroku, aby se `buildChecks()` nemusel pouštět dvakrát — dotazy jsou
     * drahé a druhý běh by nepřinesl nic nového.
     *
     * @param list<array<string,mixed>> $checks
     * @return list<array<string,mixed>>
     */
    public function recap(array $checks, int $cap): array
    {
        return array_map(static function (array $c) use ($cap): array {
            if (!is_array($c['value'] ?? null) || !isset($c['value']['findings'])) {
                return $c;
            }
            $total = (int) ($c['value']['count'] ?? count($c['value']['findings']));
            $c['value']['findings'] = array_slice($c['value']['findings'], 0, $cap);
            // `count` zůstává PLNÝ počet — snímek smí být kratší, ale nesmí lhát o rozsahu.
            $c['value']['count'] = $total;
            $c['value']['truncated'] = $total > $cap;

            return $c;
        }, $checks);
    }

    /**
     * @param array<string,mixed> $check
     * @return array<string,mixed>
     */
    public function normalize(array $check, int $cap = self::CAP): array
    {
        $key = (string) ($check['key'] ?? '');
        $value = $check['value'] ?? null;
        if (!is_array($value)) {
            return $check;
        }

        [$raw, $kind] = self::extractList($value);
        if ($raw === null) {
            // Kontrola bez seznamu (jen zůstatky a čísla) — nechat přesně jak je.
            return $check;
        }

        $total = count($raw);
        $slice = $cap > 0 ? array_slice($raw, 0, $cap) : $raw;

        $findings = $kind === 'account'
            ? array_map(static fn ($r) => self::accountFinding((array) $r), $slice)
            : array_map(fn ($r) => $this->docFinding($r, $key), $slice);

        // Skalární doprovodné klíče (missing_cnb_count, unexplained…) zůstávají —
        // nesou informaci, kterou seznam neobsahuje.
        $rest = $value;
        unset($rest['items'], $rest['accounts'], $rest['ids'], $rest['documents'], $rest['groups'], $rest['count']);

        $check['value'] = $rest + [
            'count'     => $total,
            'findings'  => $findings,
            'truncated' => $cap > 0 && $total > $cap,
            'kind'      => $kind,
        ];

        return $check;
    }

    /**
     * Najde v hodnotě seznam nálezů a určí, jestli jde o doklady nebo účty.
     *
     * @param array<string,mixed> $value
     * @return array{0:list<mixed>|null, 1:string}
     */
    private static function extractList(array $value): array
    {
        foreach (['items', 'documents', 'ids'] as $k) {
            if (isset($value[$k]) && is_array($value[$k])) {
                return [array_values($value[$k]), 'document'];
            }
        }
        if (isset($value['accounts']) && is_array($value['accounts'])) {
            return [array_values($value['accounts']), 'account'];
        }
        // `groups` (assets_without_accumulated_depreciation) nese vnořená `asset_ids`.
        // Rozbalí se na jeden nález per karta majetku — jinak by kontrola skončila
        // s počtem a prázdnou tabulkou, protože vlastní renderer už neexistuje.
        if (isset($value['groups']) && is_array($value['groups'])) {
            return [self::flattenAssetGroups($value['groups']), 'document'];
        }

        return [null, 'scalar'];
    }

    /**
     * Skupiny majetku bez oprávek → jeden nález per karta. Skupina nese dvojici účtů
     * (majetkový a oprávkový) a seznam `asset_ids`; ta id jsou to jediné, co uživateli
     * umožní kartu najít, takže se nesmí ztratit.
     *
     * @param list<mixed> $groups
     * @return list<array<string,mixed>>
     */
    private static function flattenAssetGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $note = trim(sprintf(
                '%s / %s',
                (string) ($g['asset_account_code'] ?? ''),
                (string) ($g['accumulated_account_code'] ?? ''),
            ), ' /');
            foreach ((array) ($g['asset_ids'] ?? []) as $assetId) {
                $out[] = [
                    'doc_type' => 'asset',
                    'id'       => (int) $assetId,
                    'note'     => $note,
                    'amount'   => $g['asset_balance'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function accountFinding(array $row): array
    {
        return [
            'account_id'   => isset($row['account_id']) ? (int) $row['account_id'] : null,
            'account_code' => (string) ($row['account_code'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'amount'       => self::pick($row, self::AMOUNT_KEYS),
            'note'         => isset($row['note']) ? (string) $row['note']
                : (isset($row['line_count']) ? (int) $row['line_count'] . '×' : null),
        ];
    }

    /**
     * @param mixed $row skalární id (u `ids`) nebo asociativní pole
     * @return array<string,mixed>
     */
    private function docFinding(mixed $row, string $checkKey): array
    {
        // `ids: int[]` — holé identifikátory bez dalších údajů.
        if (!is_array($row)) {
            return [
                'doc_type'     => self::STATIC_DOC_TYPE[$checkKey] ?? null,
                'doc_id'       => (int) $row,
                'doc_no'       => null,
                'partner_name' => null,
                'amount'       => null,
                'note'         => null,
            ];
        }

        // Typ dokladu: nejdřív per-položku, pak statický podle kontroly.
        $docType = null;
        foreach (['doc_type', 'source_type', 'match_kind'] as $k) {
            if (!empty($row[$k])) {
                $docType = (string) $row[$k];
                break;
            }
        }
        $docType ??= self::STATIC_DOC_TYPE[$checkKey] ?? null;

        $docId = null;
        foreach (['doc_id', 'invoice_id', 'id', 'entry_id'] as $k) {
            if (isset($row[$k])) {
                $docId = (int) $row[$k];
                break;
            }
        }

        // `entry_id` vedle vlastního dokladu (cancelled_with_entry) je samostatný odkaz
        // do deníku — bez něj by uživatel neměl jak najít zápis, který má stornovat.
        $entryId = isset($row['entry_id']) && $docId !== (int) $row['entry_id']
            ? (int) $row['entry_id']
            : null;

        return [
            'doc_type'     => $docType,
            'doc_id'       => $docId,
            'doc_no'       => self::pickString($row, self::DOC_NO_KEYS),
            // Datum dokladu (u bankovních nálezů datum transakce). Bez něj se nález nedá
            // zařadit v čase a účetní musí každý řádek dohledávat ručně.
            'doc_date'     => self::pickString($row, self::DATE_KEYS),
            'partner_name' => self::pickString($row, self::PARTNER_KEYS),
            'amount'       => self::pick($row, self::AMOUNT_KEYS),
            'entry_id'     => $entryId,
            'currency'     => self::pickString($row, ['currency', 'currency_code', 'doc_currency']),
            // Měna DOKLADU vedle měny částky. U kontroly spárovaných plateb je částka
            // dopad v korunách, kdežto údaje v `detail` (o kolik, kolik se čekalo) jsou
            // v měně dokladu — bez tohohle pole by je klient neuměl označit.
            'doc_currency' => self::pickString($row, ['doc_currency']),
            // `issues` se posílá jako POLE KÓDŮ, ne slepený text: klient je překládá
            // a doplňuje konkrétním údajem z `detail`. Slepené „amount_mismatch,
            // counterparty_mismatch" bylo v české aplikaci anglicky a neřeklo ani
            // o kolik, ani proč.
            'issues'       => (!empty($row['issues']) && is_array($row['issues']))
                ? array_values(array_map('strval', $row['issues']))
                : null,
            'detail'       => (isset($row['detail']) && is_array($row['detail'])) ? $row['detail'] : null,
            'note'         => isset($row['note']) ? (string) $row['note'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function pick(array $row, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                return round((float) $row[$k], 2);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function pickString(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return (string) $row[$k];
            }
        }

        return null;
    }
}

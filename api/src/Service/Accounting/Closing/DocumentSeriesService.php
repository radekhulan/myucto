<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentSeriesRepository;

/**
 * Číselné řady deníku (Epic F4, R13 / F1 B-b — §11 ZoÚ označení dokladu).
 *
 * Výdej čísla MUSÍ běžet uvnitř probíhající transakce volajícího
 * (ClosingService/akce ji drží): lazy INSERT řádku řady → SELECT ... FOR UPDATE
 * → UPDATE čítače → vyrenderování šablony. FOR UPDATE eliminuje race dvou
 * souběžných výdejů; mezery po smazaných zápisech se nedorovnávají
 * (R12/R13 — dokumentováno).
 *
 * Tvar čísla je per firma × řada × rok volitelný (#22 — přechod z jiného
 * systému): `number_format` se stejnými placeholdery jako u faktur, NULL =
 * vestavěný `{PREFIX}-{YYYY}-{CCCC}` (tedy `UZ-2026-0001` jako dosud).
 *
 * `$registerId` rozlišuje řadu pokladny od společné řady firmy (L-3, migrace 1506):
 * 0 = společná řada (výchozí a jediná, pokud si to firma nepřepne), >0 = vlastní řada
 * pokladny s tímhle id. Používá se jen u `cash_in`/`cash_out`; ostatní řady jsou vždy
 * firemní, protože jiný doklad než pokladní k pokladně nepatří.
 */
final class DocumentSeriesService
{
    /** Výchozí prefixy řad (R13); per firma editovatelné přes updateSeries. */
    public const DEFAULT_PREFIXES = [
        'closing'  => 'UZ',
        'opening'  => 'OT',
        'fx'       => 'KR',
        'transfer' => 'PP',
        'manual'   => 'ID',
        'cash_in'  => 'PPD',
        'cash_out' => 'VPD',
        'stock_in'       => 'PRI',
        'stock_out'      => 'VYD',
        'stock_transfer' => 'PRE',
        'offset'         => 'ZAP',
        // Objednávka vydaná dodavateli (Epic SKLAD „na cestě"). Není účetní
        // doklad (§ 11 ZoÚ), řadu ale sdílí — číslo musí být unikátní a bez
        // souběhových děr stejně jako u dokladů.
        'purchase_order' => 'OBJ',
        'other_receivable' => 'OP',
        'other_payable' => 'OZ',
    ];

    /**
     * Řady vázané na účetní deník — v daňové evidenci deník neexistuje, takže je
     * firma v DE nevydává ani needituje. Pokladní, skladové a objednávkové řady
     * naopak DE používá (`CashDocumentService` vydává čísla PPD/VPD bez ohledu na
     * režim), proto celá routa `/document-series` pro DE zavřená být nesmí —
     * jinak si firma vlastní řadu pokladny zapne, ale prefix už nespraví.
     */
    public const DOUBLE_ENTRY_ONLY_SERIES = ['closing', 'opening', 'fx', 'transfer', 'manual', 'offset'];

    /**
     * Řady, které vydávají čísla do jedné tabulky dokladů s jednou unikátností čísla
     * (`uq_cashdoc_supplier_number`, `uq_other_item_doc_no`). Všechny začínají od jedničky,
     * takže dvě řady skupiny se stejným prefixem vydají totéž číslo dokladu.
     */
    public const PREFIX_GROUPS = [
        'pokladní řada' => ['cash_in', 'cash_out'],
        'řada ostatních položek' => ['other_receivable', 'other_payable'],
    ];

    /** Vestavěná šablona = dosavadní chování (`UZ-2026-0001`). */
    public const DEFAULT_TEMPLATE = '{PREFIX}-{YYYY}-{CCCC}';

    /** Horní mez čítače řady (stejný řád jako u faktur). */
    public const MAX_NEXT_NUMBER = 999999999;

    private const PREFIX_PATTERN = '/^[A-Z0-9]{1,10}$/';

    /**
     * Šablona = literály A–Z/0–9/`-`/`/`/`.`/`_` a placeholdery {PREFIX},
     * {YYYY}, {YY}, {C+} (padding dle počtu C). Malá písmena a mezery jsou
     * mimo — číslo dokladu se páruje a hledá, diakritiku a whitespace tam
     * nechceme.
     */
    private const FORMAT_PATTERN = '~^(?:[A-Z0-9\-/._]|\{PREFIX\}|\{YYYY\}|\{YY\}|\{C+\})+$~';

    private const FORMAT_MAX_LENGTH = 40;

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentSeriesRepository $series,
    ) {}

    /**
     * Vydá další číslo řady, např. `UZ-2026-0001`. Vyžaduje transakci
     * volajícího — atomicita výdeje s INSERTem zápisu (R13).
     */
    public function next(int $supplierId, string $seriesCode, int $fiscalYear, int $registerId = 0): string
    {
        $defaultPrefix = self::DEFAULT_PREFIXES[$seriesCode]
            ?? throw new ClosingException('unknown_series', 'Neznámá číselná řada "' . $seriesCode . '".');
        if (!$this->db->pdo()->inTransaction()) {
            throw new ClosingException(
                'series_transaction_required',
                'Výdej čísla řady ' . $seriesCode . ' vyžaduje probíhající transakci (FOR UPDATE zámek).',
                500,
            );
        }

        $this->series->ensure($supplierId, $seriesCode, $fiscalYear, $defaultPrefix, $registerId);
        $row = $this->series->lockRow($supplierId, $seriesCode, $fiscalYear, $registerId);
        if ($row === null) {
            throw new ClosingException(
                'operation_failed',
                'Řadu ' . $seriesCode . '/' . $fiscalYear . ' se nepodařilo zamknout pro výdej čísla.',
                500,
            );
        }
        $this->series->bumpNextNumber($row['id']);

        return self::format($row['prefix'], $fiscalYear, $row['next_number'], $row['number_format']);
    }

    /**
     * Formát čísla dokladu řady — čistá funkce (unit-testovatelná bez DB).
     * `$template` NULL/prázdná = vestavěný `{PREFIX}-{YYYY}-{CCCC}`.
     */
    public static function format(string $prefix, int $fiscalYear, int $number, ?string $template = null): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $out = strtr($template, [
            '{PREFIX}' => $prefix,
            '{YYYY}'   => (string) $fiscalYear,
            '{YY}'     => substr((string) $fiscalYear, -2),
        ]);

        // Padding dle počtu C; delší číslo se NEZKRACUJE (po 9999 řada pokračuje pětimístně).
        return preg_replace_callback(
            '/\{(C+)\}/',
            static fn (array $m): string => str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT),
            $out,
        ) ?? $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        return $this->series->list($supplierId);
    }

    /**
     * Nastaví prefix řady (validace `^[A-Z0-9]{1,10}$`); řádek lazy založí,
     * takže edit funguje i před prvním výdejem čísla.
     */
    public function updatePrefix(int $supplierId, string $seriesCode, int $fiscalYear, string $prefix): bool
    {
        return $this->updateSeries($supplierId, $seriesCode, $fiscalYear, ['prefix' => $prefix]);
    }

    /**
     * Nastaví prefix / šablonu čísla / čítač řady; řádek lazy založí, takže
     * edit funguje i před prvním výdejem čísla (#22 — převzetí řady z jiného
     * systému). `number_format => null` vrátí řadu na vestavěnou šablonu.
     *
     * `next_number` je číslo PŘÍŠTÍHO vydaného dokladu (stejná sémantika jako
     * u faktur, PUT /settings/supplier/invoice-counter). Čítač jde i snížit —
     * unikátnost čísla si hlídá cílová tabulka (např. `uq_cashdoc_supplier_number`),
     * takže kolize s už vystaveným dokladem skončí chybou uložení, ne tichým duplikátem.
     *
     * @param array{prefix?:string, number_format?:string|null, next_number?:int} $changes
     */
    /**
     * Založí řádek řady s daným prefixem a šablonou, pokud pro rok ještě není;
     * existující řádek nechá BEZE ZMĚNY, čítač včetně.
     *
     * Proti `updateSeries(['next_number' => 1])`, které se tady používalo: to jede
     * přes `ON DUPLICATE KEY UPDATE next_number = VALUES(next_number)`, takže dvě
     * souběžná vystavení dokladu skončila tak, že B po commitu A vrátilo čítač na 1
     * a obě transakce vydaly totéž číslo (spadne na `uq_cashdoc_supplier_number`).
     */
    public function ensureSeriesRow(
        int $supplierId,
        string $seriesCode,
        int $fiscalYear,
        string $prefix,
        ?string $numberFormat = null,
        int $registerId = 0,
    ): void {
        if (!isset(self::DEFAULT_PREFIXES[$seriesCode])) {
            throw new ClosingException('unknown_series', 'Neznámá číselná řada "' . $seriesCode . '".');
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new ClosingException(
                'invalid_prefix',
                'Prefix řady musí být 1–10 znaků A–Z/0–9 (zadáno "' . $prefix . '").',
            );
        }
        $this->assertPrefixFree($supplierId, $prefix, $seriesCode, $registerId);
        $this->series->ensure(
            $supplierId,
            $seriesCode,
            $fiscalYear,
            $prefix,
            $registerId,
            self::normalizeTemplate($numberFormat),
        );
    }

    public function updateSeries(int $supplierId, string $seriesCode, int $fiscalYear, array $changes, int $registerId = 0): bool
    {
        if (!isset(self::DEFAULT_PREFIXES[$seriesCode])) {
            throw new ClosingException('unknown_series', 'Neznámá číselná řada "' . $seriesCode . '".');
        }

        $patch = [];

        if (array_key_exists('prefix', $changes)) {
            $prefix = (string) $changes['prefix'];
            if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
                throw new ClosingException(
                    'invalid_prefix',
                    'Prefix řady musí být 1–10 znaků A–Z/0–9 (zadáno "' . $prefix . '").',
                );
            }
            $patch['prefix'] = $prefix;
        }

        if (array_key_exists('number_format', $changes)) {
            $patch['number_format'] = self::normalizeTemplate($changes['number_format']);
        }

        if (array_key_exists('next_number', $changes)) {
            $next = (int) $changes['next_number'];
            if ($next < 1 || $next > self::MAX_NEXT_NUMBER) {
                throw new ClosingException(
                    'invalid_next_number',
                    'Další číslo řady musí být celé číslo 1–' . self::MAX_NEXT_NUMBER . '.',
                );
            }
            $patch['next_number'] = $next;
        }

        if ($patch === []) {
            throw new ClosingException('validation_failed', 'Není co měnit — zadej prefix, number_format nebo next_number.');
        }

        // Řady jedné skupiny (pokladní, ostatní položky) se potkávají v jedné tabulce
        // dokladů: všechny začínají od jedničky, takže shodný prefix = duplicitní číslo
        // dokladu při ukládání (u ostatních položek až jako chyba databáze). Ruční
        // editaci prefixu v Nástrojích proto hlídáme tady, ne až v databázi.
        if (isset($patch['prefix'])) {
            $this->assertPrefixFree($supplierId, $patch['prefix'], $seriesCode, $registerId);
        }

        return $this->series->upsert(
            $supplierId,
            $seriesCode,
            $fiscalYear,
            self::DEFAULT_PREFIXES[$seriesCode],
            $patch,
            $registerId,
        );
    }

    /**
     * Prefix řady ze skupiny {@see PREFIX_GROUPS} nesmí držet jiná řada téže skupiny —
     * ani firemní (register_id = 0), ani vlastní řada jiné pokladny.
     * Kontroluje se přes obě řady skupiny (PPD i VPD, OP i OZ), protože sdílejí jednu
     * unikátnost čísla. Řady mimo skupiny se nekontrolují.
     */
    private function assertPrefixFree(int $supplierId, string $prefix, string $seriesCode, int $registerId): void
    {
        $label = self::prefixGroup($seriesCode);
        if ($label === null) {
            return;
        }
        $group = self::PREFIX_GROUPS[$label];
        // Vestavěné prefixy (PPD/VPD, OP/OZ) si drží firemní řada — i když její řádek pro
        // daný rok ještě nevznikl (zakládá se lazy), vlastní řada si je vzít nesmí, jinak by
        // kolize nastala až při prvním firemním dokladu.
        if ($registerId > 0) {
            foreach ($group as $companyCode) {
                if (strcasecmp(self::DEFAULT_PREFIXES[$companyCode], $prefix) === 0) {
                    throw new ClosingException(
                        'series_prefix_taken',
                        'Prefix "' . $prefix . '" patří společné řadě firmy — vlastní řada potřebuje jiný.',
                    );
                }
            }
        }

        foreach ($this->series->list($supplierId) as $row) {
            if (!in_array((string) $row['series_code'], $group, true)) {
                continue;
            }
            $sameScope = (int) $row['register_id'] === $registerId && (string) $row['series_code'] === $seriesCode;
            if ($sameScope || strcasecmp((string) $row['prefix'], $prefix) !== 0) {
                continue;
            }
            throw new ClosingException(
                'series_prefix_taken',
                'Prefix "' . $prefix . '" už používá jiná ' . $label . ' — dvě řady se stejným prefixem by vydaly totéž číslo dokladu.',
            );
        }
    }

    /** Skupina řady z {@see PREFIX_GROUPS} (klíč = název do hlášky); null = řada mimo skupiny. */
    private static function prefixGroup(string $seriesCode): ?string
    {
        foreach (self::PREFIX_GROUPS as $label => $group) {
            if (in_array($seriesCode, $group, true)) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Ověří šablonu čísla a vrátí ji k uložení; prázdná / vestavěná = NULL
     * (řada se vrací na `{PREFIX}-{YYYY}-{CCCC}`).
     */
    public static function normalizeTemplate(mixed $template): ?string
    {
        $t = strtoupper(trim((string) $template));
        if ($t === '' || $t === self::DEFAULT_TEMPLATE) {
            return null;
        }
        if (mb_strlen($t) > self::FORMAT_MAX_LENGTH) {
            throw new ClosingException(
                'invalid_number_format',
                'Šablona čísla řady smí mít nejvýše ' . self::FORMAT_MAX_LENGTH . ' znaků.',
            );
        }
        if (preg_match(self::FORMAT_PATTERN, $t) !== 1) {
            throw new ClosingException(
                'invalid_number_format',
                'Šablona čísla řady smí obsahovat jen A–Z, 0–9, "-", "/", ".", "_"'
                . ' a placeholdery {PREFIX}, {YYYY}, {YY}, {C…} (zadáno "' . $t . '").',
            );
        }
        if (preg_match('/\{C+\}/', $t) !== 1) {
            throw new ClosingException(
                'invalid_number_format',
                'Šablona čísla řady musí obsahovat čítač {C…} (např. {CCCCC}), jinak by se čísla opakovala.',
            );
        }
        return $t;
    }
}

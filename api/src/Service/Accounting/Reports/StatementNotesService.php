<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;

/**
 * Příloha k účetní závěrce — § 18 odst. 1 písm. c) ZoÚ, § 39 / § 39a / § 39b vyhlášky
 * 500/2002 Sb.
 *
 * Systém přílohu neměl vůbec, přestože bez ní závěrka není úplná. Prozrazoval to i sám
 * `ClosingPackageService`, který u povinného jádra § 18 cituje, ale přílohu mezi částmi
 * neměl.
 *
 * ── Co tahle služba dělá ────────────────────────────────────────────────────────────
 * Ví, KTERÉ údaje musí konkrétní účetní jednotka zveřejnit — rozsah se stupňuje podle
 * kategorie (§ 39 všechny, § 39a navíc ty s povinným auditem, § 39b navíc velké) — a
 * říká, co z nich je vyplněné a co chybí. Právě ta odstupňovanost je jádro: mikro účetní
 * jednotku by úplný výčet zbytečně zavalil, zatímco velké by neúplný výčet zastřel
 * povinnost.
 *
 * Část údajů se PŘEDVYPLNÍ z dat, která systém má (název, sídlo, IČO, právní forma,
 * kategorie, průměrný počet zaměstnanců). Zbytek je souvislý text, který systém vymyslet
 * nemůže — účetní metody, události po rozvahovém dni, odměny statutárnímu orgánu.
 *
 * ── Co tahle služba NEDĚLÁ ──────────────────────────────────────────────────────────
 * Nekontroluje OBSAH textu. „Vyplněno" znamená, že tam něco je, ne že je to správně —
 * předstírat věcnou kontrolu formulací by budilo falešnou jistotu. Chybějící povinné
 * sekce hlásí jako neúplnost, ale závěrku neblokuje: příloha se doplňuje v průběhu
 * uzávěrky a tvrdý zákaz by bránil i uložit rozdělanou práci.
 */
final class StatementNotesService
{
    /** § 39 vyhlášky — zveřejňuje KAŽDÁ účetní jednotka. */
    public const SCOPE_ALL = 'all';

    /** § 39a — navíc účetní jednotky s povinným auditem (a střední/velké). */
    public const SCOPE_AUDITED = 'audited';

    /** § 39b — navíc velké účetní jednotky. */
    public const SCOPE_LARGE = 'large';

    /**
     * Sekce přílohy. Pořadí odpovídá pořadí ve vyhlášce, ať výstup čte účetní shora dolů.
     *
     * `auto` = systém sekci umí předvyplnit z vlastních dat; ostatní jsou souvislý text.
     *
     * @var array<string, array{scope:string, label:string, legal:string, auto:bool}>
     */
    private const SECTIONS = [
        'entity_identification' => [
            'scope' => self::SCOPE_ALL, 'auto' => true,
            'label' => 'Základní údaje o účetní jednotce',
            'legal' => '§ 39 odst. 1 písm. a) — firma, sídlo, IČO, právní forma, předmět podnikání',
        ],
        'entity_category' => [
            'scope' => self::SCOPE_ALL, 'auto' => true,
            'label' => 'Kategorie účetní jednotky a rozsah závěrky',
            'legal' => '§ 1b–1e ZoÚ; určuje rozsah přílohy i výkazů',
        ],
        'accounting_principles' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Použité obecné účetní zásady, metody a odchylky',
            'legal' => '§ 39 odst. 1 písm. b)',
        ],
        // Písm. b) pokrývá i způsoby oceňování; písm. c) oceňovací model při reálné
        // hodnotě (mikro ÚJ reálnou hodnotu nepoužívají — stačí „nepoužito").
        'valuation_methods' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Způsoby oceňování, odpisování a tvorby opravných položek',
            'legal' => '§ 39 odst. 1 písm. b) a c)',
        ],
        'fx_policy' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Způsob přepočtu cizích měn na českou měnu',
            'legal' => '§ 39 odst. 1 písm. b) ve spojení s § 24 odst. 6 ZoÚ',
        ],
        // Standardní blok příloh od účetních (rozpis majetku, pohledávek, vlastního
        // kapitálu, závazků, rezerv a členění výnosů) — bez něj šablona přílohy
        // neodpovídala praxi a tenhle obsah neměl kam.
        'balance_pl_details' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Doplňující údaje k rozvaze a výkazu zisku a ztráty',
            'legal' => '§ 39 odst. 1 — rozpis významných položek výkazů (majetek, pohledávky, vlastní kapitál, závazky, rezervy, členění výnosů)',
        ],
        'receivables_payables_over_5y' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            // Rozvaha dělí pohledávky a závazky na dlouhodobé a krátkodobé podle
            // zbytkové splatnosti, ale ze zůstatku syntetiky to poznat nejde -
            // 321 je jeden účet pro obojí. Aplikace to proto nedopočítává a
            // dlouhodobé řádky zůstávají nulové, dokud firma nepoužije analytiku
            // (321D, 322D, 311D). Účetní to musí vědět dřív, než výkaz odevzdá,
            // takže to stojí přímo u sekce, kde se splatnost popisuje.
            'hint' => 'MyÚčto nedopočítává zbytkovou splatnost ze zůstatků účtů. '
                . 'Dlouhodobé řádky rozvahy zůstávají nulové, pokud dlouhodobou část '
                . 'nevedete na analytice (321D, 322D, 311D). Uveďte tady, že se '
                . 'závazky a pohledávky se zbytkovou splatností nad jeden rok '
                . 'nevykazují samostatně, nebo analytiku doplňte.',
            'label' => 'Pohledávky a závazky se splatností delší než 5 let a kryté zárukou',
            'legal' => '§ 39 odst. 1 písm. d) a e)',
        ],
        // Chyběly dvě povinné sekce § 39 odst. 1: písm. f) — půjčky a zálohy členům
        // orgánů (účetní je uvádějí i jako „žádné"), a písm. g) — mimořádné položky
        // výnosů a nákladů. Bez nich příloha hlásila „úplná", ačkoli zákonný výčet
        // pokrytý nebyl.
        'board_loans_advances' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Zálohy, závdavky, zápůjčky a úvěry členům řídících, kontrolních a správních orgánů',
            'legal' => '§ 39 odst. 1 písm. f) — včetně úrokové sazby a hlavních podmínek',
        ],
        'extraordinary_items' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Výnosy a náklady mimořádné svým objemem nebo původem',
            'legal' => '§ 39 odst. 1 písm. g)',
        ],
        'off_balance_commitments' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Závazky nevykázané v rozvaze (podrozvahová evidence)',
            'legal' => '§ 39 odst. 1 písm. h)',
        ],
        'average_employees' => [
            'scope' => self::SCOPE_ALL, 'auto' => true,
            'label' => 'Průměrný přepočtený počet zaměstnanců',
            'legal' => '§ 39 odst. 1 písm. i)',
        ],
        'subsequent_events' => [
            'scope' => self::SCOPE_ALL, 'auto' => false,
            'label' => 'Události po rozvahovém dni',
            'legal' => '§ 19 odst. 6 ZoÚ',
        ],
        'governing_body_remuneration' => [
            'scope' => self::SCOPE_AUDITED, 'auto' => false,
            'label' => 'Odměny a plnění členům řídících a kontrolních orgánů',
            'legal' => '§ 39a odst. 1 písm. b)',
        ],
        'related_party_transactions' => [
            'scope' => self::SCOPE_AUDITED, 'auto' => false,
            'label' => 'Transakce se spřízněnými stranami',
            'legal' => '§ 39a odst. 1 písm. c)',
        ],
        'deferred_tax_note' => [
            'scope' => self::SCOPE_AUDITED, 'auto' => false,
            'label' => 'Odložená daň — způsob stanovení a rozpis titulů',
            'legal' => '§ 39a; ČÚS 003',
        ],
        'auditor_fees' => [
            'scope' => self::SCOPE_LARGE, 'auto' => false,
            'label' => 'Odměny auditorské společnosti v členění podle druhu služeb',
            'legal' => '§ 39b odst. 1 písm. c)',
        ],
        'revenue_breakdown' => [
            'scope' => self::SCOPE_LARGE, 'auto' => false,
            'label' => 'Rozpis tržeb podle kategorií činnosti a zeměpisných trhů',
            'legal' => '§ 39b odst. 1 písm. a)',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly EntityCategoryService $categories,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * Příloha k závěrce období: povinné sekce dle kategorie, jejich obsah a co chybí.
     *
     * @return array{
     *   fiscal_year:int, category:string, category_label:string, scopes:list<string>,
     *   sections:list<array{key:string, label:string, legal:string, scope:string,
     *                       auto:bool, content:?string, filled:bool}>,
     *   missing:list<string>, complete:bool
     * }
     */
    public function build(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $fiscalYear = (int) $period['fiscal_year'];
        $category = $this->categoryFor($supplierId, $periodId);
        $scopes = self::scopesFor($category, $this->isAudited($supplierId));
        $storedRows = $this->storedRows($supplierId, $fiscalYear);
        $stored = [];
        foreach ($storedRows as $key => $row) {
            $stored[$key] = $row['content'];
        }
        $auto = $this->autoValues($supplierId, $fiscalYear, $category);

        $sections = [];
        $missing = [];
        foreach (self::SECTIONS as $key => $def) {
            if (!in_array($def['scope'], $scopes, true)) {
                continue;
            }
            // Ručně vyplněný text má přednost před předvyplněním — účetní ho mohla vědomě
            // upřesnit a přepsat ji automatikou by tichou ztrátu.
            $content = $stored[$key] ?? ($def['auto'] ? ($auto[$key] ?? null) : null);
            $filled = $content !== null && trim($content) !== '';
            if (!$filled) {
                $missing[] = $key;
            }
            $sections[] = [
                'key'     => $key,
                'label'   => $def['label'],
                'legal'   => $def['legal'],
                'hint'    => $def['hint'] ?? null,
                'scope'   => $def['scope'],
                'auto'    => $def['auto'],
                'content' => $content,
                'filled'  => $filled,
                // Rok, ze kterého je text převzatý a účetní ho ještě nepotvrdila.
                'carried_over_from_year' => $storedRows[$key]['carried_over_from_year'] ?? null,
            ];
        }

        // Nabídka převzetí: má smysl jen tehdy, když loni něco vyplněného je a letos
        // je aspoň jedna neautomatická sekce prázdná.
        $previous = $this->storedRows($supplierId, $fiscalYear - 1);
        $carryOverAvailable = 0;
        foreach ($previous as $key => $row) {
            if (!isset(self::SECTIONS[$key]) || self::SECTIONS[$key]['auto'] || trim($row['content']) === '') {
                continue;
            }
            if (trim((string) ($stored[$key] ?? '')) === '') {
                $carryOverAvailable++;
            }
        }

        return [
            'fiscal_year'    => $fiscalYear,
            'category'       => $category,
            'category_label' => self::categoryLabel($category),
            'scopes'         => $scopes,
            'sections'       => $sections,
            'missing'        => $missing,
            'complete'       => $missing === [],
            'carry_over'     => [
                'source_year' => $fiscalYear - 1,
                'available'   => $carryOverAvailable,
            ],
        ];
    }

    /**
     * Zmrazí automaticky předvyplněné sekce do `statement_notes` — volá se při uzavření
     * knih, stejně jako zmražení kategorie účetní jednotky.
     *
     * Bez toho se příloha schváleného roku MĚNILA spolu s firmou: název, sídlo, IČO
     * i kategorie se braly z aktuálního `supplier`, ne ze stavu k rozvahovému dni.
     * Na ostrých datech byl výstup pro 2024, 2025 i 2026 bitově shodný, přestože jde
     * o tři různé závěrky. Příloha je přitom součástí účetní závěrky a ta se po
     * schválení měnit nesmí.
     *
     * Ručně vyplněný text se NEPŘEPISUJE: účetní ho mohla vědomě upřesnit a automatika
     * by ho tiše přebila. Zmrazí se jen sekce, které vlastní text nemají.
     *
     * @return int počet zmražených sekcí
     */
    public function freezeAutoValues(int $supplierId, int $fiscalYear, int $periodId, ?int $userId = null): int
    {
        $stored = $this->stored($supplierId, $fiscalYear);
        $auto = $this->autoValues($supplierId, $fiscalYear, $this->categoryFor($supplierId, $periodId));

        $frozen = 0;
        foreach ($auto as $key => $content) {
            $existing = $stored[$key] ?? null;
            if (($existing !== null && trim($existing) !== '') || trim((string) $content) === '') {
                continue;
            }
            $this->saveSection($supplierId, $fiscalYear, $key, (string) $content, $userId);
            $frozen++;
        }

        return $frozen;
    }

    /**
     * Převezme texty přílohy z předchozího účetního období.
     *
     * Nový rok se u přílohy z velké části opakuje — sídlo, právní forma, způsoby
     * oceňování, odpisové metody — a přepisovat to ručně nemá smysl. Převzetí je ale
     * VĚDOMÝ krok účetní, ne tiché předvyplnění při otevření stránky: loňská věta
     * („v průběhu roku došlo k fúzi", „účetní jednotka nemá závazky po splatnosti")
     * může být letos nepravdivá a příloha je součástí účetní závěrky.
     *
     * Proto se drží tři pravidla:
     *   1) přepisuje se jen to, co je letos PRÁZDNÉ — vlastní text účetní se nikdy
     *      nepřebije,
     *   2) převzatá sekce si nese rok původu, aby účetní poznala, co ještě neprošla,
     *   3) jakmile ji uloží, příznak zmizí a text je její.
     *
     * Automaticky předvyplňované sekce (název, sídlo, kategorie) se nepřebírají —
     * ty se odvozují z dat a loňská hodnota by je jen zastarale zmrazila.
     *
     * @return array{copied:list<string>,source_year:int,available:int}
     */
    public function carryOverFromPreviousYear(int $supplierId, int $fiscalYear, ?int $userId): array
    {
        $sourceYear = $fiscalYear - 1;
        $previous = $this->storedRows($supplierId, $sourceYear);
        $current = $this->storedRows($supplierId, $fiscalYear);

        $copied = [];
        foreach ($previous as $key => $row) {
            if (!isset(self::SECTIONS[$key]) || self::SECTIONS[$key]['auto']) {
                continue;
            }
            $existing = $current[$key]['content'] ?? '';
            if (trim($existing) !== '' || trim($row['content']) === '') {
                continue;
            }
            $this->saveSection($supplierId, $fiscalYear, $key, $row['content'], $userId, $sourceYear);
            $copied[] = $key;
        }

        return ['copied' => $copied, 'source_year' => $sourceYear, 'available' => count($previous)];
    }

    /**
     * Uloží text sekce. `$carriedOverFromYear` vyplňuje jen převzetí z minulého roku
     * ({@see carryOverFromPreviousYear()}); ruční uložení ho vždy shodí na null, čímž
     * účetní text potvrdí jako svůj.
     */
    public function saveSection(
        int $supplierId,
        int $fiscalYear,
        string $key,
        ?string $content,
        ?int $userId,
        ?int $carriedOverFromYear = null,
    ): void
    {
        if (!isset(self::SECTIONS[$key])) {
            throw new \InvalidArgumentException('Neznámá sekce přílohy: ' . $key);
        }
        $content = $content !== null && trim($content) !== '' ? $content : null;

        if ($content === null) {
            $this->db->pdo()->prepare(
                'DELETE FROM statement_notes WHERE supplier_id = ? AND fiscal_year = ? AND section_key = ?'
            )->execute([$supplierId, $fiscalYear, $key]);

            return;
        }

        $this->db->pdo()->prepare(
            'INSERT INTO statement_notes (supplier_id, fiscal_year, section_key, content, updated_by, carried_over_from_year)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by),
                                     carried_over_from_year = VALUES(carried_over_from_year)'
        )->execute([$supplierId, $fiscalYear, $key, $content, $userId, $carriedOverFromYear]);
    }

    /**
     * Rozsahy, které se na jednotku vztahují. Povinný audit vytahuje § 39a i u menší
     * jednotky — proto se nerozhoduje jen podle kategorie.
     *
     * @return list<string>
     */
    public static function scopesFor(string $category, bool $audited): array
    {
        $scopes = [self::SCOPE_ALL];
        if ($audited || in_array($category, ['medium', 'large'], true)) {
            $scopes[] = self::SCOPE_AUDITED;
        }
        if ($category === 'large') {
            $scopes[] = self::SCOPE_LARGE;
        }

        return $scopes;
    }

    /** @return array<string,string> */
    private function stored(int $supplierId, int $fiscalYear): array
    {
        $out = [];
        foreach ($this->storedRows($supplierId, $fiscalYear) as $key => $row) {
            $out[$key] = (string) $row['content'];
        }

        return $out;
    }

    /** @return array<string,array{content:string,carried_over_from_year:?int}> */
    private function storedRows(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT section_key, content, carried_over_from_year
               FROM statement_notes WHERE supplier_id = ? AND fiscal_year = ?'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['section_key']] = [
                'content' => (string) $r['content'],
                'carried_over_from_year' => $r['carried_over_from_year'] !== null
                    ? (int) $r['carried_over_from_year']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Sekce, které systém umí předvyplnit z vlastních dat. Zbytek přílohy jsou souvislé
     * formulace, které z účetnictví odvodit nelze.
     *
     * @return array<string,string>
     */
    private function autoValues(int $supplierId, int $fiscalYear, string $category): array
    {
        $out = [];

        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($s !== false) {
            $parts = array_filter([
                (string) $s['company_name'],
                trim((string) $s['street'] . ', ' . (string) $s['zip'] . ' ' . (string) $s['city'], ' ,'),
                $s['ic'] !== null && $s['ic'] !== '' ? 'IČO: ' . (string) $s['ic'] : null,
                $s['dic'] !== null && $s['dic'] !== '' ? 'DIČ: ' . (string) $s['dic'] : null,
            ]);
            $out['entity_identification'] = implode("\n", $parts);
        }

        $out['entity_category'] = 'Kategorie účetní jednotky: ' . self::categoryLabel($category)
            . ($this->isAudited($supplierId) ? '; podléhá povinnému auditu.' : '; nepodléhá povinnému auditu.');

        // Průměrný přepočtený počet zaměstnanců je dnes ruční číslo z nastavení výkaznictví.
        // Dopočet z mzdového modulu neexistuje (`payroll_employees` nenese úvazek), takže
        // se sem tahá ta hodnota, kterou jednotka uvádí i pro kategorizaci — jiné číslo
        // na dvou místech závěrky by byl rozpor.
        $avg = $this->settings->get($supplierId)['avg_employees'] ?? null;
        if ($avg !== null && (int) $avg > 0) {
            $out['average_employees'] = 'Průměrný přepočtený počet zaměstnanců v období: ' . (int) $avg . '.';
        }

        return $out;
    }

    private function categoryFor(int $supplierId, int $periodId): string
    {
        try {
            return (string) ($this->categories->evaluate($supplierId, $periodId)['category'] ?? 'micro');
        } catch (\Throwable) {
            // Bez dat pro kategorizaci se drž nejužšího rozsahu — nadsadit povinnosti
            // jednotce, o které nic nevíme, by přílohu jen zaplevelilo.
            return 'micro';
        }
    }

    private function isAudited(int $supplierId): bool
    {
        return (bool) ($this->settings->get($supplierId)['statutory_audit'] ?? false);
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'micro'  => 'mikro',
            'small'  => 'malá',
            'medium' => 'střední',
            'large'  => 'velká',
            default  => $category,
        };
    }
}

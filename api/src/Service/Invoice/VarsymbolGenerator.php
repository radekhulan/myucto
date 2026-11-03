<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Generuje var. symbol (číslo faktury).
 *
 * Resolver template per (client, kategorie tržby, supplier, type) — nejvyšší prioritu
 * má clients.{type}_number_format, pak revenue_categories.{type}_number_format, dál
 * supplier.{type}_number_format a fallback na cfg.varsymbol.templates.{type}. Klient
 * je nad kategorií záměrně: per-client řada se sjednávala s konkrétním odběratelem
 * a nesmí ji přebít plošné nastavení kategorie. Period scope (year/month/none) řídí,
 * kdy se counter resetuje; období si nese ta úroveň, která template vyhrála
 * (clients/revenue_categories.invoice_number_period), jinak supplier.invoice_number_period
 * (legacy default 'month').
 *
 * Counter se atomicky inkrementuje v `invoice_counters` per
 * (supplier_id, client_id, revenue_category_id, invoice_type, period). Scope drží
 * právě jednu vyhrávající osu: vyhraje-li klient, je `revenue_category_id = 0`;
 * vyhraje-li kategorie, je `client_id = 0`; bez obojího jsou obě 0 = supplier-wide
 * counter, takže existující řady pokračují beze změny.
 *
 * Placeholdery v template:
 *   {YYYY} = 4-digit year      ("2026")
 *   {YY}   = 2-digit year      ("26")
 *   {MM}   = 2-digit month     ("04")
 *   {C+}   = counter, padding podle počtu C ({CCC} → 3 znaky 001..999)
 *
 * Příklady:
 *   "JD{YYYY}-{CC}"      → "JD2026-02"      (period=year)
 *   "{YYYY}{MM}{CCC}"    → "202604001"      (period=month, default)
 *   "9{YY}{MM}{CCC}"     → "92604001"       (proforma, prefix 9)
 *   "F-{YYYY}/{CCCCCC}"  → "F-2026/000042"
 *   "{YY}{CCCC}"         → "260042"         (per-client, period=year)
 *
 * Prefix se píše rovnou do template stringu (žádný separátní `prefix` field).
 *
 * Zjednodušený daňový doklad se čísluje řadou {@see self::SIMPLIFIED}: s vyplněnou
 * `supplier.simplified_number_format` má vlastní počítadlo a přednost před klientem
 * i kategorií, bez ní se vyhodnotí přesně jako faktura. Řadu dokladu určuje
 * {@see self::seriesTypeFor()}.
 */
final class VarsymbolGenerator
{
    /**
     * Řada zjednodušených daňových dokladů (§ 30 ZDPH, migrace 2037). Není to typ dokladu
     * (zjednodušený doklad je faktura s příznakem `is_simplified`), jen řada, do které
     * se čísluje. Bez `supplier.simplified_number_format` je totožná s řadou faktur.
     */
    public const SIMPLIFIED = 'simplified';

    private const SUPPORTED_TYPES = ['invoice', 'proforma', 'credit_note', self::SIMPLIFIED];
    private const VALID_PERIODS   = ['year', 'month', 'none'];
    private const DEFAULT_PERIOD  = 'month';

    /** Maximální počet pokusů přeskočit obsazené číslo, než to vzdáme (poslední pojistka). */
    private const MAX_SKIP = 1000;

    /**
     * Typy, které se číslují v řadě FAKTUR (sdílí template i counter s 'invoice') —
     * žádná dodatečná konfigurace, žádné kolize čísel.
     *
     * Daňový doklad k přijaté platbě, penalizační faktura (úrok z prodlení) a platební
     * či splátkový kalendář (§ 31/31a ZDPH). Normalizace je na JEDNOM místě záměrně:
     * dřív ji každá metoda dělala po svém a `releaseIfLatest()` s 'penalty' tiše
     * nevracela číslo do řady, ze které ho `next()` vzal.
     */
    private const INVOICE_SERIES_ALIASES = ['tax_document', 'penalty', 'payment_calendar', 'rent_calendar', 'advance_calendar'];

    private static function normalizeType(string $invoiceType): string
    {
        return in_array($invoiceType, self::INVOICE_SERIES_ALIASES, true) ? 'invoice' : $invoiceType;
    }

    /**
     * Řada, ze které doklad bere číslo: zjednodušený daňový doklad (faktura
     * s `is_simplified`) → {@see self::SIMPLIFIED}, jinak jeho typ. Volají ji VŠICHNI,
     * kdo číslo přidělují, vracejí nebo ukazují v náhledu, ať se doklad nikdy
     * neočísluje z jiné řady, než do které se číslo vrací.
     *
     * Zda je to opravdu samostatná řada, rozhoduje až generátor podle nastavení firmy
     * (bez vlastní šablony padá zjednodušený doklad do řady faktur).
     *
     * @param array<string,mixed> $invoice alespoň `invoice_type`, případně `is_simplified`
     */
    public static function seriesTypeFor(array $invoice): string
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        return $type === 'invoice' && !empty($invoice['is_simplified']) ? self::SIMPLIFIED : $type;
    }

    /**
     * Všechny typy dokladů, které dostávají čísla z řady daného typu. Kdo řadu čte
     * (kontrola úplnosti), musí počítat i s nimi, jinak číslo daňového dokladu
     * k platbě nebo penalizace vypadá jako chybějící doklad.
     *
     * @return list<string>
     */
    public static function documentTypesInSeries(string $invoiceType): array
    {
        $series = self::normalizeType($invoiceType);
        return $series === 'invoice' ? ['invoice', ...self::INVOICE_SERIES_ALIASES] : [$series];
    }

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        // Povinné parametry: autowiring PHP-DI volitelné parametry přeskakuje. S výchozím
        // null by pojistka v produkci nikdy neběžela a warning „counter byl pozadu"
        // se nikdy nezapsal.
        private readonly NumberSeriesGapGuard $gapGuard,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Atomicky vygeneruje další var. symbol pro daný typ a datum.
     *
     * Pokud má faktura už ručně zadaný varsymbol (override), volající ho použije přímo
     * a tuto metodu nezavolá — viz IssueInvoiceAction.
     *
     * Volej ve STEJNÉ transakci, která číslo zapíše na doklad. Rollback pak číslo vrátí
     * do řady sám a {@see NumberSeriesGapGuard} může bezpečně srovnat počítadlo, které
     * utíká před vydanými čísly. Mimo transakci se číslo přidělí jako dřív, jen bez
     * srovnání.
     *
     * `$clientId` = 0 znamená "supplier-wide counter" (per-client template není
     * nastavený, použije se supplier-level template + jeho counter). Totéž platí pro
     * `$revenueCategoryId` = 0.
     *
     * @throws \InvalidArgumentException pokud typ nemá template ani v clients, ani
     *                                   v revenue_categories, ani v supplier, ani v cfg
     */
    public function next(
        int $supplierId,
        string $invoiceType,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): string {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }
        $invoiceType = self::normalizeType($invoiceType);
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Nepodporovaný typ pro varsymbol: {$invoiceType}");
        }

        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        if ($template === '') {
            throw new \InvalidArgumentException(
                "Chybí template pro {$invoiceType}: nastav v Systém → Dodavatelé → Číslování faktur,"
                . " nebo doplň cfg.varsymbol.templates.{$invoiceType}."
            );
        }

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);
        if ($this->hasCounterPlaceholder($template)) {
            $this->gapGuard?->clamp('invoice_counters', [
                'supplier_id'         => $supplierId,
                'client_id'           => $counterClientId,
                'revenue_category_id' => $counterCategoryId,
                'invoice_type'        => $invoiceType,
                'period'              => $periodKey,
            ], fn (): int => $this->highestUsedCounter($supplierId, $template, $for));
        }
        $next      = $this->incrementCounter($supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey);
        $rendered  = $this->render($template, $for, $next);

        // Template bez counteru ({C+}) → číslo je fixní, nelze nic přeskakovat.
        if (!$this->hasCounterPlaceholder($template)) {
            return $rendered;
        }

        // Happy path: counter sedí, číslo je volné.
        if (!$this->varsymbolExists($supplierId, $rendered)) {
            return $rendered;
        }

        // Counter je pozadu (typicky po importu / ruční úpravě DB / ručním číslování).
        // Místo slepého inkrementu po jedné skoč rovnou za nejvyšší skutečně použité číslo
        // odpovídající aktuálnímu template+období, pak doladí případné mezery.
        $startedAt = $next;
        $highest = $this->highestUsedCounter($supplierId, $template, $for);
        if ($highest >= $next) {
            $next     = $this->liftCounterTo($supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey, $highest + 1);
            $rendered = $this->render($template, $for, $next);
        }

        $attempts = 0;
        while ($this->varsymbolExists($supplierId, $rendered)) {
            if (++$attempts > self::MAX_SKIP) {
                throw new \RuntimeException(
                    "Nepodařilo se najít volné číslo faktury ani po " . self::MAX_SKIP
                    . " pokusech (typ {$invoiceType}, období {$periodKey}). Zkontroluj číselnou řadu nebo zadej číslo ručně."
                );
            }
            $next     = $this->incrementCounter($supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey);
            $rendered = $this->render($template, $for, $next);
        }

        $this->logger?->warning('varsymbol: counter byl pozadu, automaticky posunut na volné číslo', [
            'supplier_id'         => $supplierId,
            'client_id'           => $counterClientId,
            'revenue_category_id' => $counterCategoryId,
            'invoice_type'        => $invoiceType,
            'period'              => $periodKey,
            'from_counter'        => $startedAt,
            'to_counter'          => $next,
            'varsymbol'           => $rendered,
        ]);

        return $rendered;
    }

    /**
     * Posune `invoice_counters.last_number` tak, aby navazoval na nejvyšší již použité
     * číslo odpovídající aktuálnímu template a období (samoopravná synchronizace counteru).
     * Counter nikdy nesnižuje (GREATEST). Vhodné volat po importu historických faktur
     * nebo ruční změně číslování.
     *
     * @return int Nová (případně beze změny) hodnota counteru pro danou scope.
     */
    public function syncCounter(
        int $supplierId,
        string $invoiceType,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): int {
        $invoiceType = self::normalizeType($invoiceType);
        if ($supplierId <= 0 || !in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            return 0;
        }

        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        if ($template === '' || !$this->hasCounterPlaceholder($template)) {
            return 0;
        }

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);
        $highest   = $this->highestUsedCounter($supplierId, $template, $for);
        if ($highest <= 0) {
            return 0;
        }

        return $this->liftCounterTo($supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey, $highest);
    }

    /**
     * Explicitně nastaví counter supplier-wide řady tak, aby PŘÍŠTÍ vystavený doklad
     * dostal číslo $nextNumber (uloží last_number = $nextNumber - 1). Na rozdíl od
     * syncCounter()/liftCounterTo() umí counter i snížit — kolize s už vystavenými
     * čísly řeší samoopravná logika v next() (přeskočí na první volné číslo).
     *
     * Scope určují `$clientId` / `$revenueCategoryId` (obojí 0 = supplier-wide, dosavadní
     * chování). Šablonu i období si scope řeší SÁM přes resolveTemplateAndPeriod() —
     * jinak by náhled i kontrola „šablona má čítač" mluvily o jiné řadě, než kterou
     * uživatel nastavuje.
     *
     * Osa se nastavuje jen tam, kde řada skutečně VZNIKÁ, tedy kde je vlastní šablona.
     * Zdědí-li klient (nebo kategorie) šablonu dodavatele, žádnou vlastní řadu nemá —
     * sdílí počítadlo dodavatele a zápis na jeho vlastní řádek `invoice_counters` by
     * vyrobil počítadlo, ze kterého nikdy nikdo nečte. Takový požadavek proto padá
     * výjimkou, ne tichým zápisem jinam. Tatáž větev chytá i cizí `client_id` /
     * `revenue_category_id`: resolver je čte výhradně v rámci `supplier_id`, takže cizí
     * záznam nemá šablonu, osa nevyhraje a zápis se nekoná.
     *
     * @return array{counter:int, period:string, preview:string,
     *               client_id:int, revenue_category_id:int}
     * @throws \InvalidArgumentException neplatný vstup, chybějící template,
     *                                   template bez counter placeholderu
     *                                   nebo osa bez vlastní číselné řady
     */
    public function setCounter(
        int $supplierId,
        string $invoiceType,
        int $nextNumber,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }
        $invoiceType = self::normalizeType($invoiceType);
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Nepodporovaný typ pro varsymbol: {$invoiceType}");
        }
        if ($nextNumber < 1) {
            throw new \InvalidArgumentException('next_number musí být >= 1.');
        }
        // Klíč `invoice_counters` drží právě jednu vyhrávající osu (viz docblock třídy),
        // takže obě naráz by znamenalo řádek, který resolver nikdy nesestaví.
        if ($clientId > 0 && $revenueCategoryId > 0) {
            throw new \InvalidArgumentException(
                'client_id a revenue_category_id nelze kombinovat — řada patří vždy jedné ose.'
            );
        }

        $requestedType = $invoiceType;
        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        // Bez vlastní šablony zjednodušené doklady čerpají z řady faktur. Zápis by tiše
        // posunul počítadlo faktur, i když uživatel nastavoval jinou řadu.
        if ($requestedType === self::SIMPLIFIED && $invoiceType !== self::SIMPLIFIED) {
            throw new \InvalidArgumentException(
                'Zjednodušené daňové doklady nemají vlastní řadu, číslují se řadou faktur,'
                . ' takže počítadlo se nastavuje tam.'
            );
        }
        if ($clientId > 0 && $counterClientId !== $clientId) {
            throw new \InvalidArgumentException(
                "Klient #{$clientId} nemá vlastní šablonu pro {$invoiceType} — čísluje se řadou dodavatele,"
                . ' takže počítadlo se nastavuje tam.'
            );
        }
        if ($revenueCategoryId > 0 && $counterCategoryId !== $revenueCategoryId) {
            throw new \InvalidArgumentException(
                "Kategorie tržby #{$revenueCategoryId} nemá vlastní šablonu pro {$invoiceType} —"
                . ' čísluje se řadou dodavatele, takže počítadlo se nastavuje tam.'
            );
        }
        if ($template === '') {
            throw new \InvalidArgumentException(
                "Chybí template pro {$invoiceType}: nastav v Systém → Dodavatelé → Číslování faktur,"
                . " nebo doplň cfg.varsymbol.templates.{$invoiceType}."
            );
        }
        if (!$this->hasCounterPlaceholder($template)) {
            throw new \InvalidArgumentException(
                "Template '{$template}' neobsahuje counter placeholder ({C+}) — číslo je fixní, counter nemá smysl."
            );
        }

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        // Ruční začátek řady je záměrné přeskočení, ne díra. floor_number drží hodnotu,
        // pod kterou pojistka v next() počítadlo nesrovná (NumberSeriesGapGuard).
        if ($this->db->hasColumn('invoice_counters', 'floor_number')) {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number, floor_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_number = VALUES(last_number), floor_number = VALUES(floor_number)'
            );
            $stmt->execute([
                $supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey,
                $nextNumber - 1, $nextNumber - 1,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)'
            );
            $stmt->execute([
                $supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey, $nextNumber - 1,
            ]);
        }

        return [
            'counter'             => $nextNumber - 1,
            'period'              => $periodKey,
            'preview'             => $this->render($template, $for, $nextNumber),
            'client_id'           => $counterClientId,
            'revenue_category_id' => $counterCategoryId,
        ];
    }

    private function hasCounterPlaceholder(string $template): bool
    {
        return (bool) preg_match('/\{C+\}/', $template);
    }

    private function varsymbolExists(int $supplierId, string $varsymbol): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Nejvyšší counter mezi existujícími fakturami dodavatele, jejichž varsymbol odpovídá
     * danému template po dosazení data (rok/měsíc fixní → scope = stejné období jako counter).
     * Z čísla se zpětně vyparsuje hodnota counteru (skupina {C+}). 0 = žádná shoda.
     */
    private function highestUsedCounter(int $supplierId, string $template, \DateTimeInterface $for): int
    {
        [$regex, $likePrefix] = $this->buildCounterMatcher($template, $for);
        if ($regex === null) {
            return 0;
        }

        // LIKE prefix (literál před counterem) zúží sken; prázdný prefix → '%' (vše).
        $like = $this->escapeLike($likePrefix) . '%';
        $stmt = $this->db->pdo()->prepare(
            "SELECT varsymbol FROM invoices
              WHERE supplier_id = ? AND varsymbol IS NOT NULL AND varsymbol <> '' AND varsymbol LIKE ?"
        );
        $stmt->execute([$supplierId, $like]);

        $max = 0;
        while (($vs = $stmt->fetchColumn()) !== false) {
            if (preg_match($regex, (string) $vs, $m)) {
                $n = (int) $m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        return $max;
    }

    /**
     * Postaví regex pro zpětné vyparsování counteru z varsymbolu + literální prefix pro LIKE.
     * Datumové placeholdery se dosadí konkrétně (rok/měsíc daného období), {C+} → (\d+).
     *
     * @return array{0: ?string, 1: string}  [regex nebo null (template bez counteru), likePrefix]
     */
    private function buildCounterMatcher(string $template, \DateTimeInterface $for): array
    {
        if (!$this->hasCounterPlaceholder($template)) {
            return [null, ''];
        }

        $withDate = InvoiceNumberFormat::expandDateTokens($template, $for);

        // Označ counter sentinelem (mimo regex escaping), rozsekni a escapuj literály.
        $marked = preg_replace('/\{C+\}/', "\x00C\x00", $withDate) ?? $withDate;
        $parts  = explode("\x00C\x00", $marked);
        $escaped = array_map(static fn (string $p): string => preg_quote($p, '/'), $parts);
        $pattern = implode('(\d+)', $escaped);

        $likePrefix = $parts[0]; // literál před prvním counterem

        return ['/^' . $pattern . '$/', $likePrefix];
    }

    /** Escapuje znaky se zvláštním významem v LIKE (% _ \). */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Zvedne counter dané scope na minimálně $value (GREATEST) a vrátí výslednou hodnotu.
     * Nikdy nesnižuje.
     */
    private function liftCounterTo(
        int $supplierId,
        int $clientId,
        int $revenueCategoryId,
        string $invoiceType,
        string $periodKey,
        int $value,
    ): int {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
        );
        $stmt->execute([$supplierId, $clientId, $revenueCategoryId, $invoiceType, $periodKey, $value]);

        $sel = $pdo->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?'
        );
        $sel->execute([$supplierId, $clientId, $revenueCategoryId, $invoiceType, $periodKey]);
        return (int) $sel->fetchColumn();
    }

    /**
     * Vrátí, jaký bude další varsymbol BEZ inkrementu (pro náhled v UI).
     */
    public function preview(
        int $supplierId,
        string $invoiceType,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): string {
        if ($supplierId <= 0) return '';
        $invoiceType = self::normalizeType($invoiceType);
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) return '';

        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        if ($template === '') return '';

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);

        return $this->render($template, $for, $current + 1);
    }

    /**
     * Číslo, které skutečně dostane PŘÍŠTÍ vystavený doklad dané řady, bez zápisu
     * (pro pole „Příští číslo" v nastavení). Na rozdíl od preview() napodobuje celé
     * next(): srovnání počítadla dolů na max(nejvyšší použité, ruční začátek) jako
     * NumberSeriesGapGuard a přeskok obsazených čísel. Jinak by UI ukazovalo číslo,
     * které vystavení nakonec nepoužije.
     *
     * @return array{next_number:int, counter:int, period:string, preview:string,
     *               client_id:int, revenue_category_id:int}|null
     *         null = řada bez šablony nebo bez čítače ({C+})
     */
    public function peek(
        int $supplierId,
        string $invoiceType,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): ?array {
        if ($supplierId <= 0) return null;
        $invoiceType = self::normalizeType($invoiceType);
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) return null;

        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        if ($template === '' || !$this->hasCounterPlaceholder($template)) return null;

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $floorColumn = $this->db->hasColumn('invoice_counters', 'floor_number') ? 'floor_number' : '0';
        $stmt = $this->db->pdo()->prepare(
            "SELECT last_number, {$floorColumn} AS floor_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?"
        );
        $stmt->execute([$supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey]);
        $row     = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stored  = $row === false ? 0 : (int) $row['last_number'];
        $floor   = $row === false ? 0 : (int) $row['floor_number'];
        $highest = $this->highestUsedCounter($supplierId, $template, $for);

        $counter = $stored;
        if ($row !== false && $counter > $floor) {
            $counter = min($counter, max($highest, $floor));
        }

        $next = $counter + 1;
        if ($this->varsymbolExists($supplierId, $this->render($template, $for, $next)) && $highest >= $next) {
            $next = $highest + 1;
        }
        $attempts = 0;
        while ($this->varsymbolExists($supplierId, $this->render($template, $for, $next))) {
            if (++$attempts > self::MAX_SKIP) break;
            $next++;
        }

        return [
            'next_number'         => $next,
            'counter'             => $stored,
            'period'              => $periodKey,
            'preview'             => $this->render($template, $for, $next),
            'client_id'           => $counterClientId,
            'revenue_category_id' => $counterCategoryId,
        ];
    }

    /**
     * Pokud je daná faktura "poslední" ve své counter scope (její varsymbol odpovídá
     * aktuální hodnotě counteru), dekrementuj counter — to umožní, aby další vystavená
     * faktura ve stejné scope dostala stejné číslo.
     *
     * Volej PŘED vlastním DELETE z DB (potřebujeme issue_date a varsymbol). Idempotentní:
     * pokud counter neodpovídá (nepasuje render, byla manuálně přečíslovaná, mezitím
     * inkrementoval konkurenční zápis), nic neudělá.
     *
     * @return bool true pokud byl counter dekrementován
     */
    public function releaseIfLatest(
        int $supplierId,
        string $invoiceType,
        string $varsymbol,
        ?\DateTimeInterface $for = null,
        int $clientId = 0,
        int $revenueCategoryId = 0,
    ): bool {
        $invoiceType = self::normalizeType($invoiceType);
        if ($supplierId <= 0 || $varsymbol === '' || !in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        [$template, $period, $counterClientId, $counterCategoryId, $invoiceType] =
            $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId, $revenueCategoryId);
        if ($template === '') return false;

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);
        if ($current <= 0) return false;

        if ($this->render($template, $for, $current) !== $varsymbol) {
            return false;
        }

        // Pod ručně nastavený začátek řady (floor_number) se neuvolňuje — číslo pod ním
        // counter nikdy nevydal.
        $floorCondition = $this->db->hasColumn('invoice_counters', 'floor_number') ? ' AND last_number > floor_number' : '';
        $upd = $pdo->prepare(
            'UPDATE invoice_counters SET last_number = last_number - 1
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?
                AND last_number = ?' . $floorCondition
        );
        $upd->execute([$supplierId, $counterClientId, $counterCategoryId, $invoiceType, $periodKey, $current]);

        return $upd->rowCount() > 0;
    }

    public function render(string $template, \DateTimeInterface $date, int $counter): string
    {
        $rendered = InvoiceNumberFormat::expandDateTokens($template, $date);

        // Counter: matchuj sekvenci {CC...} pro variabilní padding ({C}, {CC}, {CCCCCC}, ...)
        $rendered = preg_replace_callback('/\{(C+)\}/', function ($m) use ($counter) {
            $len = strlen($m[1]);
            return str_pad((string) $counter, $len, '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;

        return $rendered;
    }

    /**
     * Vrátí [template, period, counterClientId, counterRevenueCategoryId].
     *
     * Poslední dvě hodnoty určují scope counteru — nastavená je vždy nejvýš JEDNA
     * z nich, podle toho, která úroveň dodala template:
     *   - vlastní template klienta   → [$clientId, 0]   (per-client counter)
     *   - vlastní template kategorie → [0, $categoryId] (per-kategorii counter)
     *   - dědí se ze supplieru/cfg   → [0, 0]           (supplier-wide counter)
     *
     * Tím supplier-wide řada zůstane konzistentní napříč klienty i kategoriemi, které
     * vlastní formát nemají, a obě specifičtější osy mají nezávislý counter.
     *
     * Pátá hodnota je typ řádku počítadla (`invoice_counters.invoice_type`). Liší se od
     * požadovaného typu jen u {@see self::SIMPLIFIED}: s vlastní šablonou firmy má řada
     * vlastní počítadlo a přednost před klientem i kategorií (pokladní prodej se čísluje
     * jednou řadou bez ohledu na to, komu se prodalo); bez ní se vyhodnotí přesně jako
     * faktura, takže dosavadní číslování zůstane beze změny.
     *
     * @return array{0: string, 1: string, 2: int, 3: int, 4: string}
     */
    private function resolveTemplateAndPeriod(int $supplierId, string $invoiceType, int $clientId, int $revenueCategoryId): array
    {
        $simplifiedColumn = $this->db->hasColumn('supplier', 'simplified_number_format')
            ? 'simplified_number_format' : 'NULL';
        $supStmt = $this->db->pdo()->prepare(
            "SELECT invoice_number_format, proforma_number_format, credit_note_number_format,
                    {$simplifiedColumn} AS simplified_number_format, invoice_number_period
               FROM supplier WHERE id = ? LIMIT 1"
        );
        $supStmt->execute([$supplierId]);
        $supRow = $supStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $supplierPeriod = (string) ($supRow['invoice_number_period'] ?? self::DEFAULT_PERIOD);

        if ($invoiceType === self::SIMPLIFIED) {
            $simplifiedTemplate = trim((string) ($supRow['simplified_number_format'] ?? ''));
            if ($simplifiedTemplate !== '') {
                return [$simplifiedTemplate, $this->normalizePeriod(null, $supplierPeriod), 0, 0, self::SIMPLIFIED];
            }
            $invoiceType = 'invoice';
        }

        $col = match ($invoiceType) {
            'invoice'     => 'invoice_number_format',
            'proforma'    => 'proforma_number_format',
            'credit_note' => 'credit_note_number_format',
        };

        $clientTemplate = '';
        $clientPeriod = null;
        if ($clientId > 0) {
            $cliStmt = $this->db->pdo()->prepare(
                "SELECT {$col} AS tpl, invoice_number_period AS period
                   FROM clients WHERE id = ? AND supplier_id = ? LIMIT 1"
            );
            $cliStmt->execute([$clientId, $supplierId]);
            $cliRow = $cliStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $clientTemplate = trim((string) ($cliRow['tpl'] ?? ''));
            $clientPeriod = $cliRow['period'] ?? null;
        }

        if ($clientTemplate !== '') {
            return [$clientTemplate, $this->normalizePeriod($clientPeriod, $supplierPeriod), $clientId, 0, $invoiceType];
        }

        // Kategorie tržby — druhá nejspecifičtější osa. Čte se VÝHRADNĚ v rámci
        // supplier_id (tenant izolace): cizí kategorie nesmí ovlivnit číslování.
        if ($revenueCategoryId > 0) {
            $catStmt = $this->db->pdo()->prepare(
                "SELECT {$col} AS tpl, invoice_number_period AS period
                   FROM revenue_categories WHERE id = ? AND supplier_id = ? LIMIT 1"
            );
            $catStmt->execute([$revenueCategoryId, $supplierId]);
            $catRow = $catStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $catTemplate = trim((string) ($catRow['tpl'] ?? ''));
            if ($catTemplate !== '') {
                return [$catTemplate, $this->normalizePeriod($catRow['period'] ?? null, $supplierPeriod), 0, $revenueCategoryId, $invoiceType];
            }
        }

        $supplierTemplate = trim((string) ($supRow[$col] ?? ''));
        $template = $supplierTemplate !== ''
            ? $supplierTemplate
            : (string) $this->config->get("varsymbol.templates.{$invoiceType}", '');

        return [$template, $this->normalizePeriod(null, $supplierPeriod), 0, 0, $invoiceType];
    }

    /** Override období, jinak supplier-level; nesmysl padá na legacy default 'month'. */
    private function normalizePeriod(mixed $override, string $supplierPeriod): string
    {
        $period = $override !== null ? (string) $override : $supplierPeriod;
        return in_array($period, self::VALID_PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * Klíč scope pro invoice_counters.period:
     *   year  → "2026"
     *   month → "202604"   (zpětně kompatibilní s legacy CHAR(6))
     *   none  → "ALL"      (jediný globální counter pro daný supplier+type)
     */
    private function makePeriodKey(string $period, \DateTimeInterface $for): string
    {
        return match ($period) {
            'year'  => $for->format('Y'),
            'none'  => 'ALL',
            default => $for->format('Ym'),
        };
    }

    private function incrementCounter(
        int $supplierId,
        int $clientId,
        int $revenueCategoryId,
        string $invoiceType,
        string $periodKey,
    ): int {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute([$supplierId, $clientId, $revenueCategoryId, $invoiceType, $periodKey]);

        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $clientId, $revenueCategoryId, $invoiceType, $periodKey]);
        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * JEDINÉ veřejné pravidlo „pro tohle datum chybí účetní období → založ ho otevřené".
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Chybějící období se poznalo až ve chvíli, kdy někdo klikl na „Zaúčtovat", a to
 * hláškou odkazující na sekci menu, která se tak nejmenuje. Typický spouštěč je
 * import historie: firma naimportuje doklady z let 2024–2026 (Pohoda, ISDOC),
 * ale účetnictví má aktivované od 1. 1. 2026 — a všechno starší je slepá ulička.
 * Totéž se stane na přelomu roku, kdy se zapomene otevřít nový rok.
 *
 * Pravidlo je proto na JEDNOM veřejně volatelném místě (AGENTS.md: „SSOT musí jít
 * ZAVOLAT"), aby si ho import ani účtování nekopírovaly jako privátní helper.
 *
 * CO SE SMÍ A CO NE
 * ------------------------------------------------------------------------------
 *  - Zakládá se VÝHRADNĚ období, které NEEXISTUJE. Existující řádek se nikdy nemění,
 *    takže se nelze dotknout stavu `closing`/`closed`/`approved` (§35 ZoÚ) — pokrývá-li
 *    datum uzavřené období, metoda ho jen vrátí a rozhodnutí nechá na volajícím
 *    (PostingService odmítne `period_not_open`).
 *  - Jen pro firmu, která podvojné účetnictví SKUTEČNĚ vede. V daňové evidenci
 *    účetní období nemá význam a zakládat ho na pozadí importu by byl tichý zásah
 *    do agendy, kterou uživatel nepoužívá.
 *  - Hranice se ODVOZUJÍ z existujících období ({@see FiscalCalendar::forPeriods}),
 *    ne natvrdo z kalendářního roku. Firma s hospodářským rokem (§21a ZDP) by jinak
 *    dostala 1. 1.–31. 12. a v řadě by vznikl překryv.
 *  - Rozsah roku je stejný jako u ručního založení přes API (2000–2200). Automat
 *    nesmí být přísnější než člověk, ale ani nesmí z překlepu v datu (rok 2999)
 *    udělat period řádek.
 *  - Idempotence a souběh: nejde o „SELECT, pak INSERT", ale o INSERT proti
 *    UNIQUE(supplier_id, fiscal_year) — dva souběžné importy tedy skončí jedním
 *    obdobím, druhý dostane to vítězné ({@see AccountingPeriodRepository::create}).
 *  - Vznik je dohledatelný: `accounting_periods.created_reason` (migrace 1733) říká,
 *    která cesta období založila, a `activity_log` k tomu drží kdo/kdy/jaké hranice.
 *
 * ODLIŠNOST OD {@see AccountingPeriodRepository::ensureOpenPeriodFor}
 * ------------------------------------------------------------------------------
 * `ensureOpenPeriodFor` zakládá kalendářní rok BEZ kontroly režimu firmy a používá ho
 * aktivační backfill (CashBackfill/DocumentBackfill/OpeningBalanceService). Ten běží
 * DŘÍV, než se firmě přepne `accounting_mode` na `double_entry` (viz konec
 * {@see \MyInvoice\Service\Accounting\Activation\BackfillService::run}), takže by
 * ho zdejší brána zablokovala uprostřed průvodce. Zůstává proto vedle sebe záměrně:
 * tam si rozsah odsouhlasil uživatel v průvodci, tady rozhoduje automat.
 */
final class AccountingPeriodProvisioner
{
    /** Účtování dokladu / storna (PostingService). */
    public const REASON_POSTING = 'posting';
    /** Import dokladů z jiného systému (Pohoda, ISDOC, API). */
    public const REASON_IMPORT = 'import';
    /** Kontrolní CLI nad existující instalací (`api/bin/check-accounting-periods.php`). */
    public const REASON_MAINTENANCE = 'maintenance';
    /** Založení firmy / zřízení instance (SetupAction) — nová instalace už období má. */
    public const REASON_SETUP = 'setup';

    /**
     * Rozsah roku shodný s ruční validací v
     * {@see \MyInvoice\Action\Accounting\AccountingPeriodAction::create} — automat
     * nesmí být přísnější než člověk ani povolnější k překlepu v datu dokladu.
     */
    private const MIN_FISCAL_YEAR = 2000;
    private const MAX_FISCAL_YEAR = 2200;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Vrátí účetní období pokrývající `$date` — existující, nebo nově založené jako
     * `open`. `null` znamená „nesmí se založit" (jiný účetní režim, nesmyslný rok,
     * překryv s existující řadou); volající pak hlásí `no_accounting_period`.
     *
     * @param self::REASON_* $reason proč se období zakládá (auditní stopa)
     * @return array<string,mixed>|null
     */
    public function ensureOpenPeriodForDate(int $supplierId, string $date, string $reason, ?int $userId = null): ?array
    {
        // Existující období se NIKDY nemění — vrací se i uzavřené, o zápisu do něj
        // rozhoduje volající (§35 ZoÚ).
        $existing = $this->periods->findForDate($supplierId, $date);
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->hasActiveDoubleEntry($supplierId)) {
            return null;
        }
        $bounds = $this->boundsForDate($supplierId, $date);
        if ($bounds === null) {
            return null;
        }
        // Překryv znamená nepravidelnou řadu (zkrácené první období, ruční hranice) —
        // dopočítané období by nad částí dat vytvořilo druhé. To je rozhodnutí účetní.
        if ($this->periods->overlapping($supplierId, $bounds['starts_on'], $bounds['ends_on']) !== null) {
            return null;
        }

        try {
            $periodId = $this->periods->create(
                $supplierId,
                $bounds['fiscal_year'],
                $bounds['starts_on'],
                $bounds['ends_on'],
                $reason,
            );
        } catch (\PDOException $e) {
            // create() si duplicitu UNIQUE(supplier, fiscal_year) řeší samo, ale dohledává
            // vítěze NEZAMYKAJÍCÍM čtením: uvnitř transakce v REPEATABLE READ (a tam tohle
            // běží při účtování) vidí snapshot z jejího začátku, kde čerstvě commitnutý
            // řádek souběžného importu ještě není → výjimka propadne sem. FOR UPDATE čte
            // poslední commitnutou verzi, takže vítěze najde.
            $raced = $this->periods->findForDateForUpdate($supplierId, $date);
            if ($raced === null) {
                throw $e;
            }
            return $raced;
        }
        $created = $this->periods->findForDate($supplierId, $date);
        if ($created === null) {
            // Souběžný request stihl založit týž rok s JINÝMI hranicemi, které datum
            // nepokrývají. Nezakládat druhé — ať to rozhodne člověk.
            return null;
        }

        $this->activity->log(
            'accounting.period_auto_opened',
            $userId,
            'accounting_period',
            $periodId,
            [
                'reason'      => $reason,
                'for_date'    => $date,
                'fiscal_year' => $bounds['fiscal_year'],
                'starts_on'   => $bounds['starts_on'],
                'ends_on'     => $bounds['ends_on'],
            ],
            supplierId: $supplierId,
        );
        return $created;
    }

    /**
     * Totéž pro celý ROZSAH dat — jeden průchod přes období, která rozsah protíná.
     *
     * Tohle je vstupní bod pro IMPORT: dávka historických dokladů z jiného systému má
     * jeden rozsah („od nejstaršího po nejnovější doklad", {@see \MyInvoice\Service\Import\ImportDateSpan}),
     * takže se období řeší JEDNOU za běh, ne u každého dokladu zvlášť. Neprojde-li
     * některý rok (nepravidelná řada, rok mimo rozsah), zbytek se tím nezastaví —
     * vrací se jen to, co se povedlo, a na zbytek narazí uživatel u „Zaúčtovat"
     * s hláškou, která ho pošle na Uzávěrku.
     *
     * @param self::REASON_* $reason
     * @return list<array<string,mixed>> období pokrývající rozsah (existující i nová)
     */
    public function ensureOpenPeriodsForRange(int $supplierId, string $from, string $to, string $reason, ?int $userId = null): array
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $out = [];
        $cursor = $from;
        // Strop průchodů: rozsah přes víc než sto období je poškozený vstup, ne migrace.
        for ($i = 0; $i < 100 && $cursor <= $to; $i++) {
            $period = $this->ensureOpenPeriodForDate($supplierId, $cursor, $reason, $userId);
            if ($period === null) {
                // Tenhle rok se založit nedá — přeskoč na další kalendářní rok, ať
                // jedna díra nezastaví zbytek dávky.
                $cursor = (string) ((int) substr($cursor, 0, 4) + 1) . substr($cursor, 4);
                continue;
            }
            $out[] = $period;
            $cursor = (new \DateTimeImmutable((string) $period['ends_on']))->modify('+1 day')->format('Y-m-d');
        }
        return $out;
    }

    /**
     * Hranice období, do kterého datum spadá — dle TVARU existujících období
     * (kalendářní vs. hospodářský rok). Bez období se použije kalendářní rok.
     *
     * @return array{fiscal_year:int, starts_on:string, ends_on:string}|null
     */
    public function boundsForDate(int $supplierId, string $date): ?array
    {
        $calendar = FiscalCalendar::forPeriods($this->periods->listForTenant($supplierId));
        $fiscalYear = $calendar->fiscalYearOfDate($date);
        if ($fiscalYear < self::MIN_FISCAL_YEAR || $fiscalYear > self::MAX_FISCAL_YEAR) {
            return null;
        }
        return [
            'fiscal_year' => $fiscalYear,
            'starts_on'   => $calendar->periodStart($fiscalYear),
            'ends_on'     => $calendar->periodEnd($fiscalYear),
        ];
    }

    /**
     * Vede firma podvojné účetnictví a má ho zapnuté? `accounting_enabled` je
     * samostatný přepínač („Vést účetnictví") — vypnutá nadstavba znamená, že
     * uživatel deník nechce, takže mu ho automat nezaloží ani nepřímo.
     */
    private function hasActiveDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM supplier
              WHERE id = ? AND accounting_mode = 'double_entry' AND accounting_enabled = 1"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Accounting\FiscalCalendar;

/**
 * Stav jednoho běhu převodu, který si kroky předávají: cílová firma, záloha, volby,
 * protokol a mapy vzniklé v předchozích krocích (účty, období, doklady).
 */
final class ImportContext
{
    /** @var array<string,int> kód účtu v MyÚčtu (`221.001`) => chart_of_accounts.id */
    public array $accountIds = [];

    /** @var array<int,array{account:?string,candidates:list<string>,source:string}> */
    public array $advanceAccounts = [];

    /** @var array<string,int> adresář ROK.nnn => účetní rok */
    public array $dirYears = [];

    /** @var array<int,array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}> rok => období */
    public array $periods = [];

    /**
     * Rok po posledním převáděném, ze kterého se převezmou jen počáteční stavy (volba
     * {@see ImportOptions::$openingOnlyNext}): položka rozvrhu deníku a období, které pro
     * něj krok deníku zajistil. Do převáděných období (`$periods`) nepatří: doklady, banka
     * ani úhrady z něj do MyÚčta nepřijdou, uzávěrka ho bere jen jako další rok.
     *
     * @var array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool}|null
     */
    public ?array $openingOnly = null;

    /** @var array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}|null */
    public ?array $openingOnlyPeriod = null;

    /**
     * Počáteční stavy roku jen s počátečními stavy (XP) z AKTUÁLNÍ zálohy: kód účtu => MD − D,
     * bez účtů třídy 7. Uzávěrka posledního převáděného roku je porovná s otevíracím zápisem
     * roku v MyÚčtu, který převod ponechal (převzatý dřív z Money nebo založený v MyÚčtu).
     * Null = adresář roku počáteční stavy nemá.
     *
     * @var array<string,float>|null
     */
    public ?array $openingOnlyBalances = null;

    /**
     * Adresáře zálohy z let před prvním převáděným rokem (volba „Převést od roku"): adresář
     * => účetní rok. Převod z nich bere jen doklady otevřené k začátku prvního převáděného
     * období ({@see OpeningDocuments}), jako doklady počátečního stavu.
     *
     * @var array<string,int>
     */
    public array $openingDocumentDirs = [];

    /**
     * Doklady počátečního stavu ({@see OpeningDocuments}) vybrané k převodu: 'issued'|'purchase'
     * => "adresář|číslo dokladu" => rok. Plní je krok dokladů po ověření proti počátečním stavům.
     *
     * @var array{issued:array<string,int>,purchase:array<string,int>}|null
     */
    public ?array $openingCandidates = null;

    /** @var array<string,int> doklady počátečního stavu: "rok|číslo dokladu" => invoices.id */
    public array $openingIssued = [];

    /** @var array<string,int> doklady počátečního stavu: "rok|číslo dokladu" => purchase_invoices.id */
    public array $openingPurchases = [];

    /** @var array<string,int> "rok|číslo dokladu" => id */
    public array $purchaseInvoices = [];

    /** @var array<string,int> */
    public array $issuedInvoices = [];

    /** @var array<string,int> */
    public array $cashDocuments = [];

    /** @var array<string,int> */
    public array $bankTransactions = [];

    /** @var array<int,float> id pohybu v cizí měně => částka v Kč z Money (párování úhrad s fakturami v Kč) */
    public array $bankTransactionCzk = [];

    /** @var array<string,int> "rok|číslo dokladu" ostatní pohledávky (kniha KP) => invoices.id */
    public array $otherReceivables = [];

    /** @var array<int,int> číslo adresy v Money (`AdresarF.Cislo`) => clients.id */
    public array $clientsByMoneyNo = [];

    /** @var array<string,int> IČO => clients.id */
    public array $clientsByIco = [];

    /** @var list<int> klienti převedených vydaných faktur (přepočet statistik po převodu) */
    public array $statsClients = [];

    /** @var array<int,int> rok => počet uzávěrkových řádků deníku Money (`XZ`), jen roky, jejichž deník převod četl */
    public array $yearEndClosingRows = [];

    /** @var array<string,true>|null "rok|zdroj|číslo dokladu" dokladů, které má deník Money (líně) */
    public ?array $journalDocuments = null;

    /**
     * Převod agendy vedené v Money jako daňová evidence ({@see TaxRecordsImporter}): bez
     * deníku, osnovy a účetních období, firma zůstává v daňové evidenci.
     */
    public bool $taxRecords = false;

    /** @var array<string,Ms3CashBook> adresář ROK.nnn => peněžní deník roku (jen převod daňové evidence) */
    public array $cashBooks = [];

    /**
     * Doklady peněžního deníku převzaté do MyÚčta: 'bank'|'cash' => "adresář|číslo dokladu
     * Money" ({@see Ms3CashBook::key()} bez zdroje) => id pohybu nebo pokladního dokladu.
     *
     * @var array{bank:array<string,int>,cash:array<string,int>}
     */
    public array $movements = ['bank' => [], 'cash' => []];

    /**
     * Zámek dokladů firmy (`locked_until`) — u daňové evidence hranice uzavřené historie,
     * u podvojného účetnictví zámek účtování k datu ({@see writable()}).
     */
    public ?string $lockedUntil = null;

    public ?int $runId = null;

    /**
     * Zápisy deníku, které Money změnilo, ale převod je kvůli zásahu uživatele ponechal
     * ({@see JournalImporter}); doklad navázaný na takový zápis se nepřepisuje.
     *
     * @var list<int>
     */
    public array $keptEntries = [];

    /** @var list<int> vydané doklady, které převod aktualizoval podle Money (zahození uloženého PDF po převodu) */
    public array $refreshedInvoices = [];

    /** @var (callable(string,int,int):void)|null */
    public $progress = null;

    /**
     * Kalendář účetního roku cílové firmy (nastavení „Účetní rok začíná", jinak tvar jejích
     * období). Rok v mapách převodu je label jejího období = rok začátku období.
     */
    public FiscalCalendar $calendar;

    public function __construct(
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly Ms3Backup $backup,
        public readonly AgendaInfo $agenda,
        public readonly ImportOptions $options,
        public readonly ImportProtocol $protocol,
        ?FiscalCalendar $calendar = null,
    ) {
        $this->calendar = $calendar ?? FiscalCalendar::calendar();
    }

    public function report(string $step, int $done, int $total): void
    {
        if ($this->progress !== null) {
            ($this->progress)($step, $done, $total);
        }
    }

    /** Rok adresáře, ze kterého řádek pochází (`__dir` z {@see Ms3Backup::rowsAcrossYears()}). */
    public function yearOf(array $row): ?int
    {
        return $this->dirYears[(string) ($row['__dir'] ?? '')] ?? null;
    }

    /**
     * Opravný převod vazeb ({@see ImportOptions::$relinkOnly}) nový záznam nezakládá;
     * započítá ho v protokolu kroku jako vynechaný.
     */
    public function skipsNew(string $step): bool
    {
        if (!$this->options->relinkOnly) {
            return false;
        }
        $this->protocol->count($step, 'relink_skipped');
        return true;
    }

    /**
     * Rok, jehož knihy už jsou v MyÚčtu uzavřené — doklady do něj už nepřibývají. Daňová
     * evidence účetní období nemá: uzavřený je rok, který celý leží pod zámkem dokladů.
     */
    public function isLocked(int $year): bool
    {
        if ($this->taxRecords) {
            return $this->lockedUntil !== null && sprintf('%04d-12-31', $year) <= $this->lockedUntil;
        }
        return (bool) ($this->periods[$year]['locked'] ?? false);
    }

    /**
     * Konec posledního převáděného období (rozvrh deníku, u daňové evidence konec posledního
     * roku). Úhrada z Money s pozdějším datem patří roku, který se nepřevádí - doklad ji
     * nedostane ({@see paidWithin()}). Null = rozvrh ještě není (nic se neomezuje).
     */
    public function lastPeriodEnd(): ?string
    {
        if ($this->periods !== []) {
            return max(array_column($this->periods, 'ends_on'));
        }
        return $this->dirYears !== [] ? sprintf('%04d-12-31', max($this->dirYears)) : null;
    }

    /**
     * Datum úhrady z Money (`Uhrazeno`), pokud padne do převáděných období; pozdější úhrada
     * (faktura 2026 zaplacená v lednu 2027) do převodu nepatří a doklad zůstane neuhrazený.
     */
    public function paidWithin(?string $paidAt): ?string
    {
        $end = $this->lastPeriodEnd();
        return $paidAt === null || ($end !== null && $paidAt > $end) ? null : $paidAt;
    }

    /**
     * Smí převod k datu zapisovat a měnit vazby: datum leží v převáděném období, které je
     * v MyÚčtu otevřené, a za zámkem účtování k datu (`locked_until`). Uzavřené roky a data
     * pod zámkem mění jen opravný převod vazeb ({@see ImportOptions::$relinkOnly}), a to
     * jen vazby, ne deník.
     */
    public function writable(?string $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $date = substr($date, 0, 10);
        if ($this->lockedUntil !== null && $date <= $this->lockedUntil) {
            return false;
        }
        foreach ($this->periods as $period) {
            if ($date >= $period['starts_on'] && $date <= $period['ends_on']) {
                return !$period['locked'];
            }
        }
        return false;
    }

    /** Rok rozvrhu, do kterého převod zapisuje: období je otevřené a zámek k datu ho nezasahuje. */
    public function yearWritable(int $year): bool
    {
        $period = $this->periods[$year] ?? null;
        return $period !== null && !$period['locked'] && ($this->lockedUntil === null || $this->lockedUntil < $period['starts_on']);
    }
}

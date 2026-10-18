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

    /** @var array<string,int> adresář ROK.nnn => účetní rok */
    public array $dirYears = [];

    /** @var array<int,array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}> rok => období */
    public array $periods = [];

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

    /** Zámek dokladů firmy (`locked_until`) — u daňové evidence hranice uzavřené historie. */
    public ?string $lockedUntil = null;

    public ?int $runId = null;

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
}

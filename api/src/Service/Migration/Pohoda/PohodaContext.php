<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Stav jednoho běhu převodu, který si kroky předávají: cílová firma, export, protokol
 * a mapy vzniklé v předchozích krocích.
 *
 * Doklady se v mapách drží pod číslem dokladu z Pohody - v rámci agendy (roku) je
 * jednoznačné a právě pod ním je vede i deník (`act:number`).
 */
final class PohodaContext
{
    /** @var array<string,int> kód účtu v MyÚčtu (`221.001`) => chart_of_accounts.id */
    public array $accountIds = [];

    /** @var array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}|null */
    public ?array $period = null;

    /**
     * Účetní období, do kterých převod zapisuje: rok agendy a roky po něm, do kterých
     * padají doklady agendy (`null` = rok zůstal v období agendy, viz {@see ChartJournalImporter}).
     *
     * @var array<int,array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}|null>
     */
    public array $periods = [];

    /** @var array<string,int> id bankovního dokladu v Pohodě => bank_transactions.id */
    public array $bankByPohodaId = [];

    /** @var array<int,list<string>> bank_transactions.id => párovací symboly pohybu a jeho položek z Pohody */
    public array $bankSymbols = [];

    /** @var array<string,float> „typ|id“ dokladu => zbývá uhradit podle Pohody (Kč) */
    public array $remaining = [];

    /** @var array<string,string> kód střediska z nově převedených řádků deníku => název */
    public array $costCenters = [];

    /**
     * Dorovnání novější zálohou: datum prvního zápisu zadaného v MyÚčtu. Od něj se účtuje
     * v MyÚčtu, záznamy POHODY s tímto nebo pozdějším datem převod nepřebírá (nevznikne dvojí zápis).
     */
    public ?string $ownJournalFrom = null;

    /** Řádky deníku, kterým převod hlavní činnost nastavil už při zápisu (firma 504 z dřívějšího převodu). */
    public int $nonprofitLines = 0;

    /** @var array<int,float> other_items.id => uhrazeno podle Pohody (Kč) bez úhrad v nepřevedených rocích */
    public array $otherItemPaid = [];

    /** @var array<string,int> id adresy v Pohodě => clients.id */
    public array $clientsByPohodaId = [];

    /** @var array<string,int> IČO => clients.id */
    public array $clientsByIco = [];

    /** @var array<string,int> číslo dokladu => invoices.id */
    public array $issuedInvoices = [];

    /** @var array<string,int> číslo ostatní pohledávky => invoices.id */
    public array $receivables = [];

    /** @var array<string,int> číslo dokladu => purchase_invoices.id */
    public array $purchaseInvoices = [];

    /** @var array<string,int> číslo ostatního závazku => purchase_invoices.id */
    public array $commitments = [];

    /** @var array<string,int> číslo interního dokladu (daňový doklad k přijaté platbě) => invoices.id */
    public array $internalSales = [];

    /** @var array<string,int> číslo interního dokladu (daňový doklad k uhrazené záloze) => purchase_invoices.id */
    public array $internalPurchases = [];

    /** @var array<string,int> číslo dokladu => cash_documents.id */
    public array $cashDocuments = [];

    /** @var array<string,list<array{id:int,date:string}>> číslo pohybu => bank_transactions */
    public array $bankTransactions = [];

    /**
     * Úhrady dokladů z `liquidations` - kterým bankovním nebo pokladním dokladem Pohody
     * byl doklad uhrazen. Páruje je {@see DocumentLinker::matchPayments()}.
     *
     * @var list<array{doc:string,id:int,number:string,agenda:string,source:string,date:?string,amount:float,liq:string}>
     */
    public array $liquidations = [];

    /** @var array<string,true> id převedených dokladů minulého období („typ|id“) - nemají zápis v deníku roku */
    public array $previousPeriod = [];

    /** @var array<int,true> pokladní doklady bez zaúčtování k začátku období - jsou v počátečních stavech */
    public array $openingCash = [];

    /** @var array<string,string> číslo pokladního dokladu => účet pokladny z deníku (`211.001`) */
    public array $cashAccountsByNumber = [];

    /** @var list<int> klienti převedených vydaných faktur (přepočet statistik) */
    public array $statsClients = [];

    /**
     * Roky po roce agendy, které uživatel v průvodci nevybral: zápisy, pohyby, doklady
     * a úhrady s datem v nich se přeskočí. Opakovaný převod s vybraným rokem je doplní.
     *
     * @var array<int,true>
     */
    public array $skippedYears = [];

    /**
     * Převod skladu zvolený v průvodci: volba po skladech POHODY (kód skladu => `goods`,
     * `material` nebo `skip`) pro karty bez druhu zásoby. `null` = sklad se nepřevádí.
     *
     * @var array{warehouses:array<string,string>}|null
     */
    public ?array $stock = null;

    public ?int $runId = null;

    /**
     * Opravný převod vazeb: firma už je převedená a v MyÚčtu se mezitím účtuje. Převod
     * projde export, ale nic nového nezaloží (zápis, doklad, pohyb, partnera, kartu) - jen
     * u převzatých záznamů znovu dopočítá úhrady, zápočty, zálohy a kurzové rozdíly.
     * Novější doklady z POHODY by se jinak zdvojily s tím, co uživatel zadal v MyÚčtu.
     */
    public bool $relinkOnly = false;

    /** @var (callable(string,int,int):void)|null */
    public $progress = null;

    /**
     * Kalendář účetního roku firmy a období agendy v něm ({@see PohodaAgendaYear}). Bez
     * něj (kroky volané samostatně) kalendářní rok s rokem agendy ze složky exportu.
     */
    public FiscalCalendar $calendar;

    public ?PohodaAgendaYear $agendaYear = null;

    public function __construct(
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly PohodaExport $export,
        public readonly PohodaVat $vat,
        public readonly bool $dryRun,
        public readonly ImportProtocol $protocol,
    ) {
        $this->calendar = FiscalCalendar::calendar();
    }

    public function useAgendaYear(PohodaAgendaYear $agendaYear): void
    {
        $this->agendaYear = $agendaYear;
        $this->calendar = $agendaYear->calendar;
    }

    /** Label účetního období firmy, do kterého agenda patří (u kalendářní firmy rok agendy). */
    public function year(): int
    {
        return $this->agendaYear?->label ?? $this->export->year;
    }

    /** První den období agendy. */
    public function periodStart(): string
    {
        return (string) ($this->period['starts_on'] ?? $this->calendar->periodStart($this->year()));
    }

    /** Poslední den období agendy. */
    public function periodEnd(): string
    {
        return (string) ($this->period['ends_on'] ?? $this->calendar->periodEnd($this->year()));
    }

    /** Datum padá do období po období agendy, které se nepřevádí ({@see $skippedYears}). */
    public function skipsDate(?string $date): bool
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return false;
        }
        if ($this->ownJournalFrom !== null && substr($date, 0, 10) >= $this->ownJournalFrom) {
            return true;
        }
        if ($this->skippedYears === []) {
            return false;
        }
        $year = $this->calendar->fiscalYearOfDate(substr($date, 0, 10));
        return $year > $this->year() && isset($this->skippedYears[$year]);
    }

    /** @return list<int> */
    public function skippedYearList(): array
    {
        $years = array_keys($this->skippedYears);
        sort($years);
        return $years;
    }

    /** @return list<int> id všech období, do kterých převod zapisuje */
    public function periodIds(): array
    {
        $ids = [];
        foreach ($this->periods as $p) {
            if ($p !== null) {
                $ids[] = (int) $p['id'];
            }
        }
        if ($ids === [] && $this->period !== null) {
            $ids[] = (int) $this->period['id'];
        }
        return array_values(array_unique($ids));
    }

    /** Poslední den posledního období, do kterého převod zapisuje. */
    public function lastPeriodEnd(): ?string
    {
        $end = $this->period['ends_on'] ?? null;
        foreach ($this->periods as $p) {
            if ($p !== null && ($end === null || $p['ends_on'] > $end)) {
                $end = $p['ends_on'];
            }
        }
        return $end;
    }

    public function report(string $step, int $done, int $total): void
    {
        if ($this->progress !== null) {
            ($this->progress)($step, $done, $total);
        }
    }

    /** Opravný převod nový záznam nezakládá; započítá ho v protokolu kroku jako vynechaný. */
    public function skipsNew(string $step): bool
    {
        if (!$this->relinkOnly) {
            return false;
        }
        $this->protocol->count($step, 'relink_skipped');
        return true;
    }

    public function userOrNull(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Stav jednoho běhu převodu roku, který si kroky předávají: cílová firma, záloha,
 * protokol a mapy vzniklé v předchozích krocích.
 */
final class PremierContext
{
    /** @var array<string,int> kód účtu v MyÚčtu (`518.100`) => chart_of_accounts.id */
    public array $accountIds = [];

    /** @var array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}|null */
    public ?array $period = null;

    /** @var array<string,float> počáteční stavy roku (kód PREMIER → zůstatek MD+) */
    public array $opening = [];

    /** @var array<string,int> číslo partnera v PREMIER (`PARTNERY.CISLO`) => clients.id */
    public array $clientsByNumber = [];

    /** @var array<string,int> ID partnera v PREMIER (`PARTNERY.ID`) => clients.id */
    public array $clientsById = [];

    /** @var array<string,int> IČO => clients.id */
    public array $clientsByIco = [];

    /** @var array<int,int> FA_OUT.INTER => invoices.id */
    public array $issuedInvoices = [];

    /** @var array<int,int> FA_IN.INTER => purchase_invoices.id */
    public array $purchaseInvoices = [];

    /**
     * Úhrady vydaných faktur roku po řádcích deníku v Kč ({@see PremierDocuments::settlement()}),
     * podle nich vznikají evidované platby ({@see DocumentLinker::recordIssuedPayments()}).
     *
     * @var array<int,list<array{inter:int,date:string,amount:float}>> invoices.id => řádky úhrad
     */
    public array $issuedPaymentRows = [];

    /** @var array<string,array{table:string,id:int}> klíč dokladu deníku ({@see PremierJournal::groups()}) => doklad s DPH mimo faktury */
    public array $vatDocuments = [];

    /** @var array<string,int> klíč dokladu deníku ({@see PremierJournal::groups()}) => cash_documents.id */
    public array $cashDocuments = [];

    /** @var array<int,int> PUB_UCTO.INTER řádku na bankovním účtu => bank_transactions.id */
    public array $bankTransactions = [];

    /** @var array<string,int> klíč zápisu ({@see PremierJournal::documents()}) => journal_entries.id */
    public array $entries = [];

    /** @var array<string,true> „typ|id" převedených dokladů minulého období - zápis mají v deníku jiného roku */
    public array $previousPeriod = [];

    /** @var list<int> klienti převedených vydaných faktur (přepočet statistik) */
    public array $statsClients = [];

    /** Evidence drobného majetku a účty, ze kterých se odvozuje ({@see PremierSmallAssets}). */
    public ?PremierSmallAssets $smallAssets = null;

    /** Zaměstnanci a zpracované mzdy zálohy ({@see PremierPayroll}). */
    public ?PremierPayroll $payroll = null;

    public ?int $runId = null;

    /** @var (callable(string,int,int):void)|null */
    public $progress = null;

    public function __construct(
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly PremierBackup $backup,
        public readonly int $year,
        public readonly PremierJournal $journal,
        public readonly PremierVat $vat,
        public readonly bool $dryRun,
        public readonly ImportProtocol $protocol,
        /** Účetní rok firmy; `$year` je label jejího období. */
        public readonly FiscalCalendar $calendar = new FiscalCalendar(),
    ) {}

    public function report(string $step, int $done, int $total): void
    {
        if ($this->progress !== null) {
            ($this->progress)($step, $done, $total);
        }
    }

    public function userOrNull(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }

    public function startsOn(): string
    {
        return $this->period['starts_on'] ?? $this->calendar->periodStart($this->year);
    }

    public function endsOn(): string
    {
        return $this->period['ends_on'] ?? $this->calendar->periodEnd($this->year);
    }
}

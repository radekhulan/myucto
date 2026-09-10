<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

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

    /** @var array<int,int> číslo adresy v Money (`AdresarF.Cislo`) => clients.id */
    public array $clientsByMoneyNo = [];

    /** @var array<string,int> IČO => clients.id */
    public array $clientsByIco = [];

    /** @var list<int> klienti převedených vydaných faktur (přepočet statistik po převodu) */
    public array $statsClients = [];

    public ?int $runId = null;

    /** @var (callable(string,int,int):void)|null */
    public $progress = null;

    public function __construct(
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly Ms3Backup $backup,
        public readonly AgendaInfo $agenda,
        public readonly ImportOptions $options,
        public readonly ImportProtocol $protocol,
    ) {}

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

    /** Rok, jehož knihy už jsou v MyÚčtu uzavřené — doklady do něj už nepřibývají. */
    public function isLocked(int $year): bool
    {
        return (bool) ($this->periods[$year]['locked'] ?? false);
    }
}

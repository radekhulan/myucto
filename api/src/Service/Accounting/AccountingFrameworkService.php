<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingFrameworkRepository;

/**
 * Účetní rámec firmy k datu. Rámec je nezávislý na způsobu evidence
 * tax_evidence/double_entry a nesmí se odvozovat z aktuálního nastavení firmy.
 */
final class AccountingFrameworkService
{
    public const CZ_500 = 'cz_500';
    public const CZ_504 = 'cz_504';
    public const CONVERSION_REQUIRED = 'accounting_framework_conversion_required';
    public const CONVERSION_ROUTE = '/accounting/framework-conversion';

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingFrameworkRepository $frameworks,
    ) {}

    /** @return self::CZ_* */
    public function forDate(int $supplierId, string $date): string
    {
        $this->assertDate($date);
        return $this->frameworks->forDate($supplierId, $date) ?? self::CZ_500;
    }

    /** @param list<int> $supplierIds @return array<int,self::CZ_*> */
    public function forDateForSuppliers(array $supplierIds, string $date): array
    {
        $this->assertDate($date);
        $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds), static fn (int $id): bool => $id > 0)));
        if ($supplierIds === []) return [];
        return array_replace(
            array_fill_keys($supplierIds, self::CZ_500),
            $this->frameworks->forDateForSuppliers($supplierIds, $date),
        );
    }

    public function forDateForUpdate(int $supplierId, string $date): string
    {
        $this->assertDate($date);
        return $this->frameworks->forDateForUpdate($supplierId, $date) ?? self::CZ_500;
    }

    public function forInterval(int $supplierId, string $from, string $to, bool $currentRead = false): array
    {
        $this->assertDate($from);
        $this->assertDate($to);
        if ($to < $from) {
            throw new \InvalidArgumentException('Konec intervalu nesmí předcházet začátku.');
        }
        $out = [$from => $currentRead ? $this->forDateForUpdate($supplierId, $from) : $this->forDate($supplierId, $from)];
        if ($currentRead) {
            $cursor = $from;
            while (($next = $this->frameworks->nextChangeForUpdate($supplierId, $cursor)) !== null && $next <= $to) {
                $out[$next] = $this->forDateForUpdate($supplierId, $next);
                $cursor = $next;
            }
        } else {
            foreach ($this->history($supplierId) as $row) {
                if ($row['effective_from'] > $from && $row['effective_from'] <= $to) {
                    $out[$row['effective_from']] = $row['accounting_framework'];
                }
            }
        }
        return $out;
    }

    /** @return list<array{id:int,effective_from:string,accounting_framework:string,created_at:string,created_by:?int}> */
    public function history(int $supplierId): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'effective_from' => $row['effective_from'],
            'accounting_framework' => $row['accounting_framework'],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'],
        ], $this->frameworks->listForTenant($supplierId));
    }

    /**
     * Zapíše výchozí rámec nové firmy uvnitř transakce jejího založení.
     * Existující historii nikdy nepřepisuje.
     */
    public function initializeCz500Baseline(int $supplierId, ?int $userId = null): bool
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Inicializace účetního rámce vyžaduje transakci založení firmy.');
        }
        if ($this->frameworks->listForTenant($supplierId) !== []) {
            return false;
        }

        $this->recordFramework($supplierId, '1900-01-01', self::CZ_500, $userId);
        return true;
    }

    /**
     * Nová firma s právní formou, pro kterou je určena vyhláška 504: výchozí rámec
     * se přepíše na 504 a nedotčená šablona 500 se vymění za šablonu 504.
     * Firmu s jinou historií rámce, zápisy nebo použitou osnovou nechá být.
     *
     * @return bool true, pokud firma přešla na rámec 504
     */
    public function adoptCz504BaselineForNewSupplier(int $supplierId, ?int $userId = null): bool
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT accounting_framework_change');
        }
        try {
            $history = $this->frameworks->listForTenantForUpdate($supplierId);
            $baselineOnly = $history === []
                || (count($history) === 1
                    && $history[0]['effective_from'] === '1900-01-01'
                    && $history[0]['accounting_framework'] === self::CZ_500);
            $pristine = new PristineChart500($this->db);
            $chartEmpty = !$this->frameworks->hasAccountingHistoryForUpdate($supplierId);
            if (!$baselineOnly || (!$chartEmpty && !$pristine->isPristineForUpdate($supplierId))) {
                $this->finishTransaction($ownsTransaction);
                return false;
            }
            $this->recordFramework($supplierId, '1900-01-01', self::CZ_504, $userId);
            if (!$chartEmpty) {
                $pristine->replaceWithFrameworkTemplate($supplierId, date('Y-m-d'));
            }
            $this->finishTransaction($ownsTransaction);
            return true;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT accounting_framework_change');
                $pdo->exec('RELEASE SAVEPOINT accounting_framework_change');
            }
            throw $e;
        }
    }

    /**
     * Změní rámec od prvního dne existujícího účetního období.
     *
     * Změna ovlivňuje interval od effectiveFrom do další uložené změny. Konzervativně zamyká chronologicky
     * všechna období od effectiveFrom dál, aby zachovalo pořadí období -> deník,
     * které používá PostingService, i když hranici intervalu čte až pod zámkem.
     *
     * @return bool true, pokud vznikla nebo se změnila historická položka
     */
    public function changeFromPeriodStart(
        int $supplierId,
        string $effectiveFrom,
        string $framework,
        ?int $userId = null,
    ): bool {
        return $this->change($supplierId, $effectiveFrom, $framework, $userId, false);
    }

    /**
     * Přímé přepnutí rámce z Nastavení. Přechod 500 → 504 smí jen firma bez
     * účetní historie (žádný zápis v deníku, žádná účtová osnova). Firma
     * s historií musí projít řízeným převodem {@see Conversion\FrameworkConversionWorkflow},
     * který převede zůstatky a osnovu; prosté přepnutí by výkazy rozbilo
     * a převod by pak už nešel spustit (zdroj musí být v rámci 500).
     */
    public function changeFromSettings(
        int $supplierId,
        string $effectiveFrom,
        string $framework,
        ?int $userId = null,
    ): bool {
        return $this->change($supplierId, $effectiveFrom, $framework, $userId, true);
    }

    private function change(
        int $supplierId,
        string $effectiveFrom,
        string $framework,
        ?int $userId,
        bool $fromSettings,
    ): bool {
        $this->assertDate($effectiveFrom);
        if (!in_array($framework, [self::CZ_500, self::CZ_504], true)) {
            throw new AccountingFrameworkException(
                'invalid_accounting_framework',
                'Neznámý účetní rámec.',
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT accounting_framework_change');
        }

        try {
            // První zámek musí být období. INSERT journal_entries i jeho řádků bere
            // kvůli FK sdílený zámek supplier; supplier FOR UPDATE před obdobím by
            // obrátil pořadí PostingService (období -> FK supplier) a vytvořil deadlock.
            // Změny stejné firmy serializují samy vnořené množiny období od účinnosti dál.
            $periods = $this->frameworks->periodsOverlappingForUpdate(
                $supplierId,
                $effectiveFrom,
                null,
            );
            $startsAtPeriod = array_any(
                $periods,
                static fn (array $period): bool => $period['starts_on'] === $effectiveFrom,
            );
            if (!$startsAtPeriod) {
                throw new AccountingFrameworkException(
                    'accounting_framework_period_start_required',
                    'Účetní rámec lze změnit pouze k prvnímu dni existujícího účetního období.',
                    409,
                );
            }

            (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertFrameworkMutable($supplierId,$effectiveFrom);
            $current = $this->frameworks->forDateForUpdate($supplierId, $effectiveFrom) ?? self::CZ_500;
            $nextChange = $this->frameworks->nextChangeForUpdate($supplierId, $effectiveFrom);
            if ($current === $framework) {
                $this->finishTransaction($ownsTransaction);
                return false;
            }
            // Nedotčenou šablonu 500 nové firmy smí Nastavení vyměnit za šablonu 504;
            // cokoli dalšího je účetní historie a patří do řízeného převodu.
            $replaceSeededChart = false;
            if ($fromSettings
                && $framework === self::CZ_504
                && $this->frameworks->hasAccountingHistoryForUpdate($supplierId)
            ) {
                $replaceSeededChart = (new PristineChart500($this->db))->isPristineForUpdate($supplierId);
                if (!$replaceSeededChart) {
                    throw new AccountingFrameworkException(
                        self::CONVERSION_REQUIRED,
                        'Firma už má účetní historii (zápisy v deníku nebo upravenou či použitou účtovou osnovu). '
                            . 'Na rámec vyhlášky 504 ji převeďte řízeným převodem v Účetnictví → Převod rámce.',
                        409,
                    );
                }
            }

            try {
                $hasPostedEntries = $this->frameworks->hasPostedEntriesInIntervalForUpdate(
                    $supplierId,
                    $effectiveFrom,
                    $nextChange,
                );
            } catch (\PDOException $e) {
                // NOWAIT vrací 1205 jen pro tento journal guard. Transakce zůstává
                // aktivní a obecný catch ji proto může korektně vrátit na savepoint.
                if ((int) ($e->errorInfo[1] ?? 0) === 1205) {
                    throw new AccountingFrameworkException(
                        'accounting_framework_busy',
                        'Účetní rámec nyní nelze změnit, protože probíhá jiný zápis. Zkuste změnu po jeho dokončení.',
                        409,
                        $e,
                    );
                }
                throw $e;
            }
            if ($hasPostedEntries) {
                throw new AccountingFrameworkException(
                    'accounting_framework_period_has_posted_entries',
                    'Účetní rámec intervalu se zaúčtovanými zápisy nelze změnit.',
                    409,
                );
            }

            $this->recordFramework($supplierId, $effectiveFrom, $framework, $userId);
            if ($replaceSeededChart) {
                (new PristineChart500($this->db))->replaceWithFrameworkTemplate($supplierId, $effectiveFrom);
            }
            $this->finishTransaction($ownsTransaction);
            return true;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT accounting_framework_change');
                $pdo->exec('RELEASE SAVEPOINT accounting_framework_change');
            }
            throw $e;
        }
    }

    private function recordFramework(int $supplierId, string $effectiveFrom, string $framework, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_accounting_frameworks
                (supplier_id, effective_from, accounting_framework, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                accounting_framework = VALUES(accounting_framework),
                created_by = VALUES(created_by)"
        )->execute([$supplierId, $effectiveFrom, $framework, $userId]);
    }

    private function finishTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->db->pdo()->commit();
        } else {
            $this->db->pdo()->exec('RELEASE SAVEPOINT accounting_framework_change');
        }
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new AccountingFrameworkException(
                'invalid_accounting_framework_date',
                'Datum účinnosti účetního rámce není platné.',
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollPostingAdapter
{
    public function __construct(
        private readonly PayrollPostingBatchRepository $batches,
        private readonly PostingService $posting,
        private readonly PayrollPeriodOwnershipService $periodOwnership,
        private readonly PayrollPostingLineBuilder $lineBuilder,
        private readonly EntryFormService $entryForms,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @param array<string,mixed> $statutorySets
     * @param array<string,string> $accounts
     * @param array{
     *   user_id?:?int,
     *   ip?:?string,
     *   user_agent?:?string
     * } $meta
     * @return array{
     *   batch_id:int,
     *   journal_entry_id:?int,
     *   status:'posted'|'no_change',
     *   idempotent:bool,
     *   preview:PayrollPostingPreview
     * }
     */
    public function post(
        int $supplierId,
        int $revisionId,
        array $snapshot,
        array $result,
        array $statutorySets,
        array $accounts,
        array $meta = [],
    ): array {
        return $this->batches->transaction(function () use (
            $supplierId,
            $revisionId,
            $snapshot,
            $result,
            $statutorySets,
            $accounts,
            $meta,
        ): array {
            $context = $this->batches->lockRevisionContext(
                $supplierId,
                $revisionId,
            );
            if ($context === null) {
                throw new \DomainException('Mzdová revize pro zaúčtování neexistuje.');
            }
            if ($context['revision_status'] !== 'approved'
                || $context['revision_no'] !== $context['current_revision_no']
            ) {
                throw new \DomainException(
                    'Zaúčtovat lze pouze aktuální schválenou mzdovou revizi.',
                );
            }
            $this->assertRevisionPayload($snapshot, $result, $context);
            [$documentDate, $year, $month] = $this->period(
                $snapshot,
                $context['period_start'],
            );
            $entryDate = $this->batches->resolveEntryDate(
                $supplierId,
                $documentDate,
            );
            $previous = $this->batches->latestEffectiveBefore(
                $supplierId,
                $context['run_id'],
                $context['revision_no'],
            );
            $preview = $this->lineBuilder->build(
                $snapshot,
                $result,
                $statutorySets,
                $accounts,
                $previous['allocations'] ?? [],
            );
            $existing = $this->batches->findByRevisionForUpdate(
                $supplierId,
                $revisionId,
            );
            if ($existing !== null) {
                if (!hash_equals(
                    (string) $existing['target_hash'],
                    $preview->targetHash,
                )) {
                    throw new \DomainException(
                        'Zaúčtovaná revize má jiný cílový účetní otisk.',
                    );
                }
                if (in_array($existing['status'], ['posted', 'no_change'], true)) {
                    return [
                        'batch_id' => (int) $existing['id'],
                        'journal_entry_id' =>
                            $existing['journal_entry_id'] === null
                                ? null
                                : (int) $existing['journal_entry_id'],
                        'status' => $existing['status'],
                        'idempotent' => true,
                        'preview' => $preview,
                    ];
                }
                throw new \DomainException(
                    'Účetní dávka revize zůstala v neplatném rozpracovaném stavu.',
                );
            }

            $this->assertDimensionValuesUsable($supplierId, $preview);

            $userId = $meta['user_id'] ?? null;
            try {
                $this->periodOwnership->claimPayroll(
                    $supplierId,
                    $year,
                    $month,
                    'payroll_run_revision',
                    $revisionId,
                    $userId,
                );
            } catch (PayrollPeriodOwnedException $exception) {
                throw new \DomainException(
                    $exception->getMessage(),
                    previous: $exception,
                );
            }

            $batchId = $this->batches->insertPrepared(
                $supplierId,
                $context['run_id'],
                $revisionId,
                $previous['id'] ?? null,
                $entryDate,
                $preview,
                $userId,
            );
            $this->batches->insertAllocations(
                $supplierId,
                $batchId,
                $preview->targetAllocations,
            );
            if ($preview->lines === []) {
                $this->batches->markNoChange($supplierId, $batchId);

                return [
                    'batch_id' => $batchId,
                    'journal_entry_id' => null,
                    'status' => 'no_change',
                    'idempotent' => false,
                    'preview' => $preview,
                ];
            }

            // Jednoduchá (párová) forma k datu zápisu: tytéž částky po dvojicích
            // předkontací (souhrnně za firmu a středisko). Dávka i její otisky se
            // počítají dál ze složených řádků, takže revize nemění target ani delta hash.
            //
            // Firma, která zvolila zaúčtování po zaměstnancích (zmrazeno ve
            // snapshotu), dostane tytéž částky rozdělené po osobách: ve formě
            // single vlastní dvojice každé osoby, ve složené formě řádky po
            // osobách. Součty po účtech, stranách a střediscích se nemění.
            $employees = PayrollPostingLineBuilder::postsByEmployee($snapshot)
                ? PayrollPostingEmployees::fromSnapshot($snapshot)
                : null;
            $journalLines = $preview->lines;
            if ($this->entryForms->formAt($supplierId, $entryDate, true)->isSingle()) {
                $journalLines = $this->lineBuilder->singleEntryLines(
                    $preview->targetAllocations,
                    $previous['allocations'] ?? [],
                    $employees,
                ) ?? $preview->lines;
            } elseif ($employees !== null) {
                $journalLines = $this->lineBuilder->employeeDeltaLines(
                    $preview->targetAllocations,
                    $previous['allocations'] ?? [],
                    $employees,
                );
            }

            $journalEntryId = $this->posting->postDocument(
                $supplierId,
                'payroll',
                $revisionId,
                array_map(static function (array $line): array {
                    $mapped = [
                        'account_code' => $line['account_code'],
                        'side' => $line['side'],
                        'amount' => self::decimal($line['amount_minor']),
                    ];
                    if (isset($line['pair_group'])) {
                        $mapped['pair_group'] = $line['pair_group'];
                    }
                    if (isset($line['cost_center'])) {
                        $mapped['cost_center'] = $line['cost_center'];
                    }
                    if (isset($line['line_text'])) {
                        $mapped['line_text'] = $line['line_text'];
                    }
                    // Firemní dimenze řádku (typ → hodnota) jdou do deníku
                    // stejnou cestou jako u ručního zápisu: PostingService je
                    // předá DimensionStamperu jako výslovné dimenze řádku.
                    if (isset($line['dimensions']) && $line['dimensions'] !== []) {
                        $mapped['dimensions'] = $line['dimensions'];
                    }
                    return $mapped;
                }, $journalLines),
                [
                    'entry_date' => $entryDate,
                    'document_date' => $documentDate,
                    'document_no' => sprintf(
                        'MZ-%04d%02d-R%d',
                        $year,
                        $month,
                        $context['revision_no'],
                    ),
                    'description' => sprintf(
                        'Mzdový předpis %02d/%04d — revize %d',
                        $month,
                        $year,
                        $context['revision_no'],
                    ),
                    'posted' => true,
                    'posted_by' => $userId,
                    'user_id' => $userId,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                ],
            );
            $this->batches->markPosted(
                $supplierId,
                $batchId,
                $journalEntryId,
            );

            return [
                'batch_id' => $batchId,
                'journal_entry_id' => $journalEntryId,
                'status' => 'posted',
                'idempotent' => false,
                'preview' => $preview,
            ];
        });
    }

    /**
     * Firemní dimenze řádků jsou zmrazené ve snapshotu revize. Než se zapíšou
     * do deníku, musí hodnota pořád existovat pod svým typem a patřit firmě
     * nebo její skupině — jinak by zápis spadl na FK, nebo by firma, která
     * skupinu opustila, účtovala na cizí hodnotu skupiny.
     */
    private function assertDimensionValuesUsable(int $supplierId, PayrollPostingPreview $preview): void
    {
        $pairs = [];
        foreach ($preview->lines as $line) {
            foreach ($line['dimensions'] ?? [] as $typeId => $valueId) {
                $pairs["{$typeId}:{$valueId}"] = ['type_id' => (int) $typeId, 'value_id' => (int) $valueId];
            }
        }
        if ($pairs === []) {
            return;
        }
        $unusable = $this->batches->unusableDimensionValues($supplierId, array_values($pairs));
        if ($unusable === []) {
            return;
        }
        throw new \DomainException(sprintf(
            'Mzdové dimenze revize odkazují na firemní dimenzi, kterou firma už nemůže použít '
            . '(smazaná hodnota, jiný typ nebo hodnota skupiny, do které firma nepatří): %s. '
            . 'Opravte vazbu mzdové dimenze a revizi přepočítejte opravnou revizí.',
            implode(', ', array_map(
                static fn (array $pair): string => "typ {$pair['type_id']} / hodnota {$pair['value_id']}",
                $unusable,
            )),
        ));
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{string,int,int}
     */
    private function period(array $snapshot, string $runPeriodStart): array
    {
        $entryDate = $snapshot['period_end'] ?? null;
        if (!is_string($entryDate)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $entryDate) !== 1
        ) {
            throw new \DomainException('Snapshot nemá platný konec mzdového období.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDate);
        if ($date === false
            || $date->format('Y-m-d') !== $entryDate
            || $date->format('Y-m-t') !== $entryDate
            || $date->format('Y-m-01') !== $runPeriodStart
        ) {
            throw new \DomainException(
                'Konec období snapshotu neodpovídá mzdovému běhu.',
            );
        }

        return [
            $entryDate,
            (int) $date->format('Y'),
            (int) $date->format('n'),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $result
     * @param array{
     *   input_snapshot_hash:string,
     *   result_snapshot_hash:string
     * } $context
     */
    private function assertRevisionPayload(
        array $snapshot,
        array $result,
        array $context,
    ): void {
        $inputHash = hash('sha256', CanonicalJson::encode($snapshot));
        $resultHash = hash('sha256', CanonicalJson::encode($result));
        if (!hash_equals($context['input_snapshot_hash'], $inputHash)
            || !hash_equals($context['result_snapshot_hash'], $resultHash)
        ) {
            throw new \DomainException(
                'Účetní podklady neodpovídají neměnným snapshotům schválené revize.',
            );
        }
    }

    private static function decimal(int $minor): string
    {
        if ($minor <= 0) {
            throw new \LogicException('Účetní řádek musí mít kladnou částku.');
        }
        if ($minor > 100_000_000_000_000) {
            throw new \OverflowException(
                'Účetní řádek překračuje bezpečný rozsah přesného převodu.',
            );
        }

        return intdiv($minor, 100) . '.' . str_pad(
            (string) ($minor % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }
}

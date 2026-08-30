<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollAnnualDocumentBatchRepository;

/**
 * Roční mzdové dokumenty přes serverovou frontu.
 *
 * Do téhle třídy se přesunulo to, co dřív dělal prohlížeč: smyčka
 * `for … await` nad seznamem osob, jeden HTTP požadavek na člověka. U firmy
 * s pěti sty zaměstnanci to byl půltisíc synchronních požadavků, které skončily
 * na timeoutu nebo zavřením záložky. Fronta běh přežije — prohlížeč jen sleduje.
 */
final class PayrollAnnualDocumentBatchQueueService
{
    public function __construct(
        private readonly PayrollAnnualDocumentBatchRepository $batches,
        private readonly AnnualPayrollSheetService $payrollSheets,
        private readonly AnnualTaxCertificateService $certificates,
    ) {}

    /**
     * @param 'selected'|'all'|string $scope
     * @return array<string,mixed>
     */
    public function enqueue(
        int $supplierId,
        int $taxYear,
        PayrollDocumentKind $kind,
        string $scope,
        ?int $employeeId,
        ?int $actorUserId,
        ?string $idempotencyKey = null,
    ): array {
        if ($supplierId <= 0 || $taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Identita roční dávky dokumentů není platná.');
        }
        if (!in_array($kind->value, PayrollAnnualDocumentBatchRepository::KINDS, true)) {
            throw new \InvalidArgumentException('Tento druh dokumentu se ročně nevystavuje.');
        }
        if (!in_array($scope, PayrollAnnualDocumentBatchRepository::SCOPES, true)) {
            throw new \InvalidArgumentException('Rozsah roční dávky není platný.');
        }
        if ($scope === 'selected' && ($employeeId === null || $employeeId <= 0)) {
            throw new \InvalidArgumentException('Rozsah „jedna osoba“ vyžaduje zaměstnance.');
        }
        $target = $scope === 'selected' ? $employeeId : null;

        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            $key = sprintf(
                'annual-document-batch:%d:%d:%s:%s:%s',
                $supplierId,
                $taxYear,
                $kind->value,
                $scope,
                $target === null ? 'all' : (string) $target,
            );
        }
        if (mb_strlen($key) > 190) {
            throw new \InvalidArgumentException('Idempotency key dávky je příliš dlouhý.');
        }

        return $this->batches->enqueue(
            $supplierId,
            $taxYear,
            $kind->value,
            $scope,
            $target,
            $key,
            $actorUserId,
        );
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $batchId): ?array
    {
        return $this->batches->detail($supplierId, $batchId);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function items(int $supplierId, int $batchId, int $limit, int $offset): array
    {
        return $this->batches->items($supplierId, $batchId, $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function retry(int $supplierId, int $batchId, int $itemId): array
    {
        return $this->batches->retry($supplierId, $batchId, $itemId);
    }

    /**
     * @return array{processed:bool,outcome:string|null,batch_id:int|null,item_id:int|null}
     */
    public function processOne(): array
    {
        $claim = $this->batches->claimNext();
        if ($claim === null) {
            return [
                'processed' => false,
                'outcome' => null,
                'batch_id' => null,
                'item_id' => null,
            ];
        }
        $supplierId = (int) $claim['supplier_id'];
        $employeeId = (int) $claim['employee_id'];
        $taxYear = (int) $claim['tax_year'];
        $kindValue = (string) $claim['document_kind'];
        $actorUserId = $claim['requested_by'] === null
            ? null : (int) $claim['requested_by'];
        try {
            $kind = PayrollDocumentKind::from($kindValue);
            // Potvrzení, které osoba za rok už má, se nepřegeneruje: jeho
            // nahrazení je OPRAVA s povinným důvodem (§ opravné potvrzení),
            // a ten za účetní vymyslet nelze. Přeskočení není selhání.
            if ($kind !== PayrollDocumentKind::PayrollSheet
                && $this->batches->hasAnnualDocument(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $kindValue,
                )
            ) {
                $this->batches->skip(
                    $claim,
                    'annual_document_exists',
                    'Osoba už potvrzení za rok má; jeho nahrazení je oprava'
                        . ' s povinným důvodem.',
                );
                return [
                    'processed' => true,
                    'outcome' => 'skipped',
                    'batch_id' => (int) $claim['batch_id'],
                    'item_id' => (int) $claim['id'],
                ];
            }

            $document = $kind === PayrollDocumentKind::PayrollSheet
                ? $this->payrollSheets->generate(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $actorUserId,
                )
                : $this->certificates->generate(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $kind,
                    $actorUserId,
                );
            $this->batches->succeed($claim, (int) $document['id']);
            $outcome = 'succeeded';
        } catch (\Throwable $exception) {
            // Selhání JEDNÉ osoby nesmí zhodit dávku: uzavře se jen její
            // položka, důvod se uloží a fronta jede dál.
            $this->batches->fail(
                $claim,
                self::errorCode($exception),
                $exception->getMessage(),
            );
            $outcome = 'failed';
        }

        return [
            'processed' => true,
            'outcome' => $outcome,
            'batch_id' => (int) $claim['batch_id'],
            'item_id' => (int) $claim['id'],
        ];
    }

    /** @return array{processed:int,succeeded:int,failed:int,skipped:int} */
    public function processAvailable(int $limit = 25): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $item = $this->processOne();
            if (!$item['processed']) {
                break;
            }
            $result['processed']++;
            match ($item['outcome']) {
                'succeeded' => $result['succeeded']++,
                'skipped' => $result['skipped']++,
                default => $result['failed']++,
            };
        }
        return $result;
    }

    private static function errorCode(\Throwable $exception): string
    {
        $short = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string) preg_replace(
            '/(?<!^)[A-Z]/',
            '_$0',
            $short,
        ));
        return substr('render_' . $normalized, 0, 64);
    }
}

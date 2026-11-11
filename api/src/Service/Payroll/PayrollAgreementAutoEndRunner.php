<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;

/**
 * Noční ukončení uplynulých dohod přes všechny firmy se mzdami.
 *
 * Dohoda, které uplynul sjednaný konec, zůstávala ve stavu „aktivní", dokud ji
 * někdo neukončil ručně. Kontroly ji pak braly jako běžící: ptaly se na chybějící
 * údaje k dnešku a mzdový běh nabízel vztah, který už dávno neplatí. Runner nemá
 * vlastní pravidla, volá {@see PayrollEmploymentBulkClosure::endExpiredAgreements()},
 * tedy tytéž kandidáty a pojistky jako ruční akce „Ukončit skončené dohody".
 *
 * Selhání jedné firmy nesmí zastavit ostatní, proto je každá ve vlastním try/catch
 * a report nese jmenovitý seznam chyb.
 */
final readonly class PayrollAgreementAutoEndRunner
{
    public function __construct(
        private PayrollModuleStateRepository $modules,
        private PayrollEmploymentBulkClosure $closure,
    ) {}

    /**
     * @param list<int>|null $onlySuppliers omezení na konkrétní firmy (ladění)
     * @return array{
     *   as_of:string,suppliers:int,ended:int,blocked:int,failed:int,errors:int,
     *   failures:list<array{supplier_id:int,message:string}>
     * }
     */
    public function run(?array $onlySuppliers = null, ?string $today = null): array
    {
        $today ??= (new \DateTimeImmutable('today'))->format('Y-m-d');
        $supplierIds = $this->modules->payrollEnabledSupplierIds();
        if ($onlySuppliers !== null) {
            $wanted = array_flip($onlySuppliers);
            $supplierIds = array_values(array_filter(
                $supplierIds,
                static fn (int $id): bool => isset($wanted[$id]),
            ));
        }

        $report = [
            'as_of' => $today,
            'suppliers' => count($supplierIds),
            'ended' => 0,
            'blocked' => 0,
            'failed' => 0,
            'errors' => 0,
            'failures' => [],
        ];
        foreach ($supplierIds as $supplierId) {
            try {
                $result = $this->closure->endExpiredAgreements($supplierId, $today);
                $report['ended'] += count($result['ended']);
                $report['blocked'] += count($result['blocked']);
                $report['failed'] += count($result['failed']);
            } catch (\Throwable $exception) {
                ++$report['errors'];
                $report['failures'][] = [
                    'supplier_id' => $supplierId,
                    'message' => mb_substr($exception->getMessage(), 0, 300),
                ];
            }
        }

        return $report;
    }
}

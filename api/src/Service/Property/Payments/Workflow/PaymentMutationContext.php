<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Payments\PaymentUseGuard;
use MyInvoice\Service\Property\PropertyCatalog;

final class PaymentMutationContext
{
    public function __construct(private readonly Connection $db) {}

    public function acquire(int $supplier): bool
    {
        (new PropertyCatalog($this->db))->lockContext($supplier);
        foreach ((new AccountingFrameworkRepository($this->db))->listForTenantForUpdate($supplier) as $row) {
            if ($row['accounting_framework'] === AccountingFrameworkService::CZ_504) {
                (new PaymentLocks($this->db))->acquire($supplier);
                return true;
            }
        }
        return false;
    }

    public function assertEntriesMutable(int $supplier, array $ids): void
    {
        (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db))->assertEntriesMutable($supplier, $ids);
        (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db))->assertEntriesMutable($supplier, $ids);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($supplier, $ids);
        (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertEntriesMutable($supplier, $ids);
        if (!$this->acquire($supplier)) return;
        try {
            (new PaymentUseGuard($this->db))->assertEntriesMutable($supplier, $ids);
        } catch (PaymentException $e) {
            throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function assertSourceMutable(int $supplier, string $kind, int $id): void
    {
        (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db))->assertSourceMutable($supplier, $kind, $id);
        (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db))->assertSourceMutable($supplier, $kind, $id);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertSourceMutable($supplier, $kind, $id);
        (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertSourceMutable($supplier, $kind, $id);
        if (!$this->acquire($supplier)) return;
        try {
            (new PaymentUseGuard($this->db))->assertSourceMutable($supplier, $kind, $id);
        } catch (PaymentException $e) {
            throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function assertBankStatementMutable(int $supplier, int $id): void
    {
        (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db))->assertBankStatementMutable($supplier, $id);
        (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db))->assertBankStatementMutable($supplier, $id);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertBankStatementMutable($supplier, $id);
        (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertBankStatementMutable($supplier, $id);
        if (!$this->acquire($supplier)) return;
        try {
            (new PaymentUseGuard($this->db))->assertBankStatementMutable($supplier, $id);
        } catch (PaymentException $e) {
            throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    public function assertLinesMutable(int $supplier, array $ids): void
    {
        (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db))->assertLinesMutable($supplier, $ids);
        (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db))->assertLinesMutable($supplier, $ids);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertLinesMutable($supplier, $ids);
        (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertLinesMutable($supplier, $ids);
        if (!$this->acquire($supplier)) return;
        try {
            (new PaymentUseGuard($this->db))->assertLinesMutable($supplier, $ids);
        } catch (PaymentException $e) {
            throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function assertSourcesMutable(int $supplier, array $sources): void
    {
        $entries = (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockSources($supplier, $sources);
        $this->assertEntriesMutable($supplier, array_map('intval', array_keys($entries)));
    }

    public function assertDocumentMutable(int $supplier, string $type, int $id): void
    {
        $source = match ($type) { 'cash_document' => 'cash', 'bank_transaction' => 'bank', default => $type };
        $this->assertSourcesMutable($supplier, [[$source, $id]]);
    }
}

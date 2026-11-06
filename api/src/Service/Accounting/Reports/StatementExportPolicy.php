<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class StatementExportPolicy
{
    public static function assertSupported(array $data): void
    {
        if (($data['accounting_framework'] ?? '') === 'cz_504' || str_starts_with((string) ($data['version_code'] ?? ''), 'vyhl504-')) {
            throw new ReportException('statement_export_unsupported', 'PDF a XLSX šablony výkazů 504 zatím nejsou k dispozici.');
        }
    }
}

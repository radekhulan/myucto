<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignIssuedRepair;

final class ForeignIssuedInCzkCheck extends SourceScopedCheck
{
    public const KEY = 'foreign_issued_in_czk';

    public function __construct(
        MigrationSources $sources,
        private readonly PohodaForeignIssuedRepair $repair,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Vydané doklady v cizí měně převzaté v Kč';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12615-vydane-doklady-v-cizi-mene-prevzate-v-kc';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        foreach ($this->repair->run($supplierId, null, false) as $row) {
            $items[] = new CheckItem(
                'invoice', $row['id'], $row['document_no'], $row['issue_date'], $row['total_czk'],
                $row['status'] === 'locked' ? CheckItem::LOCKED : CheckItem::REVIEW,
                $row['reason'] . ' Původní důvod převzetí v Kč: ' . $row['source_reason'] . '.',
                null, $row['currency'], ['outcome' => $row['status']],
            );
        }
        $waiting = count(array_filter($items, static fn (CheckItem $item): bool => $item->details['outcome'] === 'needs_export'));
        return new CheckResult($items, $waiting > 0 ? [sprintf('Původní export z POHODY potřebuje k ověření %d dokladů.', $waiting)] : []);
    }
}

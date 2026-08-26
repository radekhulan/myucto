<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

final readonly class PayrollPeriodExportEntry
{
    private const CATEGORIES = [
        'payroll_document',
        'submission_artifact',
        'submission_protocol',
    ];

    public function __construct(
        public string $name,
        public string $bytes,
        public string $mimeType,
        public string $category,
        public int $sourceId,
    ) {
        if ($bytes === '' || $sourceId <= 0) {
            throw new \InvalidArgumentException(
                'Položka exportu mezd nemá platný obsah nebo zdroj.',
            );
        }
        if (preg_match(
            '#^[a-z0-9][a-z0-9._/-]{0,239}$#D',
            $name,
        ) !== 1
            || str_contains($name, '..')
            || str_contains($name, '//')
            || str_contains($name, '\\')
        ) {
            throw new \InvalidArgumentException(
                'Název položky exportu mezd není bezpečný.',
            );
        }
        if (preg_match('#^[a-z0-9][a-z0-9.+/-]{0,95}$#D', $mimeType) !== 1
            || !in_array($category, self::CATEGORIES, true)
        ) {
            throw new \InvalidArgumentException(
                'Metadata položky exportu mezd nejsou platná.',
            );
        }
        $prefix = match ($category) {
            'payroll_document' => 'documents/',
            'submission_artifact' => 'submissions/',
            'submission_protocol' => 'protocols/',
        };
        if (!str_starts_with($name, $prefix)) {
            throw new \InvalidArgumentException(
                'Umístění položky exportu mezd neodpovídá jejímu druhu.',
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Společný základ kontrol: kontrola se týká firmy, která převzala data z některého ze zdrojů
 * {@see sources()} ({@see MigrationSources}).
 */
abstract class SourceScopedCheck implements MigrationDataCheck
{
    public const MANUAL_CHAPTER = '126_Kontrola_dat_po_prevodu';

    public function __construct(protected readonly MigrationSources $sources) {}

    /** @return list<string> zdroje převodu, kterých se kontrola týká */
    abstract protected function sources(): array;

    public function appliesTo(int $supplierId): bool
    {
        return $this->sources->any($supplierId, $this->sources());
    }

    protected static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}

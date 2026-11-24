<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;

final class ImportedIssuedAccounting
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingPeriodProvisioner $provisioner,
    ) {}

    /** @return \Closure(string): ?array<string,mixed> Účetní období dokladu podle data zápisu. */
    public function periodLookup(int $supplierId): \Closure
    {
        if (!$this->provisioner->hasActiveDoubleEntry($supplierId)) {
            return static fn (string $date): ?array => null;
        }

        $periods = $this->periods->listForTenant($supplierId);
        if ($periods === []) {
            return static fn (string $date): ?array => null;
        }

        $start = $this->db->pdo()->prepare('SELECT accounting_starts_on FROM supplier WHERE id = ?');
        $start->execute([$supplierId]);
        $startsOn = $start->fetchColumn();
        if (!is_string($startsOn)) {
            $setup = array_filter($periods, static fn (array $p): bool => $p['created_reason'] === AccountingPeriodProvisioner::REASON_SETUP);
            $existing = array_filter($periods, static fn (array $p): bool => $p['created_reason'] !== AccountingPeriodProvisioner::REASON_IMPORT);
            $startsOn = $setup !== [] || $existing !== [] ? min(array_column($setup ?: $existing, 'starts_on')) : null;
        }

        $first = min(array_column($periods, 'starts_on'));
        $afterLast = (new \DateTimeImmutable(max(array_column($periods, 'ends_on'))))->modify('+1 day')->format('Y-m-d');
        $modeChanges = [];
        foreach ([$first, ...$this->modes->effectiveDatesBetween($supplierId, $first, $afterLast)] as $date) {
            $modeChanges[$date] = $this->modes->forDate($supplierId, $date);
        }
        $modeChanges = array_reverse($modeChanges, true);

        return static function (string $date) use ($startsOn, $periods, $modeChanges): ?array {
            if ($startsOn !== null && $date < $startsOn) {
                return null;
            }
            foreach ($modeChanges as $effectiveFrom => $mode) {
                if ($date >= $effectiveFrom) {
                    if ($mode !== 'double_entry') {
                        return null;
                    }
                    break;
                }
            }
            return array_find($periods, static fn (array $p): bool => $date >= $p['starts_on'] && $date <= $p['ends_on']);
        };
    }
}

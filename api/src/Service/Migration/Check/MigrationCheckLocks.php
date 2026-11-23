<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;

/**
 * Smí oprava kontroly změnit záznam k danému datu? Opravy dat po převodu nemění nic, co
 * leží v uzavřeném nebo rozpracovaném (uzávěrka) účetním období, za zámkem účtování k datu
 * (`locked_until`) a u změn s dopadem na DPH ani v období, za které je podané přiznání,
 * kontrolní hlášení nebo OSS přiznání. Takový nález jen ohlásí ({@see CheckItem::LOCKED}).
 *
 * Pojistky jsou stejné jako u ruční změny dokladu: {@see DocumentLockService::forDate()}
 * a {@see FiledVatPeriodLookup::describe()}.
 */
final class MigrationCheckLocks
{
    public function __construct(
        private readonly DocumentLockService $locks,
        private readonly FiledVatPeriodLookup $filed,
    ) {}

    /** Důvod, proč se záznam k datu `$date` nesmí změnit, nebo `null`. */
    public function reason(int $supplierId, ?string $date, bool $vat = false): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $date = substr($date, 0, 10);
        $lock = $this->locks->forDate($supplierId, $date);
        if ($lock->inClosedPeriod) {
            return sprintf('Leží v uzavřeném účetním období (%s).', $date);
        }
        if ($lock->inClosingPeriod) {
            return sprintf('Leží v období v uzávěrce (%s).', $date);
        }
        if ($lock->dateLocked) {
            return sprintf('Leží za zámkem účtování k datu (%s).', $date);
        }
        if ($vat) {
            $filed = $this->filed->describe($supplierId, $date);
            if ($filed !== null) {
                return sprintf('Leží v podaném období DPH (%s).', $filed);
            }
        }
        return null;
    }

    /**
     * Opravy, které firmu opravují jen celou (sdílená oprava převodu bez výběru dokladů):
     * když aspoň jeden nález leží v chráněném období, nejde opravit nic a ostatní nálezy
     * se jen ohlásí s vysvětlením.
     */
    public static function allOrNothing(CheckResult $result): CheckResult
    {
        $locked = $result->countState(CheckItem::LOCKED);
        if ($locked === 0) {
            return $result;
        }
        $reason = sprintf('Oprava převodu opravuje firmu jen celou a %d %s v chráněném období. Postup je v manuálu.',
            $locked, $locked === 1 ? 'nález leží' : ($locked < 5 ? 'nálezy leží' : 'nálezů leží'));
        $items = array_map(static fn (CheckItem $i): CheckItem => $i->state === CheckItem::FIXABLE ? $i->withState(CheckItem::REVIEW, $reason) : $i, $result->items);
        return new CheckResult($items, $result->notes);
    }
}

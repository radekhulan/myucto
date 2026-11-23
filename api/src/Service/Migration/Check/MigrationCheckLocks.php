<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;

/**
 * Smí oprava kontroly změnit záznam k danému datu? Co oprava nesmí změnit, jen ohlásí
 * ({@see CheckItem::LOCKED}). Pojistky jsou stejné jako u ruční změny dokladu:
 * {@see DocumentLockService::forDate()} a {@see FiledVatPeriodLookup::describe()}.
 *
 * Dvě úrovně podle toho, na co oprava sahá:
 *  - {@see reason()}: opravy, které mění výpisy, jejich stavy a vazby dokladů, ze kterých
 *    vychází deník, uzávěrka nebo DPH (převod účtu na měnu, přepočet stavů převzatých
 *    výpisů, vazba DDKP na zálohu). Chrání uzavřené a rozpracované účetní období, zámek
 *    účtování k datu (`locked_until`) a u vazeb s dopadem na DPH i podané období DPH.
 *  - {@see paymentRecordReason()}: doplnění evidence úhrad převzatých dokladů (platba bez
 *    pohybu a zápisu, úhrada mimo evidenci). Deník, DPH, uhrazenou částku ani stav dokladu
 *    nemění (hlídají to pojistky oprav převodu), jen zaeviduje úhradu, kterou doklad už nese.
 *    Uzavřené účetní období ji proto neblokuje: převod z Money S3 převedené roky sám uzavírá
 *    a doklady s úhradou v nich by jinak nešlo opravit nikdy. Blokuje ji jen zámek účtování
 *    k datu a podané období DPH.
 */
final class MigrationCheckLocks
{
    /** @var array<string,?string> firma|rok-měsíc => popis podaného období (podání se nemění podle dne) */
    private array $filedByMonth = [];

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
            $filed = $this->filedPeriod($supplierId, $date);
            if ($filed !== null) {
                return sprintf('Leží v podaném období DPH (%s).', $filed);
            }
        }
        return null;
    }

    /**
     * Důvod, proč se k datu `$date` nesmí doplnit evidence úhrady převzatého dokladu, nebo
     * `null`: jen zámek účtování k datu a podané období DPH (viz třída).
     */
    public function paymentRecordReason(int $supplierId, ?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $date = substr($date, 0, 10);
        if ($this->locks->forDate($supplierId, $date)->dateLocked) {
            return sprintf('Leží za zámkem účtování k datu (%s).', $date);
        }
        $filed = $this->filedPeriod($supplierId, $date);
        return $filed !== null ? sprintf('Leží v podaném období DPH (%s).', $filed) : null;
    }

    /**
     * Podané období DPH k datu, jednou za firmu a měsíc: {@see FiledVatPeriodLookup::describe()}
     * závisí jen na měsíci a čtvrtletí data, a nález za nálezem by jinak klepal do podání znovu.
     */
    public function filedPeriod(int $supplierId, string $date): ?string
    {
        $key = $supplierId . '|' . substr($date, 0, 7);
        if (!array_key_exists($key, $this->filedByMonth)) {
            $this->filedByMonth[$key] = $this->filed->describe($supplierId, $date);
        }
        return $this->filedByMonth[$key];
    }

    /** Zapomene zjištěná podání (oprava a dlouhý proces přes víc firem). */
    public function reset(): void
    {
        $this->filedByMonth = [];
        $this->locks->reset();
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

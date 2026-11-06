<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Stav zámku dokladu (Epic F6, §4.1) — počítá výhradně {@see DocumentLockService}.
 * `lockedForClient()` blokuje roli client; `inClosedPeriod` řídí 409 pro účetní.
 */
final class DocumentLock
{
    public function __construct(
        public readonly bool $booked,             // booked_at NOT NULL (u PF i status='booked')
        public readonly ?string $bookedAt,
        public readonly bool $posted,             // aktivní posted zápis v deníku
        public readonly ?int $journalEntryId,
        public readonly bool $inClosedPeriod,     // status closed|approved
        public readonly bool $inClosingPeriod,    // status closing (zamyká jen klienta)
        public readonly ?string $periodStatus,
        // B8 (audit 2026-07): refDate <= accounting_supplier_settings.locked_until.
        // Informativní příznak pro UI/účetní — NEmění lockedForClient (na rozdíl od
        // closed/closing období klienta zámkem k datu neváže).
        public readonly bool $dateLocked = false,
        // Přijatá faktura vzniklá z klientského staging podání patří do správy účetní.
        public readonly bool $accountantManaged = false,
    ) {}

    public function lockedForClient(): bool
    {
        return $this->booked || $this->posted || $this->inClosedPeriod
            || $this->inClosingPeriod || $this->accountantManaged;
    }

    public function forDeletion(string $status, bool $hasFinancialLinks): self
    {
        if ($status !== 'draft' || $this->booked || $this->posted || $hasFinancialLinks) {
            return $this;
        }
        return new self(
            booked: $this->booked,
            bookedAt: $this->bookedAt,
            posted: $this->posted,
            journalEntryId: $this->journalEntryId,
            inClosedPeriod: false,
            inClosingPeriod: false,
            periodStatus: null,
            dateLocked: false,
            accountantManaged: $this->accountantManaged,
        );
    }

    /** @return list<string> 'posted'|'booked'|'period_closed'|'period_closing'|'date_locked'|'accountant_managed' */
    public function reasons(): array
    {
        $reasons = [];
        if ($this->posted) {
            $reasons[] = 'posted';
        }
        if ($this->booked) {
            $reasons[] = 'booked';
        }
        if ($this->inClosedPeriod) {
            $reasons[] = 'period_closed';
        }
        if ($this->inClosingPeriod) {
            $reasons[] = 'period_closing';
        }
        if ($this->dateLocked) {
            $reasons[] = 'date_locked';
        }
        if ($this->accountantManaged) {
            $reasons[] = 'accountant_managed';
        }
        return $reasons;
    }

    /** Jednotný kontrakt pro FE — response klíč "locked" (§4.5). */
    public function toArray(): array
    {
        return [
            'is_locked'        => $this->lockedForClient(),
            'reasons'          => $this->reasons(),
            'booked_at'        => $this->bookedAt,
            'journal_entry_id' => $this->journalEntryId,
            'period_status'    => $this->periodStatus,
            'date_locked'      => $this->dateLocked,
            'accountant_managed' => $this->accountantManaged,
        ];
    }
}

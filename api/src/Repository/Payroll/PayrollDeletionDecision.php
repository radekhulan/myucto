<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Rozhodnutí, jestli jde mzdový objekt (pracovní vztah, zaměstnanec) smazat.
 *
 * ── Vodicí princip ────────────────────────────────────────────────────────────
 * Blokovat smí VÝHRADNĚ důkaz pohybu: vnější úkon (podání, registrace u ČSSZ nebo
 * zdravotní pojišťovny), schválený výpočet, nebo peníze. Nic jiného.
 *
 * Vlastní evidence objektu, poznámky, ODŠKRTNUTÉ CHECKLISTY, rozepsané údaje ani
 * „bylo by to složité uklidit" důvodem k blokaci nejsou. Odškrtnutá položka
 * checklistu je poznámka člověka, ne důkaz — někdo v aplikaci klikl, navenek se
 * nestalo nic. Skutečný záznam registrace nebo podání je opak: ten úkon už proběhl
 * a smazáním bychom lhali.
 *
 * `cascade` nese počty toho, co zmizí spolu s objektem — pro potvrzovací dialog
 * (musí jmenovat, co přesně zmizí) a pro auditní payload.
 */
final readonly class PayrollDeletionDecision
{
    /** @param array<string,int> $cascade */
    private function __construct(
        public bool $canDelete,
        public ?string $blockerCode,
        public ?string $blockerMessage,
        public array $cascade,
        public ?int $blockedEmploymentId,
        public ?string $blockedEmploymentCode,
        public ?string $blockerPeriod = null,
    ) {}

    /** @param array<string,int> $cascade */
    public static function allowed(array $cascade): self
    {
        return new self(true, null, null, $cascade, null, null);
    }

    /**
     * `$period` (první den měsíce) pojmenuje období, kterého se blokátor týká,
     * aby frontend mohl odkázat přímo na ně.
     */
    public static function blocked(string $code, string $message, ?string $period = null): self
    {
        return new self(false, $code, $message, [], null, null, $period);
    }

    /**
     * Zaměstnance blokuje konkrétní pracovní vztah — hláška musí říct KTERÝ a proč,
     * ne obecné „nelze smazat".
     */
    public static function blockedByEmployment(
        string $code,
        string $message,
        int $employmentId,
        string $employmentCode,
    ): self {
        return new self(false, $code, $message, [], $employmentId, $employmentCode);
    }

    /** @return array<string,mixed>|null */
    public function blockerPayload(): ?array
    {
        if ($this->canDelete) {
            return null;
        }

        return [
            'code' => (string) $this->blockerCode,
            'message' => (string) $this->blockerMessage,
            'employment_id' => $this->blockedEmploymentId,
            'employment_code' => $this->blockedEmploymentCode,
            'period' => $this->blockerPeriod,
        ];
    }
}

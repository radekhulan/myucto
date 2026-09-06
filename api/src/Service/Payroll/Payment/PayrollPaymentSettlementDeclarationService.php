<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use DomainException;
use MyInvoice\Repository\Payroll\PayrollPaymentSettlementSignalRepository;

/**
 * Ruční „Zaplatil jsem" u mzdového závazku.
 *
 * Záchranná brzda pro případ, kdy odvod odešel z internetového bankovnictví
 * a aplikace o tom zatím nemá jak vědět: banka neposílá avíza, výpis dorazí
 * až koncem měsíce, ale hlídač termínů zatím strašně vybírá odvod, který je
 * dávno zaplacený.
 *
 * ⚠️ NENÍ to úhrada. Prohlášení účetní není platební doklad, takže se
 * nezapisuje do platební knihy (`payroll_payment_matches`), nesnižuje saldo
 * a nezakládá nic v deníku. Umí jediné: zhasnout termín a v Mzdových
 * příkazech se ukázat jako badge. Skutečnou úhradu dodá až bankovní výpis
 * ({@see PayrollPaymentSettlementRecognizer}), který prohlášení uzavře.
 */
final class PayrollPaymentSettlementDeclarationService
{
    public function __construct(
        private readonly PayrollPaymentSettlementSignalRepository $signals,
    ) {}

    /**
     * @return array{liability_id:int,paid_on:string,amount_minor:int,created:bool}
     */
    public function declare(
        int $supplierId,
        int $liabilityId,
        ?string $paidOn,
        ?string $note,
        ?int $userId,
        ?\DateTimeImmutable $today = null,
    ): array {
        $liability = $this->signals->liabilityForDeclaration($supplierId, $liabilityId);
        if ($liability === null) {
            throw new DomainException('Mzdový závazek nebyl nalezen v aktuální firmě.');
        }
        if ($liability['direction'] !== 'outgoing') {
            throw new DomainException(
                'Prohlásit se dá jen odchozí platba — příchozí vratku párujte proti pohybu.',
            );
        }
        $outstanding = $liability['amount_minor'] - $liability['settled_minor'];
        if ($outstanding <= 0) {
            throw new DomainException('Závazek je už uhrazený, prohlašovat není co.');
        }

        $today ??= new \DateTimeImmutable('today');
        $paid = $this->validatePaidOn($paidOn, $liability, $today);

        $created = $this->signals->insert(
            $supplierId,
            $liabilityId,
            'manual',
            null,
            $paid,
            $outstanding,
            $this->validateNote($note),
            $userId,
        );

        return [
            'liability_id' => $liabilityId,
            'paid_on' => $paid,
            'amount_minor' => $outstanding,
            'created' => $created,
        ];
    }

    /** Vrací, jestli tu nějaké živé prohlášení bylo. */
    public function revoke(int $supplierId, int $liabilityId): bool
    {
        return $this->signals->deleteManual($supplierId, $liabilityId);
    }

    /**
     * @param array{period_start:string,due_on:string} $liability
     */
    private function validatePaidOn(
        ?string $paidOn,
        array $liability,
        \DateTimeImmutable $today,
    ): string {
        $value = trim((string) $paidOn);
        if ($value === '') {
            return $today->format('Y-m-d');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datum úhrady musí být ve tvaru RRRR-MM-DD.');
        }
        if ($value > $today->format('Y-m-d')) {
            throw new \InvalidArgumentException('Datum úhrady nesmí být v budoucnu.');
        }
        // Dřív než vznikla mzda zaplatit odvod nešlo — takové datum je překlep.
        if ($value < $liability['period_start']) {
            throw new \InvalidArgumentException(
                'Datum úhrady je před mzdovým obdobím, ke kterému odvod patří.',
            );
        }

        return $value;
    }

    private function validateNote(?string $note): ?string
    {
        $value = trim((string) $note);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > 190) {
            throw new \InvalidArgumentException('Poznámka je delší než 190 znaků.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException('Poznámka obsahuje řídicí znak.');
        }

        return $value;
    }
}

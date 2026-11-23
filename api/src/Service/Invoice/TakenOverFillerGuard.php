<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;

/**
 * Smí se výplň Money S3 ({@see TakenOverPayment::isMoneyS3Filler()}) smazat? Jen v období,
 * které není uzavřené, uzavírané, zamčené k datu ani podané (přiznání DPH, kontrolní hlášení):
 * smazání vrací doklad mezi neuhrazené a mění saldokonto k datu úhrady. Právo rozhoduje
 * {@see \MyInvoice\Security\RequestAuthorization::canDeleteTakenOverFiller()}.
 */
final class TakenOverFillerGuard
{
    public function __construct(
        private readonly DocumentLockService $locks,
        private readonly FiledVatPeriodLookup $filed,
    ) {}

    /**
     * Důvod, proč výplň smazat nejde, nebo `null`.
     *
     * @param array{paid_on?:mixed} $payment
     */
    public function blockedReason(int $supplierId, array $payment): ?string
    {
        $date = substr((string) ($payment['paid_on'] ?? ''), 0, 10);
        $lock = $this->locks->forDate($supplierId, $date);
        if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
            return sprintf('Úhrada převzatá z Money S3 k %s leží v uzavřeném nebo zamčeném období, smazat ji nejde.', $date);
        }
        if (($filed = $this->filed->describe($supplierId, $date, false)) !== null) {
            return sprintf('Úhrada převzatá z Money S3 k %s leží v podaném období (%s), smazat ji nejde.', $date, $filed);
        }
        return null;
    }
}

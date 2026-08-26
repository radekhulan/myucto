<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class InsolvencyInstruction
{
    public function __construct(
        public InsolvencyMode $mode,
        public bool $decisionVerified,
        public bool $recipientVerified,
        public ?int $courtDeterminedAmountMinorUnits = null,
        public ?int $paymentInstructionId = null,
        public ?string $paymentInstructionHash = null,
        public ?int $employmentId = null,
    ) {
        if ($courtDeterminedAmountMinorUnits !== null && $courtDeterminedAmountMinorUnits < 0) {
            throw new InvalidArgumentException('Court-determined insolvency amount cannot be negative.');
        }
        $binding = [
            $paymentInstructionId,
            $paymentInstructionHash,
            $employmentId,
        ];
        $present = count(array_filter(
            $binding,
            static fn (mixed $value): bool => $value !== null,
        ));
        if ($present !== 0 && $present !== count($binding)) {
            throw new InvalidArgumentException(
                'Insolvency payment instruction binding is incomplete.',
            );
        }
        if ($paymentInstructionId !== null
            && ($paymentInstructionId <= 0
                || $employmentId === null
                || $employmentId <= 0
                || $paymentInstructionHash === null
                || preg_match(
                    '/^[0-9a-f]{64}$/D',
                    $paymentInstructionHash,
                ) !== 1)
        ) {
            throw new InvalidArgumentException(
                'Insolvency payment instruction binding is invalid.',
            );
        }
    }

    public static function none(): self
    {
        return new self(InsolvencyMode::None, false, false);
    }

    public function hasImmutablePaymentInstruction(): bool
    {
        return $this->paymentInstructionId !== null;
    }
}

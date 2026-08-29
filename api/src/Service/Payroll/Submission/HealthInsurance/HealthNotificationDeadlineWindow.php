<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthNotificationDeadlineWindow
{
    public function __construct(
        public string $earliestSubmissionOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public string $source,
        public string $sourceStatus,
        /**
         * Zákonný den lhůty PŘED posunem na pracovní den. U lhůt, které se
         * neposouvají, je shodný s `dueOn`.
         */
        public string $statutoryDueOn = '',
        /** @see HealthNotificationDeadlinePolicy::SHIFT_WORKING_DAY */
        public string $dueShift = HealthNotificationDeadlinePolicy::SHIFT_NONE,
        /** Pramen posunu; `null` u lhůt, které se neposouvají. */
        public ?string $shiftSource = null,
    ) {}

    /** Termín se kvůli víkendu nebo svátku posunul oproti zákonnému dni. */
    public function isShifted(): bool
    {
        return $this->statutoryDueOn !== ''
            && $this->statutoryDueOn !== $this->dueOn;
    }

    /** @return array<string,string|bool|null> */
    public function toArray(): array
    {
        return [
            'earliest_submission_on' => $this->earliestSubmissionOn,
            'due_on' => $this->dueOn,
            'statutory_due_on' => $this->statutoryDueOn === ''
                ? $this->dueOn
                : $this->statutoryDueOn,
            'is_shifted' => $this->isShifted(),
            'due_shift' => $this->dueShift,
            'shift_source' => $this->shiftSource,
            'calendar_basis' => $this->calendarBasis,
            'ruleset_id' => $this->rulesetId,
            'source' => $this->source,
            'source_status' => $this->sourceStatus,
        ];
    }
}

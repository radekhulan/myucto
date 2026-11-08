<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Juvenile;

/**
 * Jeden nález kontroly pracovní doby mladistvého ({@see JuvenileWorkTimeEvaluator}).
 *
 * Nese fakta i hotovou větu pro uživatele se dnem a hodnotami, stejně jako
 * {@see \MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitFinding}. Je to
 * vždy jen varování: mzdu neovlivňuje a odpracovanou dobu je potřeba zaplatit
 * i tehdy, když ji zaměstnavatel neměl mladistvému rozvrhnout.
 */
final readonly class JuvenileWorkTimeFinding
{
    public const CODE_DAILY = 'juvenile_daily_shift_exceeded';
    public const CODE_WEEKLY = 'juvenile_weekly_hours_exceeded';
    public const CODE_REST = 'juvenile_rest_between_shifts_too_short';
    public const CODE_NIGHT = 'juvenile_night_work';
    public const CODE_BREAK = 'juvenile_break_missing';

    public function __construct(
        public string $code,
        public string $message,
        public string $scopeFrom,
        public string $scopeTo,
        public int $actualMinutes,
        public int $limitMinutes,
        public string $provision,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => 'warning',
            'message' => $this->message,
            'scope_from' => $this->scopeFrom,
            'scope_to' => $this->scopeTo,
            'actual_minutes' => $this->actualMinutes,
            'limit_minutes' => $this->limitMinutes,
            'provision' => $this->provision,
        ];
    }
}

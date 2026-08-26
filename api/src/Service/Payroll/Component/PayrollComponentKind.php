<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

enum PayrollComponentKind: string
{
    case BASE_WAGE = 'base_wage';
    case HOURLY_WAGE = 'hourly_wage';
    case TASK_WAGE = 'task_wage';
    case BONUS = 'bonus';
    case PREMIUM = 'premium';
    case COMMISSION = 'commission';
    case ALLOWANCE = 'allowance';
    case COMPENSATION = 'compensation';
    case SEVERANCE = 'severance';
    case COMPETITIVE_CLAUSE = 'competitive_clause';
    case BACKPAY = 'backpay';
    case NON_CASH = 'non_cash';
    case BENEFIT_MEAL = 'benefit_meal';
    case BENEFIT_VEHICLE = 'benefit_vehicle';
    case BENEFIT_PENSION = 'benefit_pension';
    case BENEFIT_CARE = 'benefit_care';
    case BENEFIT_EDUCATION = 'benefit_education';
    case BENEFIT_RECREATION = 'benefit_recreation';
    case BENEFIT_HEALTH = 'benefit_health';
    case BENEFIT_ACCOMMODATION = 'benefit_accommodation';
    case RISKY_SAVINGS = 'risky_savings';
    case TRAVEL_REIMBURSEMENT = 'travel_reimbursement';
    case OTHER = 'other';

    public function isBenefit(): bool
    {
        return str_starts_with($this->value, 'benefit_')
            || $this === self::RISKY_SAVINGS;
    }
}

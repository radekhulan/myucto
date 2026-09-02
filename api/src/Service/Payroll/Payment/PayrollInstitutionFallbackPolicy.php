<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Smí se odvod poslat na účet instituce, jehož kód se neshoduje s nastavením?
 *
 * Rozhoduje o tom, co kód instituce znamená — viz
 * {@see PayrollInstitutionPaymentTargetResolver}.
 */
enum PayrollInstitutionFallbackPolicy
{
    /**
     * Kód je identita příjemce (zdravotní pojišťovna, druh daně u FÚ).
     * Jiný účet se použít nesmí ani tehdy, když je jediný.
     */
    case NEVER;

    /**
     * Kód je jen organizační značka jedné instituce (kód pracoviště OSSZ, kód
     * pojistitele u sazby zákonného pojištění). Když pod ním účet není, použije
     * se jednoznačný ověřený a účinný účet téže instituce.
     */
    case UNIQUE_VERIFIED_ACCOUNT;
}

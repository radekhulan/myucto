<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Datum skončení 10224 ve změně (A3) a opravě (A4) vztahu, který je u ČSSZ
 * už skončený. Jediné místo pro obě akce: službu oznámení
 * ({@see PayrollRegistrationEventService}), pravidlo variant delty
 * ({@see PayrollRegistrationDeltaVariantRule}) i pravidlo částí události
 * ({@see PayrollRegistrationEventVariantRule}).
 *
 * ČSSZ, Otázky a odpovědi k NV2027 (developers.mpsv.cz), odpověď 4: změna
 * nebo oprava ostatních údajů k vztahu skončenému u ČSSZ musí nést 10224,
 * jinak jde k ručnímu zpracování na OSSZ jako možný výmaz data skončení.
 * Podle 10224 se volí i ročník věty
 * ({@see PayrollRegistrationSchemaCatalog::versionFor()}).
 *
 * EDV 1.4.0.7 i 1.5.0.3, list Slovník, ID 10224: A3-OST „N", A3-10
 * a A3-SPEC „/", A4-OST, A4-10 i A4-SPEC „N". S vyplněným 10224 pak A4-OST
 * vyžaduje příznak skončení smrtí 10225 a podklady pro podporu
 * v nezaměstnanosti (ID 10376 až 10534, „PP" se stejnou podmínkou jako
 * u A2-OST), A4-SPEC jen důvod předčasného ukončení 10534 a A4-10 nic
 * dalšího. Tyhle části jsou tytéž jako v odhlášce A2 téže varianty, proto
 * je oprava přebírá z odhlášky, ne z formuláře.
 */
final class PayrollRegistrationRegisteredEnd
{
    /**
     * Datum skončení, které vede ČSSZ. 10224 musí být s ním SHODNÉ, jinak jde
     * podání k ručnímu zpracování (ČSSZ, odpověď 16. 4. 2026, GitHub MPSV
     * #380). Zdrojem je proto odeslaná odhláška A2; datum skončení z karty
     * vztahu je jen záloha pro vztah, ke kterému v aplikaci žádná odhláška
     * není (vztah odhlášený mimo aplikaci). Odhláška schválená v aplikaci,
     * ale u ČSSZ chybějící (neodeslaná nebo zamítnutá), zálohu vylučuje:
     * ČSSZ vztah vede jako trvající a změna s 10224 by šla k ručnímu
     * zpracování, viz {@see self::awaitsSentTermination()}. Ze zálohy se bere
     * jen datum, které už nastalo (odhláška má lhůtu osm dnů, stejně to bere
     * dohlášení {@see PayrollRegistrationProfileCompletion::delta()}). Shodu
     * obou zdrojů hlídá {@see PayrollRegistrationEventService}.
     */
    public static function endOn(
        int $actionCode,
        string $variant,
        ?string $sentTerminationEndOn,
        ?string $cardEndOn,
        string $today,
        bool $terminationInApp = false,
    ): ?string {
        if (!self::allowsEndOn($actionCode, $variant)) {
            return null;
        }
        if ($sentTerminationEndOn !== null && $sentTerminationEndOn !== '') {
            return $sentTerminationEndOn;
        }
        if ($terminationInApp) {
            return null;
        }
        if ($cardEndOn === null || $cardEndOn === '' || $cardEndOn > $today) {
            return null;
        }

        return $cardEndOn;
    }

    /**
     * Musí změna nebo oprava počkat na odeslání odhlášky? Ano, když varianta
     * 10224 nese a v aplikaci k vztahu je odhláška A2, kterou ČSSZ nemá
     * (schválená či připravená a neodeslaná, nebo zamítnutá). Datum skončení
     * by pak nebylo to, které vede ČSSZ, a A4 by převzala podklady pro
     * podporu z odhlášky, kterou ČSSZ nezná.
     */
    public static function awaitsSentTermination(
        int $actionCode,
        string $variant,
        bool $terminationSent,
        bool $terminationInApp,
    ): bool {
        return self::allowsEndOn($actionCode, $variant) && !$terminationSent && $terminationInApp;
    }

    /** Smí změna nebo oprava této varianty nést 10224 (EDV, ID 10224). */
    public static function allowsEndOn(int $actionCode, string $variant): bool
    {
        return match ($actionCode) {
            3 => $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST,
            4 => true,
            default => false,
        };
    }

    /**
     * Nese oprava s 10224 i části odhlášky A2 (10225, podklady pro podporu
     * v nezaměstnanosti)? A4-OST vždy, A4-SPEC kvůli 10534, A4-10 ne.
     */
    public static function carriesTerminationParts(int $actionCode, string $variant): bool
    {
        return $actionCode === 4 && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10;
    }
}

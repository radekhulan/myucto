<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\PayrollModuleAccess;

/**
 * Upozornění průvodců převodem, že zdroj obsahuje mzdy, které se do MyÚčta nepřevedou:
 * licence nezahrnuje mzdový doplněk, nebo firma nemá zapnutý modul Mzdy.
 *
 * Převod účetnictví tím nic neblokuje. Zaúčtování mezd je součástí převedeného účetního
 * deníku, účetnictví proto zůstává úplné; chybí jen mzdová evidence (zaměstnanci,
 * vztahy, zpracované měsíce). Průvodce ukáže upozornění výrazně v náhledu
 * ({@see self::RESPONSE_KEY}) a běh převodu ho zapíše do protokolu ({@see self::warn()}),
 * aby zůstalo v historii převodů.
 *
 * Licence se tu jen čte ({@see PayrollModuleAccess::isLicensed()} a `isEnabled()`).
 * Mzdy s licencí a vypnutým modulem převod mezd zapne sám
 * ({@see \MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup}), proto
 * upozornění u důvodu {@see self::REASON_MODULE_DISABLED} převod mezd nezakazuje.
 */
final class PayrollMigrationNotice
{
    public const CODE = 'payroll_not_transferred';
    public const RESPONSE_KEY = 'payroll_notice';
    public const REASON_UNLICENSED = 'unlicensed';
    public const REASON_MODULE_DISABLED = 'module_disabled';
    public const BUY_URL = '/activation/purchase#payroll-addon';

    /** Proč mzdy nejde převzít, nebo `null`, když modul Mzdy firma má zapnutý. */
    public static function reason(PayrollModuleAccess $access, int $supplierId): ?string
    {
        return self::reasonFor($access->isLicensed(), $access->isEnabled($supplierId));
    }

    public static function reasonFor(bool $licensed, bool $enabled): ?string
    {
        if (!$licensed) {
            return self::REASON_UNLICENSED;
        }
        return $enabled ? null : self::REASON_MODULE_DISABLED;
    }

    /**
     * Souhrn mezd přes víc agend (roků): měsíce a mzdy se sečtou, první a poslední měsíc
     * jsou krajní. Zaměstnance bez osobních čísel sečíst nejde, proto platí nejvyšší počet
     * jednoho roku. Agendy bez měsíců mezd se přeskočí.
     *
     * @param iterable<array<string,mixed>|null> $summaries
     * @return array{employees:int,months:int,first:?string,last:?string}|null
     */
    public static function combine(iterable $summaries): ?array
    {
        $out = null;
        foreach ($summaries as $summary) {
            if (!is_array($summary) || (int) ($summary['months'] ?? 0) <= 0) {
                continue;
            }
            $first = isset($summary['first']) ? (string) $summary['first'] : null;
            $last = isset($summary['last']) ? (string) $summary['last'] : null;
            $out ??= ['employees' => 0, 'months' => 0, 'first' => null, 'last' => null];
            $out['employees'] = max($out['employees'], (int) ($summary['employees'] ?? 0));
            $out['months'] += (int) $summary['months'];
            $out['first'] = $first !== null && ($out['first'] === null || $first < $out['first']) ? $first : $out['first'];
            $out['last'] = $last !== null && ($out['last'] === null || $last > $out['last']) ? $last : $out['last'];
        }
        return $out;
    }

    /**
     * Upozornění pro souhrn mezd ze zdroje, nebo `null`: zdroj mzdy nemá, nebo je firma
     * převzít může.
     *
     * @param array<string,mixed>|null $summary `employees`, `months`, `first`, `last` (`YYYY-MM`)
     * @param string $source podmět věty („Export", „Záloha")
     * @param string $retry kde se mzdy převedou po nápravě (věta bez tečky)
     * @return array{level:string,code:string,reason:string,message:string,context:array{employees:int,months:int,first:?string,last:?string,buy_url:?string}}|null
     */
    public static function build(?string $reason, ?array $summary, string $source = 'Export', string $retry = 'mzdy pak převeďte v průvodci Přechod z PAMICA'): ?array
    {
        $summary = self::combine([$summary]);
        if ($reason === null || $summary === null) {
            return null;
        }
        $period = match (true) {
            $summary['first'] !== null && $summary['last'] !== null && $summary['first'] !== $summary['last']
                => ' za ' . self::monthLabel($summary['first']) . ' až ' . self::monthLabel($summary['last']),
            $summary['last'] !== null => ' za ' . self::monthLabel($summary['last']),
            default => '',
        };
        $why = $reason === self::REASON_UNLICENSED
            ? 'licence nezahrnuje mzdový doplněk. Rozšiřte licenci o mzdový doplněk (Aktivace → Zakoupení) a ' . $retry . '.'
            : 'firma nemá zapnutý modul Mzdy. Zapněte Mzdy v Nastavení firmy (Daně a účetnictví → Vést mzdy) a ' . $retry . '.';
        return [
            'level' => 'warning',
            'code' => self::CODE,
            'reason' => $reason,
            'message' => sprintf('%s obsahuje mzdy %d zaměstnanců%s (%d měsíců), které se do MyÚčta nepřevedou: %s '
                . 'Zaúčtování mezd je součástí převedeného účetního deníku, účetnictví proto zůstává úplné.',
                $source, $summary['employees'], $period, $summary['months'], $why),
            'context' => $summary + ['buy_url' => $reason === self::REASON_UNLICENSED ? self::BUY_URL : null],
        ];
    }

    /**
     * Upozornění pro firmu podle licence a modulu Mzdy.
     *
     * @param array<string,mixed>|null $summary
     * @return array{level:string,code:string,reason:string,message:string,context:array<string,mixed>}|null
     */
    public static function forSupplier(PayrollModuleAccess $access, int $supplierId, ?array $summary, string $source = 'Export', string $retry = 'mzdy pak převeďte v průvodci Přechod z PAMICA'): ?array
    {
        if (self::combine([$summary]) === null) {
            return null;
        }
        return self::build(self::reason($access, $supplierId), $summary, $source, $retry);
    }

    /**
     * Přidá upozornění do odpovědi náhledu průvodce; bez něj vrátí odpověď beze změny.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $notice
     * @return array<string,mixed>
     */
    public static function attach(array $payload, ?array $notice): array
    {
        if ($notice !== null) {
            $payload[self::RESPONSE_KEY] = $notice;
        }
        return $payload;
    }

    /**
     * Zapíše upozornění do protokolu běhu (zkouška nanečisto i ostrý převod).
     *
     * @param array<string,mixed>|null $notice
     */
    public static function warn(ImportProtocol $protocol, string $step, ?array $notice): void
    {
        if ($notice === null) {
            return;
        }
        $protocol->warn($step, self::CODE, (string) $notice['message'], ['reason' => $notice['reason']] + (array) $notice['context']);
    }

    private static function monthLabel(string $period): string
    {
        return preg_match('/^(\d{4})-(\d{2})/', $period, $m) === 1 ? $m[2] . '/' . $m[1] : $period;
    }
}

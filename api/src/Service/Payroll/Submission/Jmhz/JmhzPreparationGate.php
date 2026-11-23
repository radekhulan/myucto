<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use Psr\Clock\ClockInterface;

/**
 * Kdy jde měsíční hlášení za období VŮBEC připravit (zmrazit).
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Hlášení za měsíc, který ještě neskončil, nejde podat: katalog kontrol ho
 * zastaví (kontrola 90 a s ní 43 a 44 u každého formuláře). Aplikace přesto
 * přípravu nabídla, zaevidovala povinnost a teprve pak vypsala řadu kontrol
 * s daty. Účetní z nich nepoznala, že stačí počkat na konec měsíce, a řádek
 * přehledu mezitím změnil stav.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Dvě závory, které účetní vlastními údaji neodstraní, se posuzují PŘEDEM
 * a jednou větou: období neskončilo (s datem, od kdy to půjde) a ročník
 * hlášení aplikace ještě sestavit neumí. Přehled podání podle nich akci
 * zneaktivní, příprava se na ně zeptá dřív, než cokoli založí.
 *
 * Konec období se posuzuje TÝMŽ pravidlem jako kontrola 90 katalogu
 * ({@see self::periodFinished()}), aby se závora a kontrola nerozešly.
 */
final readonly class JmhzPreparationGate
{
    /** Hlášené období ještě neskončilo. */
    public const PERIOD_NOT_FINISHED_CODE = 'jmhz_period_not_finished';

    /**
     * Kontroly katalogu, které u neskončeného období selžou jen kvůli dnešnímu
     * datu: 90 (období neskončilo) a 43, 44 (pojištění trvá po datu vyplnění).
     *
     * @var list<int>
     */
    public const DATE_ONLY_CONTROL_IDS = [43, 44, 90];

    public function __construct(
        private JmhzDeadlinePolicy $deadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * Důvod, proč hlášení za období teď připravit nejde, nebo `null`.
     *
     * @param string $periodStart první den vykazovaného měsíce (`RRRR-MM-DD`)
     * @return array{code:string,message:string,available_from:?string}|null
     */
    public function blocked(string $periodStart): ?array
    {
        $period = substr($periodStart, 0, 7);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            return null;
        }
        try {
            JmhzSpecPackageCatalog::requireBuildable(JmhzSpecVersion::forPeriodStart($periodStart));
        } catch (JmhzXmlException $exception) {
            if ($exception->validationCode !== JmhzSpecPackageCatalog::UNAVAILABLE_CODE) {
                throw $exception;
            }

            return [
                'code' => JmhzSpecPackageCatalog::UNAVAILABLE_CODE,
                'message' => $exception->getMessage() . $this->dueSentence($periodStart),
                'available_from' => null,
            ];
        }
        if (!self::periodFinished($period, PayrollSubmissionCalendar::today($this->clock->now()))) {
            return [
                'code' => self::PERIOD_NOT_FINISHED_CODE,
                'message' => self::notFinishedMessage($period),
                'available_from' => self::availableFrom($period),
            ];
        }

        return null;
    }

    /** Zastaví přípravu dřív, než se cokoli založí. */
    public function assertOpen(string $periodStart): void
    {
        $blocked = $this->blocked($periodStart);
        if ($blocked !== null) {
            throw new JmhzXmlException($blocked['code'], $blocked['message']);
        }
    }

    /**
     * Skončilo vykazované období k danému dni? Hlášení se podává až za
     * uplynulý kalendářní měsíc (katalog kontrol MH, kontrola 90).
     *
     * @param string $period `RRRR-MM`
     * @param string $evaluatedOn den posouzení (`RRRR-MM-DD`)
     */
    public static function periodFinished(string $period, string $evaluatedOn): bool
    {
        return strcmp($period, substr($evaluatedOn, 0, 7)) < 0;
    }

    /** První den, kdy jde hlášení za období připravit (`RRRR-MM-DD`). */
    public static function availableFrom(string $period): string
    {
        return (new \DateTimeImmutable($period . '-01'))->modify('first day of next month')->format('Y-m-d');
    }

    /** Jedna věta místo výčtu kontrol 43, 44 a 90. */
    public static function notFinishedMessage(string $period): string
    {
        return sprintf(
            'Období %s ještě neskončilo. Měsíční hlášení se podává až za uplynulý kalendářní měsíc, '
            . 'připravit ho půjde od %s. Do té doby můžete obsah ověřit testem hlášení.',
            self::periodLabel($period),
            self::czechDate(self::availableFrom($period)),
        );
    }

    private function dueSentence(string $periodStart): string
    {
        try {
            $dueOn = $this->deadlines->forPeriod($periodStart)->dueOn;
        } catch (\Throwable) {
            // Období bez účinné sady lhůt: věta o termínu se vynechá, důvod zůstává.
            return '';
        }

        return ' Termín podání za ' . self::periodLabel(substr($periodStart, 0, 7)) . ' je '
            . self::czechDate($dueOn) . '.';
    }

    private static function periodLabel(string $period): string
    {
        return substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    private static function czechDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }
}

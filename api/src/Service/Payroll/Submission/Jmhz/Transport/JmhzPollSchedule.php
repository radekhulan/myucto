<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Kdy se příště zeptat ČSSZ na výsledek zpracování a kdy to vzdát.
 *
 * Rozvrh je schválně samostatná třída bez závislostí: je to jediné místo, kde
 * se rozhoduje, jak často smíme obtěžovat protistranu, a takové rozhodnutí se
 * má dát přečíst i otestovat bez databáze a bez sítě.
 *
 * **Odstup dotazů — exponenciální se stropem, podlaha od brány.** VREP sám
 * v potvrzení říká, za jak dlouho se má zeptat znovu
 * (`ResponseEndPoint/@PollInterval`, viz {@see JmhzAcknowledgementParser}).
 * Ta hodnota je podlaha, ne rozvrh: ptát se každou minutu půl dne je zbytečné
 * i nezdvořilé, protože protokol JMHZ nevzniká v řádu sekund. Interval se
 * proto od druhého dotazu zdvojnásobuje a zastaví se na hodině. Pevný odstup
 * by u rychlých podání zbytečně čekal a u pomalých zbytečně bušil; exponenciála
 * s podlahou dělá obojí správně a v ustáleném stavu vyjde na dotaz za hodinu.
 *
 * **Strop.** Vzdát to musí být možné, jinak by pokus, na který ČSSZ nikdy
 * neodpoví, kolotal do konce světa. Rozhoduje STÁŘÍ ODESLÁNÍ: po
 * {@see MAX_AGE_HOURS} hodinách od odeslání už protokol nepřijde sám od sebe a
 * je to věc člověka, ne opakování. Počet dotazů ({@see MAX_ATTEMPTS}) je jen
 * pojistka pro případ, že by hodiny nebo rozvrh selhaly — při hodinovém odstupu
 * se ho nedá dosáhnout dřív než stáří.
 */
final readonly class JmhzPollSchedule
{
    /** Doporučení brány pod minutu nectíme; pod ní se ptát nemá smysl. */
    public const MIN_INTERVAL_SECONDS = 60;

    /** Ustálený stav. Delší odstup by u lhůty do 20. dne ubíral prostor. */
    public const MAX_INTERVAL_SECONDS = 3600;

    /**
     * Deset dní. Původní strop byl tři dny, ale v oficiální diskuzi ČSSZ je
     * doložené podání, které zůstalo „ve zpracování" sedm dní, a ČSSZ sama
     * potvrdila, že balík nad 300 formulářů padá do večerní/noční dávky s
     * odpovědí až druhý den a že testovací služba DZMH umí být mimo provoz i
     * několik dní. Tři dny tuhle realitu podstřelovaly; deset dní pokrývá
     * doložené sedmidenní zpracování a nechává rezervu na víkend nebo výpadek
     * navrch.
     */
    public const MAX_AGE_HOURS = 240;

    /**
     * Pojistka proti rozbitému rozvrhu, ne provozní strop. Musí zůstat vyšší,
     * než kolik dotazů padne do {@see MAX_AGE_HOURS} při ustáleném hodinovém
     * odstupu (viz {@see JmhzPollScheduleTest}), jinak by tenhle „bezpečnostní"
     * strop vzdával podání dřív, než mu dá šanci stáří odeslání.
     */
    public const MAX_ATTEMPTS = 300;

    /** Uzavření transakce je jeden krátký dotaz; víc než pár pokusů nemá smysl. */
    public const MAX_CLOSE_ATTEMPTS = 8;

    /**
     * Odstup dalšího dotazu v sekundách.
     *
     * @param int $pollCount kolik dotazů už proběhlo (0 = ještě žádný)
     * @param int|null $gatewayIntervalSeconds doporučení VREP z posledního potvrzení
     */
    public static function delaySeconds(int $pollCount, ?int $gatewayIntervalSeconds = null): int
    {
        $floor = max(self::MIN_INTERVAL_SECONDS, $gatewayIntervalSeconds ?? 0);
        // Exponent se zastaví dřív, než by přetekl; strop stejně sráží výš.
        $exponent = min(max($pollCount, 0), 16);
        $delay = $floor * (2 ** $exponent);

        return (int) min($delay, self::MAX_INTERVAL_SECONDS);
    }

    /** Termín dalšího dotazu ve tvaru, v jakém ho drží ledger (UTC). */
    public static function nextRetryAt(
        \DateTimeImmutable $now,
        int $pollCount,
        ?int $gatewayIntervalSeconds = null,
    ): string {
        return $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . self::delaySeconds($pollCount, $gatewayIntervalSeconds) . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    /** Termín dalšího pokusu o uzavření transakce (UTC). */
    public static function nextCloseAt(\DateTimeImmutable $now, int $closeAttempts): string
    {
        return $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . self::delaySeconds($closeAttempts) . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Došla trpělivost? Vrací důvod, podle kterého se dá jednat, nebo `null`,
     * když se ještě smí ptát.
     *
     * Neznámé `sent_at` se schválně počítá jako „vzdát to": pokus, u kterého
     * nevíme, kdy odešel, nemá jak stárnout, a mlčky opakovaný dotaz by u něj
     * běžel navždy.
     */
    public static function exhaustedReason(
        \DateTimeImmutable $now,
        ?string $sentAtUtc,
        int $pollCount,
    ): ?string {
        if ($pollCount >= self::MAX_ATTEMPTS) {
            return 'ČSSZ na ' . self::MAX_ATTEMPTS . ' dotazů na výsledek neodpověděla'
                . ' protokolem. Zkontrolujte podání ručně na ePortálu ČSSZ nebo'
                . ' v datové schránce a protokol načtěte do přehledu odeslání.';
        }
        $sentAt = self::parse($sentAtUtc);
        if ($sentAt === null) {
            return 'U pokusu chybí čas odeslání, takže nelze rozhodnout, jak dlouho'
                . ' se na protokol čeká. Ověřte stav podání ručně na ePortálu ČSSZ.';
        }
        $ageHours = ($now->getTimestamp() - $sentAt->getTimestamp()) / 3600;
        if ($ageHours >= self::MAX_AGE_HOURS) {
            return 'Od odeslání uplynulo víc než ' . self::MAX_AGE_HOURS
                . ' hodin a ČSSZ protokol nevydala. Zkontrolujte podání na ePortálu'
                . ' ČSSZ nebo v datové schránce a protokol načtěte do přehledu odeslání.';
        }

        return null;
    }

    private static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );

        return $parsed === false ? null : $parsed;
    }
}

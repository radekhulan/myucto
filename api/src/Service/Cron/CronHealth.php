<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

/**
 * Vyhodnocení zdraví jedné plánované úlohy z jejího heartbeatu.
 *
 * Vytaženo z {@see \MyInvoice\Action\Admin\CronJobsAction} hlavně kvůli režimu
 * DISPATCHER, kde „poslední běh" už není spolehlivý sám o sobě:
 *
 * Dispatcher úlohu s pracovní bránou ({@see CronDispatcher::gatedScripts()})
 * vůbec nespustí, když pro ni není práce. Nevznikne tedy žádný tick, heartbeat
 * stárne a úloha se rozsvítí jako `overdue`, přestože je všechno v pořádku —
 * `cron-epo-status` (max_age 1 h) a `cron-ai-worker` (2 h) tak v klidné instalaci
 * svítily varovně prakticky pořád. V režimu INDIVIDUAL se tytéž úlohy spustily
 * naprázdno a zapsaly `noop` tick, takže byly OK.
 *
 * Řešení: u gatovaných úloh se v režimu dispatcheru za důkaz života bere
 * heartbeat SAMOTNÉHO dispatcheru — běží-li on, běží i plánování těch úloh a
 * jejich ticho je záměr, ne výpadek. Výsledkem je stav {@see self::IDLE}.
 *
 * Relaxace se schválně týká JEN gatovaných úloh: ostatní dispatcher spouští vždy,
 * když jsou na řadě, takže jejich heartbeat se pohybovat MUSÍ a jeho stárnutí je
 * pořád platný poplach (například proces, který zemře dřív, než stihne cokoli
 * zapsat). A chybu ({@see self::FAILING}) nepřebíjí nikdy nic — ta se hlásí
 * v obou režimech stejně.
 *
 * Druhá relaxace, ze stejného důvodu jako IDLE, řeší opačný extrém:
 * {@see self::NEVER_RAN} bez ohledu na stáří instalace by na čerstvém nasazení
 * svítilo červeně u KAŽDÉ úlohy hned od prvního přihlášení do admina — takže by
 * z varování udělalo šum, který se přestane číst. Instalace, která neběží ani
 * jednu periodu úlohy (`max_age_hours`), na "nikdy neběželo" nárok nemá — {@see
 * self::PENDING}. Teprve když instalace tuhle periodu přeroste a heartbeat
 * pořád chybí, je to skutečný nález (viz issue #6 — špatné jméno wrapperu
 * v Dockeru shodilo VŠECHNY plánované úlohy a stránka to celou dobu tiše
 * ukazovala jako "ještě neběželo", bez jediného varování).
 */
final class CronHealth
{
    /** Poslední úspěšný běh je v limitu `max_age_hours`. */
    public const OK = 'ok';
    /** Poslední úspěšný běh je starší, než by měl být. */
    public const OVERDUE = 'overdue';
    /** Poslední běh skončil chybou. */
    public const FAILING = 'failing';
    public const OVERDUE_AND_FAILING = 'overdue_and_failing';
    /** Žádný běh v historii a instalace už měla na první běh dost času — poplach. */
    public const NEVER_RAN = 'never_ran';
    /** Dispatcher žije, ale úloha nemá co dělat, tak ji nespouští. */
    public const IDLE = 'idle';
    /** Žádný běh v historii, ale instalace ještě nestihla ani jednu periodu úlohy. */
    public const PENDING = 'pending';

    /** Dispatcher nežije, takže se nespouští nic z toho, co spouští on. */
    public const SCHEDULER_DISPATCHER_DOWN = 'dispatcher_down';
    /** V režimu jednotlivých úloh neproběhla ve svém intervalu žádná: cron na serveru nejede. */
    public const SCHEDULER_NOTHING_RUNS = 'nothing_runs';

    /** Zdroj, ze kterého stav vychází — kvůli srozumitelnému tooltipu v UI. */
    public const SOURCE_SELF = 'self';
    public const SOURCE_DISPATCHER = 'dispatcher';

    /**
     * @param int|null $ageSecSinceOk Stáří posledního úspěšného (i prázdného) běhu; null = nikdy neběželo.
     * @param string|null $lastStatus Stav posledního doběhu: ok | noop | error | null.
     * @param int $maxAgeSec Limit z katalogu (`max_age_hours` × 3600) — zároveň bere
     *                       jako "perioda" úlohy pro účely {@see self::PENDING}.
     * @param bool $dispatcherGated Spouští tuhle úlohu dispatcher jen když má práci?
     * @param bool $dispatcherAlive Žije sám dispatcher (jeho vlastní heartbeat je čerstvý)?
     * @param int|null $installAgeSec Stáří instalace (od první migrace). Null = neznámé —
     *                                chová se jako dřív (bez PENDING relaxace).
     *
     * @return array{0:string,1:string} [health, source]
     */
    public static function evaluate(
        ?int $ageSecSinceOk,
        ?string $lastStatus,
        int $maxAgeSec,
        bool $dispatcherGated = false,
        bool $dispatcherAlive = false,
        ?int $installAgeSec = null,
    ): array {
        $health = self::NEVER_RAN;
        if ($ageSecSinceOk !== null) {
            $health = $ageSecSinceOk > $maxAgeSec ? self::OVERDUE : self::OK;
        } elseif ($installAgeSec !== null && $installAgeSec <= $maxAgeSec) {
            // Instalace ještě nedoběhla ani jednu periodu téhle úlohy — "nikdy
            // neběželo" je tu očekávaný přechodný stav, ne poplach.
            $health = self::PENDING;
        }

        // Chyba posledního běhu má přednost před vším ostatním — i před relaxací
        // níž. Když úloha spadla, není „nečinná", ale rozbitá — bez ohledu na to,
        // jak čerstvá je instalace.
        if ($lastStatus === 'error') {
            return [
                $health === self::OK || $health === self::NEVER_RAN || $health === self::PENDING
                    ? self::FAILING
                    : self::OVERDUE_AND_FAILING,
                self::SOURCE_SELF,
            ];
        }

        if ($dispatcherGated && $dispatcherAlive && ($health === self::OVERDUE || $health === self::NEVER_RAN)) {
            return [self::IDLE, self::SOURCE_DISPATCHER];
        }

        return [$health, self::SOURCE_SELF];
    }

    /**
     * Stojí celý plánovač, ne jednotlivé úlohy?
     *
     * Když nejede dispatcher nebo cron samotný, jsou všechny úlohy „zaseklé"
     * naráz. Hlásit je po jedné vede k hledání chyby v každé z nich, přitom
     * náprava je jediná. Proto se to pozná tady a hlásí se jeden nález.
     *
     * DISPATCHER: rozhoduje jeho vlastní heartbeat ({@see isDispatcherAlive()}).
     * INDIVIDUAL: aspoň dvě úlohy po limitu a žádná ve svém intervalu. Jediná
     * zaseklá úloha vedle běžících je nález o té úloze, ne o plánovači.
     *
     * Sdílí ho kontrola prostředí i stránka Plánované úlohy.
     *
     * @param int $overdue Kolik aktivních úloh je po svém limitu.
     * @param int $running Kolik aktivních úloh proběhlo ve svém intervalu (i s chybou, i nečinně).
     */
    public static function schedulerDown(string $mode, bool $dispatcherAlive, int $overdue, int $running): ?string
    {
        if ($overdue === 0) {
            return null;
        }
        if ($mode === CronScheduleMode::DISPATCHER) {
            return $dispatcherAlive ? null : self::SCHEDULER_DISPATCHER_DOWN;
        }
        return $overdue >= 2 && $running === 0 ? self::SCHEDULER_NOTHING_RUNS : null;
    }

    /**
     * Stáří instalace v sekundách — od úplně první aplikované migrace.
     *
     * Vybráno záměrně místo `cron_heartbeat`/`cron_runs`: ty jsou prázdné přesně
     * v tu chvíli, kdy potřebujeme vědět "je tahle instalace nová?" (žádný tick
     * ještě neproběhl), takže by se nedaly použít jako zdroj. `migrations` naproti
     * tomu existuje od prvního spuštění `migrate.php` (= od instalace) a řádek
     * s nejstarším `applied_at` přežije restart kontejneru i přeinstalaci image —
     * je to čistě databázový fakt.
     *
     * @param string|null $firstMigratedAt `MIN(applied_at)` z tabulky `migrations`, nebo null.
     */
    public static function installAgeSec(?string $firstMigratedAt, int $now): ?int
    {
        if ($firstMigratedAt === null) {
            return null;
        }
        $ts = strtotime($firstMigratedAt);
        if ($ts === false) {
            return null;
        }
        return max(0, $now - $ts);
    }

    /**
     * Je dispatcher naživu? Bere se jeho vlastní heartbeat — `last_ok_at` posouvá
     * i prázdný tick, takže čerstvá hodnota znamená „plánovací smyčka běží".
     *
     * Tick, který skončil chybou (nepodařilo se spustit proces), se za důkaz
     * života NEPOVAŽUJE: právě tehdy může být ticho podřízené úlohy způsobené
     * selhaným spuštěním, a to se maskovat nesmí.
     *
     * @param array<string,mixed>|null $heartbeat řádek z `cron_heartbeat` pro `cron-dispatch`
     */
    public static function isDispatcherAlive(?array $heartbeat, int $maxAgeSec, int $now): bool
    {
        if ($heartbeat === null || ($heartbeat['last_status'] ?? null) === 'error') {
            return false;
        }
        $lastOkAt = $heartbeat['last_ok_at'] ?? null;
        if ($lastOkAt === null) {
            return false;
        }
        $ts = strtotime((string) $lastOkAt);
        if ($ts === false) {
            return false;
        }
        return ($now - $ts) <= $maxAgeSec;
    }
}

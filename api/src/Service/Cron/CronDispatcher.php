<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use DateTimeImmutable;
use MyInvoice\Service\System\MaintenanceLock;
use PDO;
use Throwable;

/**
 * Rozhodne, které úlohy z {@see CronCatalog} jsou v aktuální minutě na řadě,
 * a spustí je. Srdce režimu {@see CronScheduleMode::DISPATCHER}.
 *
 * Postup jednoho ticku:
 *   0. je-li položený zámek údržby ({@see \MyInvoice\Service\System\MaintenanceLock}),
 *      nespouštěj NIC nového — už běžící úlohy ale nech doběhnout, viz
 *      {@see self::SKIP_MAINTENANCE},
 *   1. projdi katalog (bez sebe sama) a nech si jen úlohy, jejichž `linux_cron`
 *      sedí na tuhle minutu,
 *   2. zahoď ty, které u téhle instalace nedávají smysl ({@see CronJobGate}),
 *   3. zahoď ty, které prokazatelně nemají co dělat ({@see CronPreflight}),
 *   4. nárokuj si minutu (INSERT IGNORE do cron_dispatch_claims) — ochrana proti
 *      dvojímu spuštění, když dispatcher poběží v téže minutě dvakrát,
 *   5. spusť zbytek jako samostatné procesy na pozadí.
 *
 * PROČ SAMOSTATNÉ PROCESY: úloha, která spadne na fatal erroru nebo se zasekne
 * na síťovém volání, nesmí strhnout ostatní ani zablokovat další tick. Izolace
 * je tím pádem stejná jako u dvaceti samostatných položek v crontabu — což je
 * přesně to, co má režim INDIVIDUAL a co se nesmí ztratit.
 *
 * ⚠️ Dispatcher NEDĚLÁ práci úloh. Jen je spouští. Díky tomu je jeho vlastní
 * bootstrap lehký (Config + PDO, bez DI kontejneru) a tick, kdy není co dělat,
 * stojí jedno spojení do DB a pár indexovaných dotazů.
 */
final class CronDispatcher
{
    /**
     * Levné brány „má tahle úloha vůbec co dělat?". Klíč = skript, hodnota =
     * predikát nad otevřeným PDO. Úloha bez záznamu se spouští vždy, když je
     * na řadě — což je bezpečný default: chybějící brána stojí jeden zbytečný
     * běh, kdežto špatná brána tiše zastaví práci.
     *
     * @var array<string,callable(PDO):bool>
     */
    private const WORK_GATES = [
        'cron-epo-status' => [CronPreflight::class, 'hasEpoWork'],
        'cron-ai-worker'  => [CronPreflight::class, 'hasAiWork'],
    ];

    /** Minuta nárokována — úloha se spustí. */
    public const CLAIM_OK = 'claimed';
    /** Tuhle minutu už někdo nárokoval (druhý běh dispatcheru). */
    public const CLAIM_DUPLICATE = 'already_dispatched';
    /** Nelze zaručit jedinečnost → raději nespouštět. */
    public const CLAIM_UNAVAILABLE = 'claim_unavailable';

    /**
     * Instalace je v údržbě — úloha se v tomhle ticku nespustí.
     *
     * ⚠️ Zámek zastavuje jen SPOUŠTĚNÍ nových úloh. Už běžící procesy se
     * nechávají doběhnout a nikdy se nezabíjejí: záloha spuštěná ve 02:00 může
     * u velké instance běžet ještě v okamžiku, kdy provozovatel zámek položí,
     * a údržba nesmí zabít dump uprostřed. Že je ještě co dobíhat, se hosting
     * dozví z `/api/health` (`jobs.running`).
     */
    public const SKIP_MAINTENANCE = 'maintenance';

    public function __construct(
        private readonly PDO $pdo,
        private readonly CronJobGate $gate,
        private readonly CronProcessLauncher $launcher,
        private readonly ?MaintenanceLock $maintenance = null,
    ) {}

    /**
     * Skripty, které dispatcher spouští jen když pro ně je práce.
     *
     * Potřebuje to UI: takové úloze v klidné instalaci heartbeat legitimně stárne
     * (nespustí se → nemá co zapsat), takže se její zdraví posuzuje podle
     * dispatcheru. Viz {@see CronHealth}.
     *
     * @return list<string>
     */
    public static function gatedScripts(): array
    {
        return array_keys(self::WORK_GATES);
    }

    /**
     * Odbav jednu minutu.
     *
     * @return array{minute:string,due:list<string>,launched:list<string>,skipped:array<string,string>,errors:array<string,string>}
     */
    public function tick(DateTimeImmutable $now, bool $dryRun = false): array
    {
        $minuteBucket = $now->format('Y-m-d H:i:00');
        $report = [
            'minute'   => $minuteBucket,
            'due'      => [],
            'launched' => [],
            'skipped'  => [],
            'errors'   => [],
        ];

        // Zámek se čte JEDNOU za tick, ne u každé úlohy: v rámci jedné minuty
        // musí být rozhodnutí konzistentní, aby se půlka katalogu nespustila
        // a půlka ne. Odstranění zámku se projeví hned v dalším ticku.
        $maintenance = $this->maintenance?->isActive() ?? false;

        // Rozvrh smluvně řízených úloh (`cron-backup`) se bere z databáze, ne z katalogu
        // — viz CronCatalog::withContractedSchedules(). Načítá se jednou za tick.
        foreach (CronCatalog::withContractedSchedules(
            CronCatalog::dispatchable($this->gate->isManagedInstallation()),
            $this->pdo,
        ) as $job) {
            $script = (string) $job['script'];

            try {
                if (!CronExpression::matches((string) $job['linux_cron'], $now)) {
                    continue;
                }
            } catch (Throwable $e) {
                // Nesrozumitelný výraz v katalogu nesmí shodit celý tick —
                // ostatní úlohy musí proběhnout. Chyba jde do reportu, ať je vidět.
                $report['errors'][$script] = 'bad_cron_expression: ' . $e->getMessage();
                continue;
            }

            $report['due'][] = $script;

            // Údržba se vyhodnocuje hned po „je na řadě" a před vším ostatním:
            // gate ani preflight nemají v údržbě co dělat v databázi, kterou
            // možná zrovna přepisuje migrace provozovatele.
            if ($maintenance) {
                $report['skipped'][$script] = self::SKIP_MAINTENANCE;
                continue;
            }

            // Admin/spravovaná instalace úlohu výslovně vypnula přes
            // `cron.disabled_jobs` — to musí vyhrát nad vším ostatním (i nad
            // "not_configured"), stejně jako v CronJobGate::inactiveReason().
            // Vlastní důvod v reportu, ať je vidět, že jde o záměr, ne o poruchu.
            if ($this->gate->isDisabledByConfig($script)) {
                $report['skipped'][$script] = 'disabled_by_config';
                continue;
            }

            // Důvod se bere od brány, ne se tu odhaduje — tatáž situace se
            // pak v reportu dispatcheru i v přehledu úloh jmenuje stejně.
            $blocked = $this->gate->schedulableBlockReason($job);
            if ($blocked !== null) {
                $report['skipped'][$script] = $blocked;
                continue;
            }

            if (!$this->hasWork($script)) {
                $report['skipped'][$script] = 'no_work';
                continue;
            }

            if ($dryRun) {
                $report['launched'][] = $script;
                continue;
            }

            $claim = $this->claimMinute($script, $minuteBucket);
            if ($claim !== self::CLAIM_OK) {
                $report['skipped'][$script] = $claim;
                continue;
            }

            $error = null;
            if ($this->launcher->launch($script, $error)) {
                $report['launched'][] = $script;
            } else {
                $report['errors'][$script] = 'launch_failed: ' . (string) $error;
            }
        }

        // V údržbě se nezapisuje ani úklidový DELETE — retence claimů počká,
        // tabulka je malá a hodina navíc jí neublíží.
        if (!$dryRun && !$maintenance) {
            $this->purgeOldClaims($now);
        }

        return $report;
    }

    private function hasWork(string $script): bool
    {
        $gate = self::WORK_GATES[$script] ?? null;
        if ($gate === null) {
            return true;
        }
        try {
            return (bool) $gate($this->pdo);
        } catch (Throwable) {
            return true; // fail-open — raději běh navíc než zmeškaná práce
        }
    }

    /**
     * Atomicky si nárokuje (skript, minuta). Úlohu spustí jen ten, kdo řádek
     * skutečně vložil.
     *
     * Používá obyčejný INSERT a chytá porušení unique klíče, ne `INSERT IGNORE`.
     * Rozdíl není kosmetický: `IGNORE` degraduje na warning ÚPLNĚ VŠECHNY chyby
     * (useknutý název skriptu, špatný typ), takže by se selhaný zápis tvářil
     * stejně jako obsazená minuta a úloha by se tiše nespustila. Takhle je vidět,
     * co se stalo — a navíc to funguje i mimo MySQL.
     *
     * @return self::CLAIM_* důvod, proč se úloha (ne)spustí
     */
    private function claimMinute(string $script, string $minuteBucket): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cron_dispatch_claims (script, minute_bucket, claimed_at)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$script, $minuteBucket, date('Y-m-d H:i:s')]);
            return self::CLAIM_OK;
        } catch (Throwable $e) {
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                return self::CLAIM_DUPLICATE;
            }
            // Bez funkční claim tabulky je bezpečnější úlohu NESPUSTIT: duplicitní
            // běh cron-generate-recurring-invoices vyrobí doklady navíc, kdežto
            // vynechaný běh se dožene za minutu a UI ho nahlásí jako overdue.
            return self::CLAIM_UNAVAILABLE;
        }
    }

    private function purgeOldClaims(DateTimeImmutable $now): void
    {
        // Stačí jednou za hodinu — tabulka je malá a mazání každou minutu by bylo
        // víc práce než užitku.
        if ((int) $now->format('i') !== 0) {
            return;
        }
        try {
            $this->pdo
                ->prepare('DELETE FROM cron_dispatch_claims WHERE minute_bucket < ?')
                ->execute([$now->modify('-2 hours')->format('Y-m-d H:i:00')]);
        } catch (Throwable) {
            // best-effort
        }
    }
}

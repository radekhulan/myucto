<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;
use Throwable;

/**
 * Rozhoduje, které úlohy z {@see CronCatalog} mají u téhle instalace smysl.
 *
 * Jeden zdroj pravdy pro dva konzumenty, které se dřív rozcházely:
 *   - UI „Systém → Plánované úlohy" (CronJobsAction) — co vůbec zobrazit,
 *   - generátor crontabu pro Docker (DockerCrontabGenerator) — co vůbec plánovat.
 *
 * ⚠️ Ty dvě otázky NEJSOU stejné, proto má třída dvě metody:
 *
 *   inactiveReason() — jediná definice „tahle úloha u téhle instalace nemá co
 *                      dělat" a zároveň proč. Smí se ptát i na podmínky, které
 *                      se mění za běhu (opt-in AI, typ účetnictví, plátcovství
 *                      DPH). Čte ji UI přehledu i kontrola prostředí, aby se
 *                      obě nerozešly. `isVisibleInUi()` je jen její predikát.
 *
 *   isSchedulable()  — smí se ptát JEN na podmínky stabilní přes restart
 *                      kontejneru, protože crontab se generuje jednou při startu.
 *                      Kdyby sem spadl opt-in AI, admin by ho zapnul v UI a cron
 *                      by se rozjel až po restartu — tiše, bez jakéhokoli signálu.
 *                      Dynamické podmínky patří do {@see CronPreflight}, který
 *                      běží uvnitř skriptu při každém ticku.
 *
 * Brána je záměrně **fail-open**: když se nedá přečíst config nebo databáze,
 * úloha zůstane naplánovaná. Nedostupná DB při startu kontejneru nesmí vést
 * k tomu, že se cron tiše vypne a nikdo si toho měsíc nevšimne.
 *
 * Výjimka z fail-open: `cron.disabled_jobs` (seznam jmen úloh z katalogu) je
 * explicitní vůle admina/spravované instalace danou úlohu nespouštět — vyhrává
 * nad vším ostatním v {@see inactiveReason()} a kontroluje se jako úplně první,
 * ještě před DB-dotazovanými feature probes.
 */
final class CronJobGate
{
    /** Chybí adresář/schránka, se kterou úloha pracuje. */
    public const INACTIVE_NOT_CONFIGURED = 'not_configured';
    /** Funkce, kterou úloha obsluhuje, není u téhle instalace zapnutá. */
    public const INACTIVE_FEATURE_OFF = 'feature_off';
    /** Položka patří do jiného režimu plánování, než v jakém instalace běží. */
    public const INACTIVE_OTHER_MODE = 'other_mode';
    /** Admin úlohu výslovně vypnul přes `cron.disabled_jobs` (spravovaná instalace, cizí hosting apod.). */
    public const INACTIVE_DISABLED_BY_CONFIG = 'disabled_by_config';
    /** Úloha dává smysl jen ve spravovaném provozu, a ten tu není zapnutý. */
    public const INACTIVE_MANAGED_ONLY = 'managed_only';

    /** Cfg klíč se seznamem jmen úloh z {@see CronCatalog}, které mají na téhle instalaci zůstat vypnuté. */
    private const DISABLED_JOBS_CONFIG_KEY = 'cron.disabled_jobs';

    /** Aspoň jeden dodavatel vede podvojné účetnictví. */
    public const FEATURE_DOUBLE_ENTRY = 'double_entry';
    /** Aspoň jeden dodavatel vede podvojné účetnictví a je plátce/identifikovaná osoba. */
    public const FEATURE_VAT_DOUBLE_ENTRY = 'vat_double_entry';

    /**
     * Sondy k `requires_feature` z {@see CronCatalog}. Jeden indexovaný dotaz
     * na funkci — brána běží na každý request přehledu i diagnostiky.
     *
     * Dotazy jsou ZÁMĚRNĚ shodné s výběrem kandidátů v samotných úlohách
     * ({@see \MyInvoice\Service\Accounting\Payroll\PayrollAutoPostService::doubleEntrySupplierIds()},
     * {@see \MyInvoice\Service\Accounting\Vat\VatClearingService::candidateSupplierIds()}),
     * jen zkrácené na existenci. Kdyby se rozešly, hlásili bychom „neaktivní"
     * o úloze, která práci má — a to je horší než falešný poplach.
     *
     * @var array<string,string>
     */
    private const FEATURE_PROBES = [
        self::FEATURE_DOUBLE_ENTRY => "SELECT 1 FROM supplier WHERE accounting_mode = 'double_entry' LIMIT 1",
        self::FEATURE_VAT_DOUBLE_ENTRY => "SELECT 1 FROM supplier
              WHERE accounting_mode = 'double_entry'
                AND (is_vat_payer = 1 OR is_identified = 1)
              LIMIT 1",
    ];

    private ?bool $aiOptInCache = null;

    /** @var array<string,bool> */
    private array $featureCache = [];

    /** @var array<string,true>|null */
    private ?array $disabledJobsCache = null;

    /** @var list<string>|null */
    private ?array $unknownDisabledJobNamesCache = null;

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo = null,
    ) {}

    /**
     * Proč u téhle instalace úloha nemá co dělat? `null` = má.
     *
     * Jediné místo, kde se relevance rozhoduje. Vrácený důvod je strojový klíč,
     * text si doplní UI podle něj.
     *
     * Fail-open (viz docblock třídy): cokoli, co se nepodaří přečíst, nechává
     * úlohu aktivní. Falešné „neaktivní" totiž schová skutečný výpadek.
     *
     * @param array<string,mixed> $job
     */
    public function inactiveReason(array $job, string $mode = CronScheduleMode::INDIVIDUAL): ?string
    {
        // Explicitní vypnutí konfigurací musí přebít všechno ostatní — proto je
        // to úplně první kontrola, ještě před dispatcher/mode úvahou. Nemá smysl
        // kvůli vypnuté úloze sahat do databáze na feature probes ani řešit, do
        // kterého režimu plánování patří.
        if ($this->isDisabledByConfig((string) ($job['script'] ?? ''))) {
            return self::INACTIVE_DISABLED_BY_CONFIG;
        }
        // Položka dispatcheru dává smysl jen v režimu DISPATCHER — v tom druhém
        // se neplánuje, takže by navždy visela jako „nikdy neběželo".
        if (($job['dispatcher_only'] ?? false) === true) {
            return $mode === CronScheduleMode::DISPATCHER ? null : self::INACTIVE_OTHER_MODE;
        }
        $blocked = $this->schedulableBlockReason($job);
        if ($blocked !== null) {
            return $blocked;
        }
        if (($job['requires_ai_opt_in'] ?? false) === true && !$this->anySupplierHasAiOptIn()) {
            return self::INACTIVE_FEATURE_OFF;
        }
        $feature = $job['requires_feature'] ?? null;
        if (is_string($feature) && $feature !== '' && !$this->hasFeature($feature)) {
            return self::INACTIVE_FEATURE_OFF;
        }
        return null;
    }

    /**
     * Má se úloha objevit v UI přehledu? Jednotlivé úlohy se ukazují v obou
     * režimech: i pod dispatcherem pořád běží (jen je spouští on) a jejich
     * zdraví je stejně důležité.
     *
     * @param array<string,mixed> $job
     */
    public function isVisibleInUi(array $job, string $mode = CronScheduleMode::INDIVIDUAL): bool
    {
        return $this->inactiveReason($job, $mode) === null;
    }

    /**
     * Má se úloha dostat do crontabu? Jen restart-stabilní podmínky.
     *
     * @param array<string,mixed> $job
     */
    public function isSchedulable(array $job): bool
    {
        return $this->schedulableBlockReason($job) === null;
    }

    /**
     * Proč se úloha nemá dostat do crontabu? `null` = má.
     *
     * Odpověď je restart-stabilní (jen konfigurace a existence adresáře), takže
     * ji smí použít i dispatcher a generátor crontabu. Vrácený klíč je tentýž,
     * jaký hlásí {@see inactiveReason()} — jinak by přehled úloh a report
     * dispatcheru mluvily o téže situaci jinak.
     *
     * @param array<string,mixed> $job
     */
    public function schedulableBlockReason(array $job): ?string
    {
        if (($job['requires_managed'] ?? false) === true && !$this->isManagedInstallation()) {
            return self::INACTIVE_MANAGED_ONLY;
        }

        $key = $job['requires_config'] ?? null;
        if ($key === null) {
            return null;
        }
        try {
            $path = trim((string) $this->config->get((string) $key, ''));
        } catch (Throwable) {
            return null; // fail-open
        }

        return $path !== '' && is_dir($path) ? null : self::INACTIVE_NOT_CONFIGURED;
    }

    /**
     * Běží instalace ve spravovaném provozu?
     *
     * ⚠️ Fail-open jako zbytek brány: nečitelná konfigurace úlohu NEVYPÍNÁ.
     * Tiše nespuštěný cron je horší než úloha, která jednou naprázdno změří
     * spotřebu místa.
     */
    public function isManagedInstallation(): bool
    {
        try {
            return (new ManagedModeGuard($this->config))->isManaged();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Úlohy, které se mají dostat do crontabu pro daný režim plánování.
     *
     * V režimu DISPATCHER je to JEN položka dispatcheru — jednotlivé úlohy si
     * pak spouští on sám. Kdyby tu zůstaly i ony, běžely by dvakrát.
     *
     * @param string $mode {@see CronScheduleMode}
     * @return list<array<string,mixed>>
     */
    public function schedulableJobs(string $mode = CronScheduleMode::INDIVIDUAL): array
    {
        $dispatcherMode = $mode === CronScheduleMode::DISPATCHER;

        return array_values(array_filter(
            CronCatalog::forInstallation($this->isManagedInstallation()),
            function (array $job) use ($dispatcherMode): bool {
                $isDispatcher = ($job['dispatcher_only'] ?? false) === true;
                if ($isDispatcher !== $dispatcherMode) {
                    return false;
                }
                // Dispatcher sám žádný `requires_config` nemá a mít nesmí —
                // musí se naplánovat vždy, protože gating dělá až on uvnitř.
                return $isDispatcher || $this->isSchedulable($job);
            },
        ));
    }

    /** Je úloha vypnutá přes `cron.disabled_jobs`? */
    public function isDisabledByConfig(string $script): bool
    {
        if ($script === '') {
            return false;
        }
        return isset($this->disabledJobs()[$script]);
    }

    /**
     * Jména z `cron.disabled_jobs`, která nesedí na žádný skript v
     * {@see CronCatalog::all()} — pravděpodobný překlep v konfiguraci. Vystaveno
     * pro health/diagnostickou vrstvu, ať se to dá najít i bez procházení PHP
     * error logu (kam se totéž zaloguje jako varování, viz {@see disabledJobs()}).
     *
     * @return list<string>
     */
    public function unknownDisabledJobNames(): array
    {
        $this->disabledJobs(); // naplní i cache neznámých jmen
        return $this->unknownDisabledJobNamesCache ?? [];
    }

    /**
     * Načte a validuje `cron.disabled_jobs` proti katalogu. Jména mimo katalog
     * se NEIGNORUJÍ tiše — jde o překlep v konfiguraci, který by jinak vypadal
     * jako fungující vypnutí a nikdo by si nevšiml, že vlastně nic nedělá.
     *
     * Fail-open (viz docblock třídy): nečitelná/chybějící konfigurace = nic
     * není vypnuté.
     *
     * @return array<string,true>
     */
    private function disabledJobs(): array
    {
        if ($this->disabledJobsCache !== null) {
            return $this->disabledJobsCache;
        }

        $raw = [];
        try {
            $raw = $this->config->get(self::DISABLED_JOBS_CONFIG_KEY, []);
        } catch (Throwable) {
            // fail-open
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $known    = array_flip(CronCatalog::scripts());
        $disabled = [];
        $unknown  = [];
        foreach ($raw as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (isset($known[$name])) {
                $disabled[$name] = true;
            } else {
                $unknown[] = $name;
            }
        }

        if ($unknown !== []) {
            error_log(sprintf(
                'CronJobGate: cron.disabled_jobs obsahuje jméno mimo CronCatalog (pravděpodobný překlep): %s',
                implode(', ', $unknown),
            ));
        }
        $this->unknownDisabledJobNamesCache = $unknown;

        return $this->disabledJobsCache = $disabled;
    }

    /**
     * Neznámý název funkce = překlep v katalogu, ne vypnutá funkce. Vrací proto
     * true — jinak by úlohu tiše umlčel a nikdo by si toho nevšiml.
     */
    private function hasFeature(string $feature): bool
    {
        if (array_key_exists($feature, $this->featureCache)) {
            return $this->featureCache[$feature];
        }
        $sql = self::FEATURE_PROBES[$feature] ?? null;
        if ($sql === null || $this->pdo === null) {
            return $this->featureCache[$feature] = true;
        }
        try {
            $stmt = $this->pdo->query($sql);
            return $this->featureCache[$feature] = ($stmt === false || $stmt->fetchColumn() !== false);
        } catch (Throwable) {
            return $this->featureCache[$feature] = true; // fail-open
        }
    }

    private function anySupplierHasAiOptIn(): bool
    {
        if ($this->aiOptInCache !== null) {
            return $this->aiOptInCache;
        }
        if ($this->pdo === null) {
            return $this->aiOptInCache = true; // fail-open
        }
        try {
            $stmt = $this->pdo->query('SELECT 1 FROM supplier WHERE ai_assist_enabled=1 LIMIT 1');
            return $this->aiOptInCache = ($stmt !== false && $stmt->fetchColumn() !== false);
        } catch (Throwable) {
            return $this->aiOptInCache = true; // fail-open
        }
    }
}

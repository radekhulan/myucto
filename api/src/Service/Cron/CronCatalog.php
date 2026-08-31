<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Service\Backup\BackupSchedule;
use MyInvoice\Service\Backup\BackupScheduleLimit;
use PDO;

/**
 * Katalog plánovaných úloh — jeden zdroj pravdy pro:
 *   - api/bin/cron-*.php (jméno běhu)
 *   - cmd/cron-*.{cmd,sh} (wrappery)
 *   - UI Systém → Plánované úlohy (doporučená frekvence, max stáří)
 *
 * Pokud poslední úspěšný běh (`last_ok_at`) je starší než `max_age_hours`,
 * UI hlásí varování ("cron nejede" nebo "selhává"). Hodnoty mají bezpečnou
 * rezervu (víkend, výpadek hostingu, holiday).
 */
final class CronCatalog
{
    /**
     * @return array<int,array{
     *   script:string,
     *   recommended:string,
     *   linux_cron:string,
     *   windows_schtasks:string,
     *   max_age_hours:int,
     *   weekdays_only:bool,
     *   critical:bool,
     *   requires_config?:string,
     *   requires_managed?:bool,
     *   requires_ai_opt_in?:bool,
     *   requires_feature?:string,
     *   dispatcher_only?:bool
     * }>
     *
     * `requires_managed` (volitelné) = úloha dává smysl jen ve spravovaném
     * provozu (`app.managed`). Na self-hostu se nenaplánuje ani nezobrazí —
     * není to volba výkonu, ale relevance: bez kvóty nemá její výsledek
     * jediného konzumenta.
     *
     * `requires_config` (volitelné) = cfg klíč adresáře, bez kterého úloha nemá
     * co dělat (scan vypnutý). UI ji pak skryje, dokud není nastaven (CronJobsAction).
     *
     * `requires_feature` (volitelné) = název funkce, kterou úloha obsluhuje;
     * podmínku k němu drží {@see CronJobGate}. Bez zapnuté funkce úloha nemá co
     * dělat a hlásit ji jako zaseklou je falešný poplach.
     *
     * `dispatcher_only` (volitelné) = položka existuje jen v režimu
     * {@see CronScheduleMode::DISPATCHER}. V režimu INDIVIDUAL se neplánuje
     * (jinak by běžela souběžně s jednotlivými úlohami a spouštěla je dvakrát).
     */
    public static function all(): array
    {
        return [
            [
                'script' => 'cron-cleanup',
                'recommended' => 'daily_0300',
                'linux_cron' => '0 3 * * *',
                'windows_schtasks' => '/sc daily /st 03:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-backup',
                // 4× denně (02/08/14/20) — RPO logického dumpu 6 h místo 24 h.
                // Tohle je DEFAULT; skutečný rozvrh instalace je v tabulce
                // `backup_schedule_contract` a přebíjí se přes
                // {@see self::withContractedSchedules()}.
                'recommended' => 'four_times_daily',
                'linux_cron' => BackupScheduleLimit::RECOMMENDED_EXPRESSION,
                'windows_schtasks' => '/sc daily /st 02:00 /ri 360 /du 24:00',
                // Nejdelší mezera mezi běhy je 6 h; 12 h dává rezervu na jeden
                // vynechaný běh (restart, údržba), aniž by se problém schoval.
                'max_age_hours' => 12,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-backup-pdf',
                'recommended' => 'daily_0230',
                'linux_cron' => '30 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-backup-documents',
                'recommended' => 'daily_0235',
                'linux_cron' => '35 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:35',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-bank-scan',
                'recommended' => 'every_30_min',
                'linux_cron' => '*/30 * * * *',
                'windows_schtasks' => '/sc minute /mo 30',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
                'requires_config' => 'bank_import.scan_root',
            ],
            [
                'script' => 'cron-bank-email-notices',
                'recommended' => 'every_30_min',
                'linux_cron' => '*/30 * * * *',
                'windows_schtasks' => '/sc minute /mo 30',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-scan-purchase-inbox',
                'recommended' => 'every_10_min',
                'linux_cron' => '*/10 * * * *',
                'windows_schtasks' => '/sc minute /mo 10',
                'max_age_hours' => 2,
                'weekdays_only' => false,
                'critical' => false,
                'requires_config' => 'purchase_invoice.inbox_dir',
            ],
            [
                'script' => 'cron-send-reminders',
                'recommended' => 'weekdays_0900',
                'linux_cron' => '0 9 * * 1-5',
                'windows_schtasks' => '/sc weekly /d MON,TUE,WED,THU,FRI /st 09:00',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => false,
            ],
            [
                'script' => 'cron-send-approval-reminders',
                'recommended' => 'weekdays_0915',
                'linux_cron' => '15 9 * * 1-5',
                'windows_schtasks' => '/sc weekly /d MON,TUE,WED,THU,FRI /st 09:15',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => false,
            ],
            [
                'script' => 'cron-document-request-reminders',
                'recommended' => 'weekdays_0930',
                'linux_cron' => '30 9 * * 1-5',
                'windows_schtasks' => '/sc weekly /d MON,TUE,WED,THU,FRI /st 09:30',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => false,
            ],
            [
                'script' => 'cron-epo-status',
                'recommended' => 'every_1_min',
                'linux_cron' => '* * * * *',
                'windows_schtasks' => '/sc minute /mo 1',
                'max_age_hours' => 1,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // Dotažení protokolu ČSSZ a uzavření transakce u VREP. Deset
                // minut je kompromis: protokol nevzniká v řádu sekund (rozvrh
                // dotazů drží JmhzPollSchedule a začíná na minutě, ustálí se na
                // hodině), ale lhůta pro měsíční hlášení je do 20. dne, takže
                // hodinový tick by u čerstvého podání zbytečně čekal. Vlastní
                // odstup si hlídá ledger, tick ho jen obsluhuje.
                'script' => 'cron-jmhz-poll',
                'recommended' => 'every_10_min',
                'linux_cron' => '*/10 * * * *',
                'windows_schtasks' => '/sc minute /mo 10',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // MZ-28-W08: pouze čte veřejné indexy MPSV/ČSSZ a porovnává
                // jejich normalizovaný inventář dokumentů s předchozím během.
                // Změna se ukáže v provozním přehledu jako konkrétní dokument,
                // verze a URL; nikdy sama neinstaluje číselník ani nemění mzdy.
                'script' => 'cron-jmhz-source-monitor',
                'recommended' => 'daily_0700',
                'linux_cron' => '0 7 * * *',
                'windows_schtasks' => '/sc daily /st 07:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-generate-recurring-invoices',
                'recommended' => 'daily_0630',
                'linux_cron' => '30 6 * * *',
                'windows_schtasks' => '/sc daily /st 06:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-payroll-document-worker',
                'recommended' => 'every_1_min',
                'linux_cron' => '* * * * *',
                'windows_schtasks' => '/sc minute /mo 1',
                'max_age_hours' => 1,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-payroll-period-export-worker',
                'recommended' => 'every_1_min',
                'linux_cron' => '* * * * *',
                'windows_schtasks' => '/sc minute /mo 1',
                'max_age_hours' => 1,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // Jediná měsíční úloha v katalogu. Účtuje předchozí měsíc, takže musí běžet
                // až po jeho konci; 04:00 prvního dne je po nočních zálohách a před ranním
                // provozem. `max_age_hours` = 33 dní: měsíční úloha nesmí hlásit „nejede"
                // v běžné mezeře mezi dvěma běhy (nejdelší je 31 dní + rezerva na výpadek).
                'script' => 'cron-payroll-post',
                'recommended' => 'monthly_day1_0400',
                'linux_cron' => '0 4 1 * *',
                'windows_schtasks' => '/sc monthly /d 1 /st 04:00',
                'max_age_hours' => 792,
                'weekdays_only' => false,
                'critical' => false,
                'requires_feature' => CronJobGate::FEATURE_DOUBLE_ENTRY,
            ],
            [
                // Interní doklad zúčtování DPH — převod daně období z 343.100/343.200
                // na zúčtovací 343.900. Měsíční úloha ze stejného důvodu jako mzdy:
                // účtuje uzavřené období, takže musí běžet až po jeho konci. 04:30 je
                // půl hodiny po mzdách, ať se doklad počítá nad kompletním deníkem.
                // `max_age_hours` = 33 dní (nejdelší mezera mezi běhy + rezerva).
                'script' => 'cron-vat-clearing',
                'recommended' => 'monthly_day1_0430',
                'linux_cron' => '30 4 1 * *',
                'windows_schtasks' => '/sc monthly /d 1 /st 04:30',
                'max_age_hours' => 792,
                'weekdays_only' => false,
                'critical' => false,
                'requires_feature' => CronJobGate::FEATURE_VAT_DOUBLE_ENTRY,
            ],
            [
                // VH-01: propíše plánované změny plátcovství DPH (budoucí účinnost)
                // do živé cache supplier.is_vat_payer/is_identified. Běží krátce po
                // půlnoci, aby nový stav platil od začátku dne účinnosti.
                'script' => 'cron-vat-status-apply',
                'recommended' => 'daily_0030',
                'linux_cron' => '30 0 * * *',
                'windows_schtasks' => '/sc daily /st 00:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-journal-integrity-check',
                'recommended' => 'daily_0230',
                'linux_cron' => '30 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-automation-digest',
                'recommended' => 'hourly_0600_0800',
                'linux_cron' => '0 6-8 * * *',
                'windows_schtasks' => '/sc hourly /mo 1 /st 06:00 /et 08:59',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-ai-worker',
                'recommended' => 'every_10_min',
                'linux_cron' => '*/10 * * * *',
                'windows_schtasks' => '/sc minute /mo 10',
                'max_age_hours' => 2,
                'weekdays_only' => false,
                'critical' => false,
                'requires_ai_opt_in' => true,
            ],
            [
                'script' => 'cron-ai-rule-miner',
                'recommended' => 'daily_0400',
                'linux_cron' => '0 4 * * *',
                'windows_schtasks' => '/sc daily /st 04:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // #28: bez tohohle se `exchange_rates` plnila jen jako ad-hoc cache
                // prvního dotazu, takže u čerstvé instalace v ní seděl jediný kurzový
                // den — a cizoměnová úhrada ke dni bez kurzu shodila celý peněžní
                // deník. 15:00, protože ČNB vyhlašuje kurz kolem 14:30; mezery si
                // úloha dohání sama, takže pozdější běh o nic nepřijde.
                'script' => 'cron-cnb-rates',
                'recommended' => 'daily_1500',
                'linux_cron' => '0 15 * * *',
                'windows_schtasks' => '/sc daily /st 15:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-version-check',
                'recommended' => 'daily_0600',
                'linux_cron' => '0 6 * * *',
                'windows_schtasks' => '/sc daily /st 06:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-license-renew',
                'recommended' => 'hourly_15',
                'linux_cron' => '15 * * * *',
                'windows_schtasks' => '/sc hourly /mo 1 /st 00:15',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // H-10 — měření spotřeby místa (databáze + data BEZ záloh).
                // Jediné místo, kde se prochází strom souborů; web i telemetrie
                // pak čtou hotové číslo z `instance_storage_usage`.
                //
                // Hodinově: spotřeba neroste skokem, takže častěji nemá smysl,
                // ale při denní frekvenci by se blížící se kvóta ohlásila pozdě
                // — a upozornění na 90 % má smysl jen tehdy, když dorazí dřív,
                // než instalace přestane zapisovat.
                //
                // `critical` = false: když měření vypadne, spotřeba zůstane
                // NEZMĚŘENÁ (null), což NIC nezamyká. Zastavené měření je tedy
                // ztráta přehledu, ne výpadek provozu.
                // ⚠️ Jen pro spravovaný provoz. Je to JEDINÁ úloha, která
                // prochází celý datový strom, a na self-hostu by to dělala
                // každou hodinu pro nikoho: kvóta tam není nastavená, takže
                // naměřené číslo nic nevynucuje ({@see StorageQuotaPolicy::isEnforceable()}).
                // Ručnímu spuštění to nebrání — admin si spotřebu změřit může.
                'requires_managed' => true,
                'script' => 'cron-storage-usage',
                'recommended' => 'hourly_15',
                'linux_cron' => '15 * * * *',
                'windows_schtasks' => '/sc hourly /mo 1',
                'max_age_hours' => 6,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // Plánovač v režimu CronScheduleMode::DISPATCHER — jediná položka,
                // která si každou minutu spočítá, které úlohy z tohohle katalogu
                // jsou na řadě, a spustí jen je. V režimu INDIVIDUAL se NEplánuje
                // (viz `dispatcher_only`), jinak by úlohy běžely dvakrát.
                //
                // `critical` = true: když přestane běžet tenhle, přestane běžet
                // úplně všechno, a to je jediná úloha v katalogu, o které to platí.
                'script' => self::DISPATCHER_SCRIPT,
                'recommended' => 'every_1_min',
                'linux_cron' => '* * * * *',
                'windows_schtasks' => '/sc minute /mo 1',
                'max_age_hours' => 1,
                'weekdays_only' => false,
                'critical' => true,
                'dispatcher_only' => true,
            ],
        ];
    }

    /** Skript plánovače — vyčleněný, ať se název nemusí opisovat. */
    public const DISPATCHER_SCRIPT = 'cron-dispatch';

    /**
     * Úlohy, které dispatcher spouští (tj. celý katalog kromě sebe sama).
     *
     * @return list<array<string,mixed>>
     */
    public static function dispatchable(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $job): bool => ($job['dispatcher_only'] ?? false) !== true,
        ));
    }

    /**
     * @return list<string>
     */
    public static function scripts(): array
    {
        return array_map(static fn ($e) => (string) $e['script'], self::all());
    }

    /**
     * Katalog s rozvrhy PODLE INSTALACE — u smluvně řízených úloh (dnes jediná:
     * `cron-backup`) nahradí katalogový default hodnotou z `backup_schedule_contract`.
     *
     * Proč to takhle: rozvrh záloh je provozní údaj konkrétní instalace (self-host
     * si nechá jeden dump denně, spravovaná jede 4×), takže patří do databáze, ne do
     * kódu. Jenže do H-25 tabulka existovala a nikdo z ní neplánoval — plánovač i
     * generátor crontabu četly výhradně katalog, takže uložený rozvrh byl mrtvý
     * zápis a instalace zálohovala 1× denně, ať v tabulce stálo cokoli. Tohle je ta
     * chybějící vazba.
     *
     * Bez PDO (build-time generování crontabu do image) se vrátí čistý katalog.
     *
     * @return list<array<string,mixed>>
     */
    public static function withContractedSchedules(array $jobs, ?PDO $pdo): array
    {
        if ($pdo === null) {
            return array_values($jobs);
        }
        $contract = BackupSchedule::current($pdo);
        $script = (string) $contract['script'];
        $expr = (string) $contract['cron_expr'];

        return array_values(array_map(static function (array $job) use ($script, $expr): array {
            if ((string) $job['script'] === $script && $expr !== '') {
                $job['linux_cron'] = $expr;
            }
            return $job;
        }, $jobs));
    }

    /**
     * Limit stáří posledního úspěšného běhu pro daný skript.
     *
     * Fallback 24 h je pro jistotu — skript mimo katalog by neměl existovat,
     * ale výjimka kvůli přehledu stavu je horší než konzervativní odhad.
     */
    public static function maxAgeHours(string $script): int
    {
        foreach (self::all() as $job) {
            if ($job['script'] === $script) {
                return (int) $job['max_age_hours'];
            }
        }
        return 24;
    }
}

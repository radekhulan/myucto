<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use PDO;
use Throwable;

/**
 * Levná brána „mám vůbec co dělat?" pro často spouštěné cron skripty.
 *
 * Motivace: cron-epo-status běží každou minutu a cron-ai-worker po deseti,
 * ale u typického tenanta nemají 99 % ticků co dělat. Bez brány každý takový
 * tick postaví celý DI kontejner (~200 souborů), otevře DB, zjistí že fronta
 * je prázdná a skončí. Brána to rozhodne jedním indexovaným dotazem nad už
 * otevřeným spojením, ještě než se kontejner vůbec začne stavět.
 *
 * ⚠️ Dotazy tady jsou ZÁMĚRNĚ PERMISIVNĚJŠÍ než ty, které pak frontu opravdu
 * čtou (EpoDirectSubmissionRepository::pollableAttempts, AiJobService::claimBatch).
 * Vynechávají doplňkové podmínky (prostředí, přítomnost credentials, opt-in
 * dodavatele, limit pokusů). Odchylka tak může stát nanejvýš jeden zbytečný
 * bootstrap — nikdy ne zmeškanou práci. Kdyby se brána naopak zpřísnila nad
 * rámec reálného dotazu, tiše by přestala pouštět práci, což je přesně ten
 * druh chyby, který se pozná až po týdnech.
 *
 * Fail-open: jakákoli chyba (chybějící tabulka před migrací, nedostupná DB)
 * znamená „spusť to", ať se diagnostika řeší v samotném skriptu.
 */
final class CronPreflight
{
    /**
     * Je co pollovat u přímých podání EPO?
     *
     * Permisivní protějšek {@see \MyInvoice\Repository\EpoDirectSubmissionRepository::pollableAttempts()}
     * — bez filtru na prostředí, credentials a requested_by.
     */
    public static function hasEpoWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM tax_submission_attempts
             WHERE channel = 'epo_direct'
               AND status IN ('processing','confirmed','uncertain')
               AND next_poll_at IS NOT NULL
               AND next_poll_at <= CURRENT_TIMESTAMP
               AND poll_count < 12
             LIMIT 1
        ");
    }

    /**
     * Čeká nějaké mzdové podání na protokol ČSSZ nebo na uzavření transakce?
     *
     * Permisivní protějšek
     * {@see \MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::listDuePolls()}
     * a `listDueCloses()` — bez podmínky na correlation reference a bez stropu
     * pokusů o uzavření. Odchylka stojí nanejvýš jeden zbytečný bootstrap,
     * nikdy ne zmeškaný protokol.
     */
    public static function hasJmhzTransportWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM payroll_submission_transport_attempts
             WHERE (
                     status = 'awaiting_protocol'
                     OR (status = 'completed' AND closed_at IS NULL)
                   )
               AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
             LIMIT 1
        ");
    }

    /**
     * Je co zpracovat v AI frontě?
     *
     * Permisivní protějšek {@see \MyInvoice\Service\Ai\AiJobService::claimBatch()}.
     * Stav 'running' je ve výběru schválně: claimBatch zároveň recykluje joby,
     * které zůstaly viset déle než 15 minut, a o tu recyklaci se nesmíme připravit.
     */
    public static function hasAiWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM ai_jobs
             WHERE status IN ('queued','running')
             LIMIT 1
        ");
    }

    /**
     * Má tahle instalace vůbec nějakou firmu se mzdami?
     *
     * Permisivní protějšek
     * {@see \MyInvoice\Repository\Payroll\PayrollModuleStateRepository::payrollEnabledSupplierIds()}
     * — bez podmínky na stav plného modulu. Instalace, kde mzdy nikdo nezapnul
     * (drtivá většina), tak noční detekci registračních změn odbaví jedním
     * dotazem, aniž by kvůli tomu stavěla DI kontejner.
     */
    public static function hasPayrollSuppliers(PDO $pdo): bool
    {
        return self::probe($pdo, '
            SELECT 1 FROM supplier
             WHERE payroll_enabled = 1
             LIMIT 1
        ');
    }

    private static function probe(PDO $pdo, string $sql): bool
    {
        try {
            $stmt = $pdo->query($sql);
            return $stmt === false || $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return true; // fail-open
        }
    }
}

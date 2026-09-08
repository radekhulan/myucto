<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzPollSchedule;
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
     * Výběr sdílí s frontou, aby jiné agendy a vyčerpaná uzavření
     * nespouštěly worker, který pro ně nemá práci.
     */
    public static function hasJmhzTransportWork(PDO $pdo): bool
    {
        try {
            return PayrollSubmissionTransportAttemptRepository::hasDueWork(
                $pdo,
                JmhzPollSchedule::MAX_CLOSE_ATTEMPTS,
            );
        } catch (Throwable) {
            return true;
        }
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

    /**
     * Má kdo číst poštu? Aspoň jedna zapnutá IMAP schránka bankovních avíz.
     *
     * Permisivní protějšek výběru v
     * {@see \MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner::scanSupplier()}
     * — bez omezení na konkrétního dodavatele, protože ten se v úloze teprve
     * vybírá podle `app.scan_all_suppliers`. Sonda tedy říká jen „někde tu
     * schránka je"; komu patří, rozhodne úloha sama.
     *
     * Bez ní stojí instalace, kde nikdo IMAP nezapnul, 48 stavěb DI kontejneru
     * denně jen proto, aby se dozvěděla, že není co skenovat.
     */
    public static function hasBankEmailNoticeAccounts(PDO $pdo): bool
    {
        return self::probe($pdo, '
            SELECT 1 FROM bank_email_imap_settings
             WHERE enabled = 1
             LIMIT 1
        ');
    }

    public static function hasBankConnections(PDO $pdo): bool
    {
        return self::probe($pdo, '
            SELECT 1 FROM bank_connections
             WHERE enabled = 1 AND token_ciphertext IS NOT NULL
             LIMIT 1
        ');
    }

    /**
     * Leží něco ve frontě mzdových dokumentů?
     *
     * Permisivní protějšek tří front, které obsluhuje `payroll-document-worker`:
     * {@see \MyInvoice\Repository\Payroll\PayrollDocumentBatchRepository::claimNext()}
     * a `readyForBundle()`, {@see \MyInvoice\Repository\Payroll\PayrollAnnualDocumentBatchRepository::claimNext()}
     * a {@see \MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository::claimNext()}.
     *
     * Stavy `processing` / `sending` jsou ve výběru schválně: workeři zároveň
     * recyklují položky, jejichž lease vypršel (`recoverStale*()`), a o tu
     * recyklaci se bránou připravit nesmíme — jinak by zaseknutá pásky zůstala
     * viset navždy. Ze stejného důvodu se dávka hledá i přes hlavičku: může mít
     * všechny položky hotové a čekat už jen na ZIP.
     */
    public static function hasPayrollDocumentWork(PDO $pdo): bool
    {
        // Sjednocení je schválně v odvozené tabulce s jediným LIMITem venku:
        // `LIMIT` uvnitř větví UNIONu by MariaDB chtěla v závorkách a takový
        // zápis zase neumí SQLite, nad kterou běží unit testy brány.
        return self::probe($pdo, "
            SELECT 1 FROM (
                SELECT 1 AS work FROM payroll_document_batch_items
                 WHERE status IN ('queued', 'retry_wait', 'processing')
                UNION ALL
                SELECT 1 FROM payroll_document_batches
                 WHERE status <> 'completed'
                UNION ALL
                SELECT 1 FROM payroll_annual_document_batch_items
                 WHERE status IN ('queued', 'retry_wait', 'processing')
                UNION ALL
                SELECT 1 FROM payroll_document_access_links
                 WHERE dispatch_state IN ('pending', 'sending')
                   AND revoked_at IS NULL
            ) queues
             LIMIT 1
        ");
    }

    /**
     * Čeká nějaký export mzdového období na zpracování?
     *
     * Permisivní protějšek {@see \MyInvoice\Repository\Payroll\PayrollPeriodExportJobRepository::claimNext()}
     * — bez podmínky na `available_at`. `processing` je ve výběru kvůli recyklaci
     * jobů po spadlém workerovi (`recoverStaleLocked()`), která je u téhle fronty
     * jediná cesta, jak se rozpracovaný archiv vůbec dokončí.
     */
    public static function hasPayrollPeriodExportWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM payroll_period_export_jobs
             WHERE status IN ('queued', 'retry_wait', 'processing')
             LIMIT 1
        ");
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

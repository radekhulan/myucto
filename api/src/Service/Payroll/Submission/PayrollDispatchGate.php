<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Jde tenhle řádek fronty odeslat — a když ne, proč?
 *
 * Stojí to zvlášť od {@see PayrollSubmissionQueueService} ze dvou důvodů:
 *
 * 1. **Je to čistá funkce nad daty**, takže se dá otestovat bez databáze —
 *    a právě tyhle věty jsou to, co účetní čte, takže je chce mít pokryté.
 * 2. **Pravidlo musí jít ZAVOLAT.** Kdyby zůstalo jako `private` metoda uvnitř
 *    služby, okopíruje se rychleji, než kdyby neexistovalo: vytváří dojem, že
 *    SSOT existuje, ale nikdo jiný se k němu nedostane.
 *
 * Brána je fail-closed: nezná-li odpověď, blokuje. Odeslat úřední podání
 * omylem je nevratné, zatímco zbytečně zablokovaný řádek se dá odblokovat.
 */
final class PayrollDispatchGate
{
    /**
     * @param array<string,mixed> $row řádek z
     *        {@see \MyInvoice\Repository\Payroll\PayrollSubmissionQueueRepository}
     * @return string|null celá věta pro účetní, nebo `null`, když odeslat jde
     */
    public function blockedReason(
        array $row,
        PayrollDispatchCapability $capability,
        string $environment,
        int $blockingIssues,
    ): ?string {
        // 1. Nezmrazené podání. Stojí první, protože je to jediný důvod, se
        //    kterým může uživatel rovnou něco udělat.
        if ((string) ($row['submission_status'] ?? '') !== 'ready') {
            return 'Podání zatím není zmrazené k odeslání. Dokončete jeho'
                . ' přípravu v příslušné agendě; teprve zmrazené podání se dá'
                . ' odeslat.';
        }

        // 2. Kanál neumíme vůbec.
        if (!$capability->isDispatchable()) {
            return $capability->reason;
        }

        // 3. Kanál umíme, ale ne v tomhle prostředí.
        if ($capability->productionOnly && $environment !== 'production') {
            return 'Datová schránka příjemce je doložená jen pro ostré'
                . ' prostředí, takže v testovacím prostředí tohle podání'
                . ' odeslat nejde.';
        }

        // 4. Už se odesílalo.
        $attempt = $row['attempt'] ?? null;
        if (is_array($attempt) && !self::attemptAllowsRetry($attempt)) {
            return sprintf(
                'Podání už bylo odesláno (pokus č. %d, stav „%s"). Znovu se'
                    . ' neodesílá — u úřadu by vzniklo jako duplicita. Jak'
                    . ' dopadlo, uvidíte ve stavu odeslání.',
                (int) ($attempt['attempt_no'] ?? 0),
                (string) ($attempt['status'] ?? ''),
            );
        }

        // 5. Už čeká v odchozí frontě datové schránky.
        $outbox = $row['outbox'] ?? null;
        if (is_array($outbox)
            && !in_array(
                (string) ($outbox['dispatch_state'] ?? ''),
                ['failed', 'cancelled'],
                true,
            )
        ) {
            return sprintf(
                'Podání už čeká v odchozí frontě datové schránky (stav „%s").'
                    . ' Dokončete ho tam; podruhé se nezařazuje.',
                (string) ($outbox['dispatch_state'] ?? ''),
            );
        }

        // 6. Otevřené blokující nedostatky.
        if ($blockingIssues > 0) {
            return sprintf(
                'Podání má %d nevyřešených chyb, které brání odeslání.'
                    . ' Opravte je v jeho agendě a podání zmrazte znovu.',
                $blockingIssues,
            );
        }

        return null;
    }

    /**
     * Neúspěšný pokus BEZ `sent_at` znamená, že se odeslání nepovedlo dřív,
     * než cokoli opustilo aplikaci — u úřadu po něm nic nezůstalo, takže druhý
     * pokus nemůže nic zdvojit. Všechny ostatní stavy (včetně `failed` PO
     * odeslání a `expired`) dál blokují: tam už se neví, co úřad přijal.
     *
     * Tohle je TOTOŽNÉ pravidlo jako v
     * {@see \MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::listReadySubmissions()}.
     * Kdyby se obě kopie rozešly, nabízela by fronta odeslání tam, kde ho
     * obrazovka „Stav odeslání" zakazuje, nebo naopak — a účetní by ze dvou
     * obrazovek nad týmž podáním dostala dvě různé odpovědi.
     *
     * @param array<string,mixed> $attempt
     */
    public static function attemptAllowsRetry(array $attempt): bool
    {
        return (string) ($attempt['status'] ?? '') === 'failed'
            && ($attempt['sent_at'] ?? null) === null;
    }
}

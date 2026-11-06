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
        // 1. Podání, které už jednou odešlo a čeká na odpověď úřadu. Stojí
        //    ÚPLNĚ PRVNÍ, protože jinak by ho odbylo pravidlo o nezmrazeném
        //    podání radou „dokončete přípravu v agendě" — a to je u odeslaného
        //    podání rada do zdi. Blokuje se, ale s pravdivým důvodem: odpověď
        //    může přijít, nebo taky ne (ČSSZ zprávu převezme a zpracovat ji
        //    odmítne), a rozhodnout o tom umí jen člověk, který ji vidí.
        $status = (string) ($row['submission_status'] ?? '');
        if (in_array(
            $status,
            PayrollSubmissionStateMachine::REOPENABLE_STATUSES,
            true,
        )) {
            return 'Podání už bylo odesláno a čeká se na vyjádření úřadu.'
                . ' Pokud je z odpovědi zřejmé, že podané nic není, můžete'
                . ' pokus zahodit a podat znovu.';
        }

        // 2. Nezmrazené podání. Je to jediný důvod, se kterým může uživatel
        //    rovnou něco udělat.
        if ($status !== 'ready') {
            return 'Podání zatím není zmrazené k odeslání. Dokončete jeho'
                . ' přípravu v příslušné agendě; teprve zmrazené podání se dá'
                . ' odeslat.';
        }

        // 3. Kanál neumíme vůbec.
        if (!$capability->isDispatchable()) {
            return $capability->reason;
        }

        // 4. Kanál umíme, ale ne v tomhle prostředí.
        if ($capability->productionOnly && $environment !== 'production') {
            return 'Datová schránka příjemce je doložená jen pro ostré'
                . ' prostředí, takže v testovacím prostředí tohle podání'
                . ' odeslat nejde.';
        }

        if ($capability->testEnvironmentOnly && $environment !== 'test') {
            return 'Odeslání přes VREP je u téhle agendy zatím ověřené jen'
                . ' v testovacím prostředí ČSSZ, takže ho aplikace do ostrého'
                . ' prostředí neodešle. Připravené XML stáhněte a podejte ho'
                . ' obvyklou cestou.';
        }

        // 5. Už se odesílalo.
        $attempt = $row['attempt'] ?? null;
        if (is_array($attempt) && ($possiblyDelivered = self::possiblyDeliveredReason($attempt)) !== null) {
            return $possiblyDelivered;
        }
        if (is_array($attempt) && !self::attemptAllowsRetry($attempt)) {
            return sprintf(
                'Podání už bylo odesláno (pokus č. %d, stav „%s"). Znovu se'
                    . ' neodesílá — u úřadu by vzniklo jako duplicita. Jak'
                    . ' dopadlo, uvidíte ve stavu odeslání.',
                (int) ($attempt['attempt_no'] ?? 0),
                (string) ($attempt['status'] ?? ''),
            );
        }

        // 6. Už čeká v odchozí frontě datové schránky.
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

        // 7. Otevřené blokující nedostatky.
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
     * Kudy podání odejde: režimy, které jsou pro firmu a prostředí připravené,
     * a výchozí z nich.
     *
     * Primární kanál z katalogu má přednost, pokud je připravený. Když není
     * (typicky VREP bez zvoleného podpisového certifikátu) a alternativní ano,
     * výchozím se stane alternativní — jinak by odeslání skončilo chybou
     * „není zvolený podpisový certifikát", přestože datovou schránkou jde.
     * Když není připravené nic, zůstává primární: chybu pak pojmenuje jeho
     * služba a účetní ví, co nastavit.
     *
     * @return array{default:string,modes:list<string>}
     */
    public function channels(
        PayrollDispatchCapability $capability,
        string $environment,
        bool $signingProfileReady,
    ): array {
        $candidates = $capability->alternateMode === null
            ? [$capability->mode]
            : [$capability->mode, $capability->alternateMode];
        $ready = array_values(array_filter(
            $candidates,
            static fn (string $mode): bool => self::modeReady($mode, $environment, $signingProfileReady),
        ));
        if ($ready === []) {
            return ['default' => $capability->mode, 'modes' => [$capability->mode]];
        }

        return ['default' => $ready[0], 'modes' => $ready];
    }

    private static function modeReady(string $mode, string $environment, bool $signingProfileReady): bool
    {
        return match ($mode) {
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION => $signingProfileReady,
            PayrollDispatchCapabilityCatalog::MODE_VREP_CSSZ_FORM => $signingProfileReady
                && ($environment === 'test' || Vrep\CsszFormVrepTransportService::PRODUCTION_OPEN),
            // Datovou schránkou jde zařadit vždy: způsob přihlášení (brána,
            // Mobilní klíč, jméno a heslo, SMS, certifikát) volí účetní až
            // při odeslání a jméno s heslem nepotřebuje nic uloženého.
            PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL,
            PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH => true,
            default => false,
        };
    }

    /**
     * Kód důvodu, kterým se značí pokus VĚDOMĚ ZAHOZENÝ ČLOVĚKEM.
     *
     * Nese ho `payroll_submission_transport_attempts.error_code` a je to jediné,
     * čím se takový pokus liší od pokusu, který automatika vzdala sama — u toho
     * se pořád neví, co úřad přijal, takže dál blokuje.
     */
    public const ABANDONED_ERROR_CODE = 'abandoned_by_user';

    /**
     * Kód, kterým se značí pokus „možná doručeno", u kterého účetní VÝSLOVNĚ
     * potvrdila opakování ({@see PayrollSubmissionRetryConfirmationService}).
     */
    public const RETRY_CONFIRMED_ERROR_CODE = 'retry_confirmed_by_user';

    /** Požadavek odešel, odpověď nepřišla; opakovat jen po potvrzení. */
    public const POSSIBLY_DELIVERED_STATUS = 'possibly_delivered';

    /**
     * Kód pokusu, na který ČSSZ odpověděla kontrolou 22 „shodné podání už
     * existuje": originál je u ČSSZ a opakovat nemá smysl ani s potvrzením.
     */
    public const ORIGINAL_AT_CSSZ_ERROR_CODE = 'jmhz_original_at_cssz';

    /**
     * Věta pro účetní u pokusu „možná doručeno", nebo `null`.
     *
     * @param array<string,mixed> $attempt
     */
    public static function possiblyDeliveredReason(array $attempt): ?string
    {
        if ((string) ($attempt['status'] ?? '') !== self::POSSIBLY_DELIVERED_STATUS) {
            return null;
        }
        if ((string) ($attempt['error_code'] ?? '') === self::ORIGINAL_AT_CSSZ_ERROR_CODE) {
            return 'Originál podání je u ČSSZ: odpověď na opakované odeslání'
                . ' hlásí, že shodné podání už existuje. Znovu neodesílejte;'
                . ' doložte nebo načtěte protokol originálu.';
        }

        return 'Podání možná doručeno: požadavek odešel, ale odpověď ČSSZ'
            . ' nedorazila. Nejdřív dohledejte protokol (datová schránka, portál'
            . ' ČSSZ) a načtěte ho. Znovu odeslat lze až po výslovném potvrzení,'
            . ' a to se stejným GUID podání.';
    }

    /**
     * Neúspěšný pokus BEZ `sent_at` znamená, že se odeslání nepovedlo dřív,
     * než cokoli opustilo aplikaci — u úřadu po něm nic nezůstalo, takže druhý
     * pokus nemůže nic zdvojit. Selhání PO odeslání požadavku (vypršený čas,
     * ztracená nebo nečitelná odpověď) se od migrace 1904 zapisuje jako
     * `possibly_delivered`, takže sem nepropadne.
     *
     * Druhá povolená cesta je pokus, který účetní VĚDOMĚ ZAHODILA poté, co viděla,
     * co úřad odpověděl ({@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::abandonAndReopen()}).
     * Důvodů, proč úřad podání nepřijme, je víc, než kolik jich umíme spolehlivě
     * rozpoznat z protokolu — proto o opakování nerozhoduje automatika podle textu
     * odpovědi, ale člověk, který ten text vidí. Zahození se zapisuje do auditní
     * stopy a pokus zůstává v ledgeru; nezmizí, jen přestane blokovat.
     *
     * Všechny ostatní stavy (včetně `failed` PO odeslání a `expired`, které vzdala
     * automatika) dál blokují: tam se pořád neví, co úřad přijal.
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
        $status = (string) ($attempt['status'] ?? '');
        if ($status === 'failed' && ($attempt['sent_at'] ?? null) === null) {
            return true;
        }

        return $status === 'expired'
            && in_array(
                (string) ($attempt['error_code'] ?? ''),
                [self::ABANDONED_ERROR_CODE, self::RETRY_CONFIRMED_ERROR_CODE],
                true,
            );
    }
}

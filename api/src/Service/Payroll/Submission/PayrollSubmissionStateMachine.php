<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final class PayrollSubmissionStateMachine
{
    /**
     * Stavy, ze kterých se smí podání VRÁTIT na `ready` a odeslat znovu.
     *
     * Existuje to kvůli situaci, kterou dřív nešlo z aplikace vyřešit vůbec:
     * ČSSZ zprávu převezme, ale zpracovat ji odmítne — třeba proto, že certifikát,
     * kterým je e-podání podepsané, není u OSSZ zapsaný v registru podávajících.
     * Odeslané tedy nic není, ale podání zůstalo ve stavu, ze kterého nevedla cesta
     * nikam: `ready` se nedalo dosáhnout odnikud a klíč `uq_payroll_submissions_regular`
     * pouští na jednu povinnost jediné řádné podání, takže nešlo založit ani nové.
     * Povinnost byla z aplikace trvale nepodatelná, i po nápravě příčiny u OSSZ.
     *
     * Návrat je VĚDOMÉ ROZHODNUTÍ ČLOVĚKA, ne automatika: důvodů, proč úřad podání
     * nepřijal, je víc, než kolik jich umíme spolehlivě rozpoznat, takže aplikace
     * důvod jen ukáže a rozhodne účetní
     * ({@see PayrollSubmissionService::abandonAndReopen()}).
     *
     * `accepted` ani `partially_accepted` tu ZÁMĚRNĚ nejsou: tam podané něco JE
     * a opakované odeslání by u úřadu vyrobilo duplicitu. Oprava přijatého podání
     * vede přes `correction_required`, ne přes nové odeslání téhož.
     *
     * @var list<string>
     */
    public const REOPENABLE_STATUSES = [
        'submitted',
        'processing',
        'waiting_for_identity',
        'rejected',
    ];

    /**
     * Stavy PŘED odesláním — podání v nich ještě nikam neodešlo a úřad o něm
     * nerozhodl.
     *
     * Není to popisná pomůcka, ale zrcadlo databázového omezení
     * `chk_payroll_submissions_dates` (migrace `1279_payroll_submission_platform.sql`),
     * které přesně pro tyhle čtyři stavy vyžaduje `submitted_at IS NULL`.
     * Dokud se chodilo jen dopředu, nezáleželo na tom: datum se vyplnilo při
     * odeslání a víc se nesahalo. Návrat na `ready` ale řádek s vyplněným
     * `submitted_at` do předodeslaného stavu vrací, takže se datum musí zároveň
     * smazat — jinak zápis padne na constraint
     * ({@see PayrollSubmissionRepository::updateSubmissionStatus()}).
     *
     * @var list<string>
     */
    public const PRE_SUBMISSION_STATUSES = [
        'draft',
        'validated',
        'prepared',
        'ready',
    ];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'draft' => ['validated', 'cancelled_in_time'],
        'validated' => ['draft', 'prepared', 'ready', 'cancelled_in_time'],
        'prepared' => ['validated', 'ready', 'cancelled_in_time'],
        'ready' => ['validated', 'submitted', 'cancelled_in_time'],
        // `accepted` tu ZÁMĚRNĚ není. Přijetí tvrdí ověřený protokol úřadu
        // a ten podání vždycky posune přes `processing`; přímá hrana by navíc
        // obešla roční uzávěrku, protože `accepted` se u ní vědomě nehlídá
        // ({@see \MyInvoice\Repository\Payroll\PayrollSubmissionRepository::updateSubmissionStatus()}).
        // Agendy, u kterých úřad výsledek neposílá, se uzavírají na úrovni
        // POVINNOSTI, ne podání ({@see PayrollSubmissionSettlementService}).
        'submitted' => [
            'processing',
            'partially_accepted',
            'rejected',
            'waiting_for_identity',
            'correction_required',
            // Návrat k odeslání po vědomém zahození pokusu — viz REOPENABLE_STATUSES.
            'ready',
        ],
        'processing' => [
            'accepted',
            'partially_accepted',
            'rejected',
            'waiting_for_identity',
            'correction_required',
            'ready',
        ],
        'waiting_for_identity' => [
            'processing',
            'rejected',
            'correction_required',
            'ready',
        ],
        'partially_accepted' => ['correction_required', 'superseded'],
        // „Nebylo přijato" i „zamítnuto" padají na `rejected` a v obou případech musí
        // zaměstnavatel poslat nové hlášení ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus::payrollRemoteStatus()}).
        // Bez návratu na `ready` k tomu ale nevedla žádná cesta.
        'rejected' => ['correction_required', 'superseded', 'ready'],
        'correction_required' => ['superseded', 'cancelled_in_time'],
        'accepted' => ['superseded'],
        'superseded' => [],
        'cancelled_in_time' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(
                "Stav podání nelze změnit z {$from} na {$to}.",
            );
        }
    }

    public function isKnownStatus(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }
}

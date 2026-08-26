<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Na jaké podání se u které agendy smí navázat oprava nebo storno.
 *
 * **Výchozí sada je PŘÍSNÁ a platí pro každou agendu:** navázat se dá jen na
 * podání, o kterém úřad už rozhodl. Dokud se neví, jestli originál prošel,
 * je oprava cesta k duplicitnímu podání — a to se u agend s okamžitým
 * protokolem (EPO) pozná až u správce daně, kdy se s tím nedá nic dělat.
 *
 * **Odchylka je vždy jmenovitá.** JMHZ má užší pravidlo než obecná platforma:
 * O/S navazuje jen na řádné podání s konečným výsledkem accepted nebo
 * partially_accepted. Zamítnuté řádné podání nemá platný kořen a následuje po
 * něm nové R s novým GUID. Výjimka pro pending predecessor se zapisuje zvlášť
 * a musí mít důvod.
 *
 * PROČ KATALOG V KÓDU, A NE SLOUPEC V DATABÁZI: jestli protokol chodí hned nebo
 * za dva dny, je vlastnost agendy dané zákonem a provozem úřadu, ne nastavení
 * firmy. `payroll_agenda_matrix` je tenantová a proměnná v čase; pravidlo z ní
 * by šlo přenastavit u jednoho zaměstnavatele a duplicitní podání by vzniklo
 * jen jemu. Modul takhle pinuje i ostatní vnější fakta (matice příznaků JMHZ,
 * katalog kontrol, lhůty) — jedno místo, čitelný důvod, změna vidět v diffu.
 */
final class PayrollAgendaCorrectionPolicy
{
    /** @var array<string,list<string>> */
    private const AGENDA_STATUSES = [
        'JMHZ25' => ['accepted', 'partially_accepted'],
    ];

    /**
     * Obecná sada rozhodnutých podání. Konkrétní agenda ji může zpřísnit přes
     * {@see AGENDA_STATUSES}.
     *
     * @var list<string>
     */
    private const DECIDED_STATUSES = [
        'accepted',
        'partially_accepted',
        'rejected',
        'correction_required',
    ];

    /**
     * Průběžné stavy, které dokládají, že podání DOPUTOVALO k úřadu
     * (`submitted_at` u obou vynucuje check platformy), ale rozhodnuté ještě
     * není.
     *
     * @var list<string>
     */
    private const PENDING_STATUSES = [
        'submitted',
        'processing',
    ];

    /**
     * Agendy, které smějí navázat opravu i na nerozhodnuté podání — a proč.
     *
     * Klíč je `payroll_obligations.agenda_code`. Kód JMHZ je tu jako řetězec
     * schválně: sdílená vrstva podání nemá znát třídy konkrétní agendy. Že
     * odpovídá {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService::AGENDA_CODE},
     * hlídá test katalogu.
     *
     * @var array<string,string>
     */
    private const PENDING_PREDECESSOR_AGENDAS = [];

    /**
     * Stavy původního podání, na které se u téhle agendy smí navázat oprava.
     *
     * @return list<string>
     */
    public static function correctableStatuses(string $agendaCode): array
    {
        if (isset(self::AGENDA_STATUSES[$agendaCode])) {
            return self::AGENDA_STATUSES[$agendaCode];
        }
        if (!self::allowsPendingPredecessor($agendaCode)) {
            return self::DECIDED_STATUSES;
        }

        return [...self::DECIDED_STATUSES, ...self::PENDING_STATUSES];
    }

    public static function allowsPendingPredecessor(string $agendaCode): bool
    {
        return array_key_exists($agendaCode, self::PENDING_PREDECESSOR_AGENDAS);
    }

    /** Důvod rozšíření, nebo `null` u agendy s přísnou výchozí sadou. */
    public static function reason(string $agendaCode): ?string
    {
        return self::PENDING_PREDECESSOR_AGENDAS[$agendaCode] ?? null;
    }

    /**
     * Celý katalog výjimek. Čte ho test, který hlídá, že se rozšíření nedá
     * přidat bez odůvodnění.
     *
     * @return array<string,string>
     */
    public static function declarations(): array
    {
        return self::PENDING_PREDECESSOR_AGENDAS;
    }

    public static function supersedesPredecessorOnAcceptance(
        string $agendaCode,
        string $submissionKind,
    ): bool {
        return $agendaCode !== 'JMHZ25' || $submissionKind !== 'correction';
    }

    /**
     * Přijaté celé storno JMHZ ruší výsledek za celé období, tedy i všechny
     * dříve přijaté dílčí opravy navázané na stejný řádný kořen.
     */
    public static function supersedesCorrectionChainOnAcceptance(
        string $agendaCode,
        string $submissionKind,
    ): bool {
        return $agendaCode === 'JMHZ25' && $submissionKind === 'cancellation';
    }
}

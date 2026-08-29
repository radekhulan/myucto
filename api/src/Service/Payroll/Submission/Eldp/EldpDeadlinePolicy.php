<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty evidenčního listu důchodového pojištění.
 *
 * ## Zákonný rámec
 *
 * Zákon č. 582/1991 Sb., o organizaci a provádění sociálního zabezpečení,
 * **§ 38** (vedení evidenčních listů) a **§ 39** (jejich předkládání),
 * **ve znění účinném do 31. 12. 2025**. Modul staví evidenční list jen tam,
 * kde přechodné ustanovení (čl. V bod 1 zák. č. 360/2025 Sb.) přikazuje
 * postupovat podle znění účinného před 1. 1. 2026, proto se ustanovení citují
 * v tehdejším číslování:
 *
 * - evidenční list vede zaměstnavatel pro každého občana účastného
 *   důchodového pojištění, a to **za kalendářní rok** (§ 38 odst. 1 a 2);
 * - údaje se do něj **zapisují** po účetní závěrce (závěrce mzdových listů),
 *   **nejpozději do 30. dubna** následujícího kalendářního roku, a skončí-li
 *   účast na důchodovém pojištění před 31. prosincem, **do 1 měsíce po
 *   konečném vyúčtování příjmů, nejpozději do 31. ledna** následujícího roku
 *   (§ 38 odst. 4, úvodní část ustanovení);
 * - **předkládá se do 30 dnů ode dne zápisu údajů** (§ 39 odst. 2 písm. a));
 *   do 30 dnů ode dne zániku zaměstnavatele (§ 39 odst. 2 písm. b)); na výzvu
 *   orgánu sociálního zabezpečení do 8 dnů (§ 39 odst. 3); při úmrtí občana
 *   do 3 měsíců (§ 39 odst. 4 písm. b));
 * - jeden stejnopis zaměstnavatel zakládá do své evidence, druhý vydává
 *   občanovi (§ 38 odst. 5 věta první).
 *
 * Lhůta je tedy mez pro **zápis údajů**, ne pro „vyhotovení po zúčtování mezd
 * za prosinec" — takovou formulaci zákon neobsahuje. Modul registruje jako
 * termín povinnosti tuhle vnější zákonnou mez pro zápis (30. 4., resp.
 * vyúčtování + 1 měsíc se stropem 31. 1.), ne až mez pro předložení
 * (zápis + 30 dnů). Je to vědomě přísnější: lhůta na předložení běží od
 * zápisu, který v aplikaci vzniká právě přípravou podání, takže pozdější
 * datum by povinnost jen falešně prodlužovalo.
 *
 * ⚠️ **Číslování ustanovení se k 1. 1. 2026 změnilo a nelze je zaměňovat.**
 * V dnešním znění jsou lhůty pro předložení v § 39 odst. 3 písm. a) až d)
 * a dva stejnopisy včetně tříleté úschovy v § 38 odst. 5 — ale dopadají už
 * jen na zaměstnavatele **příslušníků ozbrojených sil** vůči ministerstvům
 * obrany a vnitra, což je větev, kterou modul nepodporuje. Doslovné znění
 * obou paragrafů před novelou i mapovací tabulka starých ustanovení na nová
 * jsou v `private/Mzdy/24-ELDP-ZNENI-DO-2025.md`.
 *
 * ## Roční povinnost zaměstnavatele od roku 2026 NEEXISTUJE
 *
 * ⚠️ Tohle je nejdůležitější věta celé třídy a nesmí se „opravit" zpátky.
 * Novela č. 360/2025 Sb. přepsala § 38 zákona č. 582/1991 Sb.: podle odst. 1
 * sděluje zaměstnavatel údaje pro důchodové pojištění **prostřednictvím
 * jednotného měsíčního hlášení zaměstnavatele**, a podle odst. 2 „**Česká
 * správa sociálního zabezpečení sestaví evidenční list** na základě údajů
 * sdělovaných podle odstavce 1". Zaměstnanci se list zpřístupňuje na ePortálu
 * (§ 39 odst. 1). Zaměstnavatel tedy evidenční list **nevyhotovuje ani
 * nepředkládá** — žádná roční povinnost „sestav a odešli ELDP za rok" mu
 * nezůstala a modul ji nesmí nabízet. Nabízet ji znamená posílat mzdovou
 * účetní dělat práci, kterou za ni dělá ČSSZ, a naopak ji utvrzovat, že za
 * data v důchodovém pojištění odpovídá ona.
 *
 * Proto {@see self::forYear()} **odmítá roky od 2026**: řádná roční lhůta
 * 30. dubna je pro ně nezákonná — počítala by se z povinnosti, která
 * neexistuje. `forYear()` slouží už jen letům, na která podle čl. V bodu 1
 * zák. č. 360/2025 Sb. dopadá znění účinné do 31. 12. 2025.
 *
 * ## Tiskopis zrušen nebyl — úzká ruční cesta zůstává
 *
 * Podle podkladu ČSSZ *Řešení částečně zrušených tiskopisů k 4. 12. 2025*
 * tiskopisy ELDP **NEBUDOU zrušeny**. Samostatný evidenční list vede a
 * předkládá zaměstnavatel dál, ale jen ve vyjmenovaných výjimkách:
 *
 * - za období **před 1. 1. 2026** stávajícím způsobem,
 * - u zaměstnání **skončených před 1. 4. 2026**,
 * - **na výzvu ČSSZ/ÚSSZ** podle § 38a odst. 2 a 3 zákona č. 582/1991 Sb. —
 *   uplynula-li lhůta pro měsíční hlášení nebo pro opravné hlášení, anebo
 *   nelze-li z nahlášených údajů evidenční list sestavit; při výzvě v průběhu
 *   roku se list uzavírá posledním měsícem, za který už zaměstnavatel
 *   zúčtoval příjem (přechodně též čl. V bod 8 zák. č. 360/2025 Sb. pro rok
 *   2026),
 * - u příslušníků ozbrojených sil (§ 38 odst. 5, § 39 odst. 3 — předkládají se
 *   MV/MO, ne ČSSZ; tuhle větev modul nepodporuje).
 *
 * O přípustnosti rozhoduje jediné místo: {@see self::standaloneStatementAllowed()}.
 * Roční workflow z něj neplyne pro žádný rok od 2026 — vždycky je to výjimka.
 */
final class EldpDeadlinePolicy
{
    /**
     * Poslední rok, za který zaměstnavatel evidenční list vůbec vyhotovoval
     * za celý kalendářní rok. Není to rok podpory modulu ani rok rulesetu —
     * je to den účinnosti novely č. 360/2025 Sb. vyjádřený rokem: od roku 2026
     * sestavuje evidenční list ČSSZ z měsíčního hlášení (§ 38 odst. 2 zákona
     * č. 582/1991 Sb.) a roční lhůta zaměstnavateli neběží.
     */
    public const LAST_ANNUAL_YEAR = 2025;

    public const ANNUAL_RULESET = 'cz-eldp-deadlines.annual.v1';
    public const TERMINATION_RULESET = 'cz-eldp-deadlines.termination.v1';
    public const AUTHORITY_REQUEST_RULESET =
        'cz-eldp-deadlines.authority-request.v1';

    private const SOURCES = [
        'law' => '582/1991 Sb., § 38 odst. 4 a § 39 odst. 2 až 4',
        'law_wording' => 've znění účinném do 31. 12. 2025; použije se podle '
            . 'čl. V bodu 1 zák. č. 360/2025 Sb.',
        'authority_request_transition' => 'čl. V bod 8 zák. č. 360/2025 Sb.',
        'pension_law' => '155/1995 Sb., § 16 odst. 4 písm. a) a j)',
        'cssz_document' => 'ČSSZ — Evidenční listy důchodového pojištění',
        'jmhz_rules' =>
            'Pravidla podání JMHZ a související procesy, verze 1.4.4, kapitola 4',
        'paragraph_detail_verified' => 'yes',
        'paragraph_detail_verified_on' => '2026-08-17',
    ];

    /**
     * Řádný roční evidenční list za skončený kalendářní rok — jen do roku 2025.
     *
     * Od roku 2026 žádná roční lhůta zaměstnavateli neběží, protože mu neběží
     * ani povinnost: evidenční list sestavuje ČSSZ z měsíčního hlášení
     * (§ 38 odst. 2 zákona č. 582/1991 Sb. ve znění zák. č. 360/2025 Sb.).
     * Vydat pro takový rok termín 30. dubna by znamenalo vymyslet povinnost.
     * Metoda proto raději spadne, než aby vrátila lhůtu, která neexistuje;
     * přípustné výjimky mají vlastní okna ({@see self::forTermination()},
     * {@see self::forAuthorityRequest()}).
     */
    public function forYear(int $year): EldpDeadlineWindow
    {
        self::assertYear($year);
        if ($year > self::LAST_ANNUAL_YEAR) {
            throw new \InvalidArgumentException(
                'Za rok ' . $year . ' zaměstnavatel evidenční list nevyhotovuje '
                . 'ani nepředkládá — sestavuje jej ČSSZ z jednotného měsíčního '
                . 'hlášení (§ 38 odst. 2 zákona č. 582/1991 Sb.). Roční lhůta '
                . 'evidenčního listu se pro tento rok neurčuje.',
            );
        }

        return $this->window(
            sprintf('%04d-01-01', $year + 1),
            sprintf('%04d-04-30', $year + 1),
            self::ANNUAL_RULESET,
            'annual_by_30_april_following_year',
            'annual',
            'Zákon č. 582/1991 Sb., § 38 odst. 4 ve znění účinném do 31. 12. 2025 — '
                . 'údaje se do evidenčního listu za kalendářní rok zapisují nejpozději '
                . 'do 30. dubna následujícího roku.',
        );
    }

    /**
     * Mimořádný evidenční list při skončení účasti na důchodovém pojištění.
     *
     * @param string $participationEndOn poslední den účasti (Y-m-d)
     * @param string $finalSettlementOn  den konečného vyúčtování příjmů (Y-m-d)
     */
    public function forTermination(
        int $year,
        string $participationEndOn,
        string $finalSettlementOn,
    ): EldpDeadlineWindow {
        self::assertYear($year);
        $end = self::date($participationEndOn, 'Poslední den účasti');
        $settlement = self::date($finalSettlementOn, 'Konečné vyúčtování příjmů');
        if ($end->format('Y') !== (string) $year) {
            throw new \InvalidArgumentException(
                'Poslední den účasti musí ležet ve vykazovaném roce.',
            );
        }
        if ($settlement < $end) {
            throw new \InvalidArgumentException(
                'Konečné vyúčtování příjmů nemůže předcházet konci účasti.',
            );
        }
        $oneMonthAfter = self::addMonthClamped($settlement)->format('Y-m-d');
        $outerBound = sprintf('%04d-01-31', $year + 1);

        return $this->window(
            $settlement->format('Y-m-d'),
            min($oneMonthAfter, $outerBound),
            self::TERMINATION_RULESET,
            'termination_one_month_after_settlement_capped_31_january',
            'termination',
            'Zákon č. 582/1991 Sb., § 38 odst. 4 ve znění účinném do 31. 12. 2025 — '
                . 'skončí-li účast na důchodovém pojištění před 31. prosincem, zapisují '
                . 'se údaje do evidenčního listu do jednoho měsíce po konečném '
                . 'vyúčtování příjmů, nejpozději do 31. ledna následujícího roku.',
        );
    }

    /**
     * Evidenční list vyžádaný ČSSZ/ÚSSZ za rok 2026.
     */
    public function forAuthorityRequest(string $requestReceivedOn): EldpDeadlineWindow
    {
        $received = self::date($requestReceivedOn, 'Datum doručení výzvy');
        $dueOn = $received->modify('+8 days')->format('Y-m-d');

        return $this->window(
            $received->format('Y-m-d'),
            $dueOn,
            self::AUTHORITY_REQUEST_RULESET,
            'authority_request_within_8_days',
            'annual',
            'Čl. V bod 8 zákona č. 360/2025 Sb. — evidenční list s údaji '
                . 'za rok 2026 vyžádaný ČSSZ/ÚSSZ se předkládá do 8 dnů ode dne '
                . 'obdržení výzvy.',
        );
    }

    /**
     * Přípustnost samostatného evidenčního listu za daný rok.
     *
     * `routine` odlišuje běžnou roční povinnost od výjimky. Pravdivá je jen
     * u let, kdy roční evidenční list opravdu existoval — od roku 2026 už
     * nikdy: každá povolená cesta je tam jednorázová výjimka, kterou spouští
     * konkrétní událost (skončení zaměstnání, výzva úřadu), ne konec roku.
     * Kdo tuhle hodnotu jednou zobrazí uživateli, nesmí ji přestat rozlišovat —
     * bez ní vypadá výjimka na obrazovce stejně jako roční rutina.
     *
     * @return array{allowed:bool,routine:bool,reason:string,rule:string}
     */
    public static function standaloneStatementAllowed(
        int $year,
        ?string $participationEndOn,
        bool $requestedByAuthority,
    ): array {
        if ($year < 2026) {
            return [
                'allowed' => true,
                'routine' => true,
                'reason' => 'Za období před 1. 1. 2026 vede a předkládá evidenční list '
                    . 'zaměstnavatel podle znění účinného do 31. 12. 2025 '
                    . '(čl. V bod 1 zákona č. 360/2025 Sb.).',
                'rule' => 'transitional_before_2026',
            ];
        }
        if ($requestedByAuthority) {
            return [
                'allowed' => true,
                'routine' => false,
                'reason' => 'Evidenční list se sestavuje na výzvu ČSSZ/ÚSSZ podle '
                    . '§ 38a odst. 2 a 3 zákona č. 582/1991 Sb. — uplynula lhůta pro '
                    . 'měsíční nebo opravné hlášení, anebo z nahlášených údajů '
                    . 'evidenční list sestavit nelze.',
                'rule' => 'on_authority_request',
            ];
        }
        if ($year === 2026
            && $participationEndOn !== null
            && $participationEndOn < '2026-04-01'
        ) {
            return [
                'allowed' => true,
                'routine' => false,
                'reason' => 'Zaměstnání skončilo před 1. 4. 2026, takže na ně dopadá '
                    . 'přechodné ustanovení a evidenční list vyhotoví zaměstnavatel.',
                'rule' => 'transitional_participation_ended_before_april_2026',
            ];
        }

        return [
            'allowed' => false,
            'routine' => false,
            'reason' => 'Zaměstnavatel evidenční list nevyhotovuje ani nepředkládá — '
                . 'údaje pro důchodové pojištění sděluje jednotným měsíčním hlášením '
                . 'a evidenční list z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona '
                . 'č. 582/1991 Sb.). Zaměstnanci je dostupný na ePortálu ČSSZ '
                . '(§ 39 odst. 1). Samostatný list se vyhotovuje jen za období před '
                . 'rokem 2026, u zaměstnání skončených před 1. 4. 2026 a na výzvu '
                . 'ČSSZ/ÚSSZ podle § 38a odst. 2 a 3.',
            'rule' => 'assembled_by_cssz_from_monthly_report',
        ];
    }

    /**
     * @param 'annual'|'termination' $statementKind
     */
    private function window(
        string $earliestSubmissionOn,
        string $dueOn,
        string $rulesetId,
        string $rule,
        string $statementKind,
        string $legalBasis,
    ): EldpDeadlineWindow {
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if (!$dueDate instanceof \DateTimeImmutable) {
            throw new \LogicException('Termín ELDP není platné datum.');
        }
        $shiftedDueOn = CzechWorkingDays::shiftToWorkingDay($dueDate)
            ->format('Y-m-d');
        $rulesetHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'eldp-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'rule' => $rule,
            'statement_kind' => $statementKind,
            'due_shift' => 'next_czech_working_day',
            'calendar_basis' => 'business_days',
            'sources' => self::SOURCES,
        ]));

        return new EldpDeadlineWindow(
            $earliestSubmissionOn,
            $shiftedDueOn,
            'business_days',
            $rulesetId,
            $rulesetHash,
            $statementKind,
            $legalBasis,
        );
    }

    /**
     * Lhůta určená podle měsíců končí dnem, který se pojmenováním shoduje se
     * dnem, kdy začala; není-li takový den v měsíci, končí posledním dnem
     * měsíce. Prosté `+1 month` v PHP místo toho přeteče do dalšího měsíce
     * (31. 8. → 1. 10.), a to by lhůtu prodloužilo o den.
     */
    private static function addMonthClamped(
        \DateTimeImmutable $date,
    ): \DateTimeImmutable {
        $shifted = $date->modify('+1 month');
        if ($shifted->format('d') !== $date->format('d')) {
            return $date->modify('first day of next month')
                ->modify('last day of this month');
        }

        return $shifted;
    }

    private static function assertYear(int $year): void
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException(
                'Rok evidenčního listu musí být v rozsahu 2000 až 2100.',
            );
        }
    }

    private static function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException("{$label} není platné datum.");
        }

        return $date;
    }
}

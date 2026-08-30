<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Druh dávky nemocenského pojištění, tedy `dokument/druhDavky` v NEMPRI25.xsd.
 *
 * Hodnota enumu je přesně hodnota enumerace `StDruhDavky` — velkými písmeny.
 * Překládat ji na hezčí slovo by znamenalo druhé místo, kde se dá splést druh
 * dávky, a rozdíl mezi ošetřovným a dlouhodobým ošetřovným je rozdíl mezi
 * jinou podpůrčí dobou i jiným okamžikem, kdy se hlásí.
 *
 * ## Proč aplikace neumí sestavit všechny
 *
 * `CtNem` a `CtVpm` obsahují VÝHRADNĚ `potvrzeniZamestnavatele`. To jsou údaje,
 * které zaměstnavatel opravdu drží: zda zaměstnanec v rozhodný den pracoval,
 * pracovní doba, důchod, studium, neplacené volno, exekuce, insolvence.
 *
 * `CtOpp`, `CtPpm`, `CtOse` a `CtDlo` naproti tomu povinně nesou i
 * `zadostODavku` — jméno a rodné číslo dítěte a pořadí dítěte, důvod otcovské,
 * důvod péče, ošetřovanou osobu, kód vztahu k ní, prohlášení o společné
 * domácnosti. Nic z toho zaměstnavatel neeviduje; je to obsah žádosti, kterou
 * podle § 109 odst. 1 písm. b) bodu 1 zák. č. 187/2006 Sb. podává POJIŠTĚNEC.
 * Vyplnit to za něj by znamenalo vytvořit tvrzení, které nikdo neučinil.
 * Proto tyhle druhy dávky zůstávají fail-closed s vlastním důvodovým kódem;
 * případ se u nich eviduje a lhůta hlídá, datová věta se nesestaví.
 */
enum SicknessBenefitKind: string
{
    case Nem = 'NEM';
    case Vpm = 'VPM';
    case Opp = 'OPP';
    case Ppm = 'PPM';
    case Ose = 'OSE';
    case Dlo = 'DLO';

    /** Název prvku uvnitř `davka` (CtDruhDavky je `xs:choice`). */
    public function elementName(): string
    {
        return strtolower($this->value);
    }

    /**
     * Umí aplikace sestavit datovou větu NEMPRI pro tenhle druh dávky?
     */
    public function isSerializable(): bool
    {
        return $this === self::Nem || $this === self::Vpm;
    }

    /**
     * Má tenhle druh dávky v potvrzení zaměstnavatele pracovní volno bez
     * náhrady příjmu? `CtPotvrzeniZamestnavateleVpm` prvek `volnoBezNahrady`
     * NEMÁ, takže by ho u vyrovnávacího příspěvku XSD odmítlo.
     */
    public function hasUnpaidLeaveSection(): bool
    {
        return $this === self::Nem;
    }

    /**
     * Má druh dávky sekci o studiu? U PPM ji `CtPotvrzeniZamestnavatelePpm`
     * nemá; u NEM a VPM je `jeStudentem` povinné.
     */
    public function hasStudentSection(): bool
    {
        return $this === self::Nem || $this === self::Vpm;
    }

    /** Důvodový kód, proč u téhle dávky nelze datovou větu sestavit. */
    public function unsupportedReasonCode(): string
    {
        return match ($this) {
            self::Nem, self::Vpm => 'nempri_benefit_kind_supported',
            self::Opp => 'nempri_paternity_application_data_not_held',
            self::Ppm => 'nempri_maternity_application_data_not_held',
            self::Ose => 'nempri_care_application_data_not_held',
            self::Dlo => 'nempri_long_term_care_application_data_not_held',
        };
    }

    public function unsupportedReason(): string
    {
        return match ($this) {
            self::Nem, self::Vpm => '',
            self::Opp =>
                'Otcovská vyžaduje v datové větě žádost o dávku s údaji o dítěti a důvodem otcovské. '
                . 'Ty podává pojištěnec podle § 109 odst. 1 písm. b) bodu 1; zaměstnavatel je nemá a aplikace je nedomýšlí. '
                . 'Případ zůstává v evidenci s hlídanou lhůtou, podání připravte v ePortálu ČSSZ.',
            self::Ppm =>
                'Peněžitá pomoc v mateřství vyžaduje v datové větě žádost o dávku s důvodem péče a seznamem dětí. '
                . 'Ty podává pojištěnec; zaměstnavatel je nemá a aplikace je nedomýšlí. '
                . 'Případ zůstává v evidenci s hlídanou lhůtou, podání připravte v ePortálu ČSSZ.',
            self::Ose =>
                'Ošetřovné vyžaduje v datové větě žádost o dávku s ošetřovanou osobou, kódem vztahu a prohlášením '
                . 'o společné domácnosti. Ty podává pojištěnec; zaměstnavatel je nemá a aplikace je nedomýšlí. '
                . 'Případ zůstává v evidenci s hlídanou lhůtou, podání připravte v ePortálu ČSSZ.',
            self::Dlo =>
                'Dlouhodobé ošetřovné vyžaduje v datové větě žádost o dávku s ošetřovanou osobou a kódem vztahu. '
                . 'Ty podává pojištěnec; zaměstnavatel je nemá a aplikace je nedomýšlí. '
                . 'Případ zůstává v evidenci s hlídanou lhůtou, podání připravte v ePortálu ČSSZ.',
        };
    }
}

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
 * ## Sestavit jde každý druh
 *
 * `CtNem` a `CtVpm` obsahují jen potvrzení zaměstnavatele. `CtOpp`, `CtPpm`,
 * `CtOse` a `CtDlo` k němu nesou i `zadostODavku` — údaje o dítěti, ošetřované
 * osobě, důvodu péče nebo otcovské. Ty zaměstnavatel NEVYMÝŠLÍ, ale opisuje
 * z žádosti, kterou mu zaměstnanec předal: § 97 odst. 1 zák. č. 187/2006 Sb.
 * mu ukládá žádosti o dávky (s výjimkou nemocenského) přijímat a neprodleně
 * předávat územní správě. Dřívější blokace těchto druhů tak bránila splnit
 * zákonnou povinnost, kterou jiné mzdové programy plní běžně. Úplnost žádosti
 * hlídá {@see SicknessXmlValidator}.
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
     * Nese dávka povinné akce vznik / trvání / ukončení? Jen ošetřovné
     * (`oseVznik` …) a dlouhodobé ošetřovné (`dloVznik` …).
     */
    public function hasActions(): bool
    {
        return $this === self::Ose || $this === self::Dlo;
    }

    /**
     * Vzniká u dávky hlášení při ukončení pracovní neschopnosti (HZUPN)?
     *
     * Jen u nemocenského: HZUPN hlásí nástup do zaměstnání po skončení
     * dočasné pracovní neschopnosti nebo karantény. Ošetřovné, otcovská ani
     * mateřská žádnou neschopnost nemají, takže lhůta HZUPN by u nich tvrdila
     * povinnost, která neexistuje. Stejné pravidlo drží obrazovka případů
     * (`HZUPN_KINDS`) i hlídač termínů.
     */
    public function hasEndOfIncapacityReport(): bool
    {
        return $this === self::Nem;
    }

    /** Nese dávka žádost o dávku (`zadostODavku`)? */
    public function hasApplication(): bool
    {
        return $this !== self::Nem && $this !== self::Vpm;
    }

    /**
     * Má tenhle druh dávky v potvrzení zaměstnavatele pracovní volno bez
     * náhrady příjmu? `CtPotvrzeniZamestnavateleVpm` ani `…Ppm` prvek
     * `volnoBezNahrady` NEMAJÍ, otcovská nese jen základní potvrzení.
     */
    public function hasUnpaidLeaveSection(): bool
    {
        return $this === self::Nem || $this === self::Ose || $this === self::Dlo;
    }

    /**
     * Má druh dávky sekci o studiu? U PPM a OPP ji potvrzení nemá; u NEM,
     * VPM, OSE a DLO je `jeStudentem` povinné.
     */
    public function hasStudentSection(): bool
    {
        return $this !== self::Ppm && $this !== self::Opp;
    }

    /**
     * Musí věta nést číslo rozhodnutí?
     *
     * Nemocenské, ošetřovné i dlouhodobé ošetřovné stojí na rozhodnutí lékaře
     * (eNeschopenka, eOČR, rozhodnutí o potřebě dlouhodobé péče) a ČSSZ podle
     * jeho čísla podání páruje. Otcovská, peněžitá pomoc v mateřství
     * a vyrovnávací příspěvek žádné takové číslo nemají — přijatá podání
     * otcovské ho nenesou. Zahraniční případ číslo z českého systému mít
     * nemusí; rozhoduje o tom {@see SicknessXmlValidator}.
     */
    public function requiresDecisionNumber(): bool
    {
        return $this === self::Nem || $this === self::Ose || $this === self::Dlo;
    }

    /** První den, od kterého se rozhodnutí o vzniku nebo potvrzení vyřizují NEMPRI25. */
    public const NEMPRI25_FROM = '2025-01-01';

    /**
     * Patří událost ještě k formuláři NEMPRI20? (FAQ ČSSZ k dávkám NP, dotazy
     * 1, 3 a 5; Elektronizace dávek NP, str. 5.)
     *
     * Ošetřovné, dlouhodobé ošetřovné, otcovská a PPM s rozhodnutím nebo
     * potvrzením vystaveným do konce roku 2024 se vyřizují NEMPRI20 až do
     * ukončení případu; ošetřovné zahájené v roce 2024 i při trvání nebo
     * ukončení v roce 2025. Lékař rozhodnutí nebo potvrzení vystavuje nejpozději
     * se vznikem události, takže událost z roku 2024 má i rozhodnutí z roku 2024.
     * U otcovské a převzetí dítěte do péče rozhoduje den podání žádosti, který
     * aplikace neeviduje: událost z roku 2024 se proto zastaví také a účetní ji
     * vyřídí mimo aplikaci. Nemocenské smí jít NEMPRI25 i ze starého období
     * (FAQ dotaz 3), VPM pravidlo nejmenuje.
     *
     * MyÚčto NEMPRI20 nesestavuje, takže vrací důvod zastavení, nebo `null`.
     *
     * @return array{code:string,message:string}|null
     */
    public function legacyFormProblem(string $eventOn, bool $hasCareReason): ?array
    {
        if (!in_array($this, [self::Ose, self::Dlo, self::Opp, self::Ppm], true)
            || $eventOn >= self::NEMPRI25_FROM
        ) {
            return null;
        }
        $message = 'Sociální událost vznikla ' . $eventOn . ', před 1. 1. 2025. Ošetřovné, dlouhodobé '
            . 'ošetřovné, otcovská a peněžitá pomoc v mateřství s rozhodnutím nebo potvrzením '
            . 'vystaveným do konce roku 2024 se vyřizují formulářem NEMPRI20 až do ukončení '
            . 'případu (FAQ ČSSZ k dávkám NP). MyÚčto sestavuje jen NEMPRI25, oznámení proto '
            . 'podejte jako NEMPRI20 mimo aplikaci, například přes ePortál ČSSZ.';
        if ($this === self::Opp || ($this === self::Ppm && $hasCareReason)) {
            $message .= ' U otcovské a u převzetí dítěte do péče rozhoduje den podání žádosti; '
                . 'podal-li ji zaměstnanec až od 1. 1. 2025, patří k ní NEMPRI25, aplikace ale '
                . 'den podání žádosti neeviduje, a tak i ten podejte mimo aplikaci.';
        }

        return ['code' => 'nempri_legacy_form_required', 'message' => $message];
    }

    public const DECISION_REQUIRED = 'required';
    public const DECISION_OPTIONAL = 'optional';
    public const DECISION_FORBIDDEN = 'forbidden';

    /**
     * Povinnost čísla rozhodnutí podle logických kontrol NEMPRI25 č. 2 a 3.
     *
     * NEM a OSE/DLO stojí na rozhodnutí lékaře. PPM nese číslo jen tehdy, když
     * nemá `duvodPece` (převzetí dítěte do péče); s důvodem je číslo zakázané.
     * U VPM se číslo nevyplňuje, otcovská ho mít smí, ale nemusí.
     *
     * @return self::DECISION_*
     */
    public function decisionNumberRequirement(bool $hasCareReason = false): string
    {
        return match ($this) {
            self::Nem, self::Ose, self::Dlo => self::DECISION_REQUIRED,
            self::Ppm => $hasCareReason ? self::DECISION_FORBIDDEN : self::DECISION_REQUIRED,
            self::Opp => self::DECISION_OPTIONAL,
            self::Vpm => self::DECISION_FORBIDDEN,
        };
    }

    /**
     * Co je s číslem rozhodnutí špatně podle kontrol NEMPRI25 č. 2 a 3: chybí
     * povinné, nesmí tam být, nebo nemá tvar druhu dávky. `null` = je v pořádku.
     *
     * Jediné místo pravidla: volá ho validátor věty i předkontrola služby před
     * sestavením věty, aby účetní dostala tutéž srozumitelnou hlášku dřív, než
     * věta vůbec vznikne. Zahraniční případ číslo z českého systému mít nemusí,
     * a tak se pro něj tvar nehlídá. PPM s důvodem převzetí do péče vlastní
     * zákaz čísla hlídá žádost o dávku (jiný kód), tady proto prochází.
     *
     * @return array{code:string,message:string}|null
     */
    public function decisionNumberProblem(
        ?string $number,
        bool $hasCareReason,
        bool $foreignCase,
    ): ?array {
        $requirement = $this->decisionNumberRequirement($hasCareReason);
        if ($requirement === self::DECISION_FORBIDDEN) {
            if ($number !== null && $this !== self::Ppm) {
                return [
                    'code' => 'nempri_decision_number_forbidden',
                    'message' => 'U tohoto druhu dávky se číslo rozhodnutí nevyplňuje. Smažte ho v případu dávky.',
                ];
            }

            return null;
        }
        if ($number === null) {
            if ($requirement === self::DECISION_REQUIRED && !$foreignCase) {
                return [
                    'code' => 'nempri_decision_number_missing',
                    'message' => 'Chybí číslo rozhodnutí (u eNeschopenky a eOČR číslo z rozhodnutí lékaře). '
                        . 'ČSSZ podle něj oznámení páruje s rozhodnutím; bez něj ho nezpracuje. '
                        . 'Výjimkou je jen zahraniční případ.',
                ];
            }

            return null;
        }
        if ($foreignCase) {
            return null;
        }
        if (preg_match($this->decisionNumberPattern(), $number) !== 1) {
            return [
                'code' => 'nempri_decision_number_format_invalid',
                'message' => 'Číslo rozhodnutí nemá tvar, který ČSSZ u dávky ' . $this->value . ' kontroluje '
                    . '(NEM: písmeno a 6 až 7 číslic nebo 10 číslic, případně s předsazeným IČPE; PPM '
                    . 'končí písmenem M, OSE N nebo Z, OPP T, DLO L, vždy se sedmimístným číslem '
                    . 'a volitelnou předponou ICPE).',
            ];
        }

        return SicknessDecisionNumber::icpeProblem($number, 'nempri_decision_number_icpe_invalid');
    }

    /**
     * Tvar čísla rozhodnutí podle kontroly č. 2. U NEM `Xnnnnnnn` (číslo
     * z papírové neschopenky) nebo `YYMMDDNNNN`, případně s předsazeným
     * osmimístným IČPE; u ostatních druhů sedmimístné pořadové číslo
     * s písmenem druhu dávky (PPM M, OSE N nebo Z, OPP T, DLO L) a volitelnou
     * předponou ICPE.
     */
    public function decisionNumberPattern(): string
    {
        return match ($this) {
            self::Nem => '/^(?:[A-Z]\d{6,7}|(?:\d{8})?\d{10})$/D',
            self::Ppm => '/^(?:\d{1,10})?\d{7}M$/D',
            self::Ose => '/^(?:\d{1,10})?\d{7}[NZ]$/D',
            self::Opp => '/^(?:\d{1,10})?\d{7}T$/D',
            self::Dlo => '/^(?:\d{1,10})?\d{7}L$/D',
            self::Vpm => '/^$/D',
        };
    }

    /**
     * Platí se výplata dávky na základě platebního spojení i bez akce vznik?
     * U OSE/DLO je spojení povinné při vzniku a bez vzniku zakázané; u ostatních
     * druhů povinné vždy, u NEM jen pro číslo rozhodnutí platné od 1. 1. 2020
     * (elektronické `YYMMDDNNNN`).
     */
    public function paymentConnectionRequirement(
        bool $startsClaim,
        ?string $decisionNumber,
    ): string {
        if ($this->hasActions()) {
            return $startsClaim ? self::DECISION_REQUIRED : self::DECISION_FORBIDDEN;
        }
        if ($this === self::Nem) {
            return $decisionNumber !== null && SicknessDecisionNumber::isElectronicSickness($decisionNumber)
                ? self::DECISION_REQUIRED
                : self::DECISION_OPTIONAL;
        }

        return self::DECISION_REQUIRED;
    }
}

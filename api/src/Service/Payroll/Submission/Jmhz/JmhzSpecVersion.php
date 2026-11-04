<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;

/**
 * Verze specifikace JMHZ (ročník datových vět ČSSZ/MPSV) - JEDINÉ místo, kde
 * žije hranice mezi verzí 2026 a 2027.
 *
 * MPSV provozuje obě verze souběžně a volí se podle toho, ZA CO se podává,
 * ne kdy (přehled změn https://developers.mpsv.cz/jmhz/2027/, příručka
 * „JMHZ testování změn 2027", kap. 3):
 *
 * - měsíční hlášení rozhoduje VYKAZOVANÉ OBDOBÍ: do 12/2026 verze 2026,
 *   od 01/2027 verze 2027. Opravné a stornovací hlášení za období 2026
 *   podané v roce 2027 jde dál verzí 2026,
 * - registrační podání (REGZEC, PREZEC) rozhoduje DATUM UDÁLOSTI (nástup,
 *   skončení, platnost změny): od 1. 1. 2027 REGZEC27 (EDV 1.5.0.2,
 *   řádky 276 až 278 - 10223, 10009 a 10224 nejdřív 1. 1. 2027).
 *
 * Jediná výjimka je změna a oprava (A3, A4) bez data skončení 10224: tu ČSSZ
 * podle 10224 hlásí jako REGZEC27, jenže ostrá ČSSZ přijímá REGZEC27 až od
 * {@see self::FIRST_REGZEC27_ACCEPTED_ON}. Do té doby jde taková věta jako
 * REGZEC25 ({@see self::forRegistrationChangeWithoutEnd()}). Den vyhotovení
 * se předává parametrem, hodiny nebere žádná metoda.
 *
 * Kódy agend v evidenci povinností (`payroll_obligations.agenda_code`) se
 * s verzí NEMĚNÍ: `JMHZ25` a `REGZEC25` jsou jména agendy, ne ročník
 * datové věty. Na nich stojí generovaný sloupec jedinečnosti řádného
 * hlášení (migrace 1731), odklady i řetězy oprav přes přelom roku. Ročník
 * nese až `eType` obálky ({@see self::envelopeTypeFor()}) a jmenný prostor
 * datové věty. Kód `JMHZ27`/`REGZEC27`, který přijde zvenčí (protokol,
 * datovka), katalogy mapují na tutéž agendu ({@see self::canonicalAgendaCode()}).
 */
enum JmhzSpecVersion: string
{
    case V2026 = '2026';
    case V2027 = '2027';

    /** První vykazované období verze 2027 (`YYYY-MM`). */
    public const FIRST_2027_PERIOD = '2027-01';

    /** První den události, od kterého se registrační podání řídí verzí 2027. */
    public const FIRST_2027_EVENT_DATE = '2027-01-01';

    /**
     * První den, kdy ostrá ČSSZ přijímá REGZEC27. Nejde o rozhodné datum
     * ročníku ({@see self::FIRST_2027_EVENT_DATE}), ale o otevření příjmu:
     * ČSSZ, Otázky a odpovědi k NV2027 (developers.mpsv.cz), odpověď 5:
     * „REGZEC 2027 tak bude umožněno posílat již ode dne 23. 12. 2026", aby
     * šlo přihlásit před nástupem 1. 1. 2027.
     */
    public const FIRST_REGZEC27_ACCEPTED_ON = '2026-12-23';

    /** Kódy agendy měsíčního hlášení s ročníkem; všechny znamenají totéž hlášení. */
    public const MONTHLY_AGENDA_CODES = ['JMHZ25', 'JMHZ27'];

    /** Kódy agendy registrace zaměstnance s ročníkem. */
    public const REGISTRATION_AGENDA_CODES = ['REGZEC25', 'REGZEC27'];

    /**
     * Ročníkový `eType` obálky ČSSZ → kód agendy v evidenci. Viz docblock
     * třídy: ročník datové věty se do kódu agendy nepromítá.
     */
    private const AGENDA_ALIASES = [
        'JMHZ27' => 'JMHZ25',
        'REGZEC27' => 'REGZEC25',
    ];

    /**
     * GovTalk `Class` → `eType` vnořené obálky ČSSZ podle verze. Zdroj 2026:
     * CSSZSubmClasses.pdf; zdroj 2027: příručka testování změn 2027,
     * kap. 3.1 a 13.2 (Class beze změny, eType `JMHZ27`, `REGZEC27`).
     * PREZEC27 a DZMH27 doložené nejsou (otevřená otázka O-4), proto je verze
     * 2027 nemá a obálka se pro ně nepostaví.
     */
    private const ENVELOPE_TYPES = [
        '2026' => [
            'CSSZ_JMHZ' => 'JMHZ25',
            'CSSZ_REGZEC' => 'REGZEC25',
            'CSSZ_PREZEC' => 'PREZEC26',
        ],
        '2027' => [
            'CSSZ_JMHZ' => 'JMHZ27',
            'CSSZ_REGZEC' => 'REGZEC27',
        ],
    ];

    /** Měsíční hlášení za vykazované období. */
    public static function forPeriod(int $year, int $month): self
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2999) {
            throw new JmhzXmlException(
                'jmhz_spec_version_period_invalid',
                'Vykazované období hlášení není platný měsíc.',
            );
        }

        return sprintf('%04d-%02d', $year, $month) < self::FIRST_2027_PERIOD
            ? self::V2026
            : self::V2027;
    }

    /** Měsíční hlášení za období zadané prvním dnem (`YYYY-MM-DD`) nebo měsícem (`YYYY-MM`). */
    public static function forPeriodStart(string $period): self
    {
        if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/D', $period, $match) !== 1
            || (isset($match[3]) && !checkdate((int) $match[2], (int) $match[3], (int) $match[1]))
        ) {
            throw new JmhzXmlException(
                'jmhz_spec_version_period_invalid',
                'Vykazované období hlášení není platné datum.',
            );
        }

        return self::forPeriod((int) $match[1], (int) $match[2]);
    }

    /**
     * Registrační podání podle data události: nástup, skončení nebo platnost
     * změny (`YYYY-MM-DD`).
     */
    public static function forRegistrationEventDate(string $eventDate): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $eventDate) {
            throw new JmhzXmlException(
                'jmhz_spec_version_period_invalid',
                'Datum události registračního podání není platné datum.',
            );
        }

        return $eventDate < self::FIRST_2027_EVENT_DATE ? self::V2026 : self::V2027;
    }

    /**
     * Změna nebo oprava (A3, A4) bez data skončení 10224. ČSSZ, Otázky
     * a odpovědi k NV2027, odpovědi 4 a 13: rozhoduje výhradně 10224, prázdné
     * znamená REGZEC27 bez ohledu na platnost 10009 i na datum opravovaného
     * oznámení. REGZEC27 ale ostrá ČSSZ přijímá až od 23. 12. 2026 (odpověď 5),
     * takže věta vyhotovená dřív jde jako REGZEC25. Tohle je jediné místo,
     * kde o ročníku rozhoduje den vyhotovení (`YYYY-MM-DD`).
     */
    public static function forRegistrationChangeWithoutEnd(string $preparedOn): self
    {
        self::forRegistrationEventDate($preparedOn);

        return $preparedOn < self::FIRST_REGZEC27_ACCEPTED_ON ? self::V2026 : self::V2027;
    }

    /**
     * Verze uložená u zmrazeného podání nebo v payloadu. Neznámá hodnota je
     * chyba, ne „asi 2026": datová věta jiné verze by ČSSZ odmítla.
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom(trim($value)) ?? throw new JmhzXmlException(
            'jmhz_spec_version_unknown',
            "Verze specifikace JMHZ „{$value}“ není známá; podání se nesestaví ani neodešle.",
        );
    }

    /** Verze podle `eType` obálky ČSSZ; `null` u formuláře, který ročníky nerozlišuje. */
    public static function forEnvelopeType(string $envelopeType): ?self
    {
        foreach (self::ENVELOPE_TYPES as $version => $types) {
            if (in_array($envelopeType, $types, true)) {
                return self::from((string) $version);
            }
        }

        return null;
    }

    /** Rozlišuje GovTalk `Class` ročníky datové věty? */
    public static function isVersionedClass(string $submissionClass): bool
    {
        foreach (self::ENVELOPE_TYPES as $types) {
            if (isset($types[$submissionClass])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verze podle jmenného prostoru kořene datové věty. Jmenný prostor
     * nese ročník spolehlivě u JMHZ a REGZEC; PREZEC zůstává v 2027
     * v `.../PREZEC/2026`, a proto se tu nerozpozná (`null`).
     */
    public static function fromDocumentNamespace(string $namespace): ?self
    {
        return match ($namespace) {
            JmhzSchemaCatalog::NS_PODANI,
            PayrollRegistrationSchemaCatalog::NS_REGZEC_2026 => self::V2026,
            JmhzSchemaCatalog::NS_PODANI_2027,
            PayrollRegistrationSchemaCatalog::NS_REGZEC_2027 => self::V2027,
            default => null,
        };
    }

    /**
     * Kód agendy v evidenci pro kód, který může nést ročník datové věty.
     * Ostatní kódy vrací normalizované, ale beze změny.
     */
    public static function canonicalAgendaCode(string $agendaCode): string
    {
        $normalized = strtoupper(trim($agendaCode));

        return self::AGENDA_ALIASES[$normalized] ?? $normalized;
    }

    /** Je kód agendou měsíčního hlášení s ročníkem (`JMHZ25`, `JMHZ27`)? */
    public static function isMonthlyAgenda(string $agendaCode): bool
    {
        return in_array(strtoupper(trim($agendaCode)), self::MONTHLY_AGENDA_CODES, true);
    }

    /**
     * Je kód jen `eType` verze 2027, ne kód agendy? Takový kód se do evidence
     * povinností nezapisuje (viz docblock třídy).
     */
    public static function isEnvelopeOnlyCode(string $agendaCode): bool
    {
        return isset(self::AGENDA_ALIASES[strtoupper(trim($agendaCode))]);
    }

    /** `eType` obálky ČSSZ pro třídu v téhle verzi; `null` = nedoložený. */
    public function envelopeTypeFor(string $submissionClass): ?string
    {
        return self::ENVELOPE_TYPES[$this->value][$submissionClass] ?? null;
    }

    /** Text pro účetní: „2026" / „2027" s vysvětlením hranice. */
    public function label(): string
    {
        return match ($this) {
            self::V2026 => 'JMHZ 2026 (období do 12/2026)',
            self::V2027 => 'JMHZ 2027 (období od 01/2027)',
        };
    }
}

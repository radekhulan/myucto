<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * JEDINÝ seznam údajů osoby, bez kterých nejde sestavit registrační datová
 * věta (PREZEC26 / REGZEC25).
 *
 * PROČ: požadavky žily jen v serializéru a snapshotu, každý jako samostatná
 * výjimka. Příprava registrace proto hlásila chybějící údaje PO JEDNOM — po
 * doplnění občanství vyskočilo rodné příjmení, pak místo narození — a do textu
 * prosakoval technický název pole v závorce. Sekce „Údaje pro registraci
 * zaměstnance" na kartě osoby mezitím svítila „Doplněno", protože si stav
 * počítala po svém (stačil jí jeden vyplněný titul).
 *
 * Teď se ptají všichni tady: příprava registrace (vrací VŠECHNY mezery naráz,
 * každou s adresou pole pro proklik) i karta osoby (štítek sekce). Serializér
 * si svoje kontroly nechává jako poslední pojistku, ale na tuhle cestu se
 * s úplnými daty už nedostane.
 *
 * Požadavky kopírují, co serializér opravdu čte (`identityElements()`):
 * `name/@sur,@fir`, `birth/@nam,@cit` a `stat/@cnt` u obou agend, u REGZEC
 * navíc `stat/@mal`; identifikátor `client/@bno` podle snapshotu (PREZEC RČ
 * nebo EČP, REGZEC identifikátor NEBO úplná zahraniční identita).
 */
final class PayrollRegistrationIdentityRequirements
{
    public const AGENDA_PREZEC = 'PREZEC26';
    public const AGENDA_REGZEC = 'REGZEC25';

    /**
     * Den podání, od kterého PREZEC nevyžaduje rodné příjmení (ID 10063).
     *
     * EDV PREZEC 1.5 (Changelog 9. 9. 2026, zveřejněno 5. 11. 2026) mění
     * povinnost u akce P1 z „P" na „N"; EDV 1.2 platná pro rok 2026 ji drží
     * povinnou. XSD PREZEC26 1.2 i 1.5 má `birth/@nam` nepovinný, takže kontrolu
     * dělá jen ČSSZ podle verze, kterou má nasazenou. Proto rozhoduje den
     * podání, ne den nástupu: přihláška k lednovému nástupu podaná v prosinci
     * se ještě kontroluje podle EDV 1.2.
     */
    public const PREZEC_BIRTH_SURNAME_OPTIONAL_FROM = '2027-01-01';

    /** Panel karty osoby s historií jména a údaji pro registraci. */
    public const PANEL_IDENTITY = 'registration_identity';

    /** Panel karty osoby s identifikátory (RČ, EČP, VČP). */
    public const PANEL_IDENTIFIERS = 'identifiers';

    /** Kam proklik vede: karta osoby, nebo nastavení mezd zaměstnavatele. */
    public const TARGET_PERSON = 'person';
    public const TARGET_EMPLOYER_SETTINGS = 'employer_settings';
    public const TARGETS = [self::TARGET_PERSON, self::TARGET_EMPLOYER_SETTINGS];

    /**
     * Údaje identity, které nese datová věta u OBOU agend, v pořadí,
     * ve kterém je formulář na kartě osoby ukazuje.
     *
     * @var list<string>
     */
    private const COMMON_FIELDS = [
        'first_name',
        'last_name',
        'birth_surname',
        'birth_place',
        'citizenship_country_code',
    ];

    /**
     * Osobní údaje, které přihláška REGZEC A1 povinně nese (EDV 10053–10066).
     * Čte je i {@see PayrollRegistrationA1SnapshotBuilder}.
     *
     * @var list<string>
     */
    public const A1_IDENTITY_FIELDS = [
        'first_name', 'last_name', 'birth_surname', 'birth_date',
        'birth_place', 'birth_country_code', 'sex',
    ];

    /** Dohlášení údajů (A3 s `completion`) v rozsahu celého profilu. */
    private const COMPLETION_FULL_FIELDS = [
        'first_name', 'last_name', 'birth_date', 'sex',
        'citizenship_country_code',
    ];

    /**
     * Osobní údaje, které nese dohlášení údajů REGZEC A3.
     *
     * Delta A3 přenáší jen příjmení, jméno, tituly, dřívější příjmení, datum
     * narození, pohlaví a občanství (viz {@see PayrollRegistrationProfileCompletion}).
     * Rodné příjmení, místo a stát narození do věty nikdy nejdou, takže je
     * dohlášení nesmí vyžadovat. V rozsahu „jen údaje, které ONZ nevedla"
     * osobní údaje nejdou do věty vůbec.
     *
     * @return list<string>
     */
    public static function completionIdentityFields(?string $completion): array
    {
        return $completion === PayrollRegistrationProfileCompletion::FULL
            ? self::COMPLETION_FULL_FIELDS
            : [];
    }

    /**
     * Osobní údaje, které věta REGZEC pro danou akci čte z evidence osoby.
     *
     * A1 nese celou identitu. Akce 2–8 nesou jen `client/@ikmpsv` a to, co se
     * do nich dostane ze schválené události (u A3 s dohlášením viz
     * {@see self::completionIdentityFields()}); EDV rodné příjmení, místo
     * a stát narození u nich nepřipouští, takže je evidence osoby nesmí
     * vyžadovat.
     *
     * @return list<string>
     */
    public static function regzecIdentityFields(?int $action, ?string $completion): array
    {
        if ($action === null || $action === 1) {
            return self::A1_IDENTITY_FIELDS;
        }

        return $action === 3 ? self::completionIdentityFields($completion) : [];
    }

    /**
     * Chybějící údaje osoby pro danou agendu.
     *
     * `$agenda === null` znamená „agendu ještě nejde určit" — typicky proto,
     * že chybí státní občanství, podle kterého se mezi PREZEC a REGZEC
     * rozhoduje. Pak se hlásí jen to, co vyžadují obě agendy, aby seznam
     * neslibal víc, než kolik se opravdu bude chtít.
     *
     * `$hasBirthSurname` je samostatně, protože rodné příjmení je v evidenci
     * šifrované a volající (karta osoby) má k dispozici jen informaci, zda
     * existuje.
     *
     * `$action` (kód akce REGZEC, 2 až 8) přepne na požadavky navazující
     * události; `null` je přihláška. U A3 pak `$completion` určuje rozsah
     * dohlášení.
     *
     * `$filedOn` je den podání; u PREZEC rozhoduje o povinnosti rodného
     * příjmení ({@see self::birthSurnameRequired()}).
     *
     * @param array<string,mixed> $identity řádek historie identity
     * @param array<string,mixed> $identifiers birth_number/ecp/vcp → hodnota nebo null
     * @return list<array{field:string,label:string,message:string,panel:string,target:string}>
     */
    public static function missing(
        ?string $agenda,
        array $identity,
        array $identifiers,
        ?bool $hasBirthSurname = null,
        ?int $action = null,
        ?string $completion = null,
        ?string $filedOn = null,
    ): array {
        if ($agenda === self::AGENDA_REGZEC && $action !== null && $action !== 1) {
            // Navazující událost: identifikátor osoby je `ikmpsv` z události,
            // rodné číslo se přenáší jen v dohlášení a tam je nepovinné.
            $missing = [];
            foreach (self::regzecIdentityFields($action, $completion) as $field) {
                $present = $field === 'sex'
                    ? in_array($identity['sex'] ?? null, ['male', 'female'], true)
                    : self::filled($identity[$field] ?? null);
                if (!$present) {
                    $missing[] = self::identityProblem($field);
                }
            }

            return $missing;
        }
        $missing = [];
        foreach (self::COMMON_FIELDS as $field) {
            if ($field === 'birth_surname'
                && $agenda === self::AGENDA_PREZEC
                && !self::birthSurnameRequired($agenda, $filedOn)
            ) {
                continue;
            }
            $present = $field === 'birth_surname' && $hasBirthSurname !== null
                ? $hasBirthSurname
                : self::filled($identity[$field] ?? null);
            if (!$present) {
                $missing[] = self::identityProblem($field);
            }
        }
        if ($agenda === self::AGENDA_REGZEC
            && !in_array($identity['sex'] ?? null, ['male', 'female'], true)
        ) {
            $missing[] = self::identityProblem('sex');
        }
        // PREZEC nemá atribut pro stát narození; mimo ČR se stát píše za obec
        // do `birth/@cit` (ID 10066), takže bez státu nejde místo sestavit.
        if ($agenda === self::AGENDA_PREZEC
            && !self::filled($identity['birth_country_code'] ?? null)
        ) {
            $missing[] = self::identityProblem('birth_country_code');
        }

        $hasBno = self::filled($identifiers['birth_number'] ?? null)
            || self::filled($identifiers['ecp'] ?? null);
        if ($agenda === self::AGENDA_PREZEC && !$hasBno) {
            $missing[] = self::identifierProblem();
        }
        if ($agenda === self::AGENDA_REGZEC
            && !$hasBno
            && ($identity['citizenship_country_code'] ?? null) === 'CZ'
        ) {
            // EDV 1.4.0.6, client/@bno: u českého občanství povinné.
            $missing[] = self::identifierProblem(self::AGENDA_REGZEC);
        } elseif ($agenda === self::AGENDA_REGZEC
            && !$hasBno
            && !self::filled($identifiers['vcp'] ?? null)
        ) {
            // Bez identifikátoru musí REGZEC nést úplnou zahraniční identitu.
            foreach (['birth_date', 'birth_country_code'] as $field) {
                if (!self::filled($identity[$field] ?? null)) {
                    $missing[] = self::identityProblem($field);
                }
            }
        }

        return $missing;
    }

    /**
     * Zda věta dané agendy podaná v daný den musí nést rodné příjmení.
     * REGZEC ho u přihlášky A1 vyžaduje vždy, PREZEC jen do konce roku 2026
     * ({@see self::PREZEC_BIRTH_SURNAME_OPTIONAL_FROM}). Bez dne podání platí
     * přísnější pravidlo.
     */
    public static function birthSurnameRequired(?string $agenda, ?string $filedOn): bool
    {
        return $agenda !== self::AGENDA_PREZEC
            || $filedOn === null
            || $filedOn < self::PREZEC_BIRTH_SURNAME_OPTIONAL_FROM;
    }

    /**
     * Údaje, které sekce „Údaje pro registraci zaměstnance" na kartě osoby
     * potřebuje mít vyplněné, aby smělo svítit „Doplněno".
     *
     * Karta neví, kterou agendou se bude podávat (rozhoduje o tom nástup
     * a občanství), takže se ptá na přísnější REGZEC — co projde tam, projde
     * i PREZEC. Identifikátory se tu nehodnotí: mají vlastní sekci karty.
     *
     * @param array<string,mixed> $identity
     * @return list<string> klíče chybějících údajů
     */
    public static function missingForProfile(
        array $identity,
        bool $hasBirthSurname,
    ): array {
        $missing = self::missing(
            self::AGENDA_REGZEC,
            $identity,
            // Identifikátor se tu schválně „má" — jinak by karta chtěla
            // zahraniční identitu i po každém českém zaměstnanci.
            ['birth_number' => 'x'],
            $hasBirthSurname,
        );

        return array_values(array_map(
            static fn (array $problem): string => $problem['field'],
            $missing,
        ));
    }

    /**
     * Věta pro souhrnnou výjimku přípravy registrace: jmenuje všechny
     * chybějící údaje lidsky, bez technických názvů.
     *
     * @param list<array{field:string,label:string,message:string,panel:?string,target:string}> $problems
     */
    public static function summary(array $problems): string
    {
        $labels = array_map(
            static fn (array $problem): string => mb_strtolower(
                mb_substr($problem['label'], 0, 1),
            ) . mb_substr($problem['label'], 1),
            $problems,
        );

        return 'Registraci zatím nejde sestavit, chybí: '
            . implode(', ', array_values(array_unique($labels)))
            . '. U každého údaje je odkaz, který otevře přesně to pole.';
    }

    /**
     * Údaj zaměstnavatele (variabilní symbol, kód OSSZ) — zadává se
     * v nastavení mezd, ne na kartě osoby.
     *
     * @return array{field:string,label:string,message:string,panel:null,target:string}
     */
    public static function employerProblem(string $field, string $reason): array
    {
        $label = PayrollRegistrationFieldVocabulary::label($field);

        return [
            'field' => $field,
            'label' => $label,
            'message' => $label . ' ' . $reason . ' Doplňte v '
                . PayrollRegistrationFieldVocabulary::WHERE_EMPLOYER . '.',
            'panel' => null,
            'target' => self::TARGET_EMPLOYER_SETTINGS,
        ];
    }

    /** @return array{field:string,label:string,message:string,panel:string,target:string} */
    private static function identityProblem(string $field): array
    {
        $label = PayrollRegistrationFieldVocabulary::label($field);

        return [
            'field' => 'identity.' . $field,
            'label' => $label,
            'message' => $label . ' chybí. Doplňte na '
                . self::whereFor($field) . '.',
            'panel' => self::PANEL_IDENTITY,
            'target' => self::TARGET_PERSON,
        ];
    }

    /** @return array{field:string,label:string,message:string,panel:string,target:string} */
    private static function identifierProblem(string $agenda = self::AGENDA_PREZEC): array
    {
        return [
            'field' => 'identifier.value',
            'label' => 'Rodné číslo nebo EČP',
            'message' => 'Rodné číslo nebo EČP chybí — '
                . ($agenda === self::AGENDA_REGZEC
                    ? 'u zaměstnance s českým státním občanstvím ho přihláška REGZEC A1 vyžaduje'
                    : 'částečné přihlášení PREZEC bez něj nejde podat')
                . '. Doplňte na '
                . PayrollRegistrationFieldVocabulary::WHERE_IDENTIFIERS . '.',
            'panel' => self::PANEL_IDENTIFIERS,
            'target' => self::TARGET_PERSON,
        ];
    }

    private static function whereFor(string $field): string
    {
        return PayrollRegistrationFieldVocabulary::where($field)
            ?? PayrollRegistrationFieldVocabulary::WHERE_IDENTITY;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

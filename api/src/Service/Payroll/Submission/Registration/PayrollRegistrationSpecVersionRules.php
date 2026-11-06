<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Pravidla REGZEC, která se liší mezi ročníky 2025 (EDV 1.4.0.7) a 2027
 * (EDV 1.5.0.3). XSD je nepokryje: hlídá jen tvar, ne vazby mezi údaji ani
 * hranici verzí. Volá je validátor před porovnáním bajtů a XSD, takže na ČSSZ
 * neodejde věta, kterou by DIS zamítl kontrolou na vstupu.
 *
 * Mapování na záznamy listu Changelog EDV 1.5.0.3 (řádky 217 až 287) je
 * u jednotlivých metod.
 */
final class PayrollRegistrationSpecVersionRules
{
    /** Vazebník ČSÚ 5898 „Evropská unie bez České republiky (vývojově)". */
    public const EU_WITHOUT_CZ = [
        'AT', 'BE', 'BG', 'CY', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK',
    ];

    /** Vazebník ČSÚ 5898 „Evropský hospodářský prostor bez České republiky (vývojově)". */
    public const EEA_WITHOUT_CZ = [
        ...self::EU_WITHOUT_CZ,
        'IS', 'LI', 'NO',
    ];

    /** EDV 1.5 (JMHZ-3018), ID 10105: § 98 písm. a) zvláštní dlouhodobý pobyt, § 98 písm. k). */
    public const FREE_ACCESS_REASONS_2027 = ['22', '23'];

    /** EDV 1.5 (JMHZ-4797), ID 10234: CZ-ISCO 23596 Sociální pedagog. */
    public const PROFESSION_CODES_2027 = ['23596'];

    /** EDV 1.5.0.1 (JMHZ-3017), ID 10529: „Mimo ČR" v číselníku CISOB. */
    public const MUNICIPALITY_OUTSIDE_CZ = '111111';

    /**
     * EDV 1.5 (JMHZ-3394), ID 10525 a 10381: druhy služebního poměru 3 a 4
     * a k nim povolené důvody skončení.
     *
     * @var array<string,list<string>>
     */
    public const SERVICE_TERMINATION_BY_TYPE = [
        '2' => ['1', '2', '3', '4', '5', '6'],
        '3' => ['5', '7', '8', '9', '10', '11', '12', '13', '14'],
        '4' => ['5', '15', '16', '17', '18', '19', '20', '21', '22', '23'],
    ];

    private const FIRST_2027 = JmhzSpecVersion::FIRST_2027_EVENT_DATE;

    public static function assert(PayrollRegistrationXmlPayload $payload): void
    {
        if ($payload->interaction->documentType !== 'REGZEC25') {
            return;
        }
        self::assertBoundary($payload);
        if ($payload->specVersion === JmhzSpecVersion::V2026) {
            self::assertEdv1407($payload);
            self::assertNoValuesFrom2027($payload);

            return;
        }
        $action = $payload->interaction->actionCode;
        $data = self::eventData($payload);
        if ($action >= 3 && $action <= 7) {
            self::assertChangeNotInFuture($payload);
        }
        foreach (self::addresses($payload, $data) as $label => $address) {
            self::assertAddress($label, $address);
        }
        if ($action === 1) {
            self::assertA1($payload);
        } elseif (in_array($action, [3, 4], true)) {
            self::assertDelta($payload, $action, self::array($data['delta'] ?? null));
            if ($action === 4) {
                self::assertUnemployment(self::array($data['unemployment'] ?? null));
            }
        } elseif ($action === 2) {
            self::assertUnemployment(self::array($data['unemployment'] ?? null));
        } elseif ($action === 7) {
            // Changelog 1.5 ř. 233 a 234: 10428 je u A7 povinné a nesmí být CZ;
            // serializér ho bere ze státu cizozemského nositele.
            self::assertForeignLegislationState([
                'country_code' => self::array($data['foreign_insurance'] ?? null)['country_code'] ?? null,
            ]);
        }
    }

    /**
     * EDV 1.5.0.3 a 1.4.0.7, Slovník (DM-296), Changelog 1.5.0.3 ř. 277 až
     * 280: ročník musí odpovídat rozhodnému datu. Volba verze stojí na téže
     * metodě ({@see PayrollRegistrationSchemaCatalog::versionFor()}, u A3/A4
     * podle 10224, bez něj podle dne vyhotovení 10005); tady je pojistka
     * proti payloadu složenému mimo službu podání.
     */
    private static function assertBoundary(PayrollRegistrationXmlPayload $payload): void
    {
        $action = $payload->interaction->actionCode;
        if ($action === 8) {
            return;
        }
        $data = self::eventData($payload);
        if ($action === 1) {
            $date = $payload->identity->regzecA1?->employment['actual_start_on'] ?? $payload->actualStartOn;
            if (!is_string($date) || $date === '') {
                return;
            }
            $expected = JmhzSpecVersion::forRegistrationEventDate($date);
        } else {
            $effectiveOn = (string) ($payload->eventSnapshot['effective_on'] ?? '');
            if ($effectiveOn === '') {
                return;
            }
            $date = PayrollRegistrationSchemaCatalog::decisiveDate($action, $effectiveOn, $data);
            $expected = PayrollRegistrationSchemaCatalog::versionFor(
                $action,
                $effectiveOn,
                $data,
                $payload->preparedOn,
            );
        }
        if ($expected === $payload->specVersion) {
            return;
        }
        throw new PayrollRegistrationXmlException(
            'registration_spec_version_mismatch',
            PayrollRegistrationFieldVocabulary::action('REGZEC25', $action)
                . " s rozhodným datem {$date} vyhotovené {$payload->preparedOn}"
                . ' se podává formulářem '
                . ($expected === JmhzSpecVersion::V2027 ? 'REGZEC27' : 'REGZEC25')
                . ', ČSSZ jiný ročník odmítne (hranice 1. 1. 2027, u změny'
                . ' a opravy bez data skončení rozhoduje den vyhotovení,'
                . ' REGZEC27 od 23. 12. 2026). Připravte podání znovu.',
        );
    }

    /**
     * Logické kontroly REGZEC25 platné pro každou větu bez ohledu na datum
     * události:
     * - 10009 <= aktuální datum u A3 až A7. Pravidlo stojí v EDV REGZEC25 už
     *   od verze 1.4.0.6 (29. 5. 2026, řádek 10009; změnová karta JMHZ-3292
     *   „zákaz předčasného zaslání REGZEC“ je v produkci), nejde o novinku
     *   1.4.0.7. U A1 a A2 se 10009 neuvádí; předstih přihlášky (nejdřív
     *   8 dnů před nástupem, § 19 odst. 1 písm. a) z. 323/2025 Sb.) hlídá
     *   pravidlo u 10223 a zůstává.
     * - Kontrola 5 (novinka EDV 1.4.0.7, vydání 14. 10. 2026, list
     *   Vysvětlivky): údaje cizince v A3/A4 jako ucelená sada se státním
     *   občanstvím 10067 (stejné pravidlo jako v EDV 1.5.0.3). EDV datum
     *   účinnosti neuvádí a úplná sada projde i bez kontroly, proto se
     *   vynucuje hned.
     * - občan EU (vazebník 5898) má volný přístup „A“ s důvodem „1“.
     * Věty bez cizince se nemění.
     */
    private static function assertEdv1407(PayrollRegistrationXmlPayload $payload): void
    {
        $action = $payload->interaction->actionCode;
        if ($action >= 3 && $action <= 7) {
            self::assertChangeNotInFuture($payload);
        }
        if ($action === 1) {
            $worker = $payload->identity->regzecA1?->foreignWorker;
            if (is_array($worker)) {
                self::assertEuFreeAccess($worker, self::citizenship($payload->identity->identity));
            }

            return;
        }
        if ($action !== 3 && $action !== 4) {
            return;
        }
        $delta = self::array(self::eventData($payload)['delta'] ?? null);
        $worker = $delta['foreign_worker'] ?? null;
        if (!is_array($worker)) {
            return;
        }
        $citizenship = self::citizenship(self::array($delta['identity'] ?? null))
            ?? self::citizenship($payload->identity->identity);
        self::assertControl5($worker, $citizenship);
        self::assertEuFreeAccess($worker, $citizenship);
    }

    /**
     * Hodnoty číselníků, které přidala až verze 2027. REGZEC25 je nezná
     * a ČSSZ by je kontrolou číselníku zamítla.
     */
    private static function assertNoValuesFrom2027(PayrollRegistrationXmlPayload $payload): void
    {
        $data = self::eventData($payload);
        $a1 = $payload->identity->regzecA1;
        $employments = [
            $a1?->employment,
            self::array(self::array($data['delta'] ?? null)['employment'] ?? null),
        ];
        foreach ($employments as $employment) {
            if (!is_array($employment)) {
                continue;
            }
            $profession = $employment['profession_code'] ?? null;
            if (is_string($profession) && in_array($profession, self::PROFESSION_CODES_2027, true)) {
                self::fail(
                    'registration_regzec25_value_from_2027',
                    "Profese {$profession} (Sociální pedagog) je v číselníku ČSSZ až"
                        . ' pro události od 1. 1. 2027. Pro dřívější datum vyberte'
                        . ' jinou profesi CZ-ISCO.',
                );
            }
            if (($employment['workplace_municipality_code'] ?? null) === self::MUNICIPALITY_OUTSIDE_CZ) {
                self::fail(
                    'registration_regzec25_value_from_2027',
                    'Kód obce pracoviště 111111 (mimo ČR) přijímá ČSSZ až pro'
                        . ' události od 1. 1. 2027. Pro dřívější datum uveďte kód'
                        . ' obce v ČR.',
                );
            }
        }
        foreach ([$a1?->foreignWorker, self::array(self::array($data['delta'] ?? null)['foreign_worker'] ?? null)] as $worker) {
            $reason = is_array($worker) ? ($worker['free_access_reason_code'] ?? null) : null;
            if (is_string($reason) && in_array($reason, self::FREE_ACCESS_REASONS_2027, true)) {
                self::fail(
                    'registration_regzec25_value_from_2027',
                    "Důvod volného přístupu na trh práce {$reason} přijímá ČSSZ až"
                        . ' pro události od 1. 1. 2027. Pro dřívější datum vyberte'
                        . ' jiný důvod.',
                );
            }
        }
        $unemployment = self::array($data['unemployment'] ?? null);
        if (in_array((string) ($unemployment['employment_type'] ?? ''), ['3', '4'], true)) {
            self::fail(
                'registration_regzec25_value_from_2027',
                'Druh zaměstnání 3 a 4 (služební poměr podle zákonů 361/2003 Sb.'
                    . ' a 221/1999 Sb.) přijímá ČSSZ až pro skončení od 1. 1. 2027.',
            );
        }
    }

    /**
     * EDV REGZEC25 1.4.0.6 a 1.4.0.7, řádek 10009, a EDV 1.5.0.3, ID 10009
     * (Changelog ř. 278): „Datum platnosti od / oprava, změna ze dne (10009)
     * <= aktuální datum (změna / oprava se nesmí podávat do budoucna)".
     * Aktuálním datem je den vyhotovení věty (10005).
     */
    private static function assertChangeNotInFuture(PayrollRegistrationXmlPayload $payload): void
    {
        $effectiveOn = $payload->eventSnapshot['effective_on'] ?? null;
        if (is_string($effectiveOn) && $effectiveOn > $payload->preparedOn) {
            self::fail(
                'registration_regzec27_change_in_future',
                PayrollRegistrationFieldVocabulary::action('REGZEC25', $payload->interaction->actionCode)
                    . " platí od {$effectiveOn}, tedy později než dnes"
                    . " ({$payload->preparedOn}). ČSSZ změnu do budoucna nepřijme;"
                    . ' podání připravte nejdřív v den, kdy změna začne platit.',
            );
        }
    }

    /**
     * Changelog 1.5 ř. 217 až 219 a 227 až 230 (JMHZ-4797): kód adresního
     * místa 1 až 9 číslic (10076, 10505, 10512), číslo orientační 1 až
     * 4 znaky u všech adres (10079, 10508, 10515, 10521, 10096).
     *
     * @param array<string,mixed> $address
     */
    private static function assertAddress(string $label, array $address): void
    {
        $orientation = $address['orientation_number'] ?? null;
        if (is_string($orientation) && $orientation !== '' && mb_strlen($orientation) > 4) {
            self::fail(
                'registration_regzec27_orientation_number_too_long',
                "Číslo orientační ({$label}) „{$orientation}“ je delší než čtyři"
                    . ' znaky. Od 1. 1. 2027 přijímá ČSSZ u všech adres nejvýš'
                    . ' čtyři znaky; zkraťte je na adrese.',
            );
        }
        $ruian = $address['ruian_point'] ?? null;
        if ($ruian !== null && $ruian !== ''
            && preg_match('/^[1-9]\d{0,8}$/D', (string) $ruian) !== 1
        ) {
            self::fail(
                'registration_regzec27_ruian_point_invalid',
                "Kód adresního místa RÚIAN ({$label}) „{$ruian}“ musí mít 1 až 9"
                    . ' číslic bez úvodní nuly.',
            );
        }
    }

    private static function assertA1(PayrollRegistrationXmlPayload $payload): void
    {
        $a1 = $payload->identity->regzecA1;
        if ($a1 === null) {
            return;
        }
        $citizenship = self::citizenship($payload->identity->identity);
        if ($a1->variant === PayrollRegistrationBusinessMatrix::VARIANT_SPEC
            && $a1->foreignLegislation === null
        ) {
            // EDV 1.5.0.1 (JMHZ-2997), Changelog ř. 254: 10427 je u A1-SPEC povinné.
            self::fail(
                'registration_regzec27_foreign_legislation_missing',
                'Přihláška zaměstnance s druhem činnosti 11 až 14, M nebo'
                    . ' s výkonem trestu musí od 1. 1. 2027 nést údaj, zda se'
                    . ' na něj vztahují cizí právní předpisy. Vyplňte oddíl'
                    . ' Příslušnost k cizím právním předpisům a uložte profil.'
                    . PayrollRegistrationFieldVocabulary::reference('foreign_legislation.applies'),
            );
        }
        self::assertForeignLegislationState($a1->foreignLegislation);
        if ($a1->foreignWorker !== null) {
            self::assertForeignWorker($a1->foreignWorker, $citizenship, true);
        }
    }

    /**
     * @param array<string,mixed> $delta
     */
    private static function assertDelta(
        PayrollRegistrationXmlPayload $payload,
        int $action,
        array $delta,
    ): void {
        $identity = self::array($delta['identity'] ?? null);
        $citizenship = self::citizenship($identity)
            ?? self::citizenship($payload->identity->identity);
        if ($action === 3 && is_array($delta['foreign_legislation'] ?? null)) {
            // Changelog 1.5 ř. 235 a 236 (JMHZ-2997): u A3 jsou 10427 i 10428 zakázané.
            self::fail(
                'registration_regzec27_a3_foreign_legislation_forbidden',
                'Změnu příslušnosti k cizím právním předpisům od 1. 1. 2027'
                    . ' nejde oznámit změnou údajů (A3), ČSSZ ji zamítne. Podejte'
                    . ' oznámení o vzniku nebo skončení příslušnosti k českým'
                    . ' předpisům (A6, A7).',
            );
        }
        if ($action === 4) {
            self::assertForeignLegislationState(self::array($delta['foreign_legislation'] ?? null));
        }
        $proof = $delta['proof_identity'] ?? null;
        if (is_array($proof)) {
            // Changelog 1.5 ř. 222 až 226, Vysvětlivky Kontrola 4: doklad
            // v A3/A4 jen celý (10069 až 10072).
            $missing = array_values(array_filter(
                ['type_code', 'number', 'foreign_issuer', 'country_code'],
                static fn (string $key): bool => !is_string($proof[$key] ?? null) || $proof[$key] === '',
            ));
            if ($missing !== []) {
                self::fail(
                    'registration_regzec27_proof_identity_incomplete',
                    'Doklad totožnosti se od 1. 1. 2027 v oznámení změny nebo'
                        . ' opravy posílá jen celý: typ, číslo, vydávající orgán'
                        . ' i stát vydání (kontrola 4). Doplňte ve formuláři'
                        . ' oznámení: ' . implode(', ', array_map(
                            static fn (string $key): string => PayrollRegistrationFieldVocabulary::name('proof_identity.' . $key) ?? $key,
                            $missing,
                        )) . '.',
                );
            }
        }
        $worker = $delta['foreign_worker'] ?? null;
        if (is_array($worker)) {
            self::assertControl5($worker, $citizenship);
            self::assertForeignWorker($worker, $citizenship, false);
        }
    }

    /**
     * Changelog 1.5 ř. 233 (JMHZ-2997): kód státu cizích předpisů 10428
     * nesmí být CZ.
     *
     * @param array<string,mixed>|null $legislation
     */
    private static function assertForeignLegislationState(?array $legislation): void
    {
        if (($legislation['country_code'] ?? null) === 'CZ') {
            self::fail(
                'registration_regzec27_foreign_legislation_cz',
                'Stát cizích právních předpisů nemůže být Česko. Vyberte stát,'
                    . ' jehož předpisy se na zaměstnance vztahují.'
                    . PayrollRegistrationFieldVocabulary::reference('foreign_legislation.country_code'),
            );
        }
    }

    /**
     * EDV REGZEC25 1.4.0.7 (ID 10414 a 10105) i EDV 1.5 (JMHZ-3022): občan
     * státu EU (vazebník 5898) má volný přístup „A“ s důvodem „1“.
     *
     * @param array<string,mixed> $worker
     */
    private static function assertEuFreeAccess(array $worker, ?string $citizenship): void
    {
        if ($citizenship === null || !in_array($citizenship, self::EU_WITHOUT_CZ, true)) {
            return;
        }
        $freeAccess = $worker['free_access'] ?? null;
        $reason = self::text($worker['free_access_reason_code'] ?? null);
        if ($freeAccess === false || ($freeAccess === true && $reason !== '1')) {
            self::fail(
                'registration_regzec27_eu_free_access',
                "Občan státu EU ({$citizenship}) má volný přístup na trh práce"
                    . ' s důvodem 1 (§ 87 zákona o zaměstnanosti); ČSSZ jiné'
                    . ' vyplnění zamítne. Opravte údaje cizince.',
            );
        }
    }

    /**
     * Údaje cizince podle EDV 1.5:
     * - Changelog ř. 241 až 243 (JMHZ-3022): občan EU má volný přístup „A"
     *   s důvodem 1; důvod 3 (rodinný příslušník občana EU) jen u občana
     *   mimo EU,
     * - ř. 244, 245, 258 až 263, 269 až 272 (JMHZ-3428): platnost pobytu
     *   10568/10569 povinná u občana mimo EHP, u občana ČR zakázaná, „od"
     *   před „do", jedno bez druhého ne.
     *
     * @param array<string,mixed> $worker
     */
    private static function assertForeignWorker(array $worker, ?string $citizenship, bool $a1): void
    {
        if ($citizenship === null) {
            return;
        }
        $from = self::text($worker['stay_valid_from'] ?? null);
        $to = self::text($worker['stay_valid_to'] ?? null);
        if ($citizenship === 'CZ') {
            if ($from !== null || $to !== null) {
                self::fail(
                    'registration_regzec27_stay_validity_forbidden',
                    'Platnost pobytu se u zaměstnance s českým státním'
                        . ' občanstvím neuvádí. Odeberte ji z údajů cizince.',
                );
            }

            return;
        }
        $eu = in_array($citizenship, self::EU_WITHOUT_CZ, true);
        $reason = self::text($worker['free_access_reason_code'] ?? null);
        self::assertEuFreeAccess($worker, $citizenship);
        if ($reason === '3' && $eu) {
            self::fail(
                'registration_regzec27_family_member_citizenship',
                'Důvod volného přístupu 3 (rodinný příslušník občana EU) nejde'
                    . " použít u občana státu EU ({$citizenship}). Zvolte důvod 1.",
            );
        }
        if (($from === null) !== ($to === null)) {
            self::fail(
                'registration_regzec27_stay_validity_incomplete',
                'Platnost pobytu se uvádí celá: od i do. Doplňte chybějící datum'
                    . ' v údajích cizince.',
            );
        }
        if ($from !== null && $to !== null && $from >= $to) {
            self::fail(
                'registration_regzec27_stay_validity_order',
                "Platnost pobytu od ({$from}) musí být dřív než platnost pobytu"
                    . " do ({$to}).",
            );
        }
        $eea = in_array($citizenship, self::EEA_WITHOUT_CZ, true);
        if (!$eea && $from === null && ($a1 || array_key_exists('free_access', $worker))) {
            self::fail(
                'registration_regzec27_stay_validity_missing',
                "U občana státu mimo EHP ({$citizenship}) vyžaduje ČSSZ od"
                    . ' 1. 1. 2027 platnost pobytu (od a do, ID 10568 a 10569).'
                    . ' Doplňte ji v údajích cizince podle průkazu o pobytu.',
            );
        }
    }

    /**
     * Vysvětlivky EDV 1.5.0.3 i EDV REGZEC25 1.4.0.7, Kontrola 5 (Changelog
     * 1.5.0.3 ř. 281 až 287, JMHZ-4618): v A3/A4 se údaje cizince posílají jen jako ucelená sada
     * podle volného přístupu a druhu oprávnění.
     *
     * @param array<string,mixed> $worker
     */
    private static function assertControl5(array $worker, ?string $citizenship): void
    {
        if ($citizenship === 'CZ') {
            self::fail(
                'registration_regzec27_foreign_worker_forbidden',
                'Údaje o přístupu na trh práce se u zaměstnance s českým'
                    . ' státním občanstvím neposílají (kontrola 5). Odeberte je'
                    . ' z oznámení, nebo opravte státní občanství na kartě osoby.',
            );
        }
        $required = match (true) {
            ($worker['free_access'] ?? null) === true => ['free_access', 'free_access_reason_code'],
            ($worker['free_access'] ?? null) === false
                && (string) ($worker['permit_type_code'] ?? '') === '1' => [
                    'free_access', 'permit_type_code', 'permit_identifier',
                    'permit_from', 'permit_to', 'issuing_labour_office_code',
                ],
            ($worker['free_access'] ?? null) === false => [
                'free_access', 'permit_type_code', 'permit_identifier',
                'permit_from', 'permit_to',
            ],
            default => ['free_access'],
        };
        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => !array_key_exists($key, $worker)
                || $worker[$key] === null || $worker[$key] === '',
        ));
        $surplus = array_values(array_filter(
            ['free_access_reason_code', 'permit_type_code', 'permit_identifier',
                'permit_from', 'permit_to', 'issuing_labour_office_code'],
            static fn (string $key): bool => !in_array($key, $required, true)
                && array_key_exists($key, $worker)
                && $worker[$key] !== null && $worker[$key] !== '',
        ));
        if ($surplus !== []) {
            self::fail(
                'registration_regzec27_foreign_worker_set_incomplete',
                'Sada údajů cizince v oznámení změny nebo opravy neodpovídá'
                    . ' kontrole 5: navíc je vyplněno '
                    . implode(', ', array_map(
                        static fn (string $key): string => PayrollRegistrationFieldVocabulary::name('foreign_worker.' . $key) ?? $key,
                        $surplus,
                    ))
                    . '. Úřad práce se uvádí jen u povolení k zaměstnání (druh 1),'
                    . ' pracovní oprávnění jen bez volného přístupu.',
            );
        }
        if ($missing !== [] || $citizenship === null) {
            self::fail(
                'registration_regzec27_foreign_worker_set_incomplete',
                'Údaje cizince se v oznámení změny nebo opravy'
                    . ' posílají jen jako celá sada (kontrola 5): státní'
                    . ' občanství, volný přístup a podle něj důvod, nebo celé'
                    . ' pracovní oprávnění. Chybí: '
                    . implode(', ', array_map(
                        static fn (string $key): string => PayrollRegistrationFieldVocabulary::name('foreign_worker.' . $key) ?? $key,
                        $citizenship === null ? [...$missing, 'citizenship'] : $missing,
                    )) . '.',
            );
        }
    }

    /**
     * Changelog 1.5 ř. 247 až 251 (JMHZ-3394) a 1.5.0.1 ř. 264 až 268
     * (JMHZ-3393): druh zaměstnání 1 až 4, důvod skončení služebního poměru
     * podle druhu, náhrada podle § 271ca (10530) jen u pracovního vztahu.
     *
     * @param array<string,mixed> $unemployment
     */
    private static function assertUnemployment(array $unemployment): void
    {
        $type = (string) ($unemployment['employment_type'] ?? '');
        $reason = (string) ($unemployment['service_termination_reason'] ?? '');
        if ($reason !== '' && !in_array($reason, self::SERVICE_TERMINATION_BY_TYPE[$type] ?? [], true)) {
            self::fail(
                'registration_regzec27_service_termination_reason',
                "Důvod skončení služebního poměru {$reason} nepatří k druhu"
                    . " zaměstnání {$type}. ČSSZ od 1. 1. 2027 přijímá u druhu 2"
                    . ' důvody 1 až 6, u druhu 3 důvody 5 a 7 až 14, u druhu 4'
                    . ' důvody 5 a 15 až 23.'
                    . PayrollRegistrationFieldVocabulary::reference('unemployment'),
            );
        }
        if (isset($unemployment['replacement']) && $type !== '1') {
            self::fail(
                'registration_regzec27_replacement_not_employment',
                'Jednorázová náhrada podle § 271ca zákoníku práce patří jen'
                    . ' k pracovnímu vztahu (druh zaměstnání 1).'
                    . PayrollRegistrationFieldVocabulary::reference('unemployment'),
            );
        }
    }

    /**
     * Všechny adresy, které věta nese, s lidským názvem pro hlášku.
     *
     * @param array<string,mixed> $data
     * @return array<string,array<string,mixed>>
     */
    private static function addresses(PayrollRegistrationXmlPayload $payload, array $data): array
    {
        $a1 = $payload->identity->regzecA1;
        $delta = self::array($data['delta'] ?? null);
        $candidates = [
            'trvalý pobyt' => $payload->interaction->actionCode === 1 ? $a1?->permanentAddress : ($delta['permanent_address'] ?? null),
            'pobyt v ČR' => $payload->interaction->actionCode === 1 ? $a1?->czechResidenceAddress : ($delta['czech_residence_address'] ?? null),
            'kontaktní adresa' => $payload->interaction->actionCode === 1 ? $a1?->contactAddress : ($delta['contact_address'] ?? null),
            'bydliště ve státě rezidence' => $payload->interaction->actionCode === 1
                ? ($a1?->taxResidency['residence_address'] ?? null)
                : (self::array($delta['tax_residency'] ?? null)['residence_address'] ?? null),
            'cizozemský nositel pojištění' => $payload->interaction->actionCode === 1
                ? $a1?->foreignInsurance
                : ($data['foreign_insurance'] ?? null),
        ];

        return array_filter(
            $candidates,
            static fn (mixed $address): bool => is_array($address),
        );
    }

    /** @return array<string,mixed> */
    private static function eventData(PayrollRegistrationXmlPayload $payload): array
    {
        return self::array($payload->eventSnapshot['data'] ?? null);
    }

    /** @return array<string,mixed> */
    private static function array(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @param array<string,mixed> $identity */
    private static function citizenship(array $identity): ?string
    {
        return self::text($identity['citizenship_country_code'] ?? null);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function fail(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}

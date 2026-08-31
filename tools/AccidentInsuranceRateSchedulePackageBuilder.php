<?php

declare(strict_types=1);

/**
 * Builder připnutého sazebníku zákonného pojištění odpovědnosti zaměstnavatele
 * — příloha č. 2 vyhlášky č. 125/1993 Sb.
 *
 * Na rozdíl od {@see CzIscoClassificationPackageBuilder} tady NENÍ strojový
 * zdroj: příloha vyhlášky se nikde nepublikuje jako datová sada, existuje jen
 * jako text právního předpisu. Tabulka je proto přepsaná ručně a bydlí přímo
 * v tomhle souboru — je to zároveň to, co jde v code review přečíst řádek po
 * řádku proti Sbírce zákonů. Builder z ní udělá manifest s otisky, aby se
 * pozdější změna dat projevila jako změna hashe, kterou musí někdo obhájit.
 *
 * Text přílohy je úřední dílo (§ 3 písm. a) autorského zákona), takže se smí
 * převzít doslovně. Zapisuj ho VERBATIM včetně zjevných chyb předpisu —
 * u „24.12 Protektorování a opravy pryžových pneumatik" je pořadí i kód
 * v rozporu se zbytkem tabulky (patřilo by 25.12 hned za 25.11), ale opravovat
 * text vyhlášky nám nepřísluší.
 *
 * Spuštění: php tools/AccidentInsuranceRateSchedulePackageBuilder.php
 */

require __DIR__ . '/../api/vendor/autoload.php';

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class AccidentInsuranceRateSchedulePackageBuilder
{
    public const PACKAGE_KEY = 'cz-accident-insurance-annex-2-v1';
    public const SCHEMA_VERSION = 'accident-insurance-rate-schedule.v1';
    public const PARSER_VERSION = 1;

    private const DIRECTORY = 'annex-2-2002-01-01';

    /**
     * Hlavička manifestu — právní identita sazebníku.
     *
     * Seznam činností je v příloze č. 2 od PŮVODNÍHO znění vyhlášky
     * (č. 125/1993 Sb., účinné od 22. 4. 1993); žádná z novel 43/1995,
     * 98/1996, 74/2000 ani 365/2011 Sb. do něj nesáhla. Sazby přepsala
     * vyhláška č. 487/2001 Sb. čl. I bod 3 s účinností od 1. 1. 2002
     * (12 → 50,4; 7 → 9,8; 6 → 8,4; 5 → 7; 3 → 4,2; 2 → 2,8; 7,5 → 10,5;
     * 4 → 5,6). Poslední konsolidované znění vyhlášky je verze 6 účinná
     * od 1. 1. 2012 (novela č. 365/2011 Sb.).
     *
     * @var array<string,mixed>
     */
    private const DECREE = [
        'decree' => '125/1993 Sb.',
        'decree_title' => 'Vyhláška ministerstva financí, kterou se stanoví '
            . 'podmínky a sazby zákonného pojištění odpovědnosti zaměstnavatele '
            . 'za škodu při pracovním úrazu nebo nemoci z povolání',
        'annex' => 'Příloha č. 2',
        'annex_title' => 'Sazby pojistného podle převažující činnosti '
            . 'vykonávané zaměstnavatelem',
        'activity_list_source' => 'původní znění vyhlášky č. 125/1993 Sb.',
        'activity_list_effective_from' => '1993-04-22',
        'rates_source' => 'vyhláška č. 487/2001 Sb., čl. I bod 3',
        'rates_effective_from' => '2002-01-01',
        'consolidated_version' => 6,
        'consolidated_effective_from' => '2012-01-01',
        'consolidated_amendment' => 'vyhláška č. 365/2011 Sb.',
        'rate_selection_rule' => '§ 12 odst. 2 vyhlášky — sazba přílohy pro '
            . 'kategorii určenou podle převažující základní činnosti tvořící '
            . 'předmět podnikání zaměstnavatele',
        'minimum_quarterly_premium_czk' => 100,
        'minimum_quarterly_premium_source' => 'poslední věta přílohy č. 2 '
            . '(„Minimální pojistné za kalendářní čtvrtletí je 100 Kč.")',
        'classification' => 'OKEČ',
        'classification_note' => 'Členění ekonomických činností bylo převzato '
            . 'z Odvětvové klasifikace ekonomických činností (OKEČ) zpracované '
            . 'Českým statistickým úřadem.',
        'classification_retired_on' => '2007-12-31',
        'classification_successor' => 'CZ-NACE',
        'source_url' => 'https://www.zakonyprolidi.cz/cs/1993-125',
    ];

    /**
     * Sazbové skupiny v pořadí přílohy. `kind`:
     *   - `classified` — skupina daná výčtem kódů OKEČ,
     *   - `hazard`     — skupina daná VĚCNÝM kritériem (práce s výbušninami,
     *                    radioaktivními látkami, …), bez kódu,
     *   - `residual`   — zbytková skupina „Ostatní ekonomické činnosti".
     *
     * @var list<array{rate:string,kind:string,label:?string,activities:list<array{0:string,1:string}>}>
     */
    private const GROUPS = [
        [
            'rate' => '50.40',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['10.1', 'Dobývání černého uhlí včetně výroby černouhelných briket'],
                ['12', 'Dobývání a úprava uranových a thoriových rud'],
                ['13', 'Dobývání rud'],
            ],
        ],
        [
            'rate' => '9.80',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['15.1', 'Výroba masa a masných výrobků (vč. drůbeže)'],
                ['15.2', 'Zpracování ryb a rybích výrobků (vč. konzervování)'],
                ['15.4', 'Výroba rostlinných a živočišných olejů a tuků'],
                ['20.1', 'Výroba pilařská a impregnace dřeva'],
                ['24.11', 'Výroba technických plynů'],
                ['26.11', 'Výroba plochého skla'],
                ['26.7', 'Zpracování přírodního kamene'],
                ['27.5', 'Odlévání kovů (slévárenství)'],
                ['37.1', 'Zpracování kovového odpadu a šrotu'],
                ['45', 'Stavebnictví'],
                ['75.25', 'Protipožární ochrana a ostatní záchranné práce'],
            ],
        ],
        [
            'rate' => '8.40',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['02', 'Lesnictví, těžba dřeva a přidružené služby'],
                ['10.2', 'Dobývání hnědého uhlí včetně výroby hnědouhelných briket'],
                ['11', 'Dobývání ropy a zemního plynu a související služby'],
                ['14.1', 'Dobývání a úprava kameniva'],
                ['15.5', 'Úprava a zpracování mléka'],
                ['15.83', 'Výroba cukru'],
                ['15.9', 'Výroba nápojů'],
                ['17.14', 'Úprava a spřádání lnářských vláken'],
                ['17.25.4', 'Tkaní jutařských tkanin'],
                ['17.53', 'Výroba netkaných textilií a výrobků z nich (kromě oděvů)'],
                ['20.2', 'Výroba dýh, překližkových výrobků a aglomerovaných dřevařských výrobků'],
                ['20.3', 'Výroba stavebně truhlářská a tesařská'],
                ['20.4', 'Výroba dřevěných obalů'],
                ['21.1', 'Výroba vlákniny, papíru a lepenky'],
                ['24.3', 'Výroba nátěrových hmot, laků a podobných ochranných vrstev, tiskařských černí a tmelů'],
                ['24.64', 'Výroba chemických výrobků pro fotografické účely'],
                ['24.7', 'Výroba chemických vláken'],
                ['25.11', 'Výroba pryžových pneumatik'],
                ['24.12', 'Protektorování a opravy pryžových pneumatik'],
                ['26.13', 'Výroba dutého skla'],
                ['26.26', 'Výroba žáruvzdorných keramických výrobků'],
                ['26.3', 'Výroba keramických obkládaček a dlaždic'],
                ['26.5', 'Výroba cementu, vápna a sádry'],
                ['26.6', 'Výroba výrobků z betonu, cementu a sádry'],
                ['26.81', 'Výroba brusiv'],
                ['27.1-27.4', 'Výroba kovů (kromě slévárenství)'],
                ['28', 'Výroba kovových konstrukcí a kovodělných výrobků kromě výroby strojů a nářadí'],
                ['29', 'Výroba strojů a přístrojů'],
                ['31.3', 'Výroba kabelů a vodičů'],
                ['35.1', 'Stavba lodí a člunů (vč. oprav)'],
                ['36.1', 'Výroba nábytku'],
                ['37.2', 'Zpracování nekovového starého materiálu a zbytkového materiálu'],
                ['60.2', 'Pozemní doprava (mimo potrubní a železniční) vč. MHD'],
                ['85.2', 'Veterinární činnosti'],
                ['90', 'Odstraňování odpadu a odvod odpadních vod'],
            ],
        ],
        [
            'rate' => '7.00',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['01', 'Zemědělství'],
            ],
        ],
        [
            'rate' => '4.20',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['16', 'Zpracování tabáku'],
                ['17.23', 'Tkaní česaných vlnařských tkanin'],
                ['17.24', 'Tkaní hedvábnických tkanin'],
                ['17.25.3', 'Tkaní lnářských tkanin'],
                ['17.25.5', 'Tkaní vigoňových tkanin'],
                ['17.4', 'Výroba konfekčního textilního zboží (kromě oděvů) - koberce, ložní prádlo aj.'],
                ['17.52.1', 'Výroba provaznická'],
                ['17.54.1', 'Výroba stuh a prýmků'],
                ['17.54.2', 'Výroba tylů, krajek, záclon a výšivek'],
                ['17.6', 'Výroba pletených materiálů'],
                ['17.7', 'Výroba pleteného zboží'],
                ['18', 'Oděvní průmysl, zpracování a barvení kožešin'],
                ['19', 'Výroba usní a úprava kůží; výroba brašnářského a sedlářského zboží a obuvi'],
                ['26.21', 'Výroba keramických a porcelánových výrobků pro domácnost a ozdobných předmětů'],
                ['26.22', 'Výroba keramických výrobků pro sanitární účely'],
                ['30.02', 'Výroba počítačů aj. přístrojů a zařízení na zpracování dat'],
                ['32', 'Výroba rádiových, televizních a spojovacích zařízení a přístrojů'],
                ['33', 'Výroba zdravotnických, přesných a optických přístrojů a hodin'],
                ['35.3', 'Výroba letadel a kosmických lodí'],
                ['36.2', 'Výroba zlatnických a šperkařských předmětů'],
                ['41', 'Výroba a rozvod vody'],
                ['55', 'Pohostinství a ubytování'],
                ['60.3', 'Potrubní doprava'],
                ['61.11', 'Námořní doprava'],
                ['62', 'Letecká doprava'],
                ['63.3', 'Cestovní kanceláře, průvodcovská činnost'],
                ['64.2', 'Telekomunikace'],
                ['70', 'Činnosti v oblasti nemovitostí (nákup, prodej, pronájem, správa, realitní agentury)'],
                ['73.1', 'Výzkum a vývoj v oblasti přírodních a technických věd'],
                ['74.1', 'Právní, daňové a podnikatelské poradenství; Účetnictví a jeho revize; Výzkum trhu a veřejného mínění; Správa cenných papírů'],
                ['74.2', 'Architektonické a inženýrské poradenství a podobné technické služby'],
                ['75 (kromě 75.25)', 'Veřejná správa; Obrana; Povinné sociální pojištění (kromě protipožární ochrany a ostatních záchranářských prací)'],
                ['80', 'Školství'],
                ['85.1', 'Zdravotnictví'],
                ['85.3', 'Sociální činnosti'],
                ['91', 'Činnosti organizací společenských'],
                ['92.2', 'Provoz rozhlasu a televize'],
            ],
        ],
        [
            'rate' => '2.80',
            'kind' => 'classified',
            'label' => null,
            'activities' => [
                ['22.1', 'Vydavatelské činnosti'],
                ['65', 'Peněžnictví'],
                ['66', 'Pojišťovnictví kromě povinného sociálního zabezpečení'],
                ['67', 'Činnosti související s úvěry a pojišťovnictvím'],
                ['72', 'Zpracování dat a související činnosti (poradenská činnost, opravy, databanky aj.)'],
                ['73.2', 'Výzkum a vývoj v oblasti humanitních, společenských věd a nauk o literatuře'],
                ['74.4', 'Reklamní činnosti'],
                ['74.81', 'Fotografické služby'],
                ['92.1', 'Výroba, půjčování a distribuce filmů a videa'],
                ['92.5 (kromě 92.53)', 'Činnosti knihoven, veřejných archivů muzeí a jiných kulturních zařízení (kromě činnosti botanických a zoologických zahrad a přírodních rezervací)'],
                ['93.02', 'Kadeřnické a jiné služby pro ošetření těla (manikura, pedikura, kosmetické úkony)'],
            ],
        ],
        [
            'rate' => '10.50',
            'kind' => 'hazard',
            'label' => 'Činnosti nezařazené do jiných sazbových skupin '
                . '(s výjimkou skupiny „Ostatní ekonomické činnosti"), ve kterých '
                . 'se zejména pracuje s výbušninami, radioaktivními látkami, '
                . 'radonem, infekčním materiálem, jedy, činnosti ve velkých '
                . 'výškách nebo hloubkách',
            'activities' => [],
        ],
        [
            'rate' => '5.60',
            'kind' => 'residual',
            'label' => 'Ostatní ekonomické činnosti',
            'activities' => [],
        ],
    ];

    public function build(string $resourceRoot): string
    {
        $groups = [];
        $activityCount = 0;
        $byGroup = [];
        $seenKeys = [];
        $seenCodes = [];
        foreach (self::GROUPS as $ordinal => $group) {
            $key = self::groupKey($group['rate']);
            if (isset($seenKeys[$key])) {
                throw new RuntimeException("Sazbová skupina {$key} je v sazebníku dvakrát.");
            }
            $seenKeys[$key] = true;
            if (!in_array($group['kind'], ['classified', 'hazard', 'residual'], true)) {
                throw new RuntimeException("Skupina {$key} má neznámý druh.");
            }
            if ($group['kind'] === 'classified' && $group['activities'] === []) {
                throw new RuntimeException("Skupina {$key} nemá žádnou činnost.");
            }
            if ($group['kind'] !== 'classified' && $group['activities'] !== []) {
                throw new RuntimeException("Skupina {$key} nesmí mít výčet kódů.");
            }
            if ($group['kind'] !== 'classified' && ($group['label'] ?? '') === '') {
                throw new RuntimeException("Skupina {$key} musí mít popis kritéria.");
            }

            $activities = [];
            foreach ($group['activities'] as $index => [$code, $label]) {
                if (trim($code) !== $code || $code === ''
                    || trim($label) !== $label || $label === ''
                ) {
                    throw new RuntimeException("Činnost {$code} má neplatný zápis.");
                }
                if (isset($seenCodes[$code])) {
                    throw new RuntimeException("Kód OKEČ {$code} je v sazebníku dvakrát.");
                }
                $seenCodes[$code] = true;
                $activities[] = [
                    'ordinal' => $index + 1,
                    'okec_code' => $code,
                    'label' => $label,
                ];
            }
            $activityCount += count($activities);
            $byGroup[$key] = count($activities);
            $groups[] = [
                'ordinal' => $ordinal + 1,
                'key' => $key,
                'rate_per_mille' => $group['rate'],
                'kind' => $group['kind'],
                'label' => $group['label'],
                'activities' => $activities,
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_key' => self::PACKAGE_KEY,
            'parser_version' => self::PARSER_VERSION,
            'legal' => self::DECREE,
            'groups' => $groups,
            'counts' => [
                'groups' => count($groups),
                'activities' => $activityCount,
                'activities_by_group' => $byGroup,
            ],
            'content_hash' => hash('sha256', CanonicalJson::encode(['groups' => $groups])),
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];

        $directory = $resourceRoot . '/' . self::DIRECTORY;
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException("Adresář {$directory} nelze vytvořit.");
        }
        $path = $directory . '/manifest.json';
        file_put_contents(
            $path,
            json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
        file_put_contents(
            $resourceRoot . '/SHA256SUMS',
            sprintf(
                "%s  %s/manifest.json\n",
                hash_file('sha256', $path),
                self::DIRECTORY,
            ),
        );

        return $manifest['manifest_sha256'];
    }

    /** `50.40` → `rate-50-40`; klíč je stabilní a nezávislý na pořadí v příloze. */
    public static function groupKey(string $rate): string
    {
        return 'rate-' . str_replace('.', '-', $rate);
    }
}

$builder = new AccidentInsuranceRateSchedulePackageBuilder();
$hash = $builder->build(__DIR__ . '/../api/resources/payroll/accident-insurance-rates');
echo "manifest_sha256 = {$hash}\n";

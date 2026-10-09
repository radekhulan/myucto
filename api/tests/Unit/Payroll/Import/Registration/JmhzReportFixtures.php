<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;

/**
 * Syntetická měsíční hlášení JMHZ „cizího" mzdového programu.
 *
 * XML nevzniká ručně psanou šablonou, ale SKUTEČNÝM serializérem aplikace
 * nad syntetickým normalizovaným dokumentem — kruhový test tak ověřuje, že
 * import je zrcadlem toho, co aplikace do hlášení píše. Všechny hodnoty jsou
 * vymyšlené; OIČ a rodná čísla procházejí kontrolou modulo 11.
 */
final class JmhzReportFixtures
{
    public const VENDOR = 'Syntetické mzdy';

    /**
     * Jedna osoba s jedním vztahem. Výchozí čísla drží daňovou identitu
     * 10298 − 10305 − 10304 = uplatněné slevy (6 000 − 2 163 − 1 267 = 2 570).
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    public static function person(array $o = []): array
    {
        $o += [
            'employment_id' => 101,
            'oic' => RegistrationXmlFixtures::oic(7),
            'id_ppv' => '200000000000000000101',
            'primary' => true,
            'wage' => 40_000,
            'taxable' => 40_000,
            'base' => 40_000,
            'computed' => 6_000,
            'after_credits' => 2_163,
            'bonus' => 0,
            'declaration' => true,
            'basic_credit' => 2_570,
            'ztp_p_credit' => null,
            'children' => [[
                'identity' => ['given_name' => 'Eliška', 'family_name' => 'Testovací', 'birth_date' => '2018-05-20'],
                'ztp_p' => false,
                'order' => '1',
            ]],
            'other_caregivers' => [],
            'child_monthly' => 1_267,
            'child_applied' => 1_267,
            'social_base' => 40_000,
            'social_discount' => null,
            'work_place' => 'Brno',
            'municipality' => '582786',
            'apz' => 'no',
            'functional' => 'no',
            'worked_millihours' => 168_000,
            'worked_days' => 21,
            'average_minor' => 23_810,
            'irregular' => 0,
            'withholding' => null,
            'insurance_from' => null,
            'insurance_to' => null,
            'standard_fund' => 168_000,
            'agreed_fund' => 168_000,
            'unworked' => [],
            'deductions_recorded' => false,
            // Příspěvky zaměstnavatele z osvobozených příjmů: ID atributu (10292 až 10296, 10418, 10417) => Kč.
            'contributions' => [],
            // Zařazení scénáře součásti: formulář vězně, jiného příjmu nebo
            // pronájmu síly místo `bezPriznaku`.
            'selector' => ['scenario_key' => 'scenario_1', 'activity_code' => '1', 'relationship_detail_code' => '1'],
        ];
        $childCredit = null;
        if ($o['declaration'] && $o['children'] !== []) {
            $childCredit = [
                'monthly_credit_czk' => $o['child_monthly'],
                'applied_credit_czk' => $o['child_applied'],
                'other_household_caregiver' => $o['other_caregivers'] !== [],
                'other_household_caregivers' => $o['other_caregivers'],
                'children' => $o['children'],
            ];
        }

        return [
            'insurance_from' => $o['insurance_from'],
            'insurance_to' => $o['insurance_to'],
            'summary' => [
                'income_total_czk' => $o['wage'],
                'exempt_income_czk' => null,
                'employer_contributions_czk' => $o['contributions'],
                'net_income_czk' => (int) round($o['wage'] * 0.78),
                'deductions_recorded' => $o['deductions_recorded'],
                'employee_health_czk' => (int) ceil($o['wage'] * 0.045),
                'employer_health_czk' => (int) ceil($o['wage'] * 0.09),
                'employee_social_czk' => (int) ceil($o['social_base'] * 0.071),
                'employer_social_czk' => (int) ceil($o['social_base'] * 0.248),
                'taxpayer_declaration_signed' => $o['declaration'],
                'advance_tax_czk' => [
                    'base' => $o['base'],
                    'computed' => $o['computed'],
                    'after_credits' => $o['after_credits'],
                    'bonus' => $o['bonus'],
                    'taxable_income' => $o['taxable'],
                ],
                'withholding_tax_czk' => $o['withholding'],
                'tax_credits_czk' => [
                    'basic' => $o['declaration'] ? $o['basic_credit'] : null,
                    'disability_basic' => null,
                    'disability_extended' => null,
                    'ztp_p' => $o['declaration'] ? $o['ztp_p_credit'] : null,
                ],
                'child_credit' => $childCredit,
                'annual' => [],
            ],
            'employment' => [
                'employment_id' => $o['employment_id'],
                'primary' => $o['primary'],
                'identity' => [
                    'person_external_identifier' => $o['oic'],
                    'employment_external_identifier' => $o['id_ppv'],
                ],
                'selector' => $o['selector'],
                'term' => [
                    'work_place' => $o['work_place'],
                    'jmhz_workplace_municipality_code' => $o['municipality'],
                    'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => $o['apz'],
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => $o['functional'],
                    'jmhz_temporary_assignment_status' => 'no',
                ],
                'jmhz_default_interpretations' => null,
                'work_month' => ['jmhz_work_summary' => ['values' => $o['unworked'] + [
                    'standard_fund_millihours' => $o['standard_fund'],
                    'agreed_fund_millihours' => $o['agreed_fund'],
                    'weekly_work_centihours' => 4_000,
                    'evidence_days' => 30,
                    'worked_millihours' => $o['worked_millihours'],
                    'worked_days' => $o['worked_days'],
                    'unworked_total_millihours' => null,
                    'employee_obstacle_paid_millihours' => null,
                    'employer_obstacle_millihours' => null,
                ]]],
                'social_base' => [
                    'assessment_base_czk' => $o['social_base'],
                    'reported_income_czk' => $o['social_base'],
                    'paragraph5_letter' => 'a',
                ],
                'reports_social_contributions' => $o['primary'],
                'employee_social_discount' => $o['social_discount'] === null ? null : ['amount_czk' => $o['social_discount']],
                'part_time_discount' => null,
                'taxable_income_czk' => $o['taxable'],
                'earnings_by_attribute_czk' => [
                    '10328' => $o['wage'],
                    '10329' => $o['wage'] - $o['irregular'],
                    '10330' => 0,
                    '10331' => $o['irregular'],
                ],
                'average_hourly' => ['minor_units' => $o['average_minor'], 'scale' => 2],
            ],
        ];
    }

    /**
     * Hlášení za měsíc. Osoby se stejným `oic` a víc vztahy se sloučí do
     * jedné osoby (souběh); souhrnná data nese vztah s `primary = true`.
     *
     * @param list<array<string,mixed>> $people výstupy {@see person()}
     * @param array<string,mixed> $o
     */
    public static function report(array $people, int $year, int $month, array $o = []): string
    {
        $o += [
            'guid_seed' => 1,
            'filled_at' => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
                ->modify('+1 month +9 days')->format('Y-m-d') . 'T08:00:00Z',
            'type' => 'R',
        ];
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        $byPerson = [];
        $formGuids = [];
        $after = 0;
        $bonus = 0;
        foreach ($people as $person) {
            $employment = $person['employment'];
            $insuranceFrom = $person['insurance_from'] ?? $monthStart;
            $insuranceTo = $person['insurance_to'] ?? $monthEnd;
            $employment['eldp'] = [
                'insurance_interval' => ['insurance_from' => $insuranceFrom, 'insurance_to' => $insuranceTo],
                'eldp_sections' => [[
                    'ordinal' => 1,
                    'code' => '1++',
                    'valid_from' => $insuranceFrom,
                    'valid_to' => $insuranceTo,
                    'insurance_days' => (int) substr($insuranceTo, 8, 2) - (int) substr($insuranceFrom, 8, 2) + 1,
                    'assessment_base_czk' => $employment['social_base']['assessment_base_czk'],
                    'excluded_days' => null,
                ]],
            ];
            $key = (string) $employment['identity']['person_external_identifier'];
            if (!isset($byPerson[$key])) {
                $byPerson[$key] = ['summary' => $person['summary'], 'employments' => []];
            }
            if ($employment['primary'] === true) {
                $byPerson[$key]['summary'] = $person['summary'];
                $after += $person['summary']['advance_tax_czk']['after_credits'];
                $bonus += $person['summary']['advance_tax_czk']['bonus'];
            }
            $byPerson[$key]['employments'][] = $employment;
            $formGuids[(int) $employment['employment_id']] = self::guid((int) $o['guid_seed'], (int) $employment['employment_id']);
        }

        $payload = [
            'schema_reference' => JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => ['scenario_key' => 'scenario_1', 'submission_kind' => 'regular'],
            'header' => ['type' => 'R', 'variable_symbol' => '1234567890', 'month' => $month, 'year' => $year],
            'employer' => [
                'summary_totals' => ['advance_tax_after_credits' => $after, 'tax_bonus' => $bonus],
                'pvpoj' => ['values' => [
                    'pojistne' => [
                        'zakladZamestnavateleA' => 40_000,
                        'pojistneZamestnavateleA' => 9_920,
                        'pojistneZamestnavateleCelkem' => 9_920,
                        'pojistneZamestnance' => 2_840,
                        'pojistneCelkem' => 12_760,
                    ],
                    'pojistneUhrada' => 12_760,
                ]],
            ],
            'people' => array_values($byPerson),
        ];
        $xml = (new JmhzScenario1XmlSerializer())->serialize(
            new JmhzScenario1NormalizedDocument($payload),
            JmhzSubmissionEnvelope::create(
                self::guid((int) $o['guid_seed'], 0),
                $formGuids,
                (string) $o['filled_at'],
                self::VENDOR,
                '1.0',
            ),
        );
        if ($o['type'] === 'O') {
            $xml = str_replace(
                ['<typPodani>R</typPodani>', '<typFormulare>R</typFormulare>'],
                ['<typPodani>O</typPodani>', '<typFormulare>O</typFormulare>'],
                $xml,
            );
        }

        return $xml;
    }

    /**
     * Formulář vztahu s daným ID PPV přepíše na dohodu bez účasti na pojištění
     * tak, jak ji hlásí cizí programy: ELDP bez kódu s nula dny, nulový
     * vyměřovací základ, příjem z nepojištěné činnosti (10476), „missingová"
     * týdenní doba 99 (10261) a bez druhu činnosti v identifikaci.
     */
    public static function uninsuredAgreement(string $xml, string $idPpv, int $income): string
    {
        return self::editForm($xml, $idPpv, static function (\DOMXPath $xpath, \DOMElement $body): void {
            $f = JmhzSchemaCatalog::NS_FORM;
            foreach ($xpath->query('f:identifikace/f:druhCinnosti', $body) ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            $base = $xpath->query('f:pojisteni/f:vymerovaciZaklad', $body)?->item(0);
            if ($base instanceof \DOMElement) {
                while ($base->firstChild !== null) {
                    $base->removeChild($base->firstChild);
                }
                $base->appendChild($base->ownerDocument->createElementNS($f, 'form:castkaOdvodPojistneho', '0'));
                $base->appendChild($base->ownerDocument->createElementNS($f, 'form:prijemNepojistenaCinnost', '__INCOME__'));
            }
            $list = $xpath->query('f:pojisteni/f:eldpSeznam', $body)?->item(0);
            if ($list instanceof \DOMElement) {
                while ($list->firstChild !== null) {
                    $list->removeChild($list->firstChild);
                }
                $eldp = $list->appendChild($list->ownerDocument->createElementNS($f, 'form:eldp'));
                $eldp->appendChild($list->ownerDocument->createElementNS($f, 'form:pocetDnu', '0'));
            }
            foreach ($xpath->query('f:vykonavanaPozice/f:fondPracovniDoby/f:stanovenaTydenniDoba', $body) ?: [] as $node) {
                $node->textContent = '99';
            }
        }, ['__INCOME__' => (string) $income]);
    }

    /**
     * Formulář pracujícího důchodce: ELDP se za něj nehlásí (MPSV) — seznam má
     * jen povinnou sekci bez kódu s nula dny —, vyměřovací základ a pojistné ano.
     */
    public static function withoutEldp(string $xml, string $idPpv): string
    {
        return self::editForm($xml, $idPpv, static function (\DOMXPath $xpath, \DOMElement $body): void {
            $list = $xpath->query('f:pojisteni/f:eldpSeznam', $body)?->item(0);
            if ($list instanceof \DOMElement) {
                while ($list->firstChild !== null) {
                    $list->removeChild($list->firstChild);
                }
                $eldp = $list->appendChild($list->ownerDocument->createElementNS(JmhzSchemaCatalog::NS_FORM, 'form:eldp'));
                $eldp->appendChild($list->ownerDocument->createElementNS(JmhzSchemaCatalog::NS_FORM, 'form:pocetDnu', '0'));
            }
        });
    }

    /**
     * ELDP s kódem, nulou dnů a vyloučenými dny jen v podpoložkách (bez úhrnu
     * 10357) — tak hlásí cizí programy měsíc celý v dávkách.
     */
    public static function eldpOnBenefits(string $xml, string $idPpv, int $benefitDays): string
    {
        return self::editForm($xml, $idPpv, static function (\DOMXPath $xpath, \DOMElement $body): void {
            $f = JmhzSchemaCatalog::NS_FORM;
            foreach ($xpath->query('f:pojisteni/f:eldpSeznam/f:eldp', $body) ?: [] as $eldp) {
                if (!$eldp instanceof \DOMElement) {
                    continue;
                }
                foreach ($xpath->query('f:pocetDnu', $eldp) ?: [] as $node) {
                    $node->textContent = '0';
                }
                foreach ($xpath->query('f:vymerovaciZaklad|f:vylouceneDny|f:odecitaneDny', $eldp) ?: [] as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $excluded = $eldp->appendChild($eldp->ownerDocument->createElementNS($f, 'form:vylouceneDny'));
                foreach (['vyloucenePar18' => '__DAYS__', 'omluvenaNepritomnost' => '0', 'pracovniNeschopnost' => '0', 'vyplaceniDavek' => '__DAYS__'] as $name => $value) {
                    $excluded->appendChild($eldp->ownerDocument->createElementNS($f, 'form:' . $name, $value));
                }
            }
            foreach ($xpath->query('f:pojisteni/f:vymerovaciZaklad/f:castkaOdvodPojistneho', $body) ?: [] as $node) {
                $node->textContent = '0';
            }
        }, ['__DAYS__' => (string) $benefitDays]);
    }

    /**
     * Vyloučené dny první sekce ELDP formuláře přepíše na zadané prvky (v pořadí
     * XSD) a volitelně vyměřovací základ 10477 a příjem 10476.
     *
     * @param array<string,int> $excluded prvek `vylouceneDny` => dny
     */
    public static function withExcludedDays(
        string $xml,
        string $idPpv,
        array $excluded,
        ?int $socialBase = null,
        ?int $uninsuredIncome = null,
    ): string {
        return self::editForm($xml, $idPpv, static function (\DOMXPath $xpath, \DOMElement $body) use ($excluded, $socialBase, $uninsuredIncome): void {
            $f = JmhzSchemaCatalog::NS_FORM;
            $eldp = $xpath->query('f:pojisteni/f:eldpSeznam/f:eldp', $body)?->item(0);
            if (!$eldp instanceof \DOMElement) {
                throw new \LogicException('Formulář nemá sekci ELDP.');
            }
            foreach ($xpath->query('f:vylouceneDny|f:odecitaneDny', $eldp) ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            $block = $eldp->ownerDocument->createElementNS($f, 'form:vylouceneDny');
            foreach ([
                'vylouceneDobyCelkem', 'docasNeschopnost', 'penezitaPomocMaterstvi',
                'osetrovaniClenaRodiny', 'otcovska', 'vyloucenePar16', 'vyloucenePar18',
                'omluvenaNepritomnost', 'pracovniNeschopnost', 'vyplaceniDavek',
            ] as $name) {
                if (array_key_exists($name, $excluded)) {
                    $block->appendChild($eldp->ownerDocument->createElementNS($f, 'form:' . $name, (string) $excluded[$name]));
                }
            }
            $eldp->appendChild($block);
            if ($socialBase !== null) {
                foreach ($xpath->query('f:pojisteni/f:vymerovaciZaklad/f:castkaOdvodPojistneho', $body) ?: [] as $node) {
                    $node->textContent = (string) $socialBase;
                }
            }
            if ($uninsuredIncome !== null) {
                $base = $xpath->query('f:pojisteni/f:vymerovaciZaklad', $body)?->item(0);
                if ($base instanceof \DOMElement) {
                    foreach ($xpath->query('f:prijemNepojistenaCinnost', $base) ?: [] as $node) {
                        $node->parentNode?->removeChild($node);
                    }
                    $base->appendChild($base->ownerDocument->createElementNS($f, 'form:prijemNepojistenaCinnost', (string) $uninsuredIncome));
                }
            }
        });
    }

    /**
     * @param callable(\DOMXPath,\DOMElement):void $edit
     * @param array<string,string> $replace
     */
    private static function editForm(string $xml, string $idPpv, callable $edit, array $replace = []): string
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);
        $identification = $xpath->query("//f:identifikace[f:idPpv='{$idPpv}']")?->item(0);
        $body = $identification?->parentNode;
        if (!$body instanceof \DOMElement) {
            throw new \LogicException("Formulář s ID PPV {$idPpv} v hlášení není.");
        }
        $edit($xpath, $body);

        return strtr((string) $document->saveXML(), $replace);
    }

    /** Syntetický UUIDv7 (jen tvar); `index` 0 je GUID podání, jinak vztah. */
    public static function guid(int $seed, int $index): string
    {
        return sprintf(
            '%08X-%04X-7%03X-8%03X-%012X',
            0x0195E2C4 + $seed,
            $seed & 0xFFFF,
            $index & 0xFFF,
            $seed & 0xFFF,
            $seed * 100_000 + $index,
        );
    }

    /** Opravné podání se stornem jedné součásti (formulář typu S bez těla). */
    public static function componentCancellation(int $year, int $month, int $guidSeed, int $employmentId, string $filledAt): string
    {
        $submission = self::guid($guidSeed, 0);
        $form = self::guid($guidSeed, $employmentId);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3">
              <VENDOR productName="Syntetické mzdy" productVersion="1.0"/>
              <hlavicka>
                <idPodani>{$submission}</idPodani>
                <typPodani>O</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>{$year}</rok>
                <datumVyplneni>{$filledAt}</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>1</formularePocetVBaliku>
                <formularePocetCelkem>1</formularePocetCelkem>
              </hlavicka>
              <formulareOsob>
                <formularOsoby>
                  <hlavicka>
                    <idFormulare>{$form}</idFormulare>
                    <typFormulare>S</typFormulare>
                  </hlavicka>
                </formularOsoby>
              </formulareOsob>
            </jmhz>
            XML;
    }

    /** Stornující podání celého hlášení (bez formulářů). */
    public static function submissionCancellation(int $year, int $month, int $guidSeed, string $filledAt): string
    {
        $submission = self::guid($guidSeed, 0);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3">
              <hlavicka>
                <idPodani>{$submission}</idPodani>
                <typPodani>S</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>{$year}</rok>
                <datumVyplneni>{$filledAt}</datumVyplneni>
              </hlavicka>
            </jmhz>
            XML;
    }
}

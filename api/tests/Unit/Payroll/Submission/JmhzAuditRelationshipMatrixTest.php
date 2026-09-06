<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT MZDOVÉHO MODULU (private/MZDY-AUDIT.md) — matice pracovních vztahů
 * proti JMHZ a třída chyby „nulová/prázdná hodnota serializovaná do XML".
 *
 * Fixtura je převzatá z JmhzScenario1XmlSerializerTest (syntetické hodnoty).
 * Testy pojmenované `...AuditFinding...` popisují ŽÁDOUCÍ chování a na
 * současném kódu PADAJÍ — to je záměr, jsou důkazem nálezu.
 */
final class JmhzAuditRelationshipMatrixTest extends TestCase
{
    /**
     * NÁLEZ (třída chyby 40244): měsíc s nulovou zúčtovanou mzdou (např.
     * celý měsíc nemoc po 14. dni, rodičovská, neplacené volno) serializuje
     * rozpad 10329–10331 s nulami. Kontrola 267 („nevyplnění dat pro rozklad
     * při nulovém atributu Mzda zúčtovaná") bere podle doloženého výkladu
     * ČSSZ (protokol k 08/2026, kontrola 244) za „vyplněný" už přítomnost
     * elementu. Očekávané chování: při 10328 = 0 se `form:mzdaRozpad`
     * neuvádí vůbec.
     */
    public function testAuditFindingZeroWageMonthMustNotEmitWageBreakdown(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->zeroWagePayload()),
            $this->envelope(),
        );

        self::assertStringContainsString('<form:mzdaZuctovana>0</form:mzdaZuctovana>', $result['xml']);
        self::assertStringNotContainsString('<form:tarif>0</form:tarif>', $result['xml']);
        self::assertStringNotContainsString('<form:odmenyPravidelne>0</form:odmenyPravidelne>', $result['xml']);
    }

    /**
     * Brána musí tuhle třídu chyby chytit. Serializér rozpad při nulové mzdě
     * už neuvádí, takže se evaluátor testuje proti ručně vrácenému bloku —
     * jinak by test jen opakoval předchozí případ a o kontrole 267 by
     * nedokazoval nic.
     */
    public function testControlGateRejectsZeroWageBreakdown(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->zeroWagePayload()),
            $this->envelope(),
        );
        $withBreakdown = str_replace(
            '<form:mzdaZuctovana>0</form:mzdaZuctovana>',
            '<form:mzdaZuctovana>0</form:mzdaZuctovana>'
                . '<form:mzdaRozpad><form:tarif>0</form:tarif>'
                . '<form:odmenyPravidelne>0</form:odmenyPravidelne>'
                . '<form:odmenyNepravidelne>0</form:odmenyNepravidelne></form:mzdaRozpad>',
            $result['xml'],
        );
        self::assertNotSame($result['xml'], $withBreakdown, 'Fixtura se musí změnit.');

        $report = JmhzScenario1ControlValidator::create(
            CzechPayrollRulesets2026::provider(),
        )->validate($withBreakdown, new JmhzControlContext('2026-08-05', schemaValidated: true));

        $verdict267 = array_values(array_filter(
            $report->findings,
            static fn (JmhzControlFinding $finding): bool => $finding->controlId === 267,
        ));
        self::assertNotSame([], $verdict267, 'Kontrola 267 se musí na součást vyhodnotit.');
        self::assertSame(JmhzControlOutcome::Failed, $verdict267[0]->outcome);
    }

    /**
     * Regrese opravy f244a5d43 i pro statutární větev `form:cinnostKS`
     * (jednatel bez prohlášení): `form:danBonus` se neuvádí.
     */
    public function testStatutoryBranchOmitsDanBonusWithoutDeclaration(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->statutoryPayload(declarationSigned: false)),
            $this->envelope(),
        );

        self::assertStringContainsString('<form:cinnostKS', $result['xml']);
        self::assertStringNotContainsString('<form:danBonus>', $result['xml']);
        self::assertStringContainsString('<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>', $result['xml']);
        // Statutární větev nemá mzdu, průběh zaměstnání ani pojistné zaměstnavatele na ZP.
        self::assertStringNotContainsString('<form:mzda>', $result['xml']);
        self::assertStringNotContainsString('<form:zdravPojZamestnavatel>', $result['xml']);
    }

    public function testStatutoryBranchKeepsZeroDanBonusWithDeclaration(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->statutoryPayload(declarationSigned: true)),
            $this->envelope(),
        );

        self::assertStringContainsString('<form:danBonus>0</form:danBonus>', $result['xml']);
    }

    /**
     * DPP bez prohlášení pod 12 000 Kč (2026) se daní srážkou § 6 odst. 4.
     * Resolver ji nepodporuje a blokuje CELOU přípravu, i když XSD má pro
     * srážkovou daň blok `zvlastniSazbaDane` (10307/10309).
     */
    public function testWithholdingTaxWithoutBaseStillBlocks(): void
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['withholding_tax_minor_units'] = 150_000;
        // Základ chybí — sražená daň bez základu je formulář, který sám sobě
        // neodpovídá, takže blokace zůstává.
        $tax['withholding_groups'] = [[
            'group' => 'dpp',
            'base_minor_units' => 1_000_000,
            'tax_minor_units' => 150_000,
        ]];
        $tax['advance_tax']['taxable_income_minor_units'] = 0;
        $tax['advance_tax']['rounded_tax_base_minor_units'] = 0;
        $tax['advance_tax']['tax_before_credits_minor_units'] = 0;
        $tax['advance_tax']['tax_after_credits_minor_units'] = 0;
        unset($tax);

        self::assertContains(
            'jmhz_scenario1_withholding_base_missing',
            $this->blockerCodes($this->resolutionFor($payload)),
        );
    }

    /**
     * Zaměstnanec s podepsaným prohlášením a zálohou nižší než sleva na
     * poplatníka (hrubá mzda 12 000 Kč: záloha 1 800 < sleva 2 570). Výpočet
     * uplatní jen 1 800, resolver vidí claimed != applied a blokuje.
     */
    /**
     * Jediná nárokovaná sleva se při částečném uplatnění vykazuje uplatněnou
     * částkou — 10299 leží v XSD uvnitř bloku „Výpočet zálohy na daň", takže
     * nese to, co do výpočtu skutečně vstoupilo. Hlášení se blokovat nesmí:
     * hrubá mzda pod ~17 200 Kč s prohlášením je zcela běžný stav.
     */
    public function testPartiallyAppliedSingleTaxCreditIsReportedAsApplied(): void
    {
        $resolution = $this->resolutionFor($this->partialCreditPayload());

        self::assertNotContains(
            'jmhz_scenario1_partial_tax_credit_unsupported',
            $this->blockerCodes($resolution),
        );

        $xml = (new JmhzScenario1XmlValidator())->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:zakladniSleva>1800</form:zakladniSleva>', $xml);
    }

    /**
     * Víc druhů slev při částečném uplatnění zůstává blokované: zákon
     * neurčuje, která z nich se zkrátila, a rozdělit ji odhadem by znamenalo
     * vykázat nedoložený údaj.
     */
    public function testPartiallyAppliedMultipleTaxCreditsStayBlocked(): void
    {
        $payload = $this->partialCreditPayload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 300_000;
        $tax['claimed_non_refundable_credit_breakdown'] = [
            'taxpayer' => 257_000,
            'disability_basic' => 43_000,
        ];
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 300_000;
        unset($tax);

        self::assertContains(
            'jmhz_scenario1_partial_tax_credit_unsupported',
            $this->blockerCodes($this->resolutionFor($payload)),
        );
    }

    /** @return array<string,mixed> */
    private function partialCreditPayload(): array
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
        $tax['applied_non_refundable_credits_minor_units'] = 180_000;
        $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
        $tax['advance_tax']['taxable_income_minor_units'] = 1_200_000;
        $tax['advance_tax']['rounded_tax_base_minor_units'] = 1_200_000;
        $tax['advance_tax']['tax_before_credits_minor_units'] = 180_000;
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
        $tax['advance_tax']['tax_after_credits_minor_units'] = 0;
        unset($tax);

        return $payload;
    }

    public function testChildCreditBlocksTheWholeReport(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['advance_tax']['child_credit_minor_units'] = 200_000;
        unset($tax);

        self::assertContains(
            'jmhz_scenario1_child_credit_breakdown_unavailable',
            $this->blockerCodes($this->resolutionFor($payload)),
        );
    }

    /**
     * Exekuce, insolvence ani dohoda o srážkách nesmí blokovat hlášení. JMHZ
     * o srážkách nechce částky, jen boolean 10116, a čistá mzda 10344 se
     * vykazuje PŘED srážkami — srážka je věc mezi zaměstnancem a věřitelem,
     * ne údaj pro ČSSZ.
     */
    public function testWageDeductionDoesNotBlockAndIsReportedAsBoolean(): void
    {
        $payload = $this->payload();
        $net = &$payload['people'][0]['person_summary']['statutory']['net_pay'];
        $net['deducted_minor_units'] = 100_000;
        $net['net_payable_minor_units'] = 63_400;
        $net['deductions'] = [['kind' => 'enforcement', 'amount_minor_units' => 100_000]];
        unset($net);
        $payload['ordinary_evidence'][0]['attribute_values']['10116'] = true;

        $resolution = $this->resolutionFor($payload);
        self::assertNotContains(
            'jmhz_scenario1_deductions_unsupported',
            $this->blockerCodes($resolution),
        );

        $xml = (new JmhzScenario1XmlValidator())->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString(
            '<form:srazkyZeMzdyEvidovany>true</form:srazkyZeMzdyEvidovany>',
            $xml,
        );
        // Čistá mzda je PŘED srážkami (734 Kč), ne k výplatě po srážce (634 Kč).
        self::assertStringContainsString('<form:mzdaCista>734</form:mzdaCista>', $xml);
        self::assertStringNotContainsString('<form:mzdaCista>634</form:mzdaCista>', $xml);
    }

    /**
     * Podlimitní DPP bez prohlášení (srážková daň § 6 odst. 4): hlášení se
     * nesmí blokovat a XML musí nést blok `zvlastniSazbaDane` se základem
     * a sraženou daní. Záloha na daň se u čistě srážkové osoby neuvádí —
     * nulový `zalohaNaDan` je tatáž třída chyby jako 40244.
     */
    public function testWithholdingTaxIsReportedInsteadOfBlocking(): void
    {
        $payload = $this->subLimitDppPayload(declarationSigned: false);
        $resolution = $this->resolutionFor($payload);

        self::assertNotContains(
            'jmhz_scenario1_withholding_tax_unsupported',
            $this->blockerCodes($resolution),
        );

        $xml = (new JmhzScenario1XmlValidator())->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:zvlastniSazbaDane>', $xml);
        self::assertStringContainsString('<form:zakladDane>8000</form:zakladDane>', $xml);
        self::assertStringContainsString('<form:srazenaDan>1200</form:srazenaDan>', $xml);
        self::assertStringNotContainsString('<form:zalohaNaDan>', $xml);
    }

    /**
     * Táž oprava ve statutární větvi: jednatel s odměnou pod rozhodnou částkou
     * a bez prohlášení plátce se daní srážkou. Pořadí prvků v
     * `souhrnDataZecCinnostKSType` je stejné jako u `bezPriznaku`, takže musí
     * projít i XSD.
     */
    public function testWithholdingTaxIsReportedInStatutoryBranch(): void
    {
        $payload = $this->statutoryPayload(declarationSigned: false);
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['withholding_tax_minor_units'] = 45_000;
        $tax['withholding_base_minor_units'] = 300_000;
        $tax['withholding_groups'] = [[
            'group' => 'statutory_body', 'base_minor_units' => 300_000, 'tax_minor_units' => 45_000,
        ]];
        foreach ([
            'taxable_income_minor_units', 'rounded_tax_base_minor_units',
            'tax_before_credits_minor_units', 'tax_after_credits_minor_units',
        ] as $field) {
            $tax['advance_tax'][$field] = 0;
        }
        unset($tax);

        $xml = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        )['xml'];

        self::assertStringContainsString('<form:cinnostKS', $xml);
        self::assertStringContainsString('<form:zakladDane>3000</form:zakladDane>', $xml);
        self::assertStringContainsString('<form:srazenaDan>450</form:srazenaDan>', $xml);
        self::assertStringNotContainsString('<form:zalohaNaDan>', $xml);
    }

    /**
     * Podlimitní DPP (8 000 Kč, neúčastná na SP) s podepsaným prohlášením:
     * záloha 1 200 je nižší než sleva 2 570, takže se sleva uplatní jen zčásti.
     * Po opravě N-03 to hlášení blokovat nesmí a 10299 nese uplatněnou částku.
     */
    public function testSubLimitDppWithDeclarationPassesWithPartialCredit(): void
    {
        $withDeclaration = $this->subLimitDppPayload(declarationSigned: true, claimTaxpayerCredit: true);

        self::assertNotContains(
            'jmhz_scenario1_partial_tax_credit_unsupported',
            $this->blockerCodes($this->resolutionFor($withDeclaration)),
        );
    }

    /**
     * Umělá varianta podlimitní DPP, která resolverem projde: XML musí projít
     * XSD a nesmí nést pojistné SP ani vyměřovací základ, jen
     * `prijemNepojistenaCinnost` a bezkódovou ELDP sekci s nulou dnů.
     */
    public function testSubLimitDppSerializesWithoutSocialInsuranceAndValidatesAgainstXsd(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->subLimitDppPayload(declarationSigned: true, claimTaxpayerCredit: false)),
            $this->envelope(),
        );
        $xml = preg_replace('/>\s+</', '><', $result['xml']) ?? '';

        self::assertStringContainsString('<form:druhCinnosti>T</form:druhCinnosti>', $xml);
        self::assertStringNotContainsString('<form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString('<form:prijemNepojistenaCinnost>8000</form:prijemNepojistenaCinnost>', $xml);
        self::assertStringNotContainsString('<form:vymerovaciZakladParagraf5>', $xml);
        self::assertStringNotContainsString('<form:pojisteniZamestnanec>', $xml);
        self::assertStringNotContainsString('<form:pojisteniZamestnavatel>', $xml);
        self::assertStringContainsString('<form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp>', $xml);
        self::assertStringContainsString('<form:zdravPojZamestnanec><form:zdravotniPojisteni>0</form:zdravotniPojisteni>', $xml);
        // Bez účasti na ZP (DPP < 12 000) je zdravotní pojistné nula — a je VYPLNĚNÉ, protože XSD element vyžaduje.
        self::assertStringContainsString('<form:dnyEvidencniStav>0</form:dnyEvidencniStav>', $xml);
    }

    /** @return list<string> */
    private function blockerCodes(JmhzScenario1Resolution $resolution): array
    {
        return array_map(
            static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
            $resolution->blockers,
        );
    }

    /** @return array<string,mixed> */
    private function zeroWagePayload(): array
    {
        $payload = $this->payload();
        $person = &$payload['people'][0];
        $person['person_summary']['totals']['jmhz_amount_minor'] = 0;
        $statutory = &$person['person_summary']['statutory'];
        $statutory['health_insurance']['employee_contribution_minor_units'] = 0;
        $statutory['health_insurance']['employer_contribution_minor_units'] = 0;
        $statutory['social_insurance']['capped_assessment_base_minor_units'] = 0;
        $statutory['social_insurance']['employee_contribution_minor_units'] = 0;
        $statutory['social_insurance']['employer_contribution_minor_units'] = 0;
        foreach ([
            'taxable_income_minor_units', 'rounded_tax_base_minor_units',
            'tax_before_credits_minor_units', 'tax_after_credits_minor_units',
        ] as $field) {
            $statutory['income_tax']['advance_tax'][$field] = 0;
        }
        $statutory['net_pay']['net_before_deductions_minor_units'] = 0;
        $statutory['net_pay']['net_payable_minor_units'] = 0;
        unset($statutory);
        $employment = &$person['employments'][0];
        $employment['earnings_by_attribute_minor'] = [
            '10328' => 0, '10329' => 0, '10330' => 0, '10331' => 0,
        ];
        $employment['insurance']['participation']['participation_income_minor_units'] = 0;
        $employment['insurance']['assessment_base_minor_units'] = 0;
        $employment['insurance']['capped_assessment_base_minor_units'] = 0;
        $employment['eldp']['eldp_sections'][0]['assessment_base_czk'] = 0;
        $employment['work_month']['jmhz_work_summary']['values']['worked_millihours'] = 0;
        unset($employment, $person);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function statutoryPayload(bool $declarationSigned): array
    {
        $payload = $this->payload();
        $payload['scope']['scenario_set'] = ['scenario_3'];
        $employment = &$payload['people'][0]['employments'][0];
        $employment['employment']['relation_type'] = 'statutory_body';
        $employment['term']['activity_code'] = 'S';
        $employment['term']['jmhz_relationship_detail_code'] = '1';
        $employment['term']['tax_declaration_signed'] = $declarationSigned;
        $employment['scenario_resolution'] = [
            'scenario_key' => 'scenario_3',
            'activity_code' => 'S',
            'relationship_detail_code' => '1',
        ];
        $employment['eldp']['eldp_sections'][0]['code'] = 'S++';
        $employment['insurance']['kind'] = 'corporate_body';
        unset($employment);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function subLimitDppPayload(bool $declarationSigned, bool $claimTaxpayerCredit = false): array
    {
        $payload = $this->payload();
        $person = &$payload['people'][0];
        $person['person_summary']['totals']['jmhz_amount_minor'] = 800_000;
        $statutory = &$person['person_summary']['statutory'];
        $statutory['health_insurance']['employee_contribution_minor_units'] = 0;
        $statutory['health_insurance']['employer_contribution_minor_units'] = 0;
        $statutory['social_insurance']['capped_assessment_base_minor_units'] = 0;
        $statutory['social_insurance']['employee_contribution_minor_units'] = 0;
        $statutory['social_insurance']['employer_contribution_minor_units'] = 0;
        $tax = &$statutory['income_tax'];
        if ($declarationSigned) {
            $tax['advance_tax']['taxable_income_minor_units'] = 800_000;
            $tax['advance_tax']['rounded_tax_base_minor_units'] = 800_000;
            $tax['advance_tax']['tax_before_credits_minor_units'] = 120_000;
            if ($claimTaxpayerCredit) {
                $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
                $tax['applied_non_refundable_credits_minor_units'] = 120_000;
                $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
                $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
                $tax['advance_tax']['tax_after_credits_minor_units'] = 0;
            } else {
                $tax['advance_tax']['tax_after_credits_minor_units'] = 120_000;
            }
        } else {
            $tax['withholding_tax_minor_units'] = 120_000;
            $tax['withholding_base_minor_units'] = 800_000;
            $tax['withholding_groups'] = [[
                'group' => 'dpp', 'base_minor_units' => 800_000, 'tax_minor_units' => 120_000,
            ]];
            foreach ([
                'taxable_income_minor_units', 'rounded_tax_base_minor_units',
                'tax_before_credits_minor_units', 'tax_after_credits_minor_units',
            ] as $field) {
                $tax['advance_tax'][$field] = 0;
            }
        }
        unset($tax);
        $statutory['net_pay']['net_before_deductions_minor_units'] = 680_000;
        $statutory['net_pay']['net_payable_minor_units'] = 680_000;
        unset($statutory);
        $employment = &$person['employments'][0];
        // Nová dohoda ještě nemá od ČSSZ OIČ ani ID PPV: hlásí se jmennou větví.
        $employment['identity']['person_external_identifier'] = ['value' => null];
        $employment['identity']['jmhz_employment_external_identifier'] = ['value' => null];
        $employment['employment']['relation_type'] = 'dpp';
        $employment['term']['activity_code'] = 'T';
        $employment['term']['jmhz_relationship_detail_code'] = null;
        $employment['term']['tax_declaration_signed'] = $declarationSigned;
        $employment['scenario_resolution'] = [
            'scenario_key' => 'scenario_1',
            'activity_code' => 'T',
            'relationship_detail_code' => null,
        ];
        $employment['eldp']['eldp_sections'] = [[
            'ordinal' => 1,
            'code' => null,
            'valid_from' => null,
            'valid_to' => null,
            'insurance_days' => 0,
            'assessment_base_czk' => null,
            'excluded_days' => null,
            'deducted_days' => null,
        ]];
        $employment['work_month']['jmhz_work_summary']['values']['evidence_days'] = 0;
        $employment['work_month']['jmhz_work_summary']['values']['worked_millihours'] = 40_000;
        $employment['earnings_by_attribute_minor'] = [
            '10328' => 800_000, '10329' => 800_000, '10330' => 0, '10331' => 0,
        ];
        $employment['insurance'] = [
            'relationship_id' => 'employment:101',
            'kind' => 'dpp',
            'participation' => [
                'relationship_id' => 'employment:101',
                'status' => 'does_not_participate',
                'participation_income_minor_units' => 800_000,
            ],
            'assessment_base_minor_units' => 800_000,
            'capped_assessment_base_minor_units' => 0,
            'employer_rate_category' => 'ordinary',
        ];
        unset($employment, $person);

        return $payload;
    }

    private function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    /** @param array<string,mixed> $payload */
    private function resolutionFor(array $payload): JmhzScenario1Resolution
    {
        $preparation = new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_1',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            [
                'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
                'status' => 'source_ready',
                'issue_count' => 0,
                'issues' => [],
                'official_submission_supported' => false,
            ],
            $payload,
        );

        return (new JmhzScenario1DocumentResolver())->resolve($preparation, $this->pvpoj($payload));
    }

    /** @param array<string,mixed> $payload */
    private function pvpoj(array $payload): JmhzPvpojPreview
    {
        $social = $payload['people'][0]['person_summary']['statutory']['social_insurance'];
        $employee = (int) $social['employee_contribution_minor_units'];
        $employer = (int) $social['employer_contribution_minor_units'];
        $base = intdiv((int) $social['capped_assessment_base_minor_units'], 100);
        $values = [
            'pojistne' => array_filter([
                'zakladZamestnavateleA' => $base > 0 ? $base : null,
                'pojistneZamestnavateleA' => $base > 0 ? intdiv($employer, 100) : null,
                'pojistneZamestnavateleCelkem' => intdiv($employer, 100),
                'pojistneZamestnance' => intdiv($employee, 100),
                'pojistneCelkem' => intdiv($employee + $employer, 100),
            ], static fn (?int $value): bool => $value !== null),
            'pojistneUhrada' => intdiv($employee + $employer, 100),
        ];

        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            [
                'office_id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'variable_symbol' => '1234567890',
            ],
            [[
                'office_id' => 4,
                'employee_contribution_minor_units' => $employee,
                'employer_contribution_minor_units' => $employer,
                'amount_minor_units' => $employee + $employer,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            $values,
            [['employee_id' => 11]],
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema_reference' => 'payroll-jmhz-preparation-source.v5',
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_set' => ['scenario_1'],
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'ordinary_evidence' => [[
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ]],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [['relationship_id' => 'employment:101']],
                            'net_before_deductions_minor_units' => 73_400,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 73_400,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => [
                            'value' => '2000000000000000000001',
                        ],
                        'identity' => [
                            'first_name' => 'Jana',
                            'last_name' => 'Nováková',
                            'birth_date' => '1990-04-12',
                        ],
                    ],
                    'employment' => [
                        'is_primary' => true,
                        'relation_type' => 'employment',
                        'start_date' => '2026-03-01',
                        'actual_start_date' => null,
                    ],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                        'work_place' => 'Brno',
                        'jmhz_workplace_municipality_code' => '582786',
                        'jmhz_workplace_country_code' => 'CZ',
                        'jmhz_apz_contribution_status' => 'no',
                        'jmhz_functional_benefits_status' => 'no',
                        'jmhz_temporary_assignment_status' => 'no',
                    ],
                    'scenario_resolution' => [
                        'scenario_key' => 'scenario_1',
                        'activity_code' => '1',
                        'relationship_detail_code' => '1',
                    ],
                    'eldp' => [
                        'confirmation' => ['in03_active' => false, 'in04_active' => false],
                        'insurance_interval' => [
                            'insurance_from' => '2026-07-01',
                            'insurance_to' => '2026-07-31',
                        ],
                        'eldp_sections' => [[
                            'ordinal' => 1,
                            'code' => '1++',
                            'valid_from' => '2026-07-01',
                            'valid_to' => '2026-07-31',
                            'insurance_days' => 31,
                            'assessment_base_czk' => 1_000,
                            'excluded_days' => null,
                            'deducted_days' => null,
                        ]],
                    ],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'derivation_version' => 'jmhz-work-month.v2',
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'standard_fund_millihours' => 184_000,
                                'agreed_fund_millihours' => 184_000,
                                'weekly_work_centihours' => 4_000,
                                'evidence_days' => 31,
                                'worked_millihours' => 184_000,
                                'unworked_total_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => [
                        'relationship_id' => 'employment:101',
                        'kind' => 'employment',
                        'participation' => [
                            'relationship_id' => 'employment:101',
                            'status' => 'participates',
                            'participation_income_minor_units' => 100_000,
                        ],
                        'assessment_base_minor_units' => 100_000,
                        'capped_assessment_base_minor_units' => 100_000,
                        'employer_rate_category' => 'ordinary',
                    ],
                ]],
            ]],
            'source_versions' => [
                'office_id' => 9,
                'employments' => [],
                'ordinary_evidence' => [[
                    'employment_id' => 101,
                    'id' => 601,
                    'source_manifest_sha256' => str_repeat('4', 64),
                    'snapshot_fingerprint' => str_repeat('5', 64),
                ]],
            ],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Mzdové složky HPP za duben 2026 nad výchozím číselníkem: odměny, provize,
 * služební vůz a cestovní náhrady až po test měsíčního hlášení (XSD, katalog
 * kontrol). Základní mzda 40 000 Kč, ruční přepočty podle sady 2026 (pravidla
 * v {@see PayrollE2eHpp2ComponentFlowTrait}).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2ComponentTest extends TestCase
{
    use PayrollE2eHpp2ComponentFlowTrait;

    protected function setUp(): void
    {
        $this->bootComponentFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Pravidelná výkonnostní odměna 4 000 Kč, jednorázová odměna 5 000 Kč
     * a provize 3 000 Kč.
     *
     * Ruční přepočet: hrubá 40 000 + 4 000 + 5 000 + 3 000 = 52 000;
     * SP 52 000 × 7,1 % = 3 692; ZP 52 000 × 13,5 % = 7 020, zaměstnanec
     * 2 340, zaměstnavatel 4 680; záloha 52 000 × 15 % = 7 800 − 2 570
     * = 5 230; čistá 52 000 − 3 692 − 2 340 − 5 230 = 40 738.
     *
     * Hlášení: 10286 = 10328 = 52 000; 10329 tarif 40 000; 10330 pravidelné
     * 4 000 + provize 3 000 (zařazení provize volí účetní, zde pohyblivá
     * složka mzdy); 10331 nepravidelné 5 000.
     */
    public function testRegularAndOneOffBonusWithCommissionReachTheWageBreakdown(): void
    {
        $person = $this->hire('Syntetická Odměněná', 'female', '1988-03-14');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $regular = $this->createBonusComponent('ODMENA_VYKONNOSTNI', 'regular');
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->inputById($person, $regular, 400_000, 'vykon');
        $this->input($person, 'ODMENA', 500_000, 'odmena');
        $this->input($person, 'PROVIZE', 300_000, 'provize');

        $run = $this->approvedRun('bonus');
        $this->assertPayroll($run['person'], [
            'taxable' => 5_200_000,
            'social_base' => 5_200_000,
            'health_base' => 5_200_000,
            'social' => 369_200,
            'health_employee' => 234_000,
            'health_employer' => 468_000,
            'advance' => 523_000,
            'cash_income' => 5_200_000,
            'non_cash_income' => 0,
            'net' => 4_073_800,
        ]);

        // Provize nemá výchozí zařazení: příprava hlášení se zastaví a řekne proč.
        $blocked = $this->prepareJmhz($run['revision_id'], 'bonus-unmapped');
        self::assertSame('blocked', $blocked['body']['readiness_status']);
        self::assertSame(
            ['component_jmhz_mapping_missing'],
            array_values(array_unique(array_column($blocked['body']['issues'], 'code'))),
            CanonicalJson::encode($blocked['body']['issues']),
        );
        self::assertSame(1, $blocked['body']['issue_count'], 'Bez zařazení je jen provize.');
        $this->mappings()->put($this->supplierId, $this->componentId('PROVIZE'), '10330', null, $this->actors[0]);

        $xml = $this->dryRunXml($run['revision_id'], 'bonus');
        foreach ([
            '<form:zuctovanoCelkem>52000</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>52000</form:mzdaZuctovana>',
            '<form:tarif>40000</form:tarif>',
            '<form:odmenyPravidelne>7000</form:odmenyPravidelne>',
            '<form:odmenyNepravidelne>5000</form:odmenyNepravidelne>',
            '<form:zakladDane>52000</form:zakladDane>',
            '<form:vypoctenaZaloha>7800</form:vypoctenaZaloha>',
            '<form:mzdaCista>40738</form:mzdaCista>',
            '<form:osvobozenoCelkem>0</form:osvobozenoCelkem>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Služební vůz i k soukromým účelům, vstupní cena 800 000 Kč včetně DPH:
     * příjmem je 1 % vstupní ceny = 8 000 Kč měsíčně (§ 6 odst. 6 ZDP).
     * Nepeněžní příjem se zdaní a vstoupí do vyměřovacích základů, ale
     * nevyplácí se.
     *
     * Ruční přepočet: základ 40 000 + 8 000 = 48 000; SP 3 408; ZP 6 480,
     * zaměstnanec 2 160, zaměstnavatel 4 320; záloha 7 200 − 2 570 = 4 630;
     * výplata 40 000 − 3 408 − 2 160 − 4 630 = 29 802.
     *
     * Hlášení: 10286 zúčtovaný příjem 48 000; 10344 čistý příjem (zdanitelný
     * příjem po pojistném a dani) 48 000 − 10 198 = 37 802.
     *
     * Zařazení do rozpadu mzdy aplikace sama neurčí: pokyny k 10328 užití
     * vozidla mezi vyloučenými plněními nejmenují a 10328 zahrnuje i naturální
     * mzdu. Příprava hlášení se proto zastaví jedním nálezem u složky a účetní
     * ji zařadí; tady přímo na úhrn 10328 (naturální mzda), takže 10328
     * = 48 000 a tarif 10329 zůstává 40 000.
     */
    public function testCompanyCarOnePercentIsTaxedButNotPaidOut(): void
    {
        $person = $this->hire('Syntetický Řidič', 'male', '1981-11-02');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->input($person, 'SOUKROME_VOZIDLO', 800_000, 'vuz');

        $run = $this->approvedRun('car');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_800_000,
            'social_base' => 4_800_000,
            'health_base' => 4_800_000,
            'social' => 340_800,
            'health_employee' => 216_000,
            'health_employer' => 432_000,
            'advance' => 463_000,
            'cash_income' => 4_000_000,
            'non_cash_income' => 800_000,
            'net' => 2_980_200,
        ]);

        // Vůz nemá výchozí zařazení: příprava se zastaví a řekne u které složky.
        $blocked = $this->prepareJmhz($run['revision_id'], 'car-unmapped');
        self::assertSame('blocked', $blocked['body']['readiness_status']);
        self::assertSame(
            [['code' => 'component_jmhz_mapping_missing', 'entity_type' => 'component', 'count' => 1]],
            array_map(
                static fn (array $issue): array => ['code' => $issue['code'], 'entity_type' => $issue['entity_type'], 'count' => $issue['count']],
                $blocked['body']['issues'],
            ),
            CanonicalJson::encode($blocked['body']['issues']),
        );
        self::assertSame(1, $blocked['body']['issue_count'], 'Bez zařazení je jen služební vůz.');
        $this->mappings()->put($this->supplierId, $this->componentId('SOUKROME_VOZIDLO'), '10328', null, $this->actors[0]);

        $xml = $this->dryRunXml($run['revision_id'], 'car');
        foreach ([
            '<form:zuctovanoCelkem>48000</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>48000</form:mzdaZuctovana>',
            '<form:tarif>40000</form:tarif>',
            '<form:zakladDane>48000</form:zakladDane>',
            '<form:vypoctenaZaloha>7200</form:vypoctenaZaloha>',
            '<form:mzdaCista>37802</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Tuzemská pracovní cesta 14. 4. 2026 od 8:00 do 16:00 (8 hodin) vlastním
     * osobním vozem, 200 km, spotřeba 5,0 l / 100 km, benzin 95; zaměstnavatel
     * vyplácí stravné 250 Kč.
     *
     * Do limitu pro zaměstnance odměňované platem není náhrada příjmem (§ 6
     * odst. 7 písm. a) ZDP): stravné nejvýš 185 Kč (§ 176 odst. 1 ZP, vyhl.
     * č. 573/2025 Sb. § 3 písm. a)), základní náhrada 200 × 5,90 = 1 180 Kč
     * (§ 1 písm. b)), pohonné hmoty 10 l × 34,70 = 347 Kč (§ 4 písm. a) ve
     * znění do 30. 9. 2026). Nezdaněno 1 712 Kč, nadlimitní stravné
     * 250 − 185 = 65 Kč je zdanitelný příjem a vyměřovací základ.
     *
     * Ruční přepočet: základ 40 065; SP 40 065 × 7,1 % = 2 844,62 → 2 845;
     * ZP 40 065 × 13,5 % = 5 408,78 → 5 409, zaměstnanec 1 803, zaměstnavatel
     * 3 606; záloha ze 40 100 × 15 % = 6 015 − 2 570 = 3 445; výplata
     * 40 000 + 1 777 − 2 845 − 1 803 − 3 445 = 33 684.
     *
     * Hlášení: 10286 = 40 065 (náhrada do limitu příjmem není), 10328
     * = 40 000 (cestovní náhrady se do mzdy za práci nezahrnují), 10344
     * = 40 065 − 8 093 = 31 972.
     */
    public function testBusinessTripWithinLimitIsNotIncomeAndExcessIsTaxed(): void
    {
        $person = $this->hire('Syntetická Cestující', 'female', '1990-01-30');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');

        $trip = $this->approvedTrip($person, [
            'departure_at' => '2026-04-14T08:00:00+02:00',
            'arrival_at' => '2026-04-14T16:00:00+02:00',
            'transport_mode' => 'private_vehicle',
            'meal_rate_band_1' => '250',
            'items' => [[
                'item_kind' => 'private_vehicle',
                'spent_on' => '2026-04-14',
                'description' => 'Jízda vlastním vozem',
                'vehicle_kind' => 'car',
                'fuel_kind' => 'petrol_95',
                'distance_km' => '200',
                'consumption_per_100km' => '5',
            ]],
        ]);
        self::assertSame(
            [177_700, 171_200, 6_500],
            [$trip['entitlement_total_minor'], $trip['exempt_total_minor'], $trip['taxable_total_minor']],
        );
        self::assertSame(
            ['CESTOVNI_NAHRADA_LIMIT' => 171_200, 'CESTOVNI_NAHRADA_NADLIMIT' => 6_500],
            $this->materializeTrip((int) $trip['id'], $person['employment_id']),
        );

        $run = $this->approvedRun('trip');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_006_500,
            'social_base' => 4_006_500,
            'health_base' => 4_006_500,
            'social' => 284_500,
            'health_employee' => 180_300,
            'health_employer' => 360_600,
            'advance' => 344_500,
            'cash_income' => 4_177_700,
            'non_cash_income' => 0,
            'net' => 3_368_400,
        ]);

        $xml = $this->dryRunXml($run['revision_id'], 'trip');
        foreach ([
            '<form:zuctovanoCelkem>40065</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>40000</form:mzdaZuctovana>',
            '<form:zakladDane>40065</form:zakladDane>',
            '<form:vypoctenaZaloha>6015</form:vypoctenaZaloha>',
            '<form:mzdaCista>31972</form:mzdaCista>',
            '<form:osvobozenoCelkem>0</form:osvobozenoCelkem>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    /**
     * Mimořádná odměna 1 234,56 Kč: měsíční hlášení přijímá výdělek jen
     * v celých korunách a mzda se zaokrouhluje nahoru (§ 142 odst. 2 ZP).
     * Kontrola běhu haléřový vstup pojmenuje, hromadná oprava ho zvedne na
     * 1 235 Kč a měsíc pak projde až po test hlášení.
     *
     * Ruční přepočet po zaokrouhlení: hrubá 41 235; SP 41 235 × 7,1 %
     * = 2 927,69 → 2 928; ZP 41 235 × 13,5 % = 5 566,73 → 5 567, zaměstnanec
     * 1 855,67 → 1 856, zaměstnavatel 3 711; záloha ze 41 300 × 15 % = 6 195
     * − 2 570 = 3 625; čistá 41 235 − 2 928 − 1 856 − 3 625 = 32 826.
     */
    public function testBonusWithHalereIsNamedByTheRunCheckAndRoundedUp(): void
    {
        $person = $this->hire('Syntetický Haléřový', 'male', '1979-07-23');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->input($person, 'ODMENA', 123_456, 'odmena');

        $snapshots = $this->container->get(PayrollRunSnapshotBuilder::class);
        self::assertInstanceOf(PayrollRunSnapshotBuilder::class, $snapshots);
        $findings = array_values(array_filter(
            $snapshots->build($this->supplierId, self::PERIOD_START, self::PAYDAY)->validations,
            static fn (PayrollRunValidation $validation): bool => $validation->code === 'inputs_not_whole_crowns',
        ));
        self::assertCount(1, $findings);
        self::assertSame('blocker', $findings[0]->severity);
        self::assertStringContainsString('Odměna 1 234,56 Kč', $findings[0]->message);
        self::assertSame(
            "/payroll/components?tab=inputs&period=2026-04&employment={$person['employment_id']}",
            $findings[0]->remediationPath,
        );

        $rounded = $this->inputs()->roundWholeCrowns(
            $this->approverRequest('POST', '/api/payroll/inputs/round-whole-crowns')
                ->withParsedBody(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $rounded->getStatusCode(), (string) $rounded->getBody());
        $result = $this->json($rounded);
        self::assertSame(
            [[123_456, 123_500]],
            array_map(static fn (array $row): array => [$row['from_minor'], $row['to_minor']], $result['rounded']),
        );
        self::assertSame([], $result['skipped']);

        $run = $this->approvedRun('halere');
        $this->assertPayroll($run['person'], [
            'taxable' => 4_123_500,
            'social_base' => 4_123_500,
            'health_base' => 4_123_500,
            'social' => 292_800,
            'health_employee' => 185_600,
            'health_employer' => 371_100,
            'advance' => 362_500,
            'cash_income' => 4_123_500,
            'non_cash_income' => 0,
            'net' => 3_282_600,
        ]);
        $xml = $this->dryRunXml($run['revision_id'], 'halere');
        foreach ([
            '<form:zuctovanoCelkem>41235</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>41235</form:mzdaZuctovana>',
            '<form:odmenyNepravidelne>1235</form:odmenyNepravidelne>',
            '<form:zakladDane>41235</form:zakladDane>',
            '<form:vypoctenaZaloha>6195</form:vypoctenaZaloha>',
            '<form:mzdaCista>32826</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }
}

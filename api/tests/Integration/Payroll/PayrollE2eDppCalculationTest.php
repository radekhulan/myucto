<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura s brigádníky na DPP a DPČ: výpočet ověřený ručním přepočtem podle
 * zákona a sady roku, měsíční hlášení JMHZ a průchod bez slepé uličky
 * v měsících 01 až 04/2026 (přes hranici JMHZ_FROM) a 12/2026 až 01/2027.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppCalculationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DPP', 'Syntetická agentura', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('ODMENA_DOHODA', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        \assert($mappings instanceof PayrollComponentJmhzMappingRepository);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Agentura za duben 2026 (první měsíc povinného JMHZ). Očekávané částky
     * jsou přepočítané ručně podle ZDP, z. č. 589/1992 Sb., z. č. 592/1992 Sb.
     * a sady 2026 (rozhodný příjem DPP 12 000 Kč, DPČ a ZMR 4 500 Kč, SP
     * zaměstnanec 7,1 %, ZP 13,5 % / zaměstnanec 4,5 %, záloha 15 %, sleva na
     * poplatníka 2 570 Kč); rozpis je ve zprávě úkolu.
     */
    public function testAprilAgencyMonthMatchesHandCalculationAndPassesJmhz(): void
    {
        $a = $this->hire('Adam Srážka', 'male', '2000-01-15', 'dpp', 'dpp', false);
        $b = $this->hire('Bára Záloha', 'female', '1999-02-16', 'dpp', 'dpp', true);
        $c = $this->hire('Cyril Pojistný', 'male', '1990-03-17', 'dpp', 'dpp', true);
        $d = $this->hire('Dana Dvojdohoda', 'female', '1995-05-18', 'dpp', 'dpp', true);
        $d2 = $this->secondAgreement($d, 'dpp');
        $e = $this->hire('Emil Hrana', 'male', '1985-06-19', 'dpc', 'dpc', false);
        $f = $this->hire('Filip Hrana', 'male', '1985-06-20', 'dpc', 'dpc', false);
        $g = $this->hire('Gita Souběhová', 'female', '1982-07-21', 'hpp', 'employment', true, 40, 10_000);
        $g2 = $this->secondAgreement($g, 'dpp');
        $h = $this->hire('Hugo Malorozsahový', 'male', '1979-08-22', 'hpp', 'small_scale_employment', false, 8, 2_000);
        foreach ([$a, $b, $c, $d, $d2, $e, $f, $g2, $h] as $p) {
            $this->publishShifts($p['employment_id'], self::workdays(self::PERIOD));
            self::assertSame(200, $this->approveTimeMonth($p['employment_id'], self::PERIOD, ['2026-04-07', '2026-04-08'], dailyMinutes: 240)->getStatusCode());
            $this->probableAverage($p['employment_id'], 2, 20_000);
        }
        $this->publishShifts($g['employment_id'], self::workdays(self::PERIOD));
        self::assertSame(200, $this->approveTimeMonth($g['employment_id'], self::PERIOD, self::workdays(self::PERIOD))->getStatusCode());
        $this->probableAverage($g['employment_id'], 2, 20_000);
        $this->pay($a, 1_199_900);
        $this->pay($b, 1_000_000);
        $this->pay($c, 1_500_000);
        $this->pay($d, 700_000);
        $this->pay($d2, 600_000);
        $this->pay($e, 449_900);
        $this->pay($f, 450_000);
        $this->pay($g, 3_000_000);
        $this->pay($g2, 500_000);
        $this->pay($h, 400_000);

        [$revisionId, $xml] = $this->submission('april', self::PERIOD_START, self::PAYDAY);

        // [hrubá, SP zaměstnanec, ZP zaměstnanec, záloha, srážková daň, čistá] v haléřích
        $expected = [
            // DPP 11 999 Kč bez prohlášení: pod 12 000 => srážka 15 % z 11 999 = 1 799,85 => 1 799 dolů.
            'a' => [$a, [1_199_900, 0, 0, 0, 179_900, 1_020_000]],
            // DPP 10 000 s prohlášením: záloha 1 500 - sleva 2 570 => 0.
            'b' => [$b, [1_000_000, 0, 0, 0, 0, 1_000_000]],
            // DPP 15 000: SP 7,1 % = 1 065, ZP 4,5 % = 675, záloha 2 250 - 2 570 => 0.
            'c' => [$c, [1_500_000, 106_500, 67_500, 0, 0, 1_326_000]],
            // Dvě DPP 7 000 + 6 000 u téhož zaměstnavatele: součet 13 000 >= 12 000 => účast obou.
            // SP 497 + 426 = 923, ZP 4,5 % z 13 000 = 585, záloha 1 950 - 2 570 => 0.
            'd' => [$d, [1_300_000, 92_300, 58_500, 0, 0, 1_149_200]],
            // DPČ 4 499 bez prohlášení: pod 4 500 => srážka 674,85 => 674.
            'e' => [$e, [449_900, 0, 0, 0, 67_400, 382_500]],
            // DPČ 4 500 bez prohlášení: účast; SP 319,50 => 320, ZP 202,50 => 203,
            // záloha 15 % z 4 500 = 675 bez slevy.
            'f' => [$f, [450_000, 32_000, 20_300, 67_500, 0, 330_200]],
            // HPP 30 000 + DPP 5 000 s prohlášením: DPP pod limitem bez pojistného,
            // SP 2 130 a ZP 1 350 z HPP, záloha z 35 000 = 5 250 - 2 570 = 2 680.
            'g' => [$g, [3_500_000, 213_000, 135_000, 268_000, 0, 2_884_000]],
            // Zaměstnání malého rozsahu 4 000 bez prohlášení: pod 4 500 bez účasti na
            // nemocenském pojištění (SP 0), ale zaměstnanec pro ZP (§ 5 písm. a) z. č.
            // 48/1997 Sb. vyjímá jen DPP a DPČ): ZP 4,5 % = 180; srážka 15 % = 600.
            'h' => [$h, [400_000, 0, 18_000, 0, 60_000, 322_000]],
        ];
        foreach ($expected as $key => [$person, $amounts]) {
            $r = $this->personResult($revisionId, 'net_pay', $person['employee_id']);
            self::assertIsArray($r, $key);
            self::assertSame($amounts, [
                (int) $r['cash_income_minor_units'],
                (int) $r['employee_social_minor_units'],
                (int) $r['employee_health_minor_units'],
                (int) $r['advance_tax_minor_units'],
                (int) $r['withholding_tax_minor_units'],
                (int) $r['net_payable_minor_units'],
            ], "Osoba {$key}");
        }
        // ZP zaměstnavatele u DPP nad limitem: minimum 22 400 Kč, celkem 3 024 Kč
        // (13,5 % nahoru), zaměstnanec 675 => zaměstnavatel 2 349 (z toho doplatek 999).
        $health = $this->personResult($revisionId, 'health_insurance', $c['employee_id']);
        self::assertSame([234_900, 99_900], [(int) $health['employer_contribution_minor_units'], (int) $health['employer_minimum_top_up_minor_units']]);

        // JMHZ: formulář za každý vztah, souhrn jen na hlavním, celé koruny.
        self::assertSame(10, substr_count($xml, '</formularOsoby>'));
        self::assertSame(8, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        self::assertSame(2, substr_count($xml, '<primarniPpv>false</primarniPpv>'));
        self::assertSame(8, substr_count($xml, '<form:souhrnDataZec>'));
        self::assertStringContainsString('<typPodani>R</typPodani>', $xml);
        self::assertSame(10, substr_count($xml, '<typFormulare>R</typFormulare>'));
        self::assertStringContainsString('<form:zvlastniSazbaDane><form:zakladDane>11999</form:zakladDane><form:srazenaDan>1799</form:srazenaDan></form:zvlastniSazbaDane>', $xml);
        self::assertStringContainsString('<form:zvlastniSazbaDane><form:zakladDane>4499</form:zakladDane><form:srazenaDan>674</form:srazenaDan></form:zvlastniSazbaDane>', $xml);
        self::assertStringContainsString('<form:zvlastniSazbaDane><form:zakladDane>4000</form:zakladDane><form:srazenaDan>600</form:srazenaDan></form:zvlastniSazbaDane>', $xml);
        // Souhrn osoby se dvěma DPP: úhrn 10286 = 13 000, výdělek po vztazích 10328/10329.
        self::assertStringContainsString('<form:zuctovanoCelkem>13000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:mzdaZuctovana>7000</form:mzdaZuctovana><form:mzdaRozpad><form:tarif>7000</form:tarif><form:odmenyPravidelne>0</form:odmenyPravidelne><form:odmenyNepravidelne>0</form:odmenyNepravidelne></form:mzdaRozpad>', $xml);
        self::assertStringContainsString('<form:mzdaZuctovana>6000</form:mzdaZuctovana>', $xml);
        // Vyměřovací základ účastné DPP a příjem nepojištěné DPP v souběhu s HPP.
        self::assertStringContainsString('<form:vymerovaciZaklad><form:castkaOdvodPojistneho>15000</form:castkaOdvodPojistneho><form:prijemNepojistenaCinnost>15000</form:prijemNepojistenaCinnost></form:vymerovaciZaklad>', $xml);
        self::assertStringContainsString('<form:vymerovaciZaklad><form:prijemNepojistenaCinnost>5000</form:prijemNepojistenaCinnost></form:vymerovaciZaklad>', $xml);
        // Průměr 10345 z pravděpodobného výdělku 200 Kč/h, prohlášení, pracoviště.
        self::assertSame(10, substr_count($xml, '<form:vydelekPrumernyHod>200.00</form:vydelekPrumernyHod>'));
        self::assertSame(4, substr_count($xml, '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>'));
        self::assertSame(4, substr_count($xml, '<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>'));
        self::assertSame(10, substr_count($xml, '<form:mistoVykonuPrace><form:obec>Hlavní město Praha</form:obec><form:kodObce>554782</form:kodObce><form:kodStatu>CZ</form:kodStatu></form:mistoVykonuPrace>'));
        // OIČ a ID PPV z registrace: 8 osob, 10 vztahů.
        self::assertSame(8, count(array_unique($this->allMatches('/<form:ikMpsv>(\d{10})<\/form:ikMpsv>/', $xml))));
        self::assertSame(10, count(array_unique($this->allMatches('/<form:idPpv>(\d{21})<\/form:idPpv>/', $xml))));
        // Přehled o pojistném: základ 15 000 + 13 000 + 4 500 + 30 000 = 62 500,
        // zaměstnavatel 24,8 % = 15 500, zaměstnanci 1 065 + 923 + 320 + 2 130 = 4 438.
        self::assertStringContainsString('<pvpoj:zakladZamestnavateleA>62500</pvpoj:zakladZamestnavateleA><pvpoj:pojistneZamestnavateleA>15500</pvpoj:pojistneZamestnavateleA>', $xml);
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>4438</pvpoj:pojistneZamestnance>', $xml);
        // Záloha po slevě za firmu: 2 680 (G) + 675 (F).
        self::assertStringContainsString('<so:danZalohaPoSleve>3355</so:danZalohaPoSleve>', $xml);
    }

    /**
     * Prosinec 2026 a leden 2027 (nová sada: rozhodný příjem DPP 12 500 Kč,
     * DPČ 5 000 Kč). Prosinec projde až do platného XML; za leden 2027 se
     * mzda spočítá podle sady 2027, ale hlášení zastaví srozumitelná
     * blokace nevyhlášených číselníků obcí a států 2027 s odkazem.
     * Navazující dohoda přes přelom roku je v lednu jediným a hlavním vztahem.
     */
    public function testDecember2026AndJanuary2027AcrossTheYearBoundary(): void
    {
        $limit = $this->hire('Iveta Hraniční', 'female', '1994-09-23', 'dpp', 'dpp', true);
        $under = $this->hire('Jakub Podlimitní', 'male', '2001-10-24', 'dpp', 'dpp', false);
        $over = $this->hire('Klára Nadlimitní', 'female', '1993-11-25', 'dpp', 'dpp', true);
        $this->setEnd($limit['employment_id'], '2026-12-31');
        foreach ([$limit, $under, $over] as $p) {
            $this->probableAverage($p['employment_id'], 4, 20_000);
        }
        $this->month($limit, '2026-12', 1_200_000);
        $this->month($under, '2026-12', 1_199_900);
        $this->month($over, '2026-12', 1_000_000);
        // Prosincové hlášení nese roční údaje zaměstnavatele (10214, 10220,
        // OZP); kontrola běhu je musí ohlásit předem s odkazem, kde se zadávají.
        $readiness = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class)
            ->inspect($this->supplierId, '2026-12-01', '2027-01-14', $this->officeId);
        $annual = array_values(array_filter(
            $readiness['findings'],
            static fn (array $f): bool => str_contains((string) $f['code'], 'employer_annual'),
        ));
        self::assertNotSame([], $annual, 'Kontrola běhu za prosinec neohlásí roční údaje zaměstnavatele: ' . CanonicalJson::encode(array_column($readiness['findings'], 'code')));
        self::assertNotNull($annual[0]['remediation_path']);
        $this->container->get(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService::class)->save($this->supplierId, 2026, [
            'expected_revision_id' => null,
            'collective_agreement_types' => ['1'],
            'ownership_form' => '2',
            'average_headcount' => '3.00',
            'average_disabled_headcount' => '0.00',
            'ozp_reporting_office_id' => $this->officeId,
            'evidence_reference' => 'synthetic-approved-source',
        ], $this->actors[0]);
        $run = $this->runPayrollMonth('2026-12-01', '2027-01-14', $this->officeId, 'dpp-december');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
        $december = (int) $run['approved']->revision['id'];
        $preparation = $this->prepareJmhz($december, 'dpp-december');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues']));
        // Dokud prosinec neskončil, zastaví test XML jen kontroly kalendáře
        // se srozumitelnou větou; podává se až v lednu 2027.
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status']);
        $messages = array_column($tested['body']['controls']['blocking'] ?? [], 'message');
        // Do konce prosince ho zastaví jen kontroly kalendáře (období
        // a pojištění končí po dnešku); žádná věcná kontrola katalogu ani XSD.
        // Po 1. 1. 2027 je seznam prázdný, test na dnešním datu nestojí.
        $factual = array_values(array_filter(
            $messages,
            static fn (string $message): bool => !str_starts_with($message, 'Hlášené období 2026-12 ještě neskončilo')
                && !str_starts_with($message, 'Pojištění do 2026-12-31 je po datu vyplnění podání'),
        ));
        self::assertSame([], $factual);
        self::assertSame(0, (int) ($tested['body']['controls']['counts']['unimplemented'] ?? 0));
        self::assertSame([], $tested['body']['blockers'] ?? []);
        $xml = (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));
        // DPP přesně 12 000 Kč: rozhodný příjem dosažen => účast; SP 852.
        // ZP: minimální vyměřovací základ 22 400 (§ 3 odst. 8 z. č. 592/1992 Sb.
        // DPP nevyjímá), za prosinec není evidována překážka na straně
        // zaměstnavatele, takže doplatek hradí zaměstnanec (§ 3 odst. 10):
        // celkem 13,5 % z 22 400 = 3 024, ze mzdy 13,5 % z 12 000 = 1 620,
        // zaměstnanec 540 + doplatek 1 404 = 1 944. Záloha 1 800 - 2 570 => 0.
        // Čistá 12 000 - 852 - 1 944 = 9 204.
        self::assertSame([1_200_000, 85_200, 194_400, 0, 0, 920_400], $this->net($december, $limit['employee_id']));
        // 11 999 Kč bez prohlášení => srážka 1 799.
        self::assertSame([1_199_900, 0, 0, 0, 179_900, 1_020_000], $this->net($december, $under['employee_id']));
        self::assertStringContainsString('<mesic>12</mesic><rok>2026</rok>', $xml);
        self::assertSame(3, substr_count($xml, '<primarniPpv>true</primarniPpv>'));

        // Navazující dohoda od 1. 1. 2027 po dohodě skončené 31. 12. 2026.
        $follow = $this->followUpAgreement($limit, '2027-01-01');
        foreach ([$follow, $under, $over] as $p) {
            $this->probableAverage($p['employment_id'], 1, 20_000, 2027);
        }
        $this->month($follow, '2027-01', 1_250_000);
        $this->month($under, '2027-01', 1_249_900);
        $this->month($over, '2027-01', 1_249_900);
        // Bez počátečních ročních úhrnů za rok 2027 se zákonný výpočet zastaví
        // s návodem na kartu vztahu. Osoby bez exekuce nesmí dostat navíc
        // zavádějící blokaci „chybí pravidla exekučních srážek".
        $run = $this->runPayrollMonth('2027-01-01', '2027-02-12', $this->officeId, 'dpp-january-2027');
        $codes = array_values(array_unique(array_column($run['blockers'], 'code')));
        self::assertSame(['statutory_calculation_manual_review'], $codes, CanonicalJson::encode($run['blockers']));
        self::assertStringContainsString('Počáteční stavy', $run['blockers'][0]['message']);
        // Akce „Vytvořit běh" doplní prokazatelně nulové úhrny sama
        // (mzdy za rok vede MyÚčto); tady totéž přes službu, pak znovu od opravených podkladů.
        $openings = $this->container->get(\MyInvoice\Service\Payroll\PayrollOpeningBalanceService::class);
        self::assertCount(3, $openings->seedProvableZeroOpenings($this->supplierId, '2027-01-01', [], $this->actors[0]));
        $runRow = $run['calculated']->run;
        $cancelled = $this->runs->cancel($this->supplierId, (int) $runRow['id'], (int) $runRow['row_version'], 'jan27-cancel', $this->actors[0], 'Počáteční úhrny');
        $reopened = $this->runs->reopen($this->supplierId, (int) $runRow['id'], (int) $cancelled->run['row_version'], 'jan27-reopen', $this->actors[0], 'Počáteční úhrny doplněny');
        $recalculated = $this->runs->calculate($this->supplierId, (int) $runRow['id'], (int) $reopened->run['row_version'], 'jan27-calc', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $recalculated->revision['id']));
        $approved = $this->runs->approve($this->supplierId, (int) $runRow['id'], (int) $recalculated->run['row_version'], 'jan27-approve', $this->actors[0]);
        $run = ['approved' => $approved];
        $january = (int) $run['approved']->revision['id'];
        // 2027: DPP 12 500 Kč => účast; SP 887,50 => 888. ZP z minima 24 900 (sada
        // 2027): celkem 3 361,50 => 3 362, ze mzdy 1 687,50 => 1 688, zaměstnanec
        // 563 + doplatek 1 674 = 2 237. Záloha 1 875 - 2 570 => 0. Čistá 9 375.
        self::assertSame([1_250_000, 88_800, 223_700, 0, 0, 937_500], $this->net($january, $limit['employee_id']));
        // 12 499 Kč bez prohlášení => pod 12 500 => od 2027 zálohová daň ze
        // samostatného základu (místo srážkové daně do 12/2026): 15 % z 12 499
        // = 1 874,85, jako záloha zaokrouhleno na celé koruny nahoru = 1 875.
        self::assertSame([1_249_900, 0, 0, 0, 187_500, 1_062_400], $this->net($january, $under['employee_id']));
        // 12 499 Kč s prohlášením => bez pojistného, záloha 1 875 (základ na stovky nahoru 12 500) - 2 570 => 0.
        self::assertSame([1_249_900, 0, 0, 0, 0, 1_249_900], $this->net($january, $over['employee_id']));

        $preparation = $this->prepareJmhz($january, 'dpp-january-2027');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('blocked', $preparation['body']['readiness_status']);
        // Kód nálezu se mezi větvemi liší (obecný „neověřeno“ s větou o měsíci,
        // nebo samostatný „číselník nevyhlášen“); účetní musí v obou případech
        // číst, že jde o číselník a že údaje pracoviště měnit nemá.
        $codebookCodes = ['jmhz_workplace_codebooks_unverified', 'jmhz_workplace_codebooks_not_published'];
        $codes = array_column($preparation['body']['issues'], 'code');
        self::assertNotSame([], array_intersect($codebookCodes, $codes), CanonicalJson::encode($codes));
        self::assertNotContains('jmhz_primary_employment_unresolved', $codes, 'Navazující dohoda přes přelom roku nemá být sporná.');
        $dry = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('blocked', $dry['body']['status']);
        // Leden 2027 zastaví i další podklady (roční údaje zaměstnavatele za
        // rok 2027, žádost o roční zúčtování, nové údaje věty 2027). Žádná
        // blokace nesmí zůstat bez důvodu a kroku nápravy.
        foreach ($dry['body']['blockers'] as $blocker) {
            self::assertNotSame('', trim((string) ($blocker['reason'] ?? '')), CanonicalJson::encode($blocker));
            self::assertNotSame('', trim((string) ($blocker['action'] ?? '')), CanonicalJson::encode($blocker));
        }
        $workplace = array_values(array_filter(
            $dry['body']['blockers'],
            static fn (array $b): bool => in_array($b['code'], $codebookCodes, true),
        ));
        self::assertNotSame([], $workplace);
        $said = mb_strtolower(
            implode(' ', [$workplace[0]['reason'] ?? '', $workplace[0]['message'] ?? '', $workplace[0]['action'] ?? '']),
            'UTF-8',
        );
        self::assertStringContainsString('číselník', $said, 'Blokace 01/2027 posílá účetní doplňovat pole, která jsou v pořádku.');
        self::assertStringContainsString('neměňte', $said);
    }

    /**
     * Měsíce před povinným JMHZ (01-03/2026): haléře hlásí už kontrola běhu
     * s odkazem na vstupy daného měsíce, hromadné zaokrouhlení nahoru je
     * opraví jen v této firmě a podruhé už nic nezmění. Hlášení za leden jde
     * připravit, zastaví ho jen nevyřízená žádost o roční zúčtování s návodem;
     * za březen projde.
     */
    public function testMonthsBeforeJmhzFromCheckHalereAndPrepareVoluntarily(): void
    {
        $person = $this->hire('Lucie Haléřová', 'female', '1996-12-26', 'dpp', 'dpp', true);
        foreach ([1, 2] as $quarter) {
            $this->probableAverage($person['employment_id'], $quarter, 20_000);
        }
        $this->month($person, '2026-01', 750_050);
        $foreign = $this->foreignHalereInput();

        // Kontrola před během haléře nahlásí už před zamknutím vstupů.
        $readiness = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class)
            ->inspect($this->supplierId, '2026-01-01', '2026-02-13', $this->officeId);
        $found = array_values(array_filter($readiness['findings'], static fn (array $f): bool => $f['code'] === 'inputs_not_whole_crowns'));
        self::assertCount(1, $found, CanonicalJson::encode(array_column($readiness['findings'], 'code')));
        self::assertSame('blocker', $found[0]['severity']);
        self::assertStringContainsString('/payroll/components?tab=inputs&period=2026-01', (string) $found[0]['remediation_path']);

        // Kdo běh přesto spustí, dostane blokaci s odkazem na vstupy měsíce.
        $run = $this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'dpp-january');
        $halere = array_values(array_filter($run['blockers'], static fn (array $b): bool => $b['code'] === 'inputs_not_whole_crowns'));
        self::assertCount(1, $halere, CanonicalJson::encode($run['blockers']));
        $validation = $this->db->pdo()->prepare(
            'SELECT message, remediation_path FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND code = "inputs_not_whole_crowns"',
        );
        $validation->execute([$this->supplierId, (int) $run['calculated']->revision['id']]);
        $row = $validation->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString('7 500,50', (string) $row['message']);
        self::assertSame("/payroll/components?tab=inputs&period=2026-01&employment={$person['employment_id']}", $row['remediation_path']);

        // Vstup drží běh: zaokrouhlení ho přeskočí a řekne, jak dál (zrušit běh).
        $inputs = $this->container->get(\MyInvoice\Repository\Payroll\PayrollInputRepository::class);
        $locked = $inputs->roundUpToWholeCrowns($this->supplierId, '2026-01-01', null, true, $this->actors[0]);
        self::assertSame([], $locked['rounded']);
        self::assertCount(1, $locked['skipped']);
        self::assertStringContainsString('Zrušit běh', (string) $locked['skipped'][0]['reason']);
        $runRow = $run['calculated']->run;
        $cancelled = $this->runs->cancel($this->supplierId, (int) $runRow['id'], (int) $runRow['row_version'], 'jan-cancel', $this->actors[0], 'Haléře ve vstupech');

        $rounded = $inputs->roundUpToWholeCrowns($this->supplierId, '2026-01-01', null, true, $this->actors[0]);
        self::assertSame([[750_050, 750_100]], array_map(static fn (array $r): array => [$r['from_minor'], $r['to_minor']], $rounded['rounded']));
        $again = $inputs->roundUpToWholeCrowns($this->supplierId, '2026-01-01', null, true, $this->actors[0]);
        self::assertSame([], $again['rounded']);
        self::assertSame([], $again['skipped']);
        self::assertSame(12_345, (int) $this->scalar('SELECT amount_minor FROM payroll_inputs WHERE id = ?', [$foreign]));

        // „Začít z opravených vstupů“: běh se otevře znovu a spočítá ze zaokrouhlených vstupů.
        $reopened = $this->runs->reopen($this->supplierId, (int) $runRow['id'], (int) $cancelled->run['row_version'], 'jan-reopen', $this->actors[0], 'Opravené vstupy');
        $recalculated = $this->runs->calculate($this->supplierId, (int) $runRow['id'], (int) $reopened->run['row_version'], 'jan-recalc', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $recalculated->revision['id']));
        $approved = $this->runs->approve($this->supplierId, (int) $runRow['id'], (int) $recalculated->run['row_version'], 'jan-approve', $this->actors[0]);
        // 7 501 Kč s prohlášením pod limitem: záloha 15 % z 7 600 (stovky nahoru) = 1 140 - 2 570 => 0.
        self::assertSame([750_100, 0, 0, 0, 0, 750_100], $this->net((int) $approved->revision['id'], $person['employee_id']));

        $preparation = $this->prepareJmhz((int) $approved->revision['id'], 'dpp-january');
        self::assertSame(201, $preparation['status']);
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('blocked', $tested['body']['status']);
        self::assertSame(['jmhz_annual_request_source_missing'], array_values(array_unique(array_column($tested['body']['blockers'], 'code'))));
        self::assertStringContainsString('Roční zúčtování', (string) $tested['body']['blockers'][0]['action']);
        self::assertTrue($tested['body']['blockers'][0]['deferrable']);

        $this->month($person, '2026-02', 600_000);
        $this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'dpp-february');
        $this->month($person, '2026-03', 600_000);
        [, $xml] = $this->submission('march', '2026-03-01', '2026-04-14');
        self::assertStringContainsString('<mesic>3</mesic><rok>2026</rok>', $xml);
        self::assertStringContainsString('<form:mzdaZuctovana>6000</form:mzdaZuctovana>', $xml);
    }

    /** Haléřový vstup cizí firmy: hromadné zaokrouhlení se ho nesmí dotknout. */
    private function foreignHalereInput(): int
    {
        $pdo = $this->db->pdo();
        $foreignSupplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Cizí Brigádník", "employee", 1)')->execute([$foreignSupplier]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, "1", "dpp", "active", "2026-01-01", "2026-01-01", 1)',
        )->execute([$foreignSupplier, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment, health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, "CIZI", "Cizí odměna", "base_wage", "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included", "included", "included", "521", "331", "2026-01-01")',
        )->execute([$foreignSupplier]);
        $componentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_inputs (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor,
                                         source_kind, external_id, status, component_snapshot_json, component_snapshot_hash)
             VALUES (?, ?, ?, ?, "2026-01-01", 12345, "manual", "cizi-halere", "draft", "{}", ?)',
        )->execute([$foreignSupplier, $employeeId, $employmentId, $componentId, hash('sha256', '{}', true)]);

        return (int) $pdo->lastInsertId();
    }

    private function setEnd(int $employmentId, string $endOn): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET fixed_term_end_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
    }

    /**
     * Navazující DPP přes kartu osoby jako hlavní vztah.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function followUpAgreement(array $person, string $startOn): array
    {
        $action = $this->container->get(\MyInvoice\Action\Payroll\PayrollEmploymentAction::class);
        $response = $action->create(
            $this->request('POST', "/api/payroll/people/{$person['employee_id']}/employments")->withParsedBody([
                'code' => '',
                'relation_type' => 'dpp',
                'confirm_new_agreement' => true,
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => $this->officeId, 'effective_from' => $startOn, 'contract_signed_on' => $startOn,
                    'planned_start_on' => $startOn, 'actual_start_on' => null, 'fixed_term_end_on' => null,
                    'weekly_hours' => '10', 'workload_basis_points' => 2500,
                    'work_place' => 'Hlavní město Praha', 'regular_workplace' => 'Hlavní město Praha',
                    'jmhz_workplace_municipality_code' => '554782', 'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => 'no', 'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'no', 'jmhz_temporary_assignment_status' => 'no',
                    'cz_isco_code' => '43111', 'activity_code' => null, 'jmhz_relationship_detail_code' => null,
                    'social_insurance_participation' => 'automatic', 'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance', 'foreign_legislation_country_code' => null, 'a1_certificate_until' => null,
                    'risky_work' => false, 'tax_declaration_signed' => true, 'is_primary' => true,
                    'change_reason' => 'Navazující dohoda agentury',
                ],
            ]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $person['employee_id']],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $employmentId = (int) $this->json($response)['employment']['id'];
        $this->db->pdo()->prepare('UPDATE payroll_employments SET status = "active", actual_start_date = start_date WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET actual_start_on = planned_start_on WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $employmentId]);
        $agreement = ['employee_id' => $person['employee_id'], 'employment_id' => $employmentId, 'name' => $person['name']];
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->container->get(\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService::class)
            ->assignManualJmhzIdentity($this->supplierId, $employmentId, 'test', null, sprintf('2%020d', 900 + $employmentId), $startOn, null, true, $this->actors[0]);

        return $agreement;
    }

    /** @return list<string> */
    private function allMatches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return $found[1];
    }

    /** @return array{0:int,1:string} revize a XML bez mezer */
    private function submission(string $key, string $periodStart, string $payday): array
    {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "dpp-{$key}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        $revisionId = (int) $run['approved']->revision['id'];
        $preparation = $this->prepareJmhz($revisionId, "dpp-{$key}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], 'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode(array_diff_key($tested['body'], ['xml' => true])));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [$revisionId, (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml'])];
    }

    private function probableAverage(int $employmentId, int $quarter, int $hourlyMinor, int $year = 2026): mixed
    {
        $from = date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1) . ' -3 months'));
        $to = date('Y-m-t', strtotime($from . ' +2 months'));
        $response = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'decisive_from' => $from,
                'decisive_to' => $to,
                'gross_earnings_minor' => 0,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 0,
                'worked_days' => 0,
                'probable_hourly_minor' => $hourlyMinor,
                'rationale' => 'Pravděpodobný výdělek ze sjednané hodinové odměny dohody.',
            ]),
            new \Slim\Psr7\Response(),
        );
        if ($response->getStatusCode() !== 201) {
            return (string) $response->getBody();
        }
        $average = $this->json($response)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $average['id']],
        );

        return [$approved->getStatusCode(), $average['average_hourly_minor'] ?? null, (string) $approved->getBody()];
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hire(string $name, string $sex, string $birthDate, string $employmentType, string $relationType, bool $declaration, int $weekly = 10, int $workload = 2_500): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weekly,
            $workload,
            $declaration,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));

        return $person + ['sequence' => $sequence];
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function secondAgreement(array $person, string $relationType): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            $relationType,
            $relationType,
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, sprintf('2%020d', $sequence));

        return $agreement;
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart = self::PERIOD_START): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'odmena-' . $person['employment_id'] . '-' . $periodStart, $periodStart);
    }

    /**
     * Docházka a odměna dohody za měsíc.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     */
    private function month(array $person, string $period, int $amountMinor): void
    {
        $days = self::workdays($period);
        $this->publishShifts($person['employment_id'], $days);
        $response = $this->approveTimeMonth($person['employment_id'], $period, [$days[3], $days[4]], dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $response->getBody());
        $this->pay($person, $amountMinor, "{$period}-01");
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    private function net(int $revisionId, int $employeeId): array
    {
        $r = $this->personResult($revisionId, 'net_pay', $employeeId);
        self::assertIsArray($r);

        return [
            (int) $r['cash_income_minor_units'],
            (int) $r['employee_social_minor_units'],
            (int) $r['employee_health_minor_units'],
            (int) $r['advance_tax_minor_units'],
            (int) $r['withholding_tax_minor_units'],
            (int) $r['net_payable_minor_units'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function personResult(int $revisionId, string $kind, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT person.result_snapshot_json
               FROM payroll_statutory_person_results person
               JOIN payroll_statutory_results result
                 ON result.supplier_id = person.supplier_id AND result.id = person.statutory_result_id
              WHERE result.supplier_id = ? AND result.revision_id = ? AND result.calculation_kind = ?',
        );
        $stmt->execute([$this->supplierId, $revisionId, $kind]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string) $json, true);
            if (is_array($row) && in_array("employee:{$employeeId}", [$row['person_reference'] ?? null, $row['person_id'] ?? null], true)) {
                return $row;
            }
        }

        return null;
    }
}

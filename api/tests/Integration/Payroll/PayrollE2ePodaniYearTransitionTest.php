<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Přechod roku 2026/2027 celým tokem pro malou firmu: HPP s dítětem, DPP
 * a DPČ bez prohlášení. Prosinec 2026 (výplata v lednu 2027) se počítá podle
 * sady 2026 a hlásí podle JMHZ 1.4.x, leden 2027 podle sady 2027.
 *
 * Očekávané částky jsou přepočtené ručně (viz komentáře u asercí), ne opsané
 * z výstupu aplikace.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniYearTransitionTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    private int $lastPreparationId = 0;

    /** @var object{today:string} posuvné hodiny: den, kdy účetní krok dělá */
    private object $calendar;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->calendar = new class {
            public string $today = '2027-01-14';
        };
        $calendar = $this->calendar;
        if (!$this->container instanceof \DI\Container) {
            self::markTestSkipped('Kontejner nejde přenastavit na zmrazené hodiny.');
        }
        $this->container->set(ClockInterface::class, new class ($calendar) implements ClockInterface {
            /** @param object{today:string} $calendar */
            public function __construct(private readonly object $calendar) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->calendar->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
            }
        });
        $this->officeId = $this->createOffice('PRECHOD', 'Syntetická účtárna přechodu', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_PRECHOD', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Prosinec 2026 s výplatou 12. 1. 2027: daň i pojistné podle sady 2026
     * (§ 5 odst. 4 ZDP, příjem za prosinec zúčtovaný a vyplacený do 31. 1.
     * patří do roku 2026), JMHZ za 12/2026 podle specifikace 1.4.x.
     */
    public function testDecember2026PaidInJanuaryUsesRuleset2026AndSpec14(): void
    {
        $firm = $this->smallFirm('2026-12-01');
        $this->pay($firm, '2026-12-01', 'dec');

        $run = $this->runPayrollMonth('2026-12-01', '2027-01-12', $this->officeId, 'prechod-12');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet 12/2026. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování 12/2026. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $people = $this->peopleByEmployee($run['approved']->revision['result_snapshot']['statutory']['people'] ?? []);

        /*
         * HPP 40 000 Kč, prohlášení, 1 dítě (sada 2026):
         *  SP 7,1 % = 2 840; ZP 4,5 % = 1 800;
         *  záloha 15 % × 40 000 = 6 000 − sleva 2 570 − zvýhodnění 1 267 = 2 163;
         *  čistá 40 000 − 2 840 − 1 800 − 2 163 = 33 197.
         */
        $this->assertPerson($people[$firm['hpp']['employee_id']], 'cz-payroll-2026.income-tax.v1', 284_000, 180_000, 216_300, 0, 126_700, 3_319_700);
        /*
         * DPP 12 000 Kč bez prohlášení: rozhodný příjem 2026 je 12 000 Kč,
         * účast „alespoň ve výši" (§ 7a z. 187/2006) => pojištěn; srážková
         * daň jen pod hranicí (§ 6 odst. 4 písm. a) ZDP „nižší než"), tady
         * tedy záloha 15 % × 12 000 = 1 800. SP 7,1 % = 852, ZP 13,5 % =
         * 1 620, z toho zaměstnanec třetinu 540. Čistá 12 000 − 852 − 540
         * − 1 800 = 8 808.
         */
        $this->assertPerson($people[$firm['dpp']['employee_id']], 'cz-payroll-2026.income-tax.v1', 85_200, 54_000, 180_000, 0, 0, 880_800);
        /*
         * DPČ 4 800 Kč bez prohlášení: rozhodný příjem 2026 4 500 Kč =>
         * pojištěn, záloha 15 % × 4 800 = 720 (§ 6 odst. 4 písm. b) ZDP
         * jen pod 4 500 Kč). SP 7,1 % × 4 800 = 340,80 → 341 (nahoru);
         * ZP 13,5 % = 648, zaměstnanec 216. Čistá 4 800 − 341 − 216 − 720
         * = 3 523.
         */
        $this->assertPerson($people[$firm['dpc']['employee_id']], 'cz-payroll-2026.income-tax.v1', 34_100, 21_600, 72_000, 0, 0, 352_300);

        $this->saveEmployerAnnual(2026);
        $xml = $this->jmhzSubmissionXml((int) $run['approved']->revision['id'], 'prechod-12');
        // Prosinec 2026 se hlásí podle specifikace 2026 (XSD MH 1.4.3.6).
        self::assertStringContainsString('verze="1.4.3"', $xml);
        self::assertStringContainsString('xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"', $xml);
        self::assertStringContainsString('<mesic>12</mesic><rok>2026</rok>', $xml);
        self::assertStringContainsString('<datumVyplneni>2027-01-14T12:00:00+01:00</datumVyplneni>', $xml);
        // Souhrn: záloha 2 163 + 1 800 + 720 = 4 683.
        self::assertStringContainsString('<so:danZalohaPoSleve>4683</so:danZalohaPoSleve>', $xml);
        // Prosincové roční údaje zaměstnavatele (10220 vlastnictví, 10214 kolektivní smlouva).
        self::assertStringContainsString(
            '<so:zamestnavatelUdajeRok><so:formaVlastnictvi>1</so:formaVlastnictvi><so:kolektivniSmlouvy>'
                . '<so:kolektivniSmlouva><so:typKolektSmlouvy>0</so:typKolektSmlouvy>',
            $xml,
        );
        /*
         * PVPOJ: úhrn VZ 40 000 + 12 000 + 4 800 = 56 800; zaměstnavatel
         * 24,8 % = 14 086,40 → 14 087; zaměstnanci 2 840 + 852 + 341 = 4 033.
         */
        self::assertStringContainsString(
            '<pvpoj:zakladZamestnavateleA>56800</pvpoj:zakladZamestnavateleA>'
                . '<pvpoj:pojistneZamestnavateleA>14087</pvpoj:pojistneZamestnavateleA>'
                . '<pvpoj:pojistneZamestnavateleCelkem>14087</pvpoj:pojistneZamestnavateleCelkem>'
                . '<pvpoj:pojistneZamestnance>4033</pvpoj:pojistneZamestnance>'
                . '<pvpoj:pojistneCelkem>18120</pvpoj:pojistneCelkem>',
            $xml,
        );
        self::assertSame(3, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:kod>T++</form:kod>', $xml);

        // Řádné hlášení za 12/2026 se zmrazí v lednu 2027 a ČSSZ ho přijme.
        $preparationId = $this->lastPreparationId;
        $frozen = $this->freezeJmhzSubmission($preparationId, $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        $regularId = (int) $frozen['body']['submission_id'];
        $this->acceptJmhzSubmission($regularId);

        /*
         * V březnu 2027 se zjistí zapomenutá prosincová odměna HPP 2 000 Kč.
         * Oprava běhu 12/2026 se počítá dál podle sady 2026 a opravné hlášení
         * podané v roce 2027 jde podle specifikace 1.4.x (o verzi rozhoduje
         * vykazované období, ne den podání).
         * Ruční přepočet: 42 000 × 15 % = 6 300 − 2 570 − 1 267 = 2 463;
         * SP 7,1 % = 2 982; ZP 4,5 % = 1 890; čistá 42 000 − 2 982 − 1 890 − 2 463 = 34 665.
         */
        $this->calendar->today = '2027-03-10';
        $this->createApprovedInput($firm['hpp'], $this->baseComponentId, 200_000, 'dec-odmena', '2026-12-01');
        $corrected = $this->correctPayrollRun($run['approved']->run, 'prechod-12-oprava', 'Doplněna prosincová odměna.');
        self::assertSame('approved', $corrected->run['status']);
        $correctedPeople = $this->peopleByEmployee($corrected->revision['result_snapshot']['statutory']['people'] ?? []);
        $this->assertPerson($correctedPeople[$firm['hpp']['employee_id']], 'cz-payroll-2026.income-tax.v1', 298_200, 189_000, 246_300, 0, 126_700, 3_466_500);
        $preparation = $this->prepareJmhz((int) $corrected->revision['id'], 'prechod-12-oprava');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $corrections = $this->container->get(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService::class);
        $candidates = $corrections->candidates($this->supplierId, 'test', $regularId, (int) $preparation['body']['id']);
        $changed = array_values(array_filter($candidates['forms'], static fn (array $form): bool => $form['changed'] === true));
        self::assertCount(1, $changed, CanonicalJson::encode($candidates));
        $correction = $corrections->freeze(
            $this->supplierId,
            'test',
            $regularId,
            (int) $preparation['body']['id'],
            [(string) $changed[0]['employment_external_identifier']],
            $this->actors[0],
        );
        self::assertSame('correction', $correction['submission_kind']);
        $reader = $this->container->get(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader::class);
        $correctionXml = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', (int) $correction['submission_id']));
        self::assertStringContainsString('verze="1.4.3"', $correctionXml);
        self::assertStringContainsString('<typPodani>O</typPodani>', $correctionXml);
        self::assertStringContainsString('<mesic>12</mesic><rok>2026</rok>', $correctionXml);
        self::assertStringContainsString('<datumVyplneni>2027-03-10T12:00:00+01:00</datumVyplneni>', $correctionXml);
        self::assertStringContainsString('<form:zuctovanoCelkem>42000</form:zuctovanoCelkem>', $correctionXml);
        self::assertStringContainsString('<form:danZalohaPoSleve>2463</form:danZalohaPoSleve>', $correctionXml);
    }

    /**
     * První měsíc JMHZ (04/2026) malé firmy: úplný výčet formulářů a klíčových
     * atributů (typ R, primární PPV 10495, OIČ a ID PPV, rozpad mzdy 10328 až
     * 10331, úhrn 10286, průměr 10345, prohlášení, pracoviště), XSD a katalog
     * kontrol bez selhání.
     */
    public function testApril2026FirstJmhzMonthCarriesFullFormInventory(): void
    {
        $this->calendar->today = '2026-05-11';
        $firm = $this->smallFirm('2026-04-01');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        $regular = $this->createComponent('ODMENA_PRAVIDELNA', 'bonus', 'regular');
        $mappings->put($this->supplierId, $regular, '10330', null, $this->actors[0]);
        $irregular = $this->createComponent('ODMENA_MIMORADNA', 'bonus', 'one_off');
        $mappings->put($this->supplierId, $irregular, '10331', null, $this->actors[0]);
        $this->pay($firm, '2026-04-01', 'apr');
        $this->createApprovedInput($firm['hpp'], $regular, 300_000, 'apr-pravidelna', '2026-04-01');
        $this->createApprovedInput($firm['hpp'], $irregular, 500_000, 'apr-mimoradna', '2026-04-01');

        $run = $this->runPayrollMonth('2026-04-01', '2026-05-12', $this->officeId, 'duben');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
        $people = $this->peopleByEmployee($run['approved']->revision['result_snapshot']['statutory']['people'] ?? []);
        /*
         * HPP 40 000 + 3 000 + 5 000 = 48 000: SP 7,1 % = 3 408; ZP 4,5 % =
         * 2 160; záloha 15 % × 48 000 = 7 200 − 2 570 − 1 267 = 3 363;
         * čistá 48 000 − 3 408 − 2 160 − 3 363 = 39 069.
         */
        $this->assertPerson($people[$firm['hpp']['employee_id']], 'cz-payroll-2026.income-tax.v1', 340_800, 216_000, 336_300, 0, 126_700, 3_906_900);

        $xml = $this->jmhzSubmissionXml((int) $run['approved']->revision['id'], 'duben');
        self::assertStringContainsString('<mesic>4</mesic><rok>2026</rok>', $xml);
        self::assertSame(3, substr_count($xml, '<typFormulare>R</typFormulare>'));
        self::assertSame(3, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        foreach ($firm as $person) {
            self::assertStringContainsString('<form:ikMpsv>' . self::syntheticOic($person['sequence']) . '</form:ikMpsv>', $xml);
            self::assertStringContainsString('<form:idPpv>' . sprintf('2%020d', $person['sequence']) . '</form:idPpv>', $xml);
        }
        // Rozpad mzdy HPP: tarif 10329, pravidelné 10330, nepravidelné 10331, úhrn 10328.
        self::assertStringContainsString(
            '<form:mzdaZuctovana>48000</form:mzdaZuctovana><form:mzdaRozpad><form:tarif>40000</form:tarif>'
                . '<form:odmenyPravidelne>3000</form:odmenyPravidelne><form:odmenyNepravidelne>5000</form:odmenyNepravidelne>',
            $xml,
        );
        self::assertStringContainsString('<form:zuctovanoCelkem>48000</form:zuctovanoCelkem>', $xml);
        self::assertSame(3, substr_count($xml, '<form:vydelekPrumernyHod>750.00</form:vydelekPrumernyHod>'));
        self::assertSame(1, substr_count($xml, '<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>'));
        self::assertSame(2, substr_count($xml, '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>'));
        self::assertSame(3, substr_count($xml, '<form:kodObce>554782</form:kodObce><form:kodStatu>CZ</form:kodStatu>'));
        // Duben není prosinec: roční údaje zaměstnavatele se v roce 2026 nehlásí.
        self::assertStringNotContainsString('zamestnavatelUdajeRok', $xml);
        // Souhrn: 3 363 + 1 800 + 720 = 5 883.
        self::assertStringContainsString('<so:danZalohaPoSleve>5883</so:danZalohaPoSleve>', $xml);
    }

    /**
     * Leden 2027 téže firmy po prosincovém běhu: výpočet podle sady 2027
     * (nové rozhodné příjmy 12 500 a 5 000 Kč, § 6 odst. 4 ZDP od 2027
     * jako samostatný základ zálohy), nový rok kumulací. JMHZ za 01/2027
     * se zastaví srozumitelnou blokací nevyhlášeného číselníku obcí 2027.
     */
    public function testJanuary2027AfterDecemberUsesRuleset2027(): void
    {
        $firm = $this->smallFirm('2026-12-01');
        $this->pay($firm, '2026-12-01', 'dec');
        $december = $this->runPayrollMonth('2026-12-01', '2027-01-12', $this->officeId, 'prechod-12');
        self::assertNotNull($december['approved'], CanonicalJson::encode($december['blockers']));

        $this->calendar->today = '2027-02-05';
        foreach ($firm as $person) {
            $workdays = self::workdays('2027-01');
            $this->publishShifts($person['employment_id'], $workdays);
            $approved = $this->approveTimeMonth(
                $person['employment_id'],
                '2027-01',
                $workdays,
                dailyMinutes: $person['daily_minutes'],
            );
            self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka 01/2027. ' . (string) $approved->getBody());
            $this->approvedAverage($person['employment_id'], '2027-01-01');
            $this->annualSettlementNotRequested($person['employee_id']);
        }
        $openings = $this->container->get(PayrollOpeningBalanceService::class);
        self::assertInstanceOf(PayrollOpeningBalanceService::class, $openings);
        $openings->seedProvableZeroOpenings($this->supplierId, '2027-01-01', [], $this->actors[0]);
        $this->pay($firm, '2027-01-01', 'jan');

        $readiness = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class)
            ->inspect($this->supplierId, '2027-01-01', '2027-02-12', $this->officeId);
        $findings = array_column($readiness['findings'], null, 'code');
        // Kontrola před během řekne už teď, že hlášení za 01/2027 nepůjde
        // zmrazit, jednou větou za firmu, bez odkazu a bez zastavení běhu.
        $codebooks = $findings['jmhz_workplace_codebooks_not_published'] ?? null;
        self::assertIsArray($codebooks, CanonicalJson::encode($readiness['findings']));
        self::assertSame('info', $codebooks['severity']);
        // Odkaz na záložku JMHZ v Mzdových podáních (route payroll-submissions-tab).
        self::assertSame('/payroll/submissions/jmhz', $codebooks['remediation_path']);
        self::assertSame([], $codebooks['bulk_actions']);
        self::assertSame(1, $codebooks['entity_total']);
        self::assertStringContainsString('ještě nebyl vyhlášen', $codebooks['message']);
        self::assertTrue($readiness['ready']);
        // Souhrn nepodepsaných prohlášení: tlačítko hromadného podpisu nese
        // počet osob, kterým podpis zapíše (DPP a DPČ), ne počet validací.
        $declarations = $findings['tax_declaration_not_signed_summary'];
        self::assertSame(
            [['count' => 2, 'employee_ids' => [$firm['dpp']['employee_id'], $firm['dpc']['employee_id']], 'kind' => 'declaration_signed']],
            array_map(static function (array $action): array {
                ksort($action);
                sort($action['employee_ids']);

                return $action;
            }, $declarations['bulk_actions']),
        );
        self::assertStringContainsString('od roku 2027 samostatný základ zálohy', $declarations['message']);
        $run = $this->runPayrollMonth('2027-01-01', '2027-02-12', $this->officeId, 'prechod-01');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet 01/2027. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování 01/2027. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $people = $this->peopleByEmployee($run['approved']->revision['result_snapshot']['statutory']['people'] ?? []);
        /*
         * HPP 40 000 Kč (sada 2027): sazby, sleva na poplatníka 2 570
         * a zvýhodnění 1 267 se proti 2026 nemění => stejné částky jako
         * v prosinci, čistá 33 197.
         */
        $this->assertPerson($people[$firm['hpp']['employee_id']], 'cz-payroll-2027.income-tax.v1', 284_000, 180_000, 216_300, 0, 126_700, 3_319_700);
        /*
         * DPP 12 000 Kč: od 1. 1. 2027 je rozhodný příjem 12 500 Kč (sdělení
         * MPSV č. 184/2026 Sb.) => bez účasti na pojištění, SP i ZP 0. Bez
         * prohlášení pod hranicí: § 6 odst. 4 ZDP ve znění z. č. 360/2025 Sb.
         * = samostatný základ zálohy 15 % × 12 000 = 1 800. Čistá 10 200.
         */
        $this->assertPerson($people[$firm['dpp']['employee_id']], 'cz-payroll-2027.income-tax.v1', 0, 0, 0, 180_000, 0, 1_020_000);
        self::assertSame(1_250_000, $people[$firm['dpp']['employee_id']]['social_insurance']['relationships'][0]['participation']['threshold_minor_units']);
        /*
         * DPČ 4 800 Kč: rozhodný příjem 2027 je 5 000 Kč (sdělení MPSV
         * č. 186/2026 Sb.) => bez účasti; samostatný základ 15 % × 4 800 =
         * 720. Čistá 4 080. Prosinec téhož člověka byl pojištěný (4 500).
         */
        $this->assertPerson($people[$firm['dpc']['employee_id']], 'cz-payroll-2027.income-tax.v1', 0, 0, 0, 72_000, 0, 408_000);
        self::assertSame(500_000, $people[$firm['dpc']['employee_id']]['social_insurance']['relationships'][0]['participation']['threshold_minor_units']);

        /*
         * JMHZ za 01/2027: číselník obcí a států pro rok 2027 ještě nevyšel.
         * Příprava se zastaví nálezem, který to říká, nenabízí opravu
         * pracoviště (druh nápravy „manual", žádný odkaz) a nic neskončí 500.
         */
        $this->saveEmployerAnnual(2027);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'prechod-01');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('blocked', $preparation['body']['readiness_status']);
        self::assertSame(
            ['jmhz_workplace_codebooks_not_published'],
            array_values(array_unique(array_column($preparation['body']['issues'] ?? [], 'code'))),
            CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('blocked', $tested['body']['status']);
        $blockers = $tested['body']['blockers'];
        /*
         * Vedle číselníku chybí data, která účetní doplnit MŮŽE a má to vědět
         * už teď: plná adresa pracoviště (10550 až 10554) u všech tří vztahů
         * a den podpisu prohlášení (10575) u HPP. Tyhle nálezy vedou odkazem
         * na kartu vztahu, resp. zákonnou evidenci osoby (dřív druh „manual"
         * bez odkazu a s obecnou radou).
         */
        $missing = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => $blocker['code'] === 'jmhz_2027_source_missing',
        ));
        self::assertCount(4, $missing, CanonicalJson::encode($blockers));
        foreach ($missing as $blocker) {
            if ($blocker['attribute_ids'] === ['10575']) {
                self::assertSame('person', $blocker['entity_type']);
                self::assertSame($firm['hpp']['employee_id'], $blocker['entity_id']);
                self::assertSame(['kind' => 'statutory_evidence', 'field' => null], $blocker['remediation']);
                self::assertStringContainsString('den podpisu prohlášení', $blocker['action']);
                continue;
            }
            self::assertSame(['10550', '10551', '10552', '10553', '10554'], $blocker['attribute_ids']);
            self::assertSame(['kind' => 'employment_terms', 'field' => 'jmhz_workplace_house_number'], $blocker['remediation']);
            self::assertStringContainsString('plnou adresu místa výkonu práce', $blocker['action']);
            self::assertSame(
                '/payroll/people?' . http_build_query([
                    'employment' => $blocker['entity_id'],
                    'panel' => 'employment_terms',
                    'field' => 'jmhz_workplace_house_number',
                ]),
                \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerRemediationPath::forBlocker(
                    new \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker(
                        $blocker['code'],
                        $blocker['entity_type'],
                        $blocker['entity_id'],
                        $blocker['attribute_ids'],
                    ),
                    '2027-01-01',
                ),
            );
        }
        $codebooks = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => $blocker['code'] === 'jmhz_workplace_codebooks_not_published',
        ));
        self::assertCount(3, $codebooks, CanonicalJson::encode($blockers));
        self::assertCount(7, $blockers, CanonicalJson::encode($blockers));
        foreach ($codebooks as $blocker) {
            self::assertSame(['kind' => 'manual', 'field' => null], $blocker['remediation']);
            self::assertStringContainsString('nebyl vyhlášen', (string) $blocker['reason']);
            self::assertStringContainsString('po jeho vyhlášení a aktualizaci aplikace', (string) $blocker['action']);
            self::assertStringNotContainsString('Doplňte obec', (string) $blocker['action']);
        }
        /*
         * Hromadné doplnění pracoviště za 01/2027 se SPRÁVNÝM kódem Prahy:
         * dřív 422 „Obec 554782 nebo stát CZ neplatí v číselníku", což
         * radilo opravu správného kódu. Teď věta o nevyhlášeném číselníku.
         * Totéž hromadná úprava podmínek (stejné místo pravdy).
         */
        $employmentIds = array_map(static fn (array $person): int => $person['employment_id'], array_values($firm));
        foreach ([
            fn () => $this->container->get(\MyInvoice\Service\Payroll\PayrollEmploymentWorkplaceBulkFill::class)
                ->apply($this->supplierId, '2027-01-01', '554782', 'CZ', $employmentIds, $this->actors[0], null, null),
            fn () => $this->container->get(\MyInvoice\Service\Payroll\PayrollEmploymentTermsBulkEdit::class)
                ->apply($this->supplierId, '2027-01-01', 'overwrite', [
                    'jmhz_workplace_municipality_code' => '554782',
                    'jmhz_workplace_country_code' => 'CZ',
                ], $employmentIds, $this->actors[0], null, null),
        ] as $bulk) {
            try {
                $bulk();
                self::fail('Hromadné ověření pracoviště za 01/2027 nesmí projít bez číselníku 2027.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('pro období 01/2027 ještě nebyl vyhlášen', $exception->getMessage());
                self::assertStringNotContainsString('neplatí v číselníku', $exception->getMessage());
            }
        }

        // Zmrazení („Odeslat") odmítne verzi 2027 s vysvětlením, ne chybou serveru.
        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertContains($frozen['status'], [409, 422], CanonicalJson::encode($frozen['body']));
        self::assertStringNotContainsString('neumí', CanonicalJson::encode($frozen['body']));
    }

    /**
     * Běh spočítaný dřív, než aplikace měla číselník obcí pro období (leden
     * po vyhlášení číselníku na nový rok), nese ve snímku „neověřeno" i se
     * správnou obcí. Nález pak musí poslat k opravě běhu, ne k opravě obce.
     */
    public function testStaleWorkplaceSnapshotSendsToRunCorrection(): void
    {
        $catalog = $this->container->get(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog::class);
        self::assertInstanceOf(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog::class, $catalog);
        $message = $catalog->workplaceMismatchMessage('554782', 'Hlavní město Praha', '2026-10-01', 'CZ');
        self::assertIsString($message);
        self::assertStringContainsString('Otevřít opravu', $message);
        self::assertStringContainsString('Údaje pracoviště neměňte', $message);
        // Neplatný stát nebo neshodný název zůstávají konkrétní radou k opravě karty.
        self::assertNull($catalog->workplaceMismatchMessage('554782', 'Hlavní město Praha', '2026-10-01', 'ZZ'));
        self::assertStringContainsString(
            'neodpovídá úřednímu názvu',
            (string) $catalog->workplaceMismatchMessage('554782', 'Praha', '2026-10-01', 'CZ'),
        );
        // Bez znalosti státu (starší volající) se chování nemění.
        self::assertNull($catalog->workplaceMismatchMessage('554782', 'Hlavní město Praha', '2026-10-01'));
    }

    /**
     * Rok bez legislativní sady (2028): založení běhu odmítne srozumitelnou
     * větou s rokem a chybějícími oblastmi (422), ne technickou chybou
     * z výpočtu a nikdy výpočtem podle sady 2027.
     */
    public function testYearWithoutRulesetIsRefusedWithExplanation(): void
    {
        $this->calendar->today = '2028-02-05';
        $person = $this->createEmployment($this->officeId, 'Syntetický Budoucí', 1, 'hpp', 'employment', 40, 10_000, true, '2028-01-01');
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'rok-2028', '2028-01-01');
        // Kontrola před během: jediný blokující nález s vysvětlením a odkazem
        // na legislativní pravidla (route payroll-rulesets), ne „obnovte stránku".
        $readiness = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class)
            ->inspect($this->supplierId, '2028-01-01', '2028-02-12', $this->officeId);
        self::assertFalse($readiness['ready']);
        self::assertSame(['ruleset_year_unavailable'], array_column($readiness['findings'], 'code'), CanonicalJson::encode($readiness));
        self::assertSame('blocker', $readiness['findings'][0]['severity']);
        self::assertSame('/payroll/rulesets', $readiness['findings'][0]['remediation_path']);
        self::assertStringContainsString('pro rok 2028 nemá legislativní sadu', $readiness['findings'][0]['message']);

        $action = $this->container->get(\MyInvoice\Action\Payroll\PayrollRunsAction::class);
        self::assertInstanceOf(\MyInvoice\Action\Payroll\PayrollRunsAction::class, $action);
        $response = $action->create(
            $this->request('POST', '/api/payroll/runs')->withParsedBody([
                'period_start' => '2028-01-01',
                'payment_date' => '2028-02-12',
                'office_id' => $this->officeId,
            ]),
            new Response(),
        );
        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $message = (string) ($body['error']['message'] ?? $body['message'] ?? '');
        self::assertStringContainsString('Mzdy za 01/2028 zatím nejde spočítat', $message, (string) $response->getBody());
        self::assertStringContainsString('pro rok 2028 nemá legislativní sadu', $message);
        self::assertStringContainsString('sociální pojištění', $message);
        self::assertStringContainsString('Legislativní pravidla mezd', $message);
        self::assertStringNotContainsString('Expected exactly one', $message);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_runs WHERE supplier_id = ? AND period_start = ?',
            [$this->supplierId, '2028-01-01'],
        ));
    }

    /**
     * Malá firma: HPP 40 h s jedním dítětem a podepsaným prohlášením, DPP
     * a DPČ bez prohlášení.
     *
     * @return array{hpp:array<string,mixed>,dpp:array<string,mixed>,dpc:array<string,mixed>}
     */
    private function smallFirm(string $periodStart): array
    {
        $hpp = $this->hire('Syntetická Rodičová', 'female', '1986-03-04', 'hpp', 'employment', 40, 10_000, true, $periodStart);
        $child = $this->createChild($hpp['employee_id'], 'Syntetické Dítě', '2018-05-06', 1);
        $this->claimChild($hpp['employee_id'], $child, 1);
        $dpp = $this->hire('Syntetický Dohodář', 'male', '1995-07-08', 'dpp', 'dpp', 10, 2_500, false, $periodStart);
        $dpc = $this->hire('Syntetický Činný', 'male', '1979-09-10', 'dpc', 'dpc', 10, 2_500, false, $periodStart);

        return ['hpp' => $hpp, 'dpp' => $dpp, 'dpc' => $dpc];
    }

    /** @param array{hpp:array<string,mixed>,dpp:array<string,mixed>,dpc:array<string,mixed>} $firm */
    private function pay(array $firm, string $periodStart, string $key): void
    {
        $this->createApprovedInput($firm['hpp'], $this->baseComponentId, 4_000_000, "{$key}-hpp", $periodStart);
        $this->createApprovedInput($firm['dpp'], $this->baseComponentId, 1_200_000, "{$key}-dpp", $periodStart);
        $this->createApprovedInput($firm['dpc'], $this->baseComponentId, 480_000, "{$key}-dpc", $periodStart);
    }

    /** @return array<string,mixed> */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        string $employmentType,
        string $relationType,
        int $weeklyHours,
        int $workload,
        bool $declaration,
        string $periodStart,
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weeklyHours,
            $workload,
            $declaration,
            $periodStart,
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
        $period = substr($periodStart, 0, 7);
        $workdays = self::workdays($period);
        $this->publishShifts($person['employment_id'], $workdays);
        $approved = $this->approveTimeMonth($person['employment_id'], $period, $workdays, dailyMinutes: (int) (480 * $workload / 10_000));
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        $this->approvedAverage($person['employment_id'], $periodStart);

        return $person + ['sequence' => $sequence, 'daily_minutes' => (int) (480 * $workload / 10_000)];
    }

    private function annualSettlementNotRequested(int $employeeId): void
    {
        $settlements = $this->container->get(PayrollAnnualSettlementRepository::class);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        $settlements->saveRequest($this->supplierId, $employeeId, 2026, [
            'request_status' => 'not_requested',
            'requested_on' => null,
            'request_evidence_reference' => null,
            'prior_employers' => 'none',
            'prior_documents_received_on' => null,
            'filing_obligation' => 'none',
            'filing_obligation_reason' => null,
            'annual_claims' => 'none',
            'annual_claims_note' => null,
            'note' => null,
        ], null, $this->actors[0]);
    }

    /** Schválený průměr pro čtvrtletí období (rozhodné = předchozí čtvrtletí). */
    private function approvedAverage(int $employmentId, string $periodStart): void
    {
        $year = (int) substr($periodStart, 0, 4);
        $quarter = intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1;
        $decisive = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1)))->modify('-3 months');
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'decisive_from' => $decisive->format('Y-m-d'),
                'decisive_to' => $decisive->modify('+2 months')->modify('last day of this month')->format('Y-m-d'),
                'gross_earnings_minor' => 12_000_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody([
                'row_version' => $average['row_version'],
            ]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 100 + $sequence);
        [$givenName, $familyName] = explode(' ', $name, 2);
        $response = $this->dependants()->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => $birthDate,
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $birthDate,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: vyživovaná osoba. ' . (string) $response->getBody());
        foreach ($this->json($response)['dependants'] as $dependant) {
            if ($dependant['full_name'] === $name) {
                return (int) $dependant['id'];
            }
        }
        self::fail("Vyživovaná osoba {$name} chybí.");
    }

    private function claimChild(int $employeeId, int $dependantId, int $order): void
    {
        $response = $this->dependants()->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")
                ->withParsedBody([
                    'child_order' => $order,
                    'claim_reason' => 'own_household',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:child-claim',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
                    'other_household_caregiver_status' => 'none',
                    'ztp_p' => false,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: nárok na dítě. ' . (string) $response->getBody());
    }

    private function dependants(): PayrollDependantAction
    {
        $action = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $action);

        return $action;
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return array<int,array<string,mixed>>
     */
    private function peopleByEmployee(array $people): array
    {
        $indexed = [];
        foreach ($people as $person) {
            $indexed[(int) substr((string) $person['person_reference'], strlen('employee:'))] = $person;
        }

        return $indexed;
    }

    /** @param array<string,mixed> $person */
    private function assertPerson(
        array $person,
        string $incomeTaxRuleset,
        int $social,
        int $health,
        int $advance,
        int $withholding,
        int $childCredit,
        int $net,
    ): void {
        $actual = [
            'ruleset' => $person['income_tax']['ruleset_id'] ?? null,
            'social' => $person['social_insurance']['employee_contribution_minor_units'] ?? null,
            'health' => $person['health_insurance']['employee_contribution_minor_units'] ?? null,
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'] ?? 0,
            'withholding' => $person['income_tax']['withholding_tax_minor_units'] ?? null,
            'child' => $person['income_tax']['applied_child_credit_minor_units'] ?? null,
            'net' => $person['net_payable_minor_units'] ?? null,
        ];
        self::assertSame([
            'ruleset' => $incomeTaxRuleset,
            'social' => $social,
            'health' => $health,
            'advance' => $advance,
            'withholding' => $withholding,
            'child' => $childCredit,
            'net' => $net,
        ], $actual, CanonicalJson::encode($person));
    }

    private function saveEmployerAnnual(int $year): void
    {
        $annual = $this->container->get(JmhzEmployerAnnualEvidenceService::class);
        self::assertInstanceOf(JmhzEmployerAnnualEvidenceService::class, $annual);
        $annual->save($this->supplierId, $year, [
            'collective_agreement_types' => ['0'],
            'ownership_form' => '1',
            'average_headcount' => '3',
            'average_disabled_headcount' => '0',
        ], $this->actors[0]);
    }

    /** Příprava a test XML (XSD + katalog kontrol); vrací XML bez mezer. */
    private function jmhzSubmissionXml(int $revisionId, string $key): string
    {
        $preparation = $this->prepareJmhz($revisionId, $key);
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $this->lastPreparationId = (int) $preparation['body']['id'];
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue($tested['body']['controls']['submittable'], CanonicalJson::encode($tested['body']['controls']));
        self::assertSame(0, $tested['body']['controls']['counts']['failed'], CanonicalJson::encode($tested['body']['controls']));

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}

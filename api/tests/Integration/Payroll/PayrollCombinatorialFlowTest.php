<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollCombinatorialScenarios;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Brána G4: kombinační scénáře mezd celým tokem.
 *
 * Kombinace druhu vztahu, daňového režimu, účasti na pojištění, nepřítomnosti,
 * svátku, slev, souběhu a začátku a konce vztahu v měsíci, vybrané tak, aby každá
 * dvojice hodnot dvou dimenzí byla aspoň v jedné kombinaci
 * ({@see PayrollCombinatorialScenarios}). Každá kombinace projde zaměstnancem,
 * docházkou, během, přípravou a testovacím sestavením JMHZ a evidenčním listem
 * a ověří invarianty, které platí pro všechny kombinace:
 *
 *  - tok skončí platným hlášením (XSD a kontroly katalogu), nebo konkrétní
 *    blokací s důvodem; výjimka ani neplatné XML nejsou přípustné,
 *  - hrubá mzda je součet složek, čistá mzda sedí na srážky, daň a pojistné
 *    jsou v celých korunách, záloha nebo srážka podle režimu,
 *  - zúčtovaný příjem 10286 a vyměřovací základ ELDP jsou součty složek podle
 *    jejich zacházení, účast na pojištění podle rozhodné částky,
 *  - odpracované a neodpracované hodiny skládají sjednaný fond (10268 + 10275
 *    = 10260), placené neodpracované nepřesahují neodpracované celkem,
 *  - v hlášení není záporná hodnota ani haléře v peněžním údaji,
 *  - evidenční list za rok nese tytéž dny a vyměřovací základ jako hlášení.
 *
 * Plná sada běží lokálně; v CI (proměnná CI) jen prvních
 * {@see PayrollCombinatorialScenarios::CI_LIMIT} kombinací, které pokrývají
 * nejvíc dvojic. Plnou sadu vynutí MYINVOICE_COMBINATORIAL_FULL=1.
 *
 * Syntetická data, transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
#[Group('combinatorial')]
final class PayrollCombinatorialFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';
    private const START_MID = '2026-07-15';
    private const END_MID = '2026-07-17';
    /** Rozhodný příjem DPP a hranice srážkové daně u ní (2026). */
    private const DPP_THRESHOLD = 1_200_000;
    /** Rozhodný příjem zaměstnání malého rozsahu, DPČ a jednatele (2026). */
    private const SMALL_SCALE_THRESHOLD = 450_000;

    /**
     * Blokace, které jsou pro danou kombinaci správnou odpovědí aplikace:
     * kód => proč. Blokace s jiným kódem test shodí jako nečekaný stav.
     *
     * @var array<string,string>
     */
    private const ACCEPTED_BLOCKERS = [];

    /**
     * Kombinace s doloženou, dosud neopravenou vadou: id kombinace => id vady
     * v private/normy/pokryti/OVERENE-DEFEKTY.json. Test je u nich neúplný; když
     * kombinace projde, test spadne, ať se záznam smaže.
     *
     * @var array<string,string>
     */
    private const KNOWN_DEFECTS = [];

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('G4', 'Syntetická účtárna G4', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_G4', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /** @return iterable<string,array{0:array<string,string>,1:int}> */
    public static function combinations(): iterable
    {
        $all = PayrollCombinatorialScenarios::all();
        $full = getenv('MYINVOICE_COMBINATORIAL_FULL') === '1';
        $ci = in_array(strtolower((string) getenv('CI')), ['1', 'true'], true);
        if ($ci && !$full) {
            $all = array_slice($all, 0, PayrollCombinatorialScenarios::CI_LIMIT);
        }
        foreach ($all as $index => $combination) {
            yield sprintf('%02d %s', $index, PayrollCombinatorialScenarios::id($combination)) => [$combination, $index];
        }
    }

    /** @param array<string,string> $c */
    #[DataProvider('combinations')]
    public function testCombinationReachesValidSubmissionOrConcreteBlocker(array $c, int $index): void
    {
        $id = PayrollCombinatorialScenarios::id($c);
        $outcome = $this->flow($c, $index);
        if ($outcome['status'] === 'blocked') {
            self::assertNotSame('', trim($outcome['reason']), "{$id}: blokace bez důvodu.");
            self::assertArrayHasKey(
                $outcome['code'],
                self::ACCEPTED_BLOCKERS,
                "{$id}: nečekaná blokace v kroku {$outcome['step']}: {$outcome['code']} {$outcome['reason']}",
            );
            return;
        }
        $violations = $outcome['violations'];
        if (isset(self::KNOWN_DEFECTS[$id])) {
            if ($violations === []) {
                self::fail("{$id}: vada " . self::KNOWN_DEFECTS[$id] . ' už nenastává, smažte ji z KNOWN_DEFECTS.');
            }
            self::markTestIncomplete(self::KNOWN_DEFECTS[$id] . ': ' . implode(' | ', $violations));
        }
        self::assertSame([], $violations, "{$id}: porušené invarianty.");
    }

    /**
     * @param array<string,string> $c
     * @return array{status:string,step:string,code:string,reason:string,violations:list<string>}
     */
    private function flow(array $c, int $index): array
    {
        $relation = $c['relation'];
        $declaration = in_array($c['tax'], ['declaration', 'nonresident'], true);
        $halfTime = $relation === 'hpp' && ($c['insurance'] === 'below' || $c['credit'] === 'employer_7a');
        $female = in_array($c['absence'], ['ppm', 'parental'], true) || $index % 2 === 1;
        $birthDate = match ($c['credit']) {
            'pensioner' => '1958-06-21',
            'employer_7a' => '1965-04-04',
            default => sprintf('19%02d-%02d-%02d', 80 + $index % 15, 1 + $index % 12, 1 + $index % 27),
        };
        [$employmentType, $relationType, $activity, $taxpayerType] = match ($relation) {
            'hpp' => ['hpp', 'employment', null, 'employee'],
            'dpc' => ['dpc', 'dpc', null, 'employee'],
            'dpp' => ['dpp', 'dpp', null, 'employee'],
            'statutory' => ['statutory_body', 'statutory_body', 'S', 'managing_partner'],
        };
        [$weekly, $workload] = match (true) {
            $relation === 'dpc', $relation === 'dpp' => [10, 2_500],
            $halfTime => [20, 5_000],
            default => [40, 10_000],
        };
        $topUp = ($halfTime || $c['absence'] === 'unpaid_leave') ? 'employee' : 'employer_obstacle_verified';

        $sequence = ++$this->sequence;
        $name = sprintf('%s G%02d', $female ? 'Syntetická' : 'Syntetický', $index);
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
            taxpayerType: $taxpayerType,
            taxpayerCreditTaxpayer: in_array($c['credit'], ['taxpayer', 'children'], true),
            // Srážku u DPČ a jednatele nespouští přepis režimu (ten aplikace odmítne),
            // ale prohlášení plátce, že sjednaná odměna nedosahuje rozhodné částky.
            otherWithholdingEligibility: !$declaration && in_array($relation, ['dpc', 'statutory'], true)
                ? ($c['tax'] === 'withholding' ? 'eligible' : 'ineligible')
                : null,
            socialDiscountStatus: $c['credit'] === 'pensioner' ? 'verified' : 'not_claimed',
            healthTopUpResponsibility: $topUp,
        );
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, $activity, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $female ? 'female' : 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $sequence),
        ]);
        $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::syntheticPpv($sequence));
        if ($halfTime) {
            $this->publishHalfShifts($person['employment_id'], self::workdays(self::PERIOD));
        } else {
            $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        }
        $averageId = (int) $this->createApprovedAverage($person['employment_id'], 3)['id'];
        if (in_array($c['absence'], ['dpn_beyond', 'ppm'], true)) {
            // Nepřítomnost od června: náhrada se počítá z průměru druhého čtvrtletí.
            $averageId = (int) $this->createApprovedAverage($person['employment_id'], 2)['id'];
        }
        if ($c['tax'] === 'nonresident') {
            $this->makeNonResident($person['employee_id'], $c['credit'] === 'pensioner' ? 'verified' : 'not_claimed', $topUp);
        }
        if ($c['credit'] === 'children') {
            for ($order = 1; $order <= 1 + $index % 3; $order++) {
                $dependant = $this->createChild($person['employee_id'], 'Syntetické ' . ['', 'Prvé', 'Druhé', 'Třetí'][$order],sprintf('20%02d-0%d-0%d', 12 + $order * 2, $order, $order), 10 * $index + $order);
                $this->claimChild($person['employee_id'], $dependant, $order);
            }
        }
        $start = $c['span'] === 'start_mid' ? self::START_MID : self::PERIOD_START;
        $this->startOn($person['employment_id'], $start);
        if ($c['credit'] === 'employer_7a') {
            $this->employerPartTimeDiscount($person['employment_id']);
        }

        $agreement = null;
        if ($c['concurrency'] === 'hpp_dpp') {
            $agreement = $this->hireAgreement($person, $declaration);
        }
        if ($c['span'] === 'end_mid') {
            $ended = $this->endOn($person['employment_id']);
            if ($ended !== null) {
                return $ended;
            }
        }

        [$absenceFrom, $absenceTo] = self::absenceWindow($c);
        $absenceDays = [];
        if ($absenceFrom !== null) {
            $blocked = $this->absence($c['absence'], $person['employment_id'], $absenceFrom, $absenceTo, $averageId);
            if ($blocked !== null) {
                return $blocked;
            }
            $absenceDays = self::dateRange($absenceFrom, $absenceTo);
        }

        $end = $c['span'] === 'end_mid' ? self::END_MID : '2026-07-31';
        $inSpan = static fn (string $day): bool => $day >= $start && $day <= $end && !in_array($day, $absenceDays, true);
        $worked = match ($relation) {
            'hpp' => array_values(array_filter(self::workdays(self::PERIOD), $inSpan)),
            'dpc', 'dpp' => array_values(array_filter(['2026-07-04', '2026-07-11', '2026-07-18', '2026-07-25'], $inSpan)),
            'statutory' => [],
        };
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, $worked, dailyMinutes: $halfTime ? 240 : ($relation === 'hpp' ? 480 : 240));
        if ($time->getStatusCode() !== 200) {
            return self::blocked('time_month', $time);
        }
        $amount = PayrollCombinatorialScenarios::amountMinor($relation, $c['insurance']);
        $this->createApprovedInput($person, $this->baseComponentId, $amount, 'base-' . $person['employment_id'], self::PERIOD_START);
        if ($agreement !== null) {
            $time = $this->approveTimeMonth($agreement['employment_id'], self::PERIOD, ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
            if ($time->getStatusCode() !== 200) {
                return self::blocked('time_month_agreement', $time);
            }
            $this->createApprovedInput($agreement, $this->baseComponentId, 500_000, 'base-' . $agreement['employment_id'], self::PERIOD_START);
        }

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "g4-{$index}");
        if ($run['blockers'] !== [] || $run['warnings'] !== []) {
            $first = $run['blockers'][0] ?? $run['warnings'][0];
            return ['status' => 'blocked', 'step' => $run['blockers'] !== [] ? 'run' : 'run_warning', 'code' => (string) $first['code'],
                'reason' => CanonicalJson::encode(array_merge($run['blockers'], $run['warnings'])), 'violations' => []];
        }
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $preparation = $this->prepareJmhz($revisionId, "g4-{$index}");
        if ($preparation['status'] !== 201 || ($preparation['body']['readiness_status'] ?? null) !== 'source_ready') {
            $issues = $preparation['body']['issues'] ?? [$preparation['body']];
            return ['status' => 'blocked', 'step' => 'preparation', 'code' => (string) ($issues[0]['code'] ?? $preparation['body']['error'] ?? 'preparation'),
                'reason' => CanonicalJson::encode($issues), 'violations' => []];
        }
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Sestavení XML: ' . CanonicalJson::encode($tested['body']));
        if (($tested['body']['status'] ?? null) !== 'dry_run_valid') {
            $blockers = $tested['body']['blockers'] ?? [];
            if ($blockers !== []) {
                return ['status' => 'blocked', 'step' => 'dry_run', 'code' => (string) ($blockers[0]['code'] ?? 'dry_run'),
                    'reason' => CanonicalJson::encode($blockers), 'violations' => []];
            }
            // Neplatné XML nebo nesplněná kontrola katalogu bez blokace = vada generátoru.
            return ['status' => 'checked', 'step' => 'dry_run', 'code' => '', 'reason' => '',
                'violations' => ['hlášení neprošlo XSD nebo kontrolami: ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body'])]];
        }
        $xml = (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));

        $violations = $this->invariants($c, $revisionId, $xml, $person, $agreement, $amount);
        array_push($violations, ...$this->eldpInvariants($c, $person, $xml, $index));

        return ['status' => 'checked', 'step' => 'invariants', 'code' => '', 'reason' => '', 'violations' => $violations];
    }

    /**
     * @param array<string,string> $c
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param array{employee_id:int,employment_id:int,name:string}|null $agreement
     * @return list<string>
     */
    private function invariants(array $c, int $revisionId, string $xml, array $person, ?array $agreement, int $amount): array
    {
        $v = [];
        $result = $this->personResult($revisionId, 'net_pay', $person['employee_id']);
        if ($result === null) {
            return ['běh nemá výsledek čisté mzdy osoby'];
        }
        $r = [];
        foreach ([
            'cash_income', 'non_cash_income', 'employee_social', 'employee_health', 'advance_tax', 'withholding_tax',
            'tax_bonus', 'correction', 'annual_settlement', 'deducted', 'net_payable',
        ] as $field) {
            $r["{$field}_minor"] = (int) ($result["{$field}_minor_units"] ?? 0);
        }

        // Hrubá = Σ složek všech vztahů osoby.
        $components = $this->components($revisionId, $person['employee_id']);
        $sum = static fn (string $field, string $value): int => array_sum(array_map(
            static fn (array $row): int => ($row['snapshot'][$field] ?? null) === $value ? $row['amount'] : 0,
            $components,
        ));
        $all = array_sum(array_column($components, 'amount'));
        if ($r['cash_income_minor'] + $r['non_cash_income_minor'] !== $all) {
            $v[] = "hrubá {$r['cash_income_minor']}+{$r['non_cash_income_minor']} ≠ Σ složek {$all}";
        }
        if (!in_array($amount, array_column($components, 'amount'), true)) {
            $v[] = "základní mzda {$amount} chybí mezi složkami běhu";
        }
        $expectedNet = $r['cash_income_minor'] - $r['employee_social_minor'] - $r['employee_health_minor'] - $r['advance_tax_minor']
            - $r['withholding_tax_minor'] + $r['tax_bonus_minor'] + $r['correction_minor'] + $r['annual_settlement_minor'] - $r['deducted_minor'];
        if ($expectedNet !== $r['net_payable_minor']) {
            $v[] = "k výplatě {$r['net_payable_minor']} ≠ hrubá - srážky {$expectedNet}";
        }
        foreach (['employee_social_minor', 'employee_health_minor', 'advance_tax_minor', 'withholding_tax_minor', 'tax_bonus_minor'] as $field) {
            if ($r[$field] % 100 !== 0) {
                $v[] = "{$field} {$r[$field]} není v celých korunách";
            }
        }

        // Rozhodný příjem vztahu = složky zahrnuté do vyměřovacího základu, tedy
        // i náhrada za dovolenou nebo překážku, ne jen sjednaná odměna.
        $income = static fn (array $relation): int => array_sum(array_map(
            static fn (array $row): int => $row['employment_id'] === $relation['employment_id']
                && ($row['snapshot']['social_treatment'] ?? null) === 'included' ? $row['amount'] : 0,
            $components,
        ));
        $primaryIncome = $income($person);

        // Záloha nebo srážka podle režimu.
        $declaration = in_array($c['tax'], ['declaration', 'nonresident'], true);
        $primaryWithheld = !$declaration && match ($c['relation']) {
            'dpp' => $primaryIncome < self::DPP_THRESHOLD,
            'dpc', 'statutory' => $c['tax'] === 'withholding' && $primaryIncome < self::SMALL_SCALE_THRESHOLD,
            'hpp' => false,
        };
        $agreementWithheld = $agreement !== null && !$declaration && $income($agreement) < self::DPP_THRESHOLD;
        $expectWithholding = $primaryWithheld || $agreementWithheld;
        if ($expectWithholding !== ($r['withholding_tax_minor'] > 0 || str_contains($xml, '<form:zvlastniSazbaDane>'))) {
            $v[] = 'srážková daň ' . ($expectWithholding ? 'chybí' : 'je navíc') . " (srážka {$r['withholding_tax_minor']})";
        }
        if ($primaryWithheld && $agreement === null && $r['advance_tax_minor'] > 0) {
            $v[] = "záloha {$r['advance_tax_minor']} u příjmu zdaněného srážkou";
        }
        if (preg_match_all('#<form:zvlastniSazbaDane><form:zakladDane>(\d+)</form:zakladDane><form:srazenaDan>(\d+)</form:srazenaDan>#', $xml, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as [, $base, $tax]) {
                if ((int) $tax !== intdiv((int) $base * 15, 100)) {
                    $v[] = "srážka {$tax} ≠ 15 % ze základu {$base}";
                }
            }
        }
        if (preg_match('#<form:zalohaNaDan><form:zakladDane>(\d+)</form:zakladDane><form:vypoctenaZaloha>(\d+)</form:vypoctenaZaloha><form:danZalohaPoSleve>(\d+)</form:danZalohaPoSleve>#', $xml, $m) === 1) {
            [, $base, $computed, $after] = array_map('intval', $m);
            if ($computed !== (int) ceil($base * 0.15 - 1e-9)) {
                $v[] = "vypočtená záloha {$computed} ≠ 15 % ze základu {$base}";
            }
            if ($after > $computed) {
                $v[] = "záloha po slevě {$after} > vypočtená {$computed}";
            }
            if (!$declaration && $after !== $computed) {
                $v[] = "bez prohlášení sleva na záloze ({$computed} → {$after})";
            }
        }
        if ($r['tax_bonus_minor'] > 0 && $c['credit'] !== 'children') {
            $v[] = "daňový bonus {$r['tax_bonus_minor']} bez nároku na dítě";
        }

        // 10286 = Σ složek zahrnutých do hlášení (osoba).
        $jmhzIncluded = $sum('jmhz_treatment', 'included');
        if (preg_match('#<form:zuctovanoCelkem>(\d+)</form:zuctovanoCelkem>#', $xml, $m) === 1) {
            if ((int) $m[1] !== intdiv($jmhzIncluded, 100)) {
                $v[] = "10286 {$m[1]} ≠ Σ složek v hlášení " . ($jmhzIncluded / 100);
            }
        } elseif ($jmhzIncluded > 0) {
            $v[] = '10286 chybí, ačkoli osoba má příjem v hlášení';
        }

        // Formuláře vztahů: pojištění, fond, záporné hodnoty a haléře.
        $forms = $this->forms($xml);
        foreach ([[$person, $c['relation']], [$agreement, 'dpp']] as [$relation, $kind]) {
            if ($relation === null) {
                continue;
            }
            $form = $forms[self::syntheticPpvOf($relation)] ?? null;
            if ($form === null) {
                $v[] = "chybí formulář vztahu {$kind}";
                continue;
            }
            $insured = match ($kind) {
                'hpp' => true,
                'dpp' => $income($relation) >= self::DPP_THRESHOLD,
                default => $income($relation) >= self::SMALL_SCALE_THRESHOLD,
            };
            $hasCode = str_contains($form, '<form:kod>');
            $pensioner = $c['credit'] === 'pensioner' && $relation === $person;
            if ($insured && !$hasCode && !$pensioner) {
                $v[] = "{$kind}: pojištěný vztah bez kódu ELDP";
            }
            if (!$insured && $hasCode) {
                $v[] = "{$kind}: nepojištěný vztah s kódem ELDP";
            }
            if ($insured && !$pensioner && preg_match('#<form:vymerovaciZaklad>(\d+)</form:vymerovaciZaklad>#', $form, $m) === 1) {
                $social = array_sum(array_map(
                    static fn (array $row): int => $row['employment_id'] === $relation['employment_id']
                        && ($row['snapshot']['social_treatment'] ?? null) === 'included' ? $row['amount'] : 0,
                    $components,
                ));
                if ((int) $m[1] !== (int) ceil($social / 100)) {
                    $v[] = "{$kind}: vyměřovací základ ELDP {$m[1]} ≠ Σ složek s pojistným " . ($social / 100);
                }
            }
            $worked = self::hours($form, 'odpracovaneHodiny');
            $unworked = self::number($form, 'hodinyNeodpracCelkem');
            $paidUnworked = self::number($form, 'hodinyNeodpracNahrada');
            $agreed = self::number($form, 'sjednanyFond');
            if ($kind === 'hpp' && $agreed !== null && abs(($worked ?? 0.0) + ($unworked ?? 0.0) - $agreed) > 0.0005) {
                $v[] = sprintf('%s: 10268 %.3f + 10275 %.3f ≠ 10260 %.3f', $kind, $worked ?? 0, $unworked ?? 0, $agreed);
            }
            if ($paidUnworked !== null && $paidUnworked - ($unworked ?? 0.0) > 0.0005) {
                $v[] = sprintf('%s: placené neodpracované 10276 %.3f > 10275 %.3f', $kind, $paidUnworked, $unworked ?? 0);
            }
        }
        if (preg_match_all('#<([\w:]+)>(-[\d.]+)</\1>#', $xml, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as [, $element, $value]) {
                $v[] = "záporná hodnota {$element} = {$value}";
            }
        }
        $money = '(?:zuctovano|osvobozeno|zakladDane|Zaloha|srazenaDan|danBonus|Sleva|sleva|mzda|Mzda|pojisteni|Pojisteni|vymerovaciZaklad|castka|Castka|pojistne|prekazkyZamest|dovolena|mzdyZuctovane|tarif|odmeny)';
        if (preg_match_all('#<form:(\w*' . $money . '\w*)>(\d+\.\d+)</form:\1>#', $xml, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as [, $element, $value]) {
                $v[] = "haléře v peněžním údaji {$element} = {$value}";
            }
        }

        return $v;
    }

    /**
     * Evidenční list za rok proti hlášení: tytéž dny pojištění, kód a vyměřovací základ.
     *
     * @param array<string,string> $c
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return list<string>
     */
    private function eldpInvariants(array $c, array $person, string $xml, int $index): array
    {
        $form = $this->forms($xml)[self::syntheticPpvOf($person)] ?? '';
        if (!str_contains($form, '<form:kod>')) {
            return [];
        }
        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        try {
            $service->prepare($this->supplierId, $person['employment_id'], 2026, 'test', [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'requested_by_authority' => true,
                'authority_request_received_on' => '2026-08-20',
                'note' => 'Syntetická výzva G4.',
            ], "g4-eldp-{$index}", $this->actors[0]);
        } catch (EldpValidationException $exception) {
            return ["ELDP odmítnut ({$exception->validationCode}) u vztahu, který hlášení vede jako pojištěný: "
                . mb_substr(CanonicalJson::encode($exception->blockers), 0, 600)];
        }
        $statement = $service->statement($this->supplierId, 'test', $person['employment_id'], 2026);
        $sections = $statement['payload']['eldp_sections'] ?? [];
        $days = array_sum(array_map(static fn (array $s): int => (int) ($s['days'] ?? $s['insurance_days'] ?? 0), $sections));
        $base = array_sum(array_map(static fn (array $s): int => (int) ($s['assessment_base_czk'] ?? 0), $sections));
        $v = [];
        preg_match_all('#<form:kod>([^<]+)</form:kod>.*?<form:pocetDnu>(\d+)</form:pocetDnu>(?:<form:vymerovaciZaklad>(\d+)</form:vymerovaciZaklad>)?#', $form, $m, PREG_SET_ORDER);
        $jmhzDays = array_sum(array_map(static fn (array $row): int => (int) $row[2], $m));
        $jmhzBase = array_sum(array_map(static fn (array $row): int => (int) ($row[3] ?? 0), $m));
        $jmhzCodes = array_values(array_unique(array_column($m, 1)));
        $eldpCodes = array_values(array_unique(array_map(static fn (array $s): string => (string) ($s['code'] ?? ''), $sections)));
        if ($days !== $jmhzDays) {
            $v[] = "ELDP {$days} dnů ≠ hlášení {$jmhzDays}";
        }
        if ($base !== $jmhzBase) {
            $v[] = "ELDP vyměřovací základ {$base} ≠ hlášení {$jmhzBase}";
        }
        if ($eldpCodes !== $jmhzCodes) {
            $v[] = 'ELDP kódy ' . implode(',', $eldpCodes) . ' ≠ hlášení ' . implode(',', $jmhzCodes);
        }

        return $v;
    }

    /** @return ?array<string,mixed> výsledek zákonného výpočtu osoby v revizi */
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

    /**
     * Složky běhu osoby se zacházením ze snímku.
     *
     * @return list<array{employment_id:int,amount:int,snapshot:array<string,mixed>}>
     */
    private function components(int $revisionId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.employment_id, input.amount_minor, input.component_snapshot_json
               FROM payroll_inputs input
              WHERE input.supplier_id = ? AND input.employee_id = ? AND input.period_start = ?
                AND input.status IN ("approved", "locked")',
        );
        $stmt->execute([$this->supplierId, $employeeId, self::PERIOD_START]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'amount' => (int) $row['amount_minor'],
                'snapshot' => json_decode((string) $row['component_snapshot_json'], true) ?: [],
            ];
        }

        return $rows;
    }

    /** @return array<string,string> identifikátor PPV => formulář */
    private function forms(string $xml): array
    {
        $forms = [];
        foreach (explode('</formularOsoby>', $xml) as $chunk) {
            if (preg_match('#<form:idPpv>(\d+)</form:idPpv>#', $chunk, $m) === 1) {
                $forms[$m[1]] = $chunk;
            }
        }

        return $forms;
    }

    private static function number(string $form, string $element): ?float
    {
        return preg_match('#<form:' . $element . '>(?:<form:pocet>)?([\d.]+)<#', $form, $m) === 1 ? (float) $m[1] : null;
    }

    private static function hours(string $form, string $element): ?float
    {
        return preg_match('#<form:' . $element . '><form:pocet>([\d.]+)</form:pocet>#', $form, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * Okno nepřítomnosti v červenci 2026 (svátek 6. 7.): uvnitř okna, nebo mimo něj,
     * a vždy uvnitř trvání vztahu.
     *
     * @param array<string,string> $c
     * @return array{0:?string,1:?string}
     */
    private static function absenceWindow(array $c): array
    {
        $inside = $c['holiday'] === 'inside';
        $late = $c['span'] === 'start_mid';

        return match ($c['absence']) {
            'none' => [null, null],
            'dpn_beyond' => ['2026-06-10', $inside ? '2026-07-10' : '2026-07-03'],
            // Nejdřív osm týdnů před očekávaným porodem 20. 8.
            'ppm' => ['2026-06-26', '2026-12-31'],
            'parental' => [$inside ? '2026-07-01' : '2026-07-08', '2026-07-31'],
            'paid_obstacle' => $inside ? ['2026-07-03', '2026-07-07'] : ($late ? ['2026-07-20', '2026-07-20'] : ['2026-07-08', '2026-07-08']),
            default => $inside ? ['2026-07-02', '2026-07-08'] : ($late ? ['2026-07-20', '2026-07-22'] : ['2026-07-08', '2026-07-10']),
        };
    }

    /** @return ?array{status:string,step:string,code:string,reason:string,violations:list<string>} */
    private function absence(string $kind, int $employmentId, string $from, string $to, int $averageId): ?array
    {
        [$type, $extra, $decision] = match ($kind) {
            'vacation' => ['vacation', [], []],
            'dpn_window', 'dpn_beyond' => ['dpn', [], [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]],
            'ocr' => ['ocr', [], []],
            'ppm' => ['ppm', ['expected_childbirth_date' => '2026-08-20'], []],
            'parental' => ['parental', [], []],
            'unpaid_leave' => ['unpaid_leave', [], []],
            'paid_obstacle' => ['employee_obstacle', ['obstacle_kind' => 'medical_examination'], []],
        };
        $created = $this->requestAbsence($employmentId, $type, $from, $to, $averageId, $extra);
        if ($created->getStatusCode() !== 201) {
            return self::blocked('absence', $created);
        }
        $absence = $this->json($created)['absence'];
        $approved = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($decision + [
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );

        return $approved->getStatusCode() === 200 ? null : self::blocked('absence_decision', $approved);
    }

    /** @return array{status:string,step:string,code:string,reason:string,violations:list<string>} */
    private static function blocked(string $step, ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $code = is_array($decoded) ? (string) ($decoded['code'] ?? $decoded['error_code'] ?? $decoded['error'] ?? "http_{$response->getStatusCode()}") : "http_{$response->getStatusCode()}";

        return ['status' => 'blocked', 'step' => $step, 'code' => $code, 'reason' => $body, 'violations' => []];
    }

    /**
     * Čtyřhodinové směny polovičního úvazku; z nich se počítají hodiny nepřítomnosti.
     *
     * @param list<string> $dates
     */
    private function publishHalfShifts(int $employmentId, array $dates): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", ?, NOW())',
        );
        foreach ($dates as $date) {
            $statement->execute([$this->supplierId, $employmentId, "g4-{$employmentId}-{$date}",
                "{$date} 06:00:00", "{$date} 10:00:00", $this->actors[0]]);
        }
    }

    /** Vztah i jeho podmínky začínají zadaným dnem. */
    private function startOn(int $employmentId, string $start): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$start, $start, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$start, $start, $start, $this->supplierId, $employmentId]);
    }

    /**
     * Skončení vztahu výpovědí zaměstnance 17. 7.
     *
     * @return ?array{status:string,step:string,code:string,reason:string,violations:list<string>}
     */
    private function endOn(int $employmentId): ?array
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]);
        $employments->transition($this->supplierId, $employmentId, 'ended', $version, self::END_MID, null, $this->actors[0], null, null);
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $termination->save($this->supplierId, $employmentId, ['termination_method' => 'employee_notice', 'legal_ground' => 'none'], $this->actors[0]);

        return null;
    }

    /** Sleva zaměstnavatele § 7a: věk nad 55 let, přijatý záměr OZUSPOJ od 1. 7. */
    private function employerPartTimeDiscount(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET social_part_time_discount_reason = "age_55_plus"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "112") ON DUPLICATE KEY UPDATE social_security_office_code = "112"',
        )->execute([$this->supplierId, $this->officeId]);
        $intents = $this->container->get(PayrollDiscountIntentRepository::class);
        self::assertInstanceOf(PayrollDiscountIntentRepository::class, $intents);
        $service = new OzuspojIntentService(
            $intents,
            new OzuspojDeadlinePolicy(),
            new OzuspojClaimDeadlinePolicy(),
            new class implements \Psr\Clock\ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('Europe/Prague'));
                }
            },
        );
        $created = $service->create($this->supplierId, 'production', $employmentId, self::PERIOD_START, '2026-06-10', $this->actors[0]);
        $row = $intents->find($this->supplierId, 'production', (int) $created['id']);
        $intents->update($this->supplierId, 'production', (int) $created['id'], (int) $row['row_version'],
            ['status' => 'accepted', 'accepted_on' => '2026-06-20']);
    }

    /**
     * Dohoda o provedení práce téže osoby (souběh), od 1. 7.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, bool $declaration): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            'dpp',
            'dpp',
            10,
            2_500,
            $declaration,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, self::syntheticPpv($sequence));
        $this->createApprovedAverage($agreement['employment_id'], 3);
        $this->startOn($agreement['employment_id'], self::PERIOD_START);

        return $agreement;
    }

    private static function syntheticPpv(int $sequence): string
    {
        return sprintf('4%020d', $sequence);
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $relation */
    private function syntheticPpvOf(array $relation): string
    {
        // Kód vztahu sdíleného toku je FLOW-{pořadí}.
        $code = (string) $this->scalar(
            'SELECT code FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $relation['employment_id']],
        );

        return self::syntheticPpv((int) substr($code, 5));
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 200 + $sequence);
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
        self::assertSame(200, $response->getStatusCode(), 'Vyživovaná osoba: ' . (string) $response->getBody());
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
        self::assertSame(200, $response->getStatusCode(), 'Nárok na dítě: ' . (string) $response->getBody());
    }

    private function dependants(): PayrollDependantAction
    {
        $action = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $action);

        return $action;
    }

    /** Daňový nerezident se slovenskou rezidencí; prohlášení zůstává podepsané. */
    private function makeNonResident(int $employeeId, string $socialDiscount, string $topUp): void
    {
        $evidence = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(PayrollPersonStatutoryEvidenceRepository::class, $evidence);
        $payload = $this->statutoryEvidence(self::PERIOD_START, true, true, $this->createHealthEvidenceDocument(900 + $employeeId), $socialDiscount, $topUp);
        $payload['sections']['tax_residences'] = [[
            'residence' => 'non-resident',
            'country_code' => 'SK',
            'evidence_reference' => 'document:synthetic-tax-residence-sk',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];
        $evidence->save($this->supplierId, $employeeId, $payload, '2026-07-31', $this->actors[0], null, 'g4-nonresident');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Absence\ProbableEarningBulkService;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\PayrollEmploymentWorkplaceBulkFill;
use MyInvoice\Service\Payroll\PayrollHealthInsurerBulkAssignment;
use MyInvoice\Service\Payroll\PayrollStatutoryEvidenceBulkDefaults;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura s brigádníky na DPP: kontrola před během nad hromadou lidí.
 *
 * Firma má 60 dohod, z toho úmyslně vadné skupiny (bez zákonných údajů, bez
 * pojišťovny, bez průměru, vstup v haléřích, bez pracoviště, skončená dohoda
 * ve stavu aktivní, nerozhodnutá žádost o roční zúčtování). Test ověřuje, že
 * každý nález má text, počet, odkaz a (kde to jde) hromadnou opravu, že počet
 * v nálezu sedí s výběrem dialogu hromadné opravy a že předpověď blokací
 * odpovídá tomu, co pak opravdu zastaví výpočet a přípravu JMHZ.
 *
 * Měsíc je záměrně únor 2026, tedy před zákonným startem JMHZ (04/2026):
 * hlášení za něj jde připravit (podává se dodatečně) a kontrola před během
 * se řídí připravitelností hlášení, ne datem startu. Únor navíc nese stav
 * žádosti o roční zúčtování za rok 2025 (10319).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eNavPreflightAgencyTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-02';
    private const PERIOD_START = '2026-02-01';
    private const PAYDAY = '2026-03-13';
    private const WORKED = ['2026-02-07', '2026-02-14', '2026-02-21'];

    private int $officeId;
    private int $componentId;
    /** @var array<string,list<array{employee_id:int,employment_id:int,name:string}>> */
    private array $groups = [];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('NAV', 'Syntetická účtárna agentury', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->componentId = $this->createComponent('ODMENA_DPP_NAV', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->componentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testPreflightOverSixtyAgreementsPredictsWhatBlocksAndBulkDialogsOfferTheSamePeople(): void
    {
        $this->buildAgency();
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $before = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId);
        $findings = $before['findings'];

        // Každý nález: text, počet dotčených a odkaz (na nálezu nebo u každého záznamu).
        foreach ($findings as $finding) {
            $code = $finding['code'];
            self::assertNotSame('', trim($finding['message']), "{$code}: nález bez textu.");
            self::assertGreaterThan(0, $finding['entity_total'], "{$code}: nález bez počtu.");
            self::assertSame($finding['count'], $finding['entity_total'], "{$code}: počet a celkem se rozcházejí.");
            if ($finding['remediation_path'] === null) {
                self::assertNotSame([], $finding['entities'], "{$code}: nález bez odkazu i bez záznamů.");
                foreach ($finding['entities'] as $entity) {
                    self::assertStringStartsWith('/payroll/', (string) ($entity['remediation_path'] ?? ''), "{$code}: záznam bez odkazu.");
                }
            } else {
                self::assertStringStartsWith('/payroll', $finding['remediation_path'], $code);
            }
            foreach ($finding['bulk_actions'] as $action) {
                self::assertContains($action['kind'], PayrollRunReadinessService::BULK_ACTION_KINDS, $code);
                if (isset($action['employee_ids'])) {
                    self::assertSame(count($action['employee_ids']), $action['count'], "{$code}: tlačítko slibuje jiný počet osob, než dostane dialog.");
                }
            }
        }

        $statutoryIds = array_column($this->groups['statutory'], 'employee_id');
        $insurerIds = array_column($this->groups['insurer'], 'employee_id');

        // Haléře: blokace schválení, jeden odkaz na hromadné zaokrouhlení období.
        $halere = self::finding($findings, 'inputs_not_whole_crowns');
        self::assertSame(5, $halere['entity_total']);
        self::assertSame('approval', $halere['impact']);
        self::assertSame('/payroll/components?tab=inputs&period=2026-02', $halere['remediation_path']);
        self::assertEqualsCanonicalizing(array_column($this->groups['halere'], 'employment_id'), array_column($halere['entities'], 'entity_id'));

        // Výpočet nechce víc, než chce zákon (audit 12. 11. 2026, ř. 7 a 9):
        // dohoda bez záznamu o prohlášení je „nepodepsaná" (srážková daň,
        // rezidence nerozhoduje) a dohoda pod rozhodným příjmem pojišťovnu
        // nepotřebuje, protože pojistné nevzniká. Nic z toho běh nezastaví,
        // kontrola na to jen upozorní souhrnem s hromadnou opravou.
        self::assertNull(self::finding($findings, 'tax_declaration_evidence_missing'));
        self::assertNull(self::finding($findings, 'statutory_calculation_manual_review'));
        $unsigned = self::finding($findings, 'tax_declaration_not_signed_summary');
        self::assertNotNull($unsigned);
        self::assertSame([], array_diff($statutoryIds, $unsigned['subject_ids']));
        $residence = self::finding($findings, 'tax_residence_missing_unsigned_summary');
        self::assertNotNull($residence);
        self::assertEqualsCanonicalizing($statutoryIds, $residence['subject_ids']);
        $coverage = self::finding($findings, 'health_coverage_missing_summary');
        self::assertNotNull($coverage);
        self::assertEqualsCanonicalizing($insurerIds, $coverage['subject_ids']);
        self::assertNotContains($coverage['impact'], ['blocking', 'approval']);
        $statutoryPreview = $this->container->get(PayrollStatutoryEvidenceBulkDefaults::class)
            ->preview($this->supplierId, self::PERIOD_START, null);
        self::assertEqualsCanonicalizing($statutoryIds, $statutoryPreview['ready_employee_ids']);
        // Zúžený dialog dostane tytéž osoby, takže počty souhrnu a dialogu sedí.
        $narrowed = $this->container->get(PayrollStatutoryEvidenceBulkDefaults::class)
            ->preview($this->supplierId, self::PERIOD_START, $residence['subject_ids']);
        self::assertSame(count($residence['subject_ids']), $narrowed['summary']['ready']);
        $insurerPreview = $this->container->get(PayrollHealthInsurerBulkAssignment::class)->preview($this->supplierId, self::PERIOD_START);
        self::assertEqualsCanonicalizing($insurerIds, array_column($insurerPreview['people'], 'employee_id'));

        // Tatáž věc nevisí podruhé v katalogu mezer jako „kdykoli".
        foreach (self::finding($findings, 'person_data_gap')['entities'] ?? [] as $entity) {
            self::assertSame([], array_intersect(['tax_residence', 'health_insurance', 'tax_declaration'], $entity['gaps']));
        }
        self::assertSame([], self::finding($findings, 'person_data_gap')['bulk_actions'] ?? []);

        // Průměr pro JMHZ 10345: odkaz na průměry čtvrtletí období.
        $average = self::finding($findings, 'jmhz_average_hourly_earning_missing');
        self::assertSame(5, $average['entity_total']);
        self::assertSame('/payroll/absences?tab=averages&year=2026&quarter=1', $average['remediation_path']);
        self::assertEqualsCanonicalizing(array_column($this->groups['average'], 'employment_id'), array_column($average['entities'], 'entity_id'));

        // Pracoviště: předpověď blokace přípravy JMHZ, počet = dialog doplnění pracoviště.
        $workplace = self::finding($findings, 'jmhz_workplace_codebooks_unverified');
        self::assertSame(2, $workplace['entity_total']);
        self::assertSame('revision', $workplace['impact']);
        $workplacePreview = $this->container->get(PayrollEmploymentWorkplaceBulkFill::class)->preview($this->supplierId, self::PERIOD_START, null);
        self::assertSame([['kind' => 'workplace', 'count' => count($workplacePreview['missing_employment_ids'])]], $workplace['bulk_actions']);
        self::assertEqualsCanonicalizing(array_column($this->groups['workplace'], 'employment_id'), $workplacePreview['missing_employment_ids']);

        // Roční zúčtování a skončené dohody.
        $annual = self::finding($findings, 'jmhz_annual_request_status_unresolved');
        self::assertSame(2, $annual['entity_total']);
        // Odkaz nese rok zúčtování (minulý rok hlášení), ne rok podle dneška.
        self::assertSame('/payroll/annual-settlement?year=2025', $annual['remediation_path']);
        foreach ($annual['entities'] as $entity) {
            self::assertSame('/payroll/annual-settlement?year=2025&person=' . $entity['entity_id'], $entity['remediation_path']);
        }
        $ended = self::finding($findings, 'employments_past_agreed_end');
        self::assertSame(3, $ended['entity_total']);
        $closurePreview = $this->container->get(PayrollEmploymentBulkClosure::class)->preview($this->supplierId, 3, 12);
        $preselected = array_values(array_filter(
            $closurePreview['items'],
            static fn (array $item): bool => $item['group'] === 'expired' && $item['blocked_reason'] === null,
        ));
        self::assertSame([['kind' => 'closure', 'count' => count($preselected)]], $ended['bulk_actions']);

        // CZ-ISCO se v měsíčním hlášení nevykazuje (jen v registraci A1):
        // jeho absence nesmí být nález kontroly před během.
        $iscoEmployment = $this->groups['isco'][0]['employment_id'];
        foreach ($findings as $finding) {
            foreach ($finding['entities'] as $entity) {
                if ($finding['code'] !== 'tax_declaration_not_signed_summary' && $finding['code'] !== 'person_data_gap') {
                    self::assertFalse($entity['entity_type'] === 'employment' && $entity['entity_id'] === $iscoEmployment, $finding['code']);
                }
            }
        }

        // ── Výpočet: zastaví přesně to, co kontrola předpověděla ──────────
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'nav-preflight-lock', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'nav-preflight-calc', $this->actors[0]);
        $blockers = $this->validationRows((int) $calculated->revision['id'], 'blocker');
        $predictedBlocking = array_values(array_unique(array_column(array_filter(
            $findings,
            static fn (array $finding): bool => in_array($finding['impact'], ['blocking', 'approval'], true),
        ), 'code')));
        sort($predictedBlocking);
        self::assertSame(['inputs_not_whole_crowns'], $predictedBlocking);
        $blockedCodes = array_values(array_unique(array_column($blockers, 'code')));
        sort($blockedCodes);
        self::assertSame(['inputs_not_whole_crowns'], $blockedCodes, 'Výpočet zastavil něco, co kontrola nepředpověděla.');

        // ── Hromadné opravy z nálezů; po nich nálezy zmizí ────────────────
        $this->applyBulkFixes(['statutory', 'insurer', 'closure']);
        $drift = (new \MyInvoice\Service\Payroll\Run\PayrollRunSourceDriftDetector($this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::class)))
            ->detect($this->supplierId, $calculated->revision['input_snapshot']);
        self::assertSame(10, $drift->statutoryEvidenceChanged, 'Opravy po zamknutí musí seznam běhů ukázat jako změněné podklady.');
        // Zaokrouhlení zamčeného vstupu neprojde, ale řekne, co dál.
        $skipped = $this->container->get(\MyInvoice\Repository\Payroll\PayrollInputRepository::class)
            ->roundUpToWholeCrowns($this->supplierId, self::PERIOD_START, null, true, $this->actors[0]);
        self::assertSame([], $skipped['rounded']);
        self::assertCount(5, $skipped['skipped']);
        self::assertStringContainsString('Zrušit běh', $skipped['skipped'][0]['reason']);
        self::assertStringContainsString('Začít z opravených vstupů', $skipped['skipped'][0]['reason']);
        $cancelled = $this->runs->cancel($this->supplierId, (int) $run['id'], (int) $calculated->run['row_version'], 'nav-preflight-cancel', $this->actors[0], 'Zaokrouhlení vstupů na celé koruny.');
        $this->applyBulkFixes(['halere']);
        $refreshed = $this->runs->reopen($this->supplierId, (int) $run['id'], (int) $cancelled->run['row_version'], 'nav-preflight-reopen-cancelled', $this->actors[0], 'Zaokrouhlené vstupy.');
        $recalculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $refreshed->run['row_version'], 'nav-preflight-recalc', $this->actors[0]);
        self::assertSame([], $this->validationRows((int) $recalculated->revision['id'], 'blocker'), 'Po hromadných opravách a obnovení podkladů nic neblokuje.');
        $after = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId);
        foreach (['inputs_not_whole_crowns', 'tax_declaration_evidence_missing', 'statutory_calculation_manual_review', 'employments_past_agreed_end'] as $code) {
            self::assertNull(self::finding($after['findings'], $code), "{$code}: po hromadné opravě nález nezmizel.");
        }
        $reviewed = $this->runs->review($this->supplierId, (int) $run['id'], (int) $recalculated->run['row_version'], 'nav-preflight-review', $this->actors[0]);
        $approved = $this->runs->approve($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'nav-preflight-approve', $this->actors[0]);

        // ── Příprava JMHZ: zastaví přesně předpovězené nálezy hlášení ─────
        $preparation = $this->prepareJmhz((int) $approved->revision['id'], 'nav-preflight-prepare');
        self::assertSame(201, $preparation['status']);
        self::assertSame('blocked', $preparation['body']['readiness_status']);
        $prepared = array_column($preparation['body']['issues'], 'count', 'code');
        $predictedJmhz = [];
        foreach ($findings as $finding) {
            if (str_starts_with($finding['code'], 'jmhz_')) {
                $predictedJmhz[$finding['code']] = $finding['entity_total'];
            }
        }
        foreach ($prepared as $code => $count) {
            self::assertSame($predictedJmhz[$code] ?? null, $count, "{$code}: příprava JMHZ zastavila něco, co kontrola nepředpověděla.");
        }

        // ── Oprava zbytku přes opravnou revizi až po zmrazení ─────────────
        $requested = $this->runs->requestCorrection($this->supplierId, (int) $run['id'], (int) $approved->run['row_version'], 'nav-preflight-corr', $this->actors[0], 'Doplnění pracoviště a průměrů pro JMHZ.');
        $reopened = $this->runs->reopen($this->supplierId, (int) $run['id'], (int) $requested->run['row_version'], 'nav-preflight-reopen', $this->actors[0], 'Doplnění pracoviště a průměrů pro JMHZ.');
        $this->applyBulkFixes(['workplace', 'average', 'annual']);
        $final = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId);
        foreach (array_keys($predictedJmhz) as $code) {
            self::assertNull(self::finding($final['findings'], $code), "{$code}: po hromadné opravě nález nezmizel.");
        }
        $refreshed = $this->runs->refreshInputs($this->supplierId, (int) $run['id'], (int) $reopened->run['row_version'], 'nav-preflight-refresh2', $this->actors[0]);
        $recalculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $refreshed->run['row_version'], 'nav-preflight-recalc2', $this->actors[0]);
        self::assertSame([], $this->validationRows((int) $recalculated->revision['id'], 'blocker'));
        $approved = $this->runs->approve($this->supplierId, (int) $run['id'], (int) $recalculated->run['row_version'], 'nav-preflight-approve2', $this->actors[0]);
        $preparation = $this->prepareJmhz((int) $approved->revision['id'], 'nav-preflight-prepare2');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], json_encode($preparation['body']['issues'] ?? null));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'] ?? null, json_encode($tested['body']['blockers'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);
        self::assertSame(0, $tested['body']['controls']['counts']['failed']);
        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(201, $frozen['status'], json_encode($frozen['body'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private static function finding(array $findings, string $code): ?array
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function validationRows(int $revisionId, string $severity): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT code, entity_type, entity_id, message, remediation_path
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND severity = ?
              ORDER BY code, id',
        );
        $statement->execute([$this->supplierId, $revisionId, $severity]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function buildAgency(): void
    {
        $layout = [
            'statutory' => 5,
            'insurer' => 5,
            'average' => 5,
            'halere' => 5,
            'workplace' => 2,
            'isco' => 1,
            'ended' => 3,
            'annual' => 2,
        ];
        $sequence = 0;
        $all = [];
        foreach ($layout as $group => $count) {
            for ($i = 0; $i < $count; $i++) {
                $all[] = [$group, ++$sequence];
            }
        }
        while ($sequence < 60) {
            $all[] = ['ok', ++$sequence];
        }
        foreach ($all as [$group, $seq]) {
            $this->groups[$group][] = $this->hireDpp($group, $seq);
        }

        // Pravděpodobný výdělek pro Q1 hromadně všem kromě skupiny bez průměru
        // a skončených dohod.
        $probable = $this->container->get(ProbableEarningBulkService::class);
        self::assertInstanceOf(ProbableEarningBulkService::class, $probable);
        $ids = [];
        foreach ($this->groups as $group => $people) {
            if (in_array($group, ['average', 'ended'], true)) {
                continue;
            }
            foreach ($people as $person) {
                $ids[] = $person['employment_id'];
            }
        }
        $applied = $probable->apply(
            $this->supplierId,
            2026,
            1,
            15_000,
            'Sjednaná sazba brigádníka 150 Kč za hodinu.',
            $ids,
            $this->actors[0],
            null,
            null,
        );
        self::assertSame(0, $applied['counts']['failed'], json_encode($applied['failed'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Hromadné opravy tak, jak je provede dialog: výběr z náhledu, zápis.
     *
     * @param list<string> $kinds
     */
    private function applyBulkFixes(array $kinds): void
    {
        $actor = $this->actors[0];
        if (in_array('statutory', $kinds, true)) {
            $statutory = $this->container->get(PayrollStatutoryEvidenceBulkDefaults::class);
            $preview = $statutory->preview($this->supplierId, self::PERIOD_START, null);
            $statutory->apply(
                $this->supplierId,
                self::PERIOD_START,
                array_values(array_unique([...$preview['ready_employee_ids'], ...$preview['declaration_missing_employee_ids']])),
                ['tax_residences', 'social_jurisdictions', 'social_discount_claims'],
                true,
                $actor,
                null,
                null,
            );
        }
        if (in_array('insurer', $kinds, true)) {
            $insurer = $this->container->get(PayrollHealthInsurerBulkAssignment::class);
            $assign = array_map(
                static fn (array $person): array => ['employee_id' => $person['employee_id'], 'insurer_code' => '111', 'effective_from' => $person['suggested_from']],
                $insurer->preview($this->supplierId, self::PERIOD_START)['people'],
            );
            self::assertSame(0, $insurer->apply($this->supplierId, $assign, $actor, null, null)['counts']['failed']);
        }
        if (in_array('workplace', $kinds, true)) {
            $workplace = $this->container->get(PayrollEmploymentWorkplaceBulkFill::class);
            $preview = $workplace->preview($this->supplierId, self::PERIOD_START, null);
            $result = $workplace->apply($this->supplierId, self::PERIOD_START, '554782', 'CZ', $preview['missing_employment_ids'], $actor, null, null);
            self::assertSame(2, $result['counts']['applied'] ?? null, json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        if (in_array('closure', $kinds, true)) {
            $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
            $items = [];
            foreach ($closure->preview($this->supplierId, 3, 12)['items'] as $item) {
                if ($item['group'] === 'expired' && $item['blocked_reason'] === null) {
                    $items[] = ['employment_id' => $item['employment_id'], 'action' => $item['action'], 'effective_on' => $item['proposed_on'], 'row_version' => $item['row_version']];
                }
            }
            self::assertSame(3, $closure->apply($this->supplierId, $items, $actor, null, null)['counts']['applied']);
        }
        if (in_array('halere', $kinds, true)) {
            $rounded = $this->container->get(\MyInvoice\Repository\Payroll\PayrollInputRepository::class)
                ->roundUpToWholeCrowns($this->supplierId, self::PERIOD_START, null, true, $actor);
            self::assertCount(5, $rounded['rounded'], json_encode($rounded, JSON_UNESCAPED_UNICODE));
            self::assertSame([360_100], array_values(array_unique(array_column($rounded['rounded'], 'to_minor'))));
        }
        if (in_array('annual', $kinds, true)) {
            foreach ($this->groups['annual'] as $person) {
                $this->db->pdo()->prepare(
                    'INSERT INTO payroll_annual_settlement_requests
                        (supplier_id, employee_id, tax_year, request_status, created_by, updated_by)
                     VALUES (?, ?, 2025, "not_requested", ?, ?)'
                )->execute([$this->supplierId, $person['employee_id'], $actor, $actor]);
            }
        }
        if (in_array('average', $kinds, true)) {
            $result = $this->container->get(ProbableEarningBulkService::class)->apply(
                $this->supplierId,
                2026,
                1,
                15_000,
                'Sjednaná sazba 150 Kč za hodinu.',
                array_column($this->groups['average'], 'employment_id'),
                $actor,
                null,
                null,
            );
            self::assertSame(5, $result['counts']['applied'], json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hireDpp(string $group, int $seq): array
    {
        $female = $seq % 2 === 0;
        $name = sprintf('%s Brigádník%s %02d', $female ? 'Syntetická' : 'Syntetický', $female ? 'ice' : '', $seq);
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $seq,
            'dpp',
            'dpp',
            10,
            2_500,
            $seq % 3 === 0,
            self::PERIOD_START,
        );
        $birthDate = sprintf('19%02d-%02d-%02d', 80 + $seq % 20, 1 + $seq % 12, 1 + $seq % 27);
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $female ? 'female' : 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $seq),
        ]);
        $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        $this->assignJmhzIdentity($person, self::syntheticOic($seq), sprintf('2%020d', $seq));
        $pdo = $this->db->pdo();
        $annualStatus = $group === 'annual' ? null : 'not_requested';
        if ($annualStatus !== null) {
            $pdo->prepare(
                'INSERT INTO payroll_annual_settlement_requests
                    (supplier_id, employee_id, tax_year, request_status, created_by, updated_by)
                 VALUES (?, ?, 2025, ?, ?, ?)'
            )->execute([$this->supplierId, $person['employee_id'], $annualStatus, $this->actors[0], $this->actors[0]]);
        }

        switch ($group) {
            case 'statutory':
                foreach (['payroll_person_tax_residences', 'payroll_person_tax_declarations'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?")
                        ->execute([$this->supplierId, $person['employee_id']]);
                }
                break;
            case 'insurer':
                $pdo->prepare('DELETE FROM payroll_person_health_coverage_history WHERE supplier_id = ? AND employee_id = ?')
                    ->execute([$this->supplierId, $person['employee_id']]);
                break;
            case 'workplace':
                $pdo->prepare(
                    'UPDATE payroll_employment_terms
                        SET work_place = NULL, jmhz_workplace_municipality_code = NULL, jmhz_workplace_country_code = NULL,
                            jmhz_external_codebook_overlay_key = NULL, jmhz_external_codebook_manifest_sha256 = NULL
                      WHERE supplier_id = ? AND employment_id = ?'
                )->execute([$this->supplierId, $person['employment_id']]);
                break;
            case 'isco':
                $pdo->prepare('UPDATE payroll_employment_terms SET cz_isco_code = NULL WHERE supplier_id = ? AND employment_id = ?')
                    ->execute([$this->supplierId, $person['employment_id']]);
                break;
            case 'ended':
                $pdo->prepare('UPDATE payroll_employments SET end_date = "2026-01-20" WHERE supplier_id = ? AND id = ?')
                    ->execute([$this->supplierId, $person['employment_id']]);
                $pdo->prepare('UPDATE payroll_employment_terms SET fixed_term_end_on = "2026-01-20" WHERE supplier_id = ? AND employment_id = ?')
                    ->execute([$this->supplierId, $person['employment_id']]);
                return $person;
        }

        $this->publishShifts($person['employment_id'], self::WORKED);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $amount = $group === 'halere' ? 360_050 : 360_000;
        $this->createApprovedInput($person, $this->componentId, $amount, 'nav-' . $person['employment_id'], self::PERIOD_START);

        return $person;
    }
}

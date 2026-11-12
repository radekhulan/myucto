<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzExternalRootAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCorrectionRootService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Opravné JMHZ za měsíc, který podal předchozí program: převzetí řádného
 * hlášení z původního XML nebo ze zadaných GUID a obsahová oprava, která nese
 * typ O, GUID původního podání a GUID původního formuláře. Syntetická data,
 * nic se neodesílá.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzExternalCorrectionRootTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';
    private const SUBMISSION_GUID = '0197F4C1-8A2D-7D4E-B8C7-5F1E2D3C4B5A';
    private const FORM_GUID = '0197F4C1-8A2D-7D4E-B8C7-5F1E2D3C4B5B';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_EXT', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testCorrectionLinksToRegularReportImportedFromPreviousProgramXml(): void
    {
        [$first, $second, $run] = $this->approvedMonth();
        $revisionId = (int) $run['approved']->revision['id'];
        $original = $this->previousProgramXml($revisionId);
        $originalGuid = self::xmlValue($original, 'idPodani');
        $firstFormGuid = self::formGuid($original, self::ppv($first['sequence']));

        foreach ([
            'jmhz_external_root_scope_mismatch' => str_replace('<variabilniSymbol>1100001237<', '<variabilniSymbol>1100009999<', $original),
            'jmhz_external_root_not_regular' => str_replace('<typPodani>R<', '<typPodani>O<', $original),
            'jmhz_external_root_xml_invalid' => '<jmhz>neplatné</jmhz>',
        ] as $code => $xml) {
            try {
                $this->roots()->attachXml($this->supplierId, 'test', $revisionId, $this->officeId, $xml, 'Hlášení podal předchozí program.', $this->actors[0]);
                self::fail("Převzetí mělo selhat: {$code}");
            } catch (JmhzXmlException $exception) {
                self::assertSame($code, $exception->validationCode, $exception->getMessage());
            }
        }

        $root = $this->roots()->attachXml(
            $this->supplierId,
            'test',
            $revisionId,
            $this->officeId,
            $original,
            'Podal předchozí program, protokol ČSSZ přijato.',
            $this->actors[0],
        );
        self::assertTrue($root['created']);
        self::assertSame(JmhzExternalCorrectionRootService::MODE_XML, $root['mode']);
        self::assertSame($originalGuid, $root['submission_guid']);
        self::assertSame(2, $root['form_count']);
        self::assertSame(0, $root['unmatched_form_count']);
        self::assertSame('accepted', $root['submission_status']);
        self::assertSame('other', $this->scalar(
            'SELECT channel FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $root['submission_id']],
        ));
        $again = $this->roots()->attachXml($this->supplierId, 'test', $revisionId, $this->officeId, $original, 'Podal předchozí program, opakovaně.', $this->actors[0]);
        self::assertFalse($again['created']);
        self::assertSame($root['submission_id'], $again['submission_id']);

        $frozen = $this->freezeJmhzSubmission(
            (int) $this->prepareJmhz($revisionId, 'ext-regular')['body']['id'],
            $this->officeId,
        );
        self::assertNotSame(201, $frozen['status'], 'Druhé řádné hlášení za převzatý měsíc nevznikne.');

        $corrected = $this->correctPayrollRun($run['approved']->run, 'ext-fix', 'Oprava mzdy po podání předchozím programem.');
        $preparation = $this->prepareJmhz((int) $corrected->revision['id'], 'ext-correction');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        $corrections = $this->corrections();
        $candidates = $corrections->candidates($this->supplierId, 'test', $root['submission_id'], (int) $preparation['body']['id'], $this->officeId);
        self::assertSame($originalGuid, $candidates['submission_guid']);
        self::assertSame(['accepted', 'accepted'], array_column($candidates['forms'], 'effective_state'));

        $correction = $corrections->freeze(
            $this->supplierId,
            'test',
            $root['submission_id'],
            (int) $preparation['body']['id'],
            [self::ppv($first['sequence'])],
            $this->actors[0],
            $this->officeId,
        );
        self::assertSame('correction', $correction['submission_kind']);
        self::assertSame($originalGuid, $correction['submission_guid']);
        $xml = $this->frozenXml((int) $correction['submission_id']);
        self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
        self::assertStringContainsString('<idPodani>' . $originalGuid . '</idPodani>', $xml);
        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString(
            '<idFormulare>' . $firstFormGuid . '</idFormulare><typFormulare>O</typFormulare>',
            $xml,
        );
        self::assertStringNotContainsString(self::ppv($second['sequence']), $xml);
    }

    public function testCorrectionLinksToRegularReportGivenByGuids(): void
    {
        [$first, $second, $run] = $this->approvedMonth();
        $revisionId = (int) $run['approved']->revision['id'];
        $candidates = $this->roots()->candidates($this->supplierId, 'test', $revisionId, $this->officeId, $this->actors[0]);
        self::assertSame('2026-07', $candidates['period']);
        self::assertCount(2, $candidates['employments']);

        try {
            $this->roots()->attachGuids($this->supplierId, 'test', $revisionId, $this->officeId, self::SUBMISSION_GUID, [$first['employment_id'] => self::FORM_GUID], false, 'Podal předchozí program.', $this->actors[0]);
            self::fail('Bez potvrzení vztahů se převzetí nesmí provést.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_external_root_confirmation_required', $exception->validationCode);
        }
        $root = $this->roots()->attachGuids(
            $this->supplierId,
            'test',
            $revisionId,
            $this->officeId,
            strtolower(self::SUBMISSION_GUID),
            [$first['employment_id'] => self::FORM_GUID],
            true,
            'Podal předchozí program, GUID z protokolu ČSSZ.',
            $this->actors[0],
        );
        self::assertSame(JmhzExternalCorrectionRootService::MODE_GUIDS, $root['mode']);
        self::assertSame(self::SUBMISSION_GUID, $root['submission_guid']);
        self::assertNull($root['original_sha256']);
        self::assertCount(1, $this->roots()->list($this->supplierId, 'test'));

        $corrected = $this->correctPayrollRun($run['approved']->run, 'ext-guid-fix', 'Oprava mzdy po podání předchozím programem.');
        $preparationId = (int) $this->prepareJmhz((int) $corrected->revision['id'], 'ext-guid-correction')['body']['id'];
        $candidates = $this->corrections()->candidates($this->supplierId, 'test', $root['submission_id'], $preparationId, $this->officeId);
        $states = array_column($candidates['forms'], 'effective_state', 'employment_external_identifier');
        self::assertSame('accepted', $states[self::ppv($first['sequence'])]);
        self::assertSame('missing', $states[self::ppv($second['sequence'])]);
        self::assertSame([null, null], array_column($candidates['forms'], 'changed'), 'Obsah původního hlášení neznáme.');

        $correction = $this->corrections()->freeze(
            $this->supplierId,
            'test',
            $root['submission_id'],
            $preparationId,
            [self::ppv($first['sequence']), self::ppv($second['sequence'])],
            $this->actors[0],
            $this->officeId,
        );
        $xml = $this->frozenXml((int) $correction['submission_id']);
        self::assertStringContainsString('<idPodani>' . self::SUBMISSION_GUID . '</idPodani>', $xml);
        self::assertStringContainsString('<idFormulare>' . self::FORM_GUID . '</idFormulare><typFormulare>O</typFormulare>', $xml);
        self::assertSame(1, substr_count($xml, '<typFormulare>R</typFormulare>'), 'Vztah mimo původní hlášení se doplní řádným formulářem.');
    }

    public function testActionAttachesUploadedXmlAndListsIt(): void
    {
        [, , $run] = $this->approvedMonth();
        $revisionId = (int) $run['approved']->revision['id'];
        $original = $this->previousProgramXml($revisionId);
        $action = $this->container->get(PayrollJmhzExternalRootAction::class);
        self::assertInstanceOf(PayrollJmhzExternalRootAction::class, $action);

        $candidates = $action->candidates(
            $this->request('GET', '/api/payroll/submissions/jmhz-external-roots/candidates')
                ->withQueryParams(['environment' => 'test', 'revision_id' => (string) $revisionId, 'office' => (string) $this->officeId]),
            new Response(),
        );
        self::assertSame(200, $candidates->getStatusCode(), (string) $candidates->getBody());
        self::assertCount(2, $this->json($candidates)['employments']);

        $invalid = $action->attach(
            $this->request('POST', '/api/payroll/submissions/jmhz-external-roots')->withParsedBody([
                'environment' => 'test',
                'revision_id' => $revisionId,
                'office' => $this->officeId,
                'mode' => JmhzExternalCorrectionRootService::MODE_XML,
                'xml_base64' => base64_encode(str_replace('<typPodani>R<', '<typPodani>S<', $original)),
                'note' => 'Podal předchozí program, protokol přijato.',
            ]),
            new Response(),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('jmhz_external_root_not_regular', $this->json($invalid)['error']['code']);

        $attached = $action->attach(
            $this->request('POST', '/api/payroll/submissions/jmhz-external-roots')->withParsedBody([
                'environment' => 'test',
                'revision_id' => $revisionId,
                'office' => $this->officeId,
                'mode' => JmhzExternalCorrectionRootService::MODE_XML,
                'xml_base64' => base64_encode($original),
                'note' => 'Podal předchozí program, protokol přijato.',
            ]),
            new Response(),
        );
        self::assertSame(201, $attached->getStatusCode(), (string) $attached->getBody());
        $listed = $this->json($action->list(
            $this->request('GET', '/api/payroll/submissions/jmhz-external-roots')->withQueryParams(['environment' => 'test']),
            new Response(),
        ));
        self::assertSame([$this->json($attached)['submission_id']], array_column($listed['items'], 'submission_id'));
    }

    /** @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>} */
    private function approvedMonth(): array
    {
        $first = $this->hire('Adam Předchozí');
        $second = $this->hire('Běla Předchozí');
        foreach ([$first, $second] as $person) {
            $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
            $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'base-' . $person['employment_id'], self::PERIOD_START);
        }
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'ext-run');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']));

        return [$first, $second, $run];
    }

    /** Řádné hlášení „předchozího programu": platná datová věta R za týž měsíc. */
    private function previousProgramXml(int $revisionId): string
    {
        $preparation = $this->prepareJmhz($revisionId, 'ext-previous');
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['blockers'] ?? []));

        return (string) $tested['body']['xml'];
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hire(string $name): array
    {
        $sequence = ++$this->sequence;
        $birthDate = sprintf('198%d-0%d-1%d', $sequence, $sequence, $sequence);
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber($birthDate, 'female', $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::ppv($sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['sequence' => $sequence];
    }

    private static function ppv(int $sequence): string
    {
        return sprintf('2%020d', $sequence);
    }

    private function roots(): JmhzExternalCorrectionRootService
    {
        $service = $this->container->get(JmhzExternalCorrectionRootService::class);
        self::assertInstanceOf(JmhzExternalCorrectionRootService::class, $service);

        return $service;
    }

    private function corrections(): JmhzContentCorrectionSubmissionService
    {
        $service = $this->container->get(JmhzContentCorrectionSubmissionService::class);
        self::assertInstanceOf(JmhzContentCorrectionSubmissionService::class, $service);

        return $service;
    }

    private function frozenXml(int $submissionId): string
    {
        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);

        return (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $submissionId));
    }

    private static function xmlValue(string $xml, string $element): string
    {
        self::assertSame(1, preg_match('/<' . $element . '>([^<]+)</', $xml, $match));

        return strtoupper($match[1]);
    }

    private static function formGuid(string $xml, string $ppv): string
    {
        $compact = (string) preg_replace('/>\s+</', '><', $xml);
        self::assertSame(
            1,
            preg_match('/<idFormulare>([^<]+)<\/idFormulare>(?:(?!<\/formularOsoby>).)*' . $ppv . '/s', $compact, $match),
        );

        return strtoupper($match[1]);
    }
}

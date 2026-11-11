<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoTemplate;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePamicaTemplate;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfiles;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSourceConfirmationRequired;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdy → Importy → Docházka a Mapování sloupců. Jen session: soubory
 * s osobními údaji celé firmy se nahrávají z obrazovky, ne z integrace přes token.
 */
final class PayrollAttendanceImportAction
{
    use PayrollActionSupport;

    /** XLSX a CSV z docházkových systémů, XML jen export docházky pro PAMICA. */
    private const FILE_EXTENSIONS = ['xlsx', 'csv', 'xml'];

    public function __construct(
        private readonly AttendanceImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PayrollTimeImportApprovalService $timeApprovals,
        private readonly AttendanceMyUctoTemplate $template,
        private readonly AttendancePamicaTemplate $pamicaTemplate,
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                ImportFiles::fromRequest($body['files'] ?? null, self::FILE_EXTENSIONS),
                $body['rules'] ?? null,
                $this->profileId($body),
                $body['components'] ?? null,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            // Hromadné schválení měsíců při použití dávky je totéž jako
            // dodatečné schválení (approveTimeMonths) — stejné právo.
            if (($body['approve_clean_time_months'] ?? false) === true
                && ($error = $this->authorize($request, $response, 'payroll.approve')) !== null
            ) {
                return $error;
            }
            // Údaje pro registraci se zapisují na kartu osoby, vztahu a do zákonné evidence.
            if (($body['fill_registration_data'] ?? false) === true
                && ($error = $this->authorize($request, $response, 'payroll.person.write')) !== null
            ) {
                return $error;
            }
            $result = $this->imports->apply(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                ImportFiles::fromRequest($body['files'] ?? null, self::FILE_EXTENSIONS),
                $body['rules'] ?? null,
                $body['links'] ?? null,
                ($body['save_links'] ?? false) === true,
                ($body['create_inputs'] ?? false) === true,
                $this->userId($request),
                $body['components'] ?? null,
                ($body['create_components'] ?? false) === true,
                $this->profileId($body),
                ($body['adopt_personal_numbers'] ?? false) === true,
                ($body['adopt_monthly_wage'] ?? false) === true,
                ($body['write_time_summary'] ?? false) === true,
                ($body['approve_clean_time_months'] ?? false) === true,
                ($body['materialize_absence_compensations'] ?? false) === true,
                ($body['create_deductions'] ?? false) === true,
                ($body['confirm_source_checks'] ?? false) === true,
                ($body['fill_registration_data'] ?? false) === true,
                ($body['adopt_probable_earnings'] ?? false) === true,
            );
        } catch (AttendanceSourceConfirmationRequired $e) {
            return Json::error($response, 'source_confirmation_required', $e->getMessage(), 409, ['source_checks' => $e->checks]);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $batch = PayrollTimeValue::row($result['batch'] ?? null, 'batch');
        $inputs = PayrollTimeValue::row($result['inputs'] ?? null, 'inputs');
        $timeSummary = is_array($result['time_summary'] ?? null) ? $result['time_summary'] : [];
        $timeApproval = is_array($result['time_approval'] ?? null) ? $result['time_approval'] : null;
        $this->logger->log(
            'payroll.attendance_import.applied',
            $this->userId($request),
            'payroll_attendance_import',
            PayrollTimeValue::int($batch['id'] ?? null, 'id'),
            [
                'period' => $batch['period'] ?? null,
                'person_count' => $batch['person_count'] ?? null,
                'metric_count' => $batch['metric_count'] ?? null,
                'inputs_created' => $inputs['created'] ?? 0,
                'inputs_updated' => $inputs['updated'] ?? 0,
                'inputs_overridden' => $inputs['overridden'] ?? 0,
                'components_created' => $result['components_created'] ?? [],
                'links_saved' => $result['links_saved'] ?? 0,
                'monthly_wages_adopted' => $result['monthly_wages_adopted'] ?? 0,
                'time_summaries_written' => $timeSummary['written'] ?? 0,
                'time_summary_exceptions' => count(is_array($timeSummary['exceptions'] ?? null) ? $timeSummary['exceptions'] : []),
                'time_months_approved' => $timeApproval['approved'] ?? 0,
                'time_approval_exceptions' => self::exceptionCodes($timeApproval),
                'absence_compensations_created' => is_array($result['absence_compensation'] ?? null)
                    ? ($result['absence_compensation']['created'] ?? 0)
                    : null,
                'deductions_created' => is_array($result['deductions'] ?? null) ? ($result['deductions']['created'] ?? 0) : 0,
                'deductions_updated' => is_array($result['deductions'] ?? null) ? ($result['deductions']['updated'] ?? 0) : 0,
                'replayed' => $result['replayed'] ?? false,
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );

        return Json::ok($response, $result, 201);
    }

    /**
     * Dodatečné hromadné schválení pracovních měsíců z už použité dávky.
     *
     * Oprávnění je stejné jako u schválení docházky po jednom
     * (`payroll.approve`), protože výsledek je týž: schválený měsíc
     * a zmrazený pracovní souhrn pro hlášení ČSSZ.
     *
     * @param array{id:string} $args
     */
    public function approveTimeMonths(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.approve')) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $importId = (int) $args['id'];
        try {
            $result = $this->timeApprovals->applyBatch($supplierId, $importId, true, $this->userId($request));
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.attendance_import.time_months_approved',
            $this->userId($request),
            'payroll_attendance_import',
            $importId,
            [
                'approved' => $result['approved'],
                'already_approved' => $result['already_approved'],
                'summaries_written' => $result['written'],
                'exceptions' => self::exceptionCodes($result),
                'warning_count' => count($result['warnings']),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['time_approval' => $result]);
    }

    /**
     * Výjimky hromadného schválení do auditu: vztah a kód, bez jmen osob.
     *
     * @param array<string,mixed>|null $approval
     * @return list<array{employment_id:mixed,code:mixed}>
     */
    private static function exceptionCodes(?array $approval): array
    {
        $exceptions = is_array($approval['exceptions'] ?? null) ? $approval['exceptions'] : [];

        return array_values(array_map(
            static fn (mixed $exception): array => [
                'employment_id' => is_array($exception) ? ($exception['employment_id'] ?? null) : null,
                'code' => is_array($exception) ? ($exception['code'] ?? null) : null,
            ],
            $exceptions,
        ));
    }

    public function persons(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.person.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $result = $this->imports->persons(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                $body['persons'] ?? null,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
                isset($body['files']) ? ImportFiles::fromRequest($body['files'], self::FILE_EXTENSIONS) : null,
                $body['rules'] ?? null,
                $this->profileId($body),
                $body['components'] ?? null,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result, 201);
    }

    public function batches(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $period = $request->getQueryParams()['period'] ?? null;
        try {
            $batches = $this->imports->batches(
                $this->currentSupplierId($request),
                is_string($period) ? trim($period) : null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['batches' => $batches]);
    }

    /** @param array{id:string} $args */
    public function batch(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $detail = $this->imports->batch($this->currentSupplierId($request), (int) $args['id']);
        if ($detail === null) {
            return Json::error($response, 'not_found', 'Dávka importu nebyla nalezena.', 404);
        }

        return Json::ok($response, $detail);
    }

    /** @param array{id:string} $args */
    public function comparison(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $comparison = $this->imports->comparison($this->currentSupplierId($request), (int) $args['id']);
        if ($comparison === null) {
            return Json::error($response, 'not_found', 'Dávka importu nebyla nalezena.', 404);
        }

        return Json::ok($response, $comparison);
    }

    public function profiles(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }

        return Json::ok($response, ['profiles' => $this->imports->profiles($this->currentSupplierId($request))]);
    }

    public function saveProfile(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $profile = $this->imports->saveProfile(
                $this->currentSupplierId($request),
                $body['id'] ?? null,
                $body['name'] ?? null,
                $body['rules'] ?? null,
                $this->userId($request),
                $body['components'] ?? null,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile]);
    }

    /** @param array{id:string} $args */
    public function deleteProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        if (!$this->imports->deleteProfile($this->currentSupplierId($request), (int) $args['id'])) {
            return Json::error($response, 'not_found', 'Profil mapování nebyl nalezen.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Obnoví vestavěný vzor (třeba po omylem smazaném). `sample_key` vybírá
     * vzor; bez něj GIRITON, jako dřív.
     */
    public function restoreSampleProfile(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        $key = $this->input($request)['sample_key'] ?? AttendanceSampleProfiles::GIRITON;
        if (!AttendanceSampleProfiles::isKey($key)) {
            return Json::error($response, 'validation_failed', 'Neznámý vzor profilu mapování.', 422);
        }
        $result = $this->imports->restoreSampleProfile($this->currentSupplierId($request), $this->userId($request), $key);

        return Json::ok($response, $result, $result['restored'] ? 201 : 200);
    }

    /**
     * Šablona ke vzoru: XLSX pro Vzor MyÚčto (s ukázkovými řádky a návodem),
     * `?sample=pamica` ukázkový XML export pro PAMICA za `?period=RRRR-MM`
     * (výchozí minulý měsíc). Jen smyšlené osoby, žádná data firmy; právo
     * stejné jako načtení profilů a náhled.
     */
    public function template(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $sample = $query['sample'] ?? AttendanceSampleProfiles::MYUCTO;
        if ($sample === AttendanceSampleProfiles::PAMICA) {
            $period = $query['period'] ?? null;
            try {
                $file = $this->pamicaTemplate->build(
                    is_string($period) && $period !== '' ? $period : (new \DateTimeImmutable('first day of last month'))->format('Y-m'),
                );
            } catch (\InvalidArgumentException $e) {
                return Json::error($response, 'validation_failed', $e->getMessage(), 422);
            }
        } elseif ($sample === AttendanceSampleProfiles::MYUCTO) {
            $file = $this->template->build();
        } else {
            return Json::error($response, 'validation_failed', 'Ke vzoru není šablona ke stažení.', 422);
        }
        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Length', (string) strlen($file['bytes']));
    }

    /**
     * Kopie profilu do jiné firmy. O přístupu k cílové firmě rozhoduje týž
     * resolver jako přepínač firem (členství, globální správce, firma vázaná
     * na doménu) — kopie tak nemůže otevřít cestu, kterou by přepnutí zavřelo.
     *
     * @param array{id:string} $args
     */
    public function copyProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $target = $body['target_supplier_id'] ?? null;
            if (!is_int($target) || $target <= 0) {
                throw new \InvalidArgumentException('Vyberte firmu, do které se má profil zkopírovat.');
            }
            if (!$this->mayAccessSupplier($request, $target)) {
                return Json::error($response, 'forbidden', 'Do vybrané firmy nemáte přístup.', 403);
            }
            $profile = $this->imports->copyProfile(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $target,
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile], 201);
    }

    /** @param array{id:string} $args */
    public function exportProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $export = $this->imports->exportProfile($this->currentSupplierId($request), (int) $args['id']);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }

        return Json::ok($response, $export);
    }

    public function importProfile(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $profile = $this->imports->importProfile(
                $this->currentSupplierId($request),
                $body['profile'] ?? null,
                $body['name'] ?? null,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile], 201);
    }

    private function mayAccessSupplier(Request $request, int $supplierId): bool
    {
        $access = $this->supplierAccess->resolve(
            $request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId),
        );

        return !$access->denied && $access->supplierId === $supplierId;
    }

    private function authorize(Request $request, Response $response, string $permission): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private function profileId(array $body): ?int
    {
        $profileId = $body['profile_id'] ?? null;
        if ($profileId !== null && (!is_int($profileId) || $profileId <= 0)) {
            throw new \InvalidArgumentException('Profil mapování nemá platné ID.');
        }

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Vyberte období importu.');
        }

        return trim($value);
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}

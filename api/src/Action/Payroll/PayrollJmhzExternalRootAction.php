<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCorrectionRootService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Řádné hlášení předchozího programu jako kořen opravného JMHZ.
 *
 *   GET  /api/payroll/submissions/jmhz-external-roots                 převzatá řádná hlášení
 *   GET  /api/payroll/submissions/jmhz-external-roots/candidates      vztahy revize pro zadání GUID
 *   POST /api/payroll/submissions/jmhz-external-roots                 převzetí z XML nebo z GUID
 *
 * Nic se neodesílá; opravu pak připraví „Opravit hodnoty hlášení" u převzatého podání.
 */
final class PayrollJmhzExternalRootAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzExternalCorrectionRootService $roots,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'items' => $this->roots->list($this->currentSupplierId($request), $environment),
        ]));
    }

    public function candidates(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        $query = $request->getQueryParams();
        $revisionId = self::positive($query['revision_id'] ?? null);
        $officeId = self::positive($query['office'] ?? null);
        if ($environment === null || $revisionId === null) {
            return $this->invalid($response, 'Zvolte prostředí a schválenou revizi mzdového běhu.');
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response);
        }
        try {
            $result = $this->roots->candidates($this->currentSupplierId($request), $environment, $revisionId, $officeId, $userId);
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error($response, 'conflict', $exception->getMessage(), 409));
        }

        return $this->noStore(Json::ok($response, $result));
    }

    public function attach(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $revisionId = self::positive($body['revision_id'] ?? null);
        $officeId = self::positive($body['office'] ?? null);
        $mode = $body['mode'] ?? null;
        $note = is_string($body['note'] ?? null) ? $body['note'] : '';
        if ($environment === null || $revisionId === null
            || !in_array($mode, [JmhzExternalCorrectionRootService::MODE_XML, JmhzExternalCorrectionRootService::MODE_GUIDS], true)
        ) {
            return $this->invalid($response, 'Zvolte prostředí, schválenou revizi běhu a způsob převzetí (XML nebo GUID).');
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response);
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            if ($mode === JmhzExternalCorrectionRootService::MODE_XML) {
                $xml = base64_decode((string) ($body['xml_base64'] ?? ''), true);
                if (!is_string($xml) || $xml === '') {
                    return $this->invalid($response, 'Nahrajte soubor řádného hlášení JMHZ (XML).');
                }
                $result = $this->roots->attachXml($supplierId, $environment, $revisionId, $officeId, $xml, $note, $userId);
            } else {
                $forms = [];
                foreach (is_array($body['forms'] ?? null) ? $body['forms'] : [] as $form) {
                    $employmentId = self::positive(is_array($form) ? ($form['employment_id'] ?? null) : null);
                    $guid = is_array($form) && is_string($form['form_guid'] ?? null) ? trim($form['form_guid']) : '';
                    if ($employmentId === null) {
                        return $this->invalid($response, 'Formulář musí patřit pracovnímu vztahu.');
                    }
                    if ($guid !== '') {
                        $forms[$employmentId] = $guid;
                    }
                }
                $result = $this->roots->attachGuids(
                    $supplierId,
                    $environment,
                    $revisionId,
                    $officeId,
                    is_string($body['submission_guid'] ?? null) ? $body['submission_guid'] : '',
                    $forms,
                    ($body['forms_confirmed'] ?? false) === true,
                    $note,
                    $userId,
                );
            }
        } catch (JmhzXmlException $exception) {
            return $this->noStore(Json::error($response, $exception->validationCode, $exception->getMessage(), 422));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error($response, 'conflict', $exception->getMessage(), 409));
        }
        if ($result['created']) {
            $this->activity->log('payroll.jmhz_external_root_created', $userId, 'payroll_submission', $result['submission_id'],
                ['environment' => $environment, 'mode' => $result['mode'], 'period' => $result['period'],
                    'form_count' => $result['form_count']],
                supplierId: $supplierId);
        }

        return $this->noStore(Json::ok($response, $result, $result['created'] ? 201 : 200));
    }

    private static function positive(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = (is_array($body) ? ($body['environment'] ?? null) : null)
            ?? $request->getQueryParams()['environment'] ?? 'production';

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

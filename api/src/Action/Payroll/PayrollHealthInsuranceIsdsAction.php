<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollHealthInsuranceIsdsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly HealthInsuranceIsdsSubmissionService $isds,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /** @param array{submissionId:string,insurerCode:string} $args */
    public function enqueue(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }

        try {
            $supplierId = $this->currentSupplierId($request);
            $this->productionGate->assertActive($supplierId);
            $result = $this->isds->enqueue(
                $supplierId,
                $this->positiveInt($args, 'submissionId'),
                $this->insurerCode($args),
                $this->userId($request),
            );
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (SubmissionChannelException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            ));
        } catch (HealthNotificationException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            ));
        }

        return $this->noStore(Json::ok($response, [
            'outbox_id' => $result['outbox_id'],
            'created' => $result['created'],
            'recipient' => $result['recipient'],
            'subject' => $result['subject'],
            'attachment' => $result['attachment'],
            'transport' => $result['transport'],
            'outbox_url' => '/admin/databox?tab=outbox',
        ]));
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            return $error;
        }

        return null;
    }

    /** @param array<string,string> $args */
    private function positiveInt(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                "{$key} musí být kladné celé číslo.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,string> $args */
    private function insurerCode(array $args): string
    {
        $value = $args['insurerCode'] ?? '';
        if (preg_match('/^[0-9]{3}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Kód zdravotní pojišťovny musí mít tři číslice.',
            );
        }

        return $value;
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

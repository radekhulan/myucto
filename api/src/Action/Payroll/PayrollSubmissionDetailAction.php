<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionDetailRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionDetailAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionDetailRepository $repository,
        private readonly PayrollModuleAccess $access,
    ) {}

    /**
     * @param array<string,string> $args
     */
    public function __invoke(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::READ,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
            }
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
            }
            return $error;
        }

        $submissionId = filter_var(
            $args['submissionId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($submissionId)) {
            return Json::error(
                $response,
                'validation_failed',
                'Identifikátor podání musí být kladné celé číslo.',
                422,
            );
        }

        $detail = $this->repository->find(
            $this->currentSupplierId($request),
            $submissionId,
        );
        if ($detail === null) {
            return Json::error(
                $response,
                'not_found',
                'Podání nebylo nalezeno ve stejné firmě.',
                404,
            );
        }

        // `subject_reference` je interní složený klíč — účetní s ním nic
        // neudělá. `subject_label` dodává jen to, co jde ověřit ze sdíleného
        // formátovače; zbytek zůstává `null`, ne hádaný.
        $detail['submission']['subject_label'] = PayrollObligationSubjectFormatter::humanSubject(
            $detail['submission']['agenda_code'],
            $detail['submission']['subject_reference'],
        );

        return Json::ok($response, $detail)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollUnsentSubmissionWithdrawService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * „Zrušit přípravu“ u zmrazeného, ale neodeslaného řádného měsíčního hlášení.
 *
 * Po opravě mzdového běhu jde hlášení připravit znovu z opravené revize;
 * dokud ale za období existuje původní zmrazené hlášení, nové zmrazit nejde.
 * Pravidla a důvody drží {@see PayrollUnsentSubmissionWithdrawService}; tady
 * je jen vstup, výslovné potvrzení, že hlášení neodešlo, a auditní stopa.
 */
final class PayrollUnsentSubmissionWithdrawAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollUnsentSubmissionWithdrawService $withdrawals,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{submissionId:string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', AccessLevel::WRITE, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->access, $error)
        ) {
            return $error ?? $this->invalid($response, 'Přístup k podáním mezd není povolen.');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $environment = $body['environment'] ?? null;
        if (!in_array($environment, ['test', 'production'], true)) {
            return $this->invalid($response, 'Prostředí podání musí být test nebo production.');
        }
        $submissionId = $args['submissionId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $submissionId) !== 1) {
            return $this->invalid($response, 'Identifikátor podání musí být kladné celé číslo.');
        }
        $rowVersion = $body['row_version'] ?? null;
        if (!is_int($rowVersion) && (!is_string($rowVersion) || preg_match('/^[1-9][0-9]*$/D', $rowVersion) !== 1)) {
            return $this->invalid(
                $response,
                'Verze podání musí být kladné celé číslo; bez ní by se dalo zrušit podání, které se mezitím pohnulo.',
            );
        }
        if (($body['confirm_not_sent'] ?? false) !== true) {
            return $this->invalid(
                $response,
                'Potvrďte, že připravené hlášení nebylo odesláno ani nahráno na ePortál ČSSZ ze staženého souboru. '
                    . 'Druhé řádné hlášení za totéž období úřad odmítne jako duplicitní.',
            );
        }
        $reason = $body['reason'] ?? null;
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            return $this->invalid($response, 'Uveďte důvod zrušení připraveného hlášení (například oprava mzdy po zmrazení).');
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->withdrawals->withdraw(
                $supplierId,
                $environment,
                (int) $submissionId,
                (int) $rowVersion,
                $reason,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error($response, 'conflict', $exception->getMessage(), 409));
        }
        $this->logger->log(
            'payroll.submission.unsent_withdrawn',
            $this->userId($request),
            'payroll_submission',
            (int) $submissionId,
            [
                'environment' => $environment,
                'reason' => mb_substr($reason, 0, 500),
                'obligation_id' => $result['obligation']['id'],
                'agenda_code' => $result['obligation']['agenda_code'],
                'period_start' => $result['obligation']['period_start'],
                'subject_reference' => $result['obligation']['subject_reference'],
                'status' => $result['submission']['status'],
            ],
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $this->noStore(Json::ok($response, $result));
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

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCompletionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dohlášení údajů zaměstnanců přihlášených dřív přes ONZ (REGZEC A3).
 *
 * `GET` vrací vztahy, u kterých dohlášení chybí nebo čeká, `POST` pro
 * vybrané vztahy schválí událost A3 a připraví podání. Odeslání zůstává
 * ve frontě podání. Session-only jako ostatní registrační podání.
 */
final class PayrollRegistrationCompletionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRegistrationCompletionService $completions,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function candidates(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => $this->completions->candidates(
            $this->currentSupplierId($request),
            $this->environment($request),
        ));
    }

    public function complete(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['employment_ids'] ?? null;
        $userId = $this->userId($request);
        if (!is_array($ids) || !array_is_list($ids) || $userId === null) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                'Vyberte pracovní vztahy, za které se mají údaje dohlásit.',
                422,
            ));
        }

        return $this->run($response, fn (): array => $this->completions->complete(
            $this->currentSupplierId($request),
            $this->environment($request),
            $ids,
            $body['completion'] ?? null,
            $body['effective_on'] ?? null,
            $userId,
        ));
    }

    /** @param callable():array<string,mixed> $work */
    private function run(Response $response, callable $work): Response
    {
        try {
            $result = $work();
        } catch (PayrollRegistrationXmlException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
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

        return $this->noStore(Json::ok($response, $result));
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
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

    private function environment(Request $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['environment']
            ?? ($request->getQueryParams()['environment'] ?? 'production');
        if (!in_array($value, ['test', 'production'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí musí být test nebo production.',
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

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaPreparationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příprava jedné měsíční povinnosti z přehledu — jedno kliknutí místo
 * „najděte si obrazovku, revizi a účtárnu".
 *
 * Metoda je POST, ne GET: zakládá podání se zmrazeným artefaktem, a to není
 * bezpečná operace, kterou by směl zopakovat prefetch prohlížeče. Vlastní
 * práci dělá {@see PayrollMonthlyAgendaPreparationService} nad TÝMIŽ službami,
 * které používají obrazovky jednotlivých agend.
 */
final class PayrollMonthlyChecklistPrepareAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollMonthlyAgendaPreparationService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function __invoke(Request $request, Response $response): Response
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

        $body = (array) ($request->getParsedBody() ?? []);
        $environment = $body['environment'] ?? 'production';
        $period = $body['period'] ?? null;
        $agendaCode = $body['agenda_code'] ?? null;
        $insurerCode = $body['insurer_code'] ?? null;
        if (!is_string($environment)
            || !is_string($period)
            || !is_string($agendaCode)
            || ($insurerCode !== null && !is_string($insurerCode))
        ) {
            return $this->invalid(
                $response,
                'Prostředí, období a kód agendy musí být řetězce.',
            );
        }
        if ($insurerCode !== null
            && preg_match('/^[0-9]{3}$/D', $insurerCode) !== 1
        ) {
            return $this->invalid(
                $response,
                'Kód zdravotní pojišťovny musí být trojmístné číslo.',
            );
        }

        try {
            $result = $this->service->prepare(
                $this->currentSupplierId($request),
                $environment,
                $period,
                $agendaCode,
                $insurerCode,
                $this->userId($request),
            );
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            // Doménové výjimky agend (JMHZ, zdravotní) nesou vlastní kód
            // v `getMessage()`; přehled je jen předává, protože jsou psané
            // pro účetní, ne pro vývojáře.
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, $result, 201)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

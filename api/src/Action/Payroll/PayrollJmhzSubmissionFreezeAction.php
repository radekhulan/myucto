<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zmrazení měsíčního hlášení do odesílatelné podoby.
 *
 * Rozdíl proti testu není v datech, ale v tom, co po sobě nechá. Test
 * generuje GUIDy při každém běhu nové a nezakládá nic; tady se GUIDy zmrazí
 * JEDNOU, výsledné XML se uloží jako artefakt a vznikne záznam podání, na který
 * se pak váže odeslání i protokol. Duplicitu přijatého podání u ČSSZ nelze
 * zopakovat, takže přemintovat GUIDy při druhém volání by znamenalo tiše
 * vyrobit jiný dokument pod týmž podáním.
 *
 * Zmrazit se dá jen to, co by prošlo. Kdyby se zmrazilo podání, o kterém už
 * teď víme, že ho ČSSZ odmítne, jen se odmítnutí odsune blíž k termínu.
 */
final class PayrollJmhzSubmissionFreezeAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzSubmissionBridgeService $bridge,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{preparationId:string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = $body['environment'] ?? 'test';
        if (!in_array($environment, ['test', 'production'], true)) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $obligationId = $body['obligation_id'] ?? null;
        if ($obligationId !== null
            && !is_int($obligationId)
            && !(is_string($obligationId)
                && preg_match('/^[1-9][0-9]*$/D', $obligationId) === 1)
        ) {
            return $this->invalid($response, 'obligation_id musí být kladné celé číslo.');
        }

        // Hlášení se podává za REGISTRACI u OSSZ. Běh přes víc mzdových účtáren
        // proto zmrazí tolik podání, kolik má registrací, a každé si volí svou.
        $officeId = self::narrowingId($body, 'office');
        if ($officeId !== null && $officeId <= 0) {
            return $this->invalid($response, 'office musí být kladné celé číslo.');
        }

        try {
            $result = $this->bridge->bridge(
                $this->currentSupplierId($request),
                $this->preparationId($args),
                $obligationId === null ? null : (int) $obligationId,
                $environment,
                $this->userId($request),
                $officeId,
            );
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, $result, $result['created'] ? 201 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
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
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array{preparationId:string} $args */
    private function preparationId(array $args): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $args['preparationId']) !== 1) {
            throw new \InvalidArgumentException('preparationId musí být kladné celé číslo.');
        }

        return (int) $args['preparationId'];
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

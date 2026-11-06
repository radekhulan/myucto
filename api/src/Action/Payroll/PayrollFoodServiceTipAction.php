<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollFoodServiceTipService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Evidence měsíčních příjmů ze stravovacích služeb pro osvobozené spropitné
 * (§ 6 odst. 9 písm. g) ZDP, od 2027). Čtení pod `payroll`, zápis pod
 * `payroll.approve` (RoutePermissionMap) — strop se zmrazuje do vstupů a mění
 * daň i pojistné. Jen z přihlášené webové relace.
 */
final class PayrollFoodServiceTipAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollFoodServiceTipService $tips,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll', AccessLevel::READ)) !== null) {
            return $error;
        }
        $period = self::period($args);
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Měsíc není platný.', 422);
        }

        return Json::ok($response, $this->tips->view($this->currentSupplierId($request), $period));
    }

    /** @param array<string,string> $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.approve', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $period = self::period($args);
        $userId = $this->userId($request);
        $body = $request->getParsedBody();
        if ($period === null || $userId === null || !is_array($body)) {
            return Json::error($response, 'validation_failed', 'Požadavek není platný.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $saved = $this->tips->save($supplierId, $period, $body, $userId);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return Json::error($response, 'food_service_tips_locked', $exception->getMessage(), 409);
        }

        $server = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $server[$key] = $value;
            }
        }
        $this->activity->log(
            'payroll.food_service_tips_saved',
            $userId,
            'supplier',
            $supplierId,
            [
                'period_start' => $period,
                'allocation_mode' => $saved['month']['allocation_mode'] ?? null,
            ],
            $this->ipMatcher->clientIpFromRequest($server),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $saved);
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array<string,string> $args */
    private static function period(array $args): ?string
    {
        $value = $args['period'] ?? '';
        if (preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            return null;
        }

        return $value . '-01';
    }
}

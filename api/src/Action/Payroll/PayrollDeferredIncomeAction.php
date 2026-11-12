<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odložený příjem na kartě skončeného pracovního vztahu (JMHZ scénář 8).
 *
 * Účetní tu potvrdí, že příjem zúčtovaný v měsíci po skončení vztahu je
 * odložený příjem a jakého typu (10548). Mzdový běh si potvrzení zmrazí,
 * pojistné z příjmu přiřadí k měsíci zúčtování a hlášení ho vykáže
 * formulářem „Odložený příjem".
 */
final class PayrollDeferredIncomeAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDeferredIncomeRepository $deferredIncomes,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{id:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeFor($request, $response, 'payroll', AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'items' => $this->deferredIncomes->listForEmployment(
                $this->currentSupplierId($request),
                (int) $args['id'],
            ),
            'supported_types' => PayrollDeferredIncomeRepository::SUPPORTED_TYPES,
        ]);
    }

    /** @param array{id:string,period:string} $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeFor($request, $response, 'payroll.employment.write', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $type = is_string($body['deferred_type'] ?? null) ? trim($body['deferred_type']) : '';
            $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
            if (mb_strlen($note) > 500) {
                throw new \InvalidArgumentException('Poznámka k odloženému příjmu může mít nejvýše 500 znaků.');
            }
            $item = $this->deferredIncomes->save(
                $this->currentSupplierId($request),
                (int) $args['id'],
                self::periodStart($args['period']),
                $type,
                $note === '' ? null : $note,
                $this->userId($request),
            );
        } catch (PayrollEmploymentNotFoundException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['item' => $item]);
    }

    /** @param array{id:string,period:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeFor($request, $response, 'payroll.employment.write', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $this->deferredIncomes->delete(
                $this->currentSupplierId($request),
                (int) $args['id'],
                self::periodStart($args['period']),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    private static function periodStart(string $period): string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Měsíc zúčtování musí být ve tvaru RRRR-MM.');
        }

        return $period . '-01';
    }

    private function authorizeFor(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if (RequestAuthorization::isRestrictedBearer($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return null;
    }
}

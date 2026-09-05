<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonProfileConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPersonQuickEditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollPersonQuickEditAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPersonQuickEditService $service,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function put(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }

        try {
            $body = $this->body($request);
            $result = $this->service->save(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $body,
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException|PayrollEmploymentNotFoundException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollPersonProfileConflictException|PayrollEmploymentConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_change', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return Json::error(
                $response,
                'quick_edit_conflict',
                'Údaje zaměstnance kolidují s existujícím záznamem.',
                409,
            );
        }

        return Json::ok($response, $result);
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }

        $error = null;
        foreach (['payroll.person.write', 'payroll.employment.write'] as $permission) {
            if (!$this->requirePermission(
                $request,
                $response,
                $permission,
                AccessLevel::WRITE,
                $error,
            )) {
                return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
            }
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }
}

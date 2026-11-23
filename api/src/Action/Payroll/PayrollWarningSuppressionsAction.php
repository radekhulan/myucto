<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionCatalog;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trvale skrytá mzdová varování.
 *
 * `GET /payroll/warning-suppressions` — přehled skrytí a kódy, které skrýt jde,
 * `POST /payroll/warning-suppressions` — skrytí (`code`, `subject_ids` nebo
 * nic = celý typ ve firmě, volitelně `reason`),
 * `POST /payroll/warning-suppressions/restore` — obnovení (`ids`),
 * `DELETE /payroll/warning-suppressions/{id}` — obnovení jednoho.
 *
 * Zápis jede na právo `payroll.approve`: skrytí je trvalé „vím o tom a je to
 * v pořádku", tedy totéž rozhodnutí, které účetní dělá při schválení běhu.
 */
final class PayrollWarningSuppressionsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollWarningSuppressionService $suppressions,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll', AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'items' => $this->suppressions->list($this->currentSupplierId($request)),
            'hideable_codes' => array_keys(PayrollWarningSuppressionCatalog::HIDEABLE),
        ]);
    }

    public function hide(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.approve', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        $subjectIds = self::ids($body['subject_ids'] ?? null);
        if ($code === '' || $subjectIds === false) {
            return Json::error(
                $response,
                'validation_failed',
                'Skrytí vyžaduje kód kontroly; subject_ids musí být seznam čísel.',
                422,
            );
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response);
        }
        try {
            $result = $this->suppressions->hide(
                $this->currentSupplierId($request),
                $code,
                $subjectIds,
                $body['reason'] ?? null,
                $userId,
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
    }

    public function restore(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $ids = self::ids(is_array($body) ? ($body['ids'] ?? null) : null);

        return $this->restoreIds($request, $response, $ids === false || $ids === null ? [] : $ids);
    }

    /** @param array<string,string> $args */
    public function restoreOne(Request $request, Response $response, array $args): Response
    {
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $this->restoreIds($request, $response, is_int($id) ? [$id] : []);
    }

    /** @param list<int> $ids */
    private function restoreIds(Request $request, Response $response, array $ids): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.approve', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response);
        }
        try {
            $restored = $this->suppressions->restore(
                $this->currentSupplierId($request),
                $ids,
                $userId,
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['restored' => $restored]);
    }

    /**
     * `null` = pole chybí, `false` = neplatný tvar.
     *
     * @return list<int>|null|false
     */
    private static function ids(mixed $raw): array|null|false
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return false;
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                return false;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error ?? throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
        }

        return null;
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

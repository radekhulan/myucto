<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollAccountOptionsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
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
        if (!$this->requirePermission($request, $response, 'payroll.settings', AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $options = [];
        foreach ($this->accounts->listForTenant($this->currentSupplierId($request), true) as $account) {
            // `asset` přibyl kvůli předkontaci `employee_receivable_debit`
            // (335, migrace 1614): přeplatek čisté mzdy je POHLEDÁVKA za
            // zaměstnancem, ne závazek. Bez aktivních účtů se nabídka toho
            // jediného pole vrátí prázdná a účetní si předkontaci nenastaví,
            // přestože ji validátor vyžaduje. Ostatní typy (výnos, kapitál)
            // se dál nepouštějí — žádná mzdová předkontace na ně nemíří.
            $type = $account['account_type'] ?? null;
            if ($type !== 'expense' && $type !== 'liability' && $type !== 'asset') {
                continue;
            }
            $id = $account['id'] ?? null;
            $code = $account['account_code'] ?? null;
            $name = $account['name'] ?? null;
            $isSynthetic = $account['is_synthetic'] ?? null;
            $parentId = $account['parent_id'] ?? null;
            $isActive = $account['is_active'] ?? null;
            if (!is_int($id)
                || !is_string($code)
                || !is_string($name)
                || !is_bool($isSynthetic)
                || $parentId !== null && !is_int($parentId)
                || !is_bool($isActive)) {
                throw new \UnexpectedValueException('Účtová osnova obsahuje neplatný záznam.');
            }
            $options[] = [
                'id' => $id,
                'account_code' => $code,
                'name' => $name,
                'account_type' => $type,
                'is_synthetic' => $isSynthetic,
                'parent_id' => $parentId,
                'is_active' => $isActive,
            ];
        }

        return Json::ok($response, ['accounts' => $options]);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}

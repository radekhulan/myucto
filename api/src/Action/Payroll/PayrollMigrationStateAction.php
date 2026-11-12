<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationStateReader;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stav přechodu z jiného mzdového programu — jen příznaky, žádná mzdová čísla.
 *
 * Slouží Importům k tomu, aby agendu přechodu vůbec nenabízely firmě, která nic
 * nepřevzala. Právo je proto základní `payroll` (čtení): rozhodnout o viditelnosti
 * záložky musí i ten, kdo nesmí otevřít sestavu za ní. Nic osobního tu neprojde —
 * odpověď jsou dva booleany a seznam roků.
 */
final class PayrollMigrationStateAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollMigrationStateReader $reader,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return Json::ok($response, ['state' => $this->reader->forSupplier($this->currentSupplierId($request))]);
    }
}

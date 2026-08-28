<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollCompanyCapabilityService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollModuleActivationService;
use MyInvoice\Service\Payroll\PayrollOnboardingStatusService;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\SupportMatrix;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollCapabilitiesAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly SupportMatrix $matrix,
        private readonly PayrollModuleStateRepository $state,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollModuleActivationService $activation,
        private readonly PayrollCompanyCapabilityService $companyCapability,
        private readonly PayrollProductionGate $productionGate,
        private readonly PayrollOnboardingStatusService $onboarding,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        // Badge modulu se čte právě odsud, takže tady se taky vyhodnotí běžné
        // dokončení nastavení firmy. Interní uvolnění produktu je samostatný
        // globální stav a zákazník ho tímto přechodem nemůže změnit.
        $this->activation->activateWhenSetupComplete(
            $supplierId,
            $this->userId($request),
        );

        $state = $this->state->get($supplierId);

        return Json::ok($response, [
            'state' => $state,
            'support_matrix' => $this->matrix->all(),
            'company_capability' => $this->companyCapability->assess(
                $supplierId,
                $state['start_period'],
            ),
            'production_release' => $this->productionGate->status(),
            // Průvodce prvním nastavením mezd se ukazuje jen do prvního
            // schváleného běhu. Odpověď o modulu se na přehledu načítá tak jako
            // tak, takže příznak jede s ní a nestojí další request.
            'onboarding' => [
                'has_settled_payroll' => $this->onboarding->hasSettledPayroll($supplierId),
            ],
        ]);
    }
}

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
        private readonly PayrollOnboardingStatusService $onboarding,
        // Rozpracovaný mzdový měsíc firmy jako výchozí období mzdových obrazovek.
        private readonly \MyInvoice\Service\Payroll\PayrollWorkingPeriodResolver $workingPeriods,
        private readonly \Psr\Clock\ClockInterface $clock,
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
        // dokončení nastavení firmy.
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
            // Průvodce prvním nastavením mezd se ukazuje jen do prvního
            // schváleného běhu. Odpověď o modulu se na přehledu načítá tak jako
            // tak, takže příznak jede s ní a nestojí další request.
            'onboarding' => [
                'has_settled_payroll' => $this->onboarding->hasSettledPayroll($supplierId),
            ],
            /*
             * Na kterém měsíci firma pracuje: první nezpracovaný (neschválený)
             * měsíc od začátku vedení mezd a poslední zpracovaný. Mzdové
             * obrazovky se podle toho otevírají z menu, dokud si uživatel
             * v sezení nezvolí období sám.
             */
            'periods' => $this->workingPeriods->resolve(
                $supplierId,
                $this->clock->now()->format('Y-m-d'),
            ),
        ]);
    }
}

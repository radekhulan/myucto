<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

final readonly class PayrollSetupFeatures
{
    public function __construct(
        public bool $homeOffice = false,
        public bool $travelExpenses = false,
        public bool $automaticPosting = false,
        public bool $secureDelivery = false,
        public bool $jmhz = false,
        public int $activeApproverCount = 0,
        public bool $jmhzRegistryReady = false,
        public bool $jmhzCertificateReady = false,
        /** @var array<string,string> */
        public array $sourceBlockers = [],
    ) {
        if ($activeApproverCount < 0) {
            throw new \InvalidArgumentException(
                'Počet aktivních schvalovatelů nesmí být záporný.',
            );
        }
        foreach ($sourceBlockers as $code => $message) {
            if ($code === '' || $message === '') {
                throw new \InvalidArgumentException(
                    'Blokující zdroj feature musí mít kód a zprávu.',
                );
            }
        }
    }
}

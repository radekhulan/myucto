<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Service\Migration\Check\Checks\AssetAccountUndeterminedCheck;
use MyInvoice\Service\Migration\Check\Checks\AssetActivationMissingCheck;
use MyInvoice\Service\Migration\Check\Checks\FinalInvoiceAdvanceVatCheck;
use MyInvoice\Service\Migration\Check\Checks\ForeignBankInCzkCheck;
use MyInvoice\Service\Migration\Check\Checks\ForeignIssuedInCzkCheck;
use MyInvoice\Service\Migration\Check\Checks\ForeignPurchaseInCzkCheck;
use MyInvoice\Service\Migration\Check\Checks\ImportedStatementBalanceCheck;
use MyInvoice\Service\Migration\Check\Checks\PayrollLiabilitiesCheck;
use MyInvoice\Service\Migration\Check\Checks\PurchaseSettledUncoveredCheck;
use MyInvoice\Service\Migration\Check\Checks\SettledWithoutPaymentCheck;
use MyInvoice\Service\Migration\Check\Checks\SubledgerLedgerCheck;
use MyInvoice\Service\Migration\Check\Checks\TaxDocumentAdvanceLinkCheck;
use MyInvoice\Service\Migration\Check\Checks\TaxEvidenceCashMissingCheck;

/**
 * Seznam kontrol dat po převodu v pořadí, ve kterém je ukazuje přehled. Kontroly dodává
 * kontejner (autowiring); nová kontrola = třída implementující {@see MigrationDataCheck}
 * a parametr tohoto konstruktoru.
 */
final class MigrationDataCheckRegistry
{
    /** @var array<string,MigrationDataCheck> */
    private array $checks = [];

    public function __construct(
        SettledWithoutPaymentCheck $settledWithoutPayment,
        ForeignBankInCzkCheck $foreignBank,
        ImportedStatementBalanceCheck $statementBalances,
        PayrollLiabilitiesCheck $payrollLiabilities,
        SubledgerLedgerCheck $subledger,
        PurchaseSettledUncoveredCheck $purchaseSettled,
        TaxDocumentAdvanceLinkCheck $taxDocumentLink,
        FinalInvoiceAdvanceVatCheck $finalInvoiceVat,
        ForeignPurchaseInCzkCheck $foreignPurchase,
        ForeignIssuedInCzkCheck $foreignIssued,
        AssetAccountUndeterminedCheck $assetAccount,
        AssetActivationMissingCheck $assetActivation,
        TaxEvidenceCashMissingCheck $taxEvidenceCash,
    ) {
        foreach (func_get_args() as $check) {
            $this->checks[$check->key()] = $check;
        }
    }

    /** @return array<string,MigrationDataCheck> */
    public function all(): array
    {
        return $this->checks;
    }

    public function get(string $key): ?MigrationDataCheck
    {
        return $this->checks[$key] ?? null;
    }
}

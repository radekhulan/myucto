<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Repository\CreditCardSettingsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\Bank\Detect\DetectionResult;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Detektor pohybů kreditní karty, které nejsou nákupem: úrok, poplatek, splátka bez
 * protiúčtu, výběr hotovosti a odměna. Vrací běžný {@see DetectionResult}, takže o auto/návrhu
 * rozhoduje politika automatiky a výsledek jde stejnou frontou jako ostatní detektory.
 *
 * Kontace píše vlastní nohu jako `221` („účet výpisu") - na analytiku úvěrového účtu 231.x
 * ji přesměruje {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticResolver}.
 *
 * Nákup a vratka detektorem neprojdou: ty patří párování s dokladem, pravidlům a naučeným
 * kontacím stejně jako u běžného účtu. Splátku z vlastního účtu (protiúčet je vlastní účet)
 * zachytí dřív {@see \MyInvoice\Service\Accounting\Bank\TransferPairService}; sem dojde jen
 * splátka bez protiúčtu (RB „VAŠE PLATBA - DĚKUJEME").
 *
 * Druhá strana splátky: odchozí platba z BĚŽNÉHO účtu na účet, kam se kreditka splácí
 * (RB: sběrný účet banky + VS z detailu úvěrového účtu), je taky vlastní převod 261/221.
 */
final class CreditCardChargeDetector
{
    public const KEY = 'credit_card';

    public function __construct(
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly CreditCardAccountRepository $creditCards,
        private readonly CreditCardSettingsRepository $settings,
        private readonly AccountRoleResolver $roles,
    ) {}

    /** @param array<string,mixed> $tx řádek bank_transactions + recipient_account/recipient_bank výpisu */
    public function detect(int $supplierId, array $tx): ?DetectionResult
    {
        $amount = (float) ($tx['amount'] ?? 0);
        if (abs($amount) < 0.005) {
            return null;
        }
        $own = $this->statementAccount($supplierId, $tx);
        if ($own === null) {
            return null;
        }
        if (($own['kind'] ?? null) !== BankAnalyticAssigner::CREDIT_CARD_KIND) {
            return $amount < 0 ? $this->repaymentFromCurrentAccount($supplierId, $tx) : null;
        }

        $codes = $this->settings->find($supplierId);
        $kind = CreditCardTransactionKind::classify((string) ($tx['description'] ?? ''), $amount);
        $in = $amount > 0;
        if (in_array($kind, [CreditCardTransactionKind::INTEREST, CreditCardTransactionKind::FEE], true)) {
            $profile = $this->roles->forDate($supplierId, (string) $tx['posted_at']);
            $field = $kind === CreditCardTransactionKind::INTEREST ? 'interest' : 'fee';
            $role = $kind === CreditCardTransactionKind::INTEREST ? 'interest.expense' : 'bank.fee';
            $codes[$field . '_account_code'] = $profile->configuredAccount($role, $codes[$field . '_account_id'], $codes[$field . '_account_code']);
        }
        return match ($kind) {
            CreditCardTransactionKind::INTEREST => $this->result(
                OperationType::BANK_INTEREST,
                $in ? '221' : $codes['interest_account_code'],
                $in ? $codes['interest_account_code'] : '221',
                'Úrok z úvěru kreditní karty',
            ),
            CreditCardTransactionKind::FEE => $this->result(
                OperationType::BANK_FEE,
                $in ? '221' : $codes['fee_account_code'],
                $in ? $codes['fee_account_code'] : '221',
                'Poplatek ke kreditní kartě',
            ),
            CreditCardTransactionKind::REPAYMENT => $this->result(
                OperationType::BANK_TRANSFER_OWN,
                '221',
                $codes['repayment_account_code'],
                'Splátka kreditní karty',
            ),
            CreditCardTransactionKind::CASH => $this->result(
                OperationType::BANK_TRANSFER_OWN,
                $codes['cash_account_code'],
                '221',
                'Výběr hotovosti kreditní kartou',
            ),
            CreditCardTransactionKind::REWARD => $this->result(
                OperationType::BANK_INTEREST,
                '221',
                $codes['reward_account_code'],
                'Odměna ke kreditní kartě',
            ),
            default => null,
        };
    }

    /**
     * Odchozí platba z běžného účtu na účet pro splátku některé kreditky firmy. VS se
     * porovnává, jen když ho úvěrový účet má - u KB/ČSOB/ERSTE se splácí přímo na úvěrový
     * účet (a to pozná už detektor vlastních převodů), sběrný účet má jen RB.
     *
     * @param array<string,mixed> $tx
     */
    private function repaymentFromCurrentAccount(int $supplierId, array $tx): ?DetectionResult
    {
        $counterparty = trim((string) ($tx['counterparty_account'] ?? ''));
        if ($counterparty === '') {
            return null;
        }
        $targets = $this->creditCards->findByRepaymentTarget(
            $supplierId,
            $counterparty,
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
        );
        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        foreach ($targets as $target) {
            $expected = VariableSymbolNormalizer::forMatching((string) ($target['repayment_vs'] ?? ''));
            if ($expected !== '' && $expected !== $vs) {
                continue;
            }
            return $this->result(
                OperationType::BANK_TRANSFER_OWN,
                $this->settings->find($supplierId)['repayment_account_code'],
                '221',
                'Splátka kreditní karty ' . $target['label'],
            );
        }
        return null;
    }

    /** @return array<string,mixed>|null vlastní účet výpisu, ze kterého pohyb je */
    private function statementAccount(int $supplierId, array $tx): ?array
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $bank = isset($tx['recipient_bank']) && (string) $tx['recipient_bank'] !== '' ? (string) $tx['recipient_bank'] : null;
        return $this->bankAccounts->matchCounterparty($supplierId, $account, $bank);
    }

    private function result(string $operationType, string $debit, string $credit, string $description): DetectionResult
    {
        return new DetectionResult(
            $operationType,
            'detector',
            0.95,
            $debit,
            $credit,
            $description,
            null,
            null,
            true,
            self::KEY,
        );
    }
}

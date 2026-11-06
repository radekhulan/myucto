<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;

final class AbraBankAccountImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    /** @param list<array<string,mixed>> $rows @return array{registered:int,virtual:int,rejected:int,statement_bank_codes_updated:int,warnings:list<string>} */
    public function import(int $supplierId, array $rows): array
    {
        $lookup = AbraPaymentMapper::bankAccountLookup($rows);
        $accounts = [];
        $virtual = [];
        $warnings = [];
        foreach ($rows as $row) {
            $id = AbraSource::reference($row['id'] ?? null);
            $source = $lookup['id:' . $id] ?? null;
            if ($id === '' || $source === null || $source['currency'] === '') continue;
            if ($source['loan']) {
                // Úvěrový účet není peněžní účet firmy: jistina zůstává v deníku na účtu úvěru.
                $warnings[] = 'bank_loan_account_not_transferred';
                continue;
            }
            $accountCode = AbraSource::account($source['accounting_code']);
            $suffix = preg_match('/^221\.([0-9]{1,6})$/D', $accountCode, $parts) === 1 ? $parts[1] : null;
            if ($suffix === null) $warnings[] = 'bank_account_source_analytic_not_221';
            $number = $source['number'];
            $iban = $source['iban'];
            if (str_starts_with($number, 'ABRA-') && $iban !== '') {
                $national = AccountNumberNormalizer::czechIbanAccountPart($iban);
                if ($national !== null) $number = $national;
            }
            $account = [
                'number' => $number,
                'bank' => $source['bank_code'],
                'iban' => $iban,
                'currency' => $source['currency'],
                'label' => $source['label'],
                'suffix' => $suffix,
            ];
            if (str_starts_with($number, 'ABRA-')) {
                $virtual[$id] = $account;
            } else {
                $accounts[$id] = $account;
            }
        }

        $registrar = new BankAccountRegistrar($this->db, $this->bankAccounts);
        $registered = $registrar->register($supplierId, $accounts);
        $rejected = count($accounts) - count($registered);
        if ($rejected > 0) $warnings[] = 'bank_account_registration_rejected';
        foreach ($virtual as $id => $account) {
            $registered[$id] = $registrar->registerVirtual(
                $supplierId, 'abra-flexi|' . $id, $account['currency'], $account['label'], $account['suffix'],
            );
        }
        $registrar->linkCompanyAccounts(
            $supplierId, $accounts + $virtual, array_keys($registered), $registered,
            new ImportProtocol('import'), 'bank_accounts', true,
        );
        $updateStatements = $this->db->pdo()->prepare('UPDATE bank_statements SET bank_code = ?
            WHERE supplier_id = ? AND source = "import" AND file_name LIKE "abra-flexi-%"
                AND account_number = ? AND currency = ? AND (bank_code IS NULL OR bank_code = "")');
        $updated = 0;
        foreach ($accounts as $id => $account) {
            if (!isset($registered[$id]) || $account['bank'] === '') continue;
            $updateStatements->execute([$account['bank'], $supplierId, $account['number'], $account['currency']]);
            $updated += $updateStatements->rowCount();
        }
        (new BankAnalyticAssigner($this->db, $this->bankAccounts, $this->chart))->ensureAllForSupplier($supplierId);
        return [
            'registered' => count($registered),
            'virtual' => count($virtual),
            'rejected' => $rejected,
            'statement_bank_codes_updated' => $updated,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}

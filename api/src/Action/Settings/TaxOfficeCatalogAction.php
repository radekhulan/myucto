<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\TaxOfficeBankAccounts;
use MyInvoice\Service\Tax\TaxOfficeCatalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník finančních úřadů pro výběr v nastavení firmy: úřady s územními
 * pracovišti, datovou schránkou a bankovními účty pro placení daní.
 *
 * Schránka se čte z číselníku příjemců (`fu_<c_ufo>`), takže platí i schránka,
 * kterou si firma u úřadu přepsala.
 */
final class TaxOfficeCatalogAction
{
    private const ACCOUNT_PREFIXES = [
        'vat' => TaxOfficeBankAccounts::PREFIX_VAT,
        'dppo' => TaxOfficeBankAccounts::PREFIX_DPPO,
        'dpfo' => TaxOfficeBankAccounts::PREFIX_DPFO,
        'employment' => TaxOfficeBankAccounts::PREFIX_EMPLOYMENT,
        'withholding' => TaxOfficeBankAccounts::PREFIX_WITHHOLDING,
    ];

    public function __construct(private readonly SubmissionRecipientRepository $recipients) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $boxes = $this->dataBoxes(SupplierGuard::currentId($request));
        $offices = [];
        foreach (TaxOfficeCatalog::offices() as $office) {
            $accounts = [];
            foreach (self::ACCOUNT_PREFIXES as $kind => $prefix) {
                $account = TaxOfficeBankAccounts::account($office['code'], $prefix);
                if ($account !== null) $accounts[$kind] = $account['account'];
            }
            $offices[] = [
                'code' => $office['code'],
                'name' => $office['name'],
                'data_box' => $boxes[$office['code']] ?? null,
                'accounts' => $accounts,
            ];
        }

        return Json::ok($response, [
            'offices' => $offices,
            'workplaces' => TaxOfficeCatalog::workplaces(),
        ]);
    }

    /** @return array<string,string> kód FÚ → ID datové schránky */
    private function dataBoxes(int $supplierId): array
    {
        if (!$this->recipients->isAvailable()) return [];
        $boxes = [];
        foreach ($this->recipients->listVisible($supplierId, 'tax_office') as $recipient) {
            if (empty($recipient['is_active']) || empty($recipient['isds_box_id'])) continue;
            if (preg_match('/^fu_(\d+)$/D', (string) $recipient['code'], $m) === 1) {
                $boxes[$m[1]] = (string) $recipient['isds_box_id'];
            }
        }
        return $boxes;
    }
}

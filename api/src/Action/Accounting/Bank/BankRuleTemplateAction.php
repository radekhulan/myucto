<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPaymentIdentifierResolver;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankRuleTemplateAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $postingRules,
        private readonly BankPostingRuleRepository $rules,
        private readonly PayrollPaymentIdentifierResolver $payrollIdentifiers,
        private readonly PayrollModuleAccess $payrollAccess,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $locale = str_starts_with(strtolower($request->getHeaderLine('Accept-Language')), 'en') ? 'en' : 'cs';
        $supplier = $this->supplier($supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, r.id AS rule_id
               FROM bank_rule_templates t
               LEFT JOIN bank_posting_rules r ON r.supplier_id = ? AND r.system_template_key = t.template_key
              WHERE t.supplier_id = ? AND t.is_active = 1 ORDER BY t.sort_order, t.id'
        );
        $stmt->execute([$supplierId, $supplierId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $posting = $this->postingRules->resolve($supplierId, (string) $row['rule_key']);
            $items[] = [
                'template_key' => (string) $row['template_key'],
                'name' => (string) $row['name_' . $locale],
                'direction' => (string) $row['direction'],
                'operation_type' => (string) $row['operation_type'],
                'counterparty_bank' => $row['counterparty_bank'],
                'counterparty_prefix' => $this->resolvedPrefix($row, $supplier),
                'vs_placeholder' => $row['vs_placeholder'],
                'vs_value' => $this->placeholderValue(
                    $row['vs_placeholder'],
                    $supplier,
                    (string) $row['operation_type'],
                ),
                'message_contains' => $row['message_contains'],
                'rule_key' => (string) $row['rule_key'],
                'debit_account_code' => $posting['debit_account_code'] ?? null,
                'credit_account_code' => $posting['credit_account_code'] ?? null,
                'default_priority' => (int) $row['default_priority'],
                'already_instantiated' => $row['rule_id'] !== null,
                'rule_id' => $row['rule_id'] === null ? null : (int) $row['rule_id'],
            ];
        }
        return Json::ok($response, $items);
    }

    public function instantiate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_rule_templates WHERE supplier_id = ? AND template_key = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, (string) $args['key']]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template === false) {
            return Json::error($response, 'template_not_found', 'Šablona nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $supplier = $this->supplier($supplierId);
        $placeholder = $this->placeholderValue(
            $template['vs_placeholder'],
            $supplier,
            (string) $template['operation_type'],
        );
        $override = array_key_exists('variable_symbol', $body)
            ? VariableSymbolNormalizer::digits((string) $body['variable_symbol']) : null;
        $variableSymbol = $override !== null && $override !== '' ? $override : $placeholder;
        if ($template['vs_placeholder'] !== null && ($variableSymbol === null || $variableSymbol === '')) {
            // Kam uživatele poslat doplnit údaj: do Mzdy → Nastavení zaměstnavatele jen
            // tehdy, když modul opravdu běží. Jinak je i u zaměstnavatelských odvodů
            // správnou adresou legacy pole v Nastavení firmy.
            $employerInPayroll = $this->payrollAccess->isEnabled($supplierId);
            $field = match ((string) $template['vs_placeholder']) {
                '{cssz_vsdp}' => $employerInPayroll && (string) $template['operation_type']
                    === 'bank.remittance.social.employer'
                    ? 'payroll_office.social_security_variable_symbol'
                    : 'cssz_vsdp',
                '{health_insurance_number}' => $employerInPayroll && (string) $template['operation_type']
                    === 'bank.remittance.health.employer'
                    ? 'payroll_institution_account.variable_symbol'
                    : 'health_insurance_number',
                default => 'dic',
            };
            return Json::error($response, 'placeholder_missing', 'V nastavení firmy chybí identifikátor platby.', 422, ['field' => $field]);
        }
        $posting = $this->postingRules->resolve($supplierId, (string) $template['rule_key']);
        if ($posting === null || ($posting['debit_account_code'] ?? null) === null || ($posting['credit_account_code'] ?? null) === null) {
            return Json::error($response, 'rule_account_forbidden', 'K šabloně chybí platná předkontace.', 422);
        }
        if (!$this->validAccounts(
            (string) $template['direction'],
            (string) $posting['debit_account_code'],
            (string) $posting['credit_account_code'],
        )) {
            return Json::error($response, 'rule_account_forbidden', 'Šablona obsahuje nepovolenou předkontaci.', 422);
        }
        $data = [
            'name' => trim((string) ($body['name'] ?? $template['name_cs'])) ?: (string) $template['name_cs'],
            'direction' => (string) $template['direction'],
            'counterparty_bank' => $template['counterparty_bank'],
            'counterparty_prefix' => $this->resolvedPrefix($template, $supplier),
            'variable_symbol' => $variableSymbol ?: null,
            'message_contains' => $template['message_contains'],
            'amount_min' => $this->amount($body['amount_min'] ?? null),
            'amount_max' => $this->amount($body['amount_max'] ?? null),
            'auto_amount_cap' => $this->amount($body['auto_amount_cap'] ?? null),
            'debit_account_code' => (string) $posting['debit_account_code'],
            'credit_account_code' => (string) $posting['credit_account_code'],
            'description' => (string) ($posting['description'] ?? $template['name_cs']),
            'mode' => 'suggest',
            'priority' => (int) $template['default_priority'],
            'operation_type' => (string) $template['operation_type'],
            'system_template_key' => (string) $template['template_key'],
            'applies_currency' => 'CZK',
        ];
        try {
            $id = $this->rules->insert($supplierId, $data, $this->userId($request));
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'template_already_instantiated', 'Šablona už je použita.', 409);
            }
            throw $e;
        }
        return Json::ok($response, ['rule' => $this->rules->find($supplierId, $id)], 201);
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, dic, cssz_vsdp, health_insurance_number, taxpayer_type
               FROM supplier
              WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $supplier */
    private function placeholderValue(
        mixed $placeholder,
        array $supplier,
        string $operationType,
    ): ?string
    {
        if (in_array($operationType, [
            'bank.remittance.social.employer',
            'bank.remittance.health.employer',
        ], true)) {
            $supplierId = (int) ($supplier['id'] ?? 0);
            $resolved = $this->payrollIdentifiers->defaultForOperation(
                $supplierId,
                $operationType,
            );
            if ($resolved !== null) {
                return $resolved['value'];
            }
            // S vypnutými Mzdami mzdový modul žádnou hodnotu nedrží (resolver vrací null
            // rovnou na přepínači) — VS zaměstnavatele je pak zpátky v Nastavení firmy.
            if ($this->payrollAccess->isEnabled($supplierId)) {
                return null;
            }
        }

        return match ((string) $placeholder) {
            '{cssz_vsdp}' => VariableSymbolNormalizer::digits((string) ($supplier['cssz_vsdp'] ?? '')) ?: null,
            '{health_insurance_number}' => VariableSymbolNormalizer::digits((string) ($supplier['health_insurance_number'] ?? '')) ?: null,
            '{dic_kmen}' => VariableSymbolNormalizer::digits((string) ($supplier['dic'] ?? '')) ?: null,
            default => null,
        };
    }

    /** @param array<string,mixed> $template @param array<string,mixed> $supplier */
    private function resolvedPrefix(array $template, array $supplier): ?string
    {
        if ($template['counterparty_prefix'] !== null) return (string) $template['counterparty_prefix'];
        $type = (string) ($supplier['taxpayer_type'] ?? 'fo');
        return match ((string) $template['template_key']) {
            'remit.income.advance' => $type === 'po' ? '7704' : '721',
            'remit.withholding' => $type === 'po' ? '7712' : '7720',
            default => null,
        };
    }

    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        $amount = round((float) $value, 2);
        if ($amount < 0) throw new PostingException('invalid_amount', 'Částka nesmí být záporná.', 422);
        return $amount;
    }

    private function validAccounts(string $direction, string $debit, string $credit): bool
    {
        $bank = $direction === 'incoming' ? $debit : $credit;
        $counter = $direction === 'incoming' ? $credit : $debit;
        if (!str_starts_with($bank, '221')) return false;
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($counter, $prefix)) return false;
        }
        return true;
    }
}

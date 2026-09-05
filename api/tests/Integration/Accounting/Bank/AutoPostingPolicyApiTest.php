<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\AutoPostingPolicyAction;
use MyInvoice\Action\Accounting\Bank\BankRuleTemplateAction;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Payroll\PayrollInstitutionAccountValidator;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AutoPostingPolicyApiTest extends BankPostingTestCase
{
    public function testPolicyPutAppliesPresetThenExplicitRows(): void
    {
        $action = $this->container->get(AutoPostingPolicyAction::class);
        $result = $this->callAction($action, 'put', 'PUT', 'admin', [
            'automation_level' => 'assisted',
            'automation_daily_limit_czk' => 25000,
            'automation_digest_enabled' => true,
            'rows' => [['operation_type' => 'bank.fee', 'level' => 'off']],
        ]);
        self::assertSame(200, $result['status']);
        self::assertSame('assisted', $result['body']['automation_level']);
        self::assertEqualsWithDelta(25000.0, (float) $result['body']['automation_daily_limit_czk'], 0.001);
        self::assertTrue($result['body']['automation_digest_enabled']);
        $rows = array_column($result['body']['rows'], null, 'operation_type');
        self::assertSame('off', $rows['bank.fee']['level']);
        self::assertSame('auto', $rows['bank.transfer.own']['effective_level']);
    }

    public function testPolicyRejectsAiAutoAndReadOnlyWrite(): void
    {
        $action = $this->container->get(AutoPostingPolicyAction::class);
        $invalid = $this->callAction($action, 'put', 'PUT', 'admin', [
            'rows' => [['operation_type' => 'ai.classify.bank', 'level' => 'auto']],
        ]);
        self::assertSame(422, $invalid['status']);
        self::assertSame('ai_auto_forbidden', $invalid['body']['error']['code']);

        $readonly = $this->callAction($action, 'put', 'PUT', 'readonly', ['automation_level' => 'off']);
        self::assertSame(403, $readonly['status']);
    }

    public function testTemplateInstantiationResolvesSupplierVariableSymbolAndIsUnique(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp='87654321' WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $action = $this->container->get(BankRuleTemplateAction::class);
        $created = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            ['amount_min' => 100, 'amount_max' => 10000],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(201, $created['status']);
        self::assertSame('suggest', $created['body']['rule']['mode']);
        self::assertSame('87654321', $created['body']['rule']['variable_symbol']);
        self::assertSame('remit.social.own', $created['body']['rule']['system_template_key']);

        $duplicate = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(409, $duplicate['status']);
        self::assertSame('template_already_instantiated', $duplicate['body']['error']['code']);
    }

    public function testTemplateListAndInstantiationDoNotUseAnotherCompanyCatalog(): void
    {
        $otherSupplierId = (int) ($this->db->pdo()->query(
            'SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($otherSupplierId === 0) $this->markTestSkipped('Second supplier required');

        $key = 'remit.social.own';
        $this->db->pdo()->prepare(
            'UPDATE bank_rule_templates SET name_cs = ? WHERE supplier_id = ? AND template_key = ?'
        )->execute(['__TEST aktuální firma', $this->supplierId, $key]);
        $this->db->pdo()->prepare(
            'UPDATE bank_rule_templates SET name_cs = ? WHERE supplier_id = ? AND template_key = ?'
        )->execute(['__TEST cizí firma', $otherSupplierId, $key]);

        $action = $this->container->get(BankRuleTemplateAction::class);
        $listed = $this->callAction($action, 'list', 'GET', 'admin');
        self::assertSame(200, $listed['status']);
        $templates = array_column($listed['body'], null, 'template_key');
        self::assertSame('__TEST aktuální firma', $templates[$key]['name'] ?? null);

        $this->db->pdo()->prepare(
            'DELETE FROM bank_rule_templates WHERE supplier_id = ? AND template_key = ?'
        )->execute([$this->supplierId, $key]);
        $foreignOnly = $this->callAction($action, 'instantiate', 'POST', 'admin', [], ['key' => $key]);
        self::assertSame(404, $foreignOnly['status']);
        self::assertSame('template_not_found', $foreignOnly['body']['error']['code']);
    }

    public function testTemplateVariableSymbolOverrideFillsMissingSupplierSetting(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp=NULL WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $action = $this->container->get(BankRuleTemplateAction::class);

        $missing = $this->callAction($action, 'instantiate', 'POST', 'admin', [], ['key' => 'remit.social.own']);
        self::assertSame(422, $missing['status']);
        self::assertSame('placeholder_missing', $missing['body']['error']['code']);

        $created = $this->callAction(
            $action,
            'instantiate',
            'POST',
            'admin',
            ['variable_symbol' => '123 456'],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(201, $created['status']);
        self::assertSame('123456', $created['body']['rule']['variable_symbol']);
    }

    public function testEmployerTemplateUsesPayrollOfficeIdentifierForPhysicalPerson(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, taxpayer_type = 'fo', cssz_vsdp = '87654321'
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol)
             VALUES (?, 'SYNTH', 'Syntetická mzdová účtárna', '1234509876')
             ON DUPLICATE KEY UPDATE
                social_security_variable_symbol = VALUES(social_security_variable_symbol),
                is_active = 1"
        )->execute([$this->supplierId]);
        $office = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_offices WHERE supplier_id = ? AND code = 'SYNTH'"
        );
        $office->execute([$this->supplierId]);
        $officeId = (int) $office->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE default_office_id = VALUES(default_office_id)'
        )->execute([$this->supplierId, $officeId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules
              WHERE supplier_id = ? AND system_template_key = 'remit.social.employer'"
        )->execute([$this->supplierId]);

        $created = $this->callAction(
            $this->container->get(BankRuleTemplateAction::class),
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.social.employer'],
        );

        self::assertSame(201, $created['status']);
        self::assertSame('1234509876', $created['body']['rule']['variable_symbol']);
        self::assertNotSame('87654321', $created['body']['rule']['variable_symbol']);
    }

    public function testHealthEmployerTemplateUsesEffectiveInstitutionIdentifier(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1,
                    taxpayer_type = 'fo',
                    health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name)
             VALUES (?, 'SYNTH', 'Syntetická mzdová účtárna')
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1"
        )->execute([$this->supplierId]);
        $office = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_offices WHERE supplier_id = ? AND code = 'SYNTH'"
        );
        $office->execute([$this->supplierId]);
        $officeId = (int) $office->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, default_health_insurer_code)
             VALUES (?, ?, 'SYN111')
             ON DUPLICATE KEY UPDATE
                default_office_id = VALUES(default_office_id),
                default_health_insurer_code = VALUES(default_health_insurer_code)"
        )->execute([$this->supplierId, $officeId]);

        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYN111',
            'institution_name' => 'Syntetická zaměstnanecká pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '9876543210',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => date('Y-01-01'),
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-EMPLOYER-HEALTH-TEMPLATE-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules
              WHERE supplier_id = ? AND system_template_key = 'remit.health.employer'"
        )->execute([$this->supplierId]);

        $created = $this->callAction(
            $this->container->get(BankRuleTemplateAction::class),
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.health.employer'],
        );

        self::assertSame(201, $created['status']);
        self::assertSame('9876543210', $created['body']['rule']['variable_symbol']);
        self::assertNotSame('555666777', $created['body']['rule']['variable_symbol']);
    }

    public function testTemplateRejectsSaldoPostingRuleOverride(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp='87654321' WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "DELETE FROM bank_posting_rules WHERE supplier_id=? AND system_template_key='remit.social.own'"
        )->execute([$this->supplierId]);
        $this->container->get(PostingRuleRepository::class)->upsertOverride(
            $this->supplierId,
            'insurance.social.paid',
            '321',
            '221',
            'Neplatná testovací kontace',
        );

        $result = $this->callAction(
            $this->container->get(BankRuleTemplateAction::class),
            'instantiate',
            'POST',
            'admin',
            [],
            ['key' => 'remit.social.own'],
        );
        self::assertSame(422, $result['status']);
        self::assertSame('rule_account_forbidden', $result['body']['error']['code']);
    }
}

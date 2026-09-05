<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\BankRuleTemplateAdminAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateValidator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class BankRuleTemplateAdminActionTest extends TestCase
{
    private Connection $db;
    private BankRuleTemplateAdminAction $action;
    /** @var list<array{id:int,supplier_id:int}> */
    private array $ids = [];
    /** @var list<int> */
    private array $supplierIds = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->db = new Connection(Config::load($root));
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW TABLES LIKE 'bank_rule_templates'")->fetchColumn() === false) {
            $this->markTestSkipped('bank_rule_templates missing');
        }
        if ($this->db->pdo()->query("SHOW COLUMNS FROM bank_rule_templates LIKE 'supplier_id'")->fetchColumn() === false) {
            $this->markTestSkipped('tenant bank_rule_templates migration missing');
        }
        $this->supplierIds = array_map(
            'intval',
            $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN),
        );
        if (count($this->supplierIds) < 2) $this->markTestSkipped('Two suppliers required');
        $this->action = new BankRuleTemplateAdminAction(
            $this->db,
            new BankRuleTemplateValidator(),
            new ActivityLogger($this->db),
            new IpMatcher(),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->ids as $row) {
            $this->db->pdo()->prepare("DELETE FROM activity_log WHERE entity_type = 'bank_rule_template' AND entity_id = ?")
                ->execute([$row['id']]);
            $this->db->pdo()->prepare('DELETE FROM bank_rule_templates WHERE supplier_id = ? AND id = ?')
                ->execute([$row['supplier_id'], $row['id']]);
        }
        $this->db->close();
    }

    public function testCompanyWriterCanCreateUpdateListAndDeleteTemplate(): void
    {
        $supplierId = $this->supplierIds[0];
        [$ruleKey, $direction] = $this->validPostingRule($supplierId);
        $key = 'test.bank.' . bin2hex(random_bytes(5));
        $payload = [
            'template_key' => $key,
            'name_cs' => '__TEST bankovní šablona',
            'name_en' => '__TEST bank template',
            'direction' => $direction,
            'operation_type' => 'bank.rule.custom',
            'counterparty_bank' => '0100',
            'counterparty_prefix' => null,
            'vs_placeholder' => null,
            'message_contains' => 'Testovací platba',
            'rule_key' => $ruleKey,
            'default_priority' => 100,
            'sort_order' => 65000,
            'is_active' => true,
        ];

        $created = $this->action->create($this->request('POST', $supplierId, $payload), $this->response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdBody = $this->json($created);
        $id = (int) ($createdBody['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->ids[] = ['id' => $id, 'supplier_id' => $supplierId];

        $payload['name_cs'] = '__TEST upravená šablona';
        $payload['is_active'] = false;
        $updated = $this->action->update($this->request('PUT', $supplierId, $payload), $this->response(), ['id' => (string) $id]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('__TEST upravená šablona', $this->json($updated)['name_cs'] ?? null);
        self::assertFalse($this->json($updated)['is_active'] ?? true);

        $listed = $this->action->list($this->request('GET', $supplierId), $this->response());
        self::assertSame(200, $listed->getStatusCode());
        self::assertContains($key, array_column($this->json($listed)['templates'] ?? [], 'template_key'));

        $deleted = $this->action->delete($this->request('DELETE', $supplierId), $this->response(), ['id' => (string) $id]);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $this->ids = [];
    }

    public function testRoleWithoutBankRulesIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/bank-rule-templates')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierIds[0])
            ->withAttribute('auth.effective_role', new EffectiveRole(4, 'Bez banky', 'staff', true));
        $response = $this->action->list($request, $this->response());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($response)['error']['code'] ?? null);
    }

    public function testSameKeyIsIsolatedBetweenSuppliers(): void
    {
        [$supplierA, $supplierB] = $this->supplierIds;
        [$ruleKey, $direction] = $this->validPostingRule($supplierA);
        $key = 'test.tenant.' . bin2hex(random_bytes(5));
        $payload = [
            'template_key' => $key,
            'name_cs' => '__TEST firma A',
            'name_en' => '__TEST tenant A',
            'direction' => $direction,
            'operation_type' => 'bank.rule.custom',
            'counterparty_bank' => null,
            'counterparty_prefix' => null,
            'vs_placeholder' => null,
            'message_contains' => 'Tenant test',
            'rule_key' => $ruleKey,
            'default_priority' => 100,
            'sort_order' => 65000,
            'is_active' => true,
        ];

        $createdA = $this->action->create($this->request('POST', $supplierA, $payload), $this->response());
        $payload['name_cs'] = '__TEST firma B';
        $createdB = $this->action->create($this->request('POST', $supplierB, $payload), $this->response());
        self::assertSame(201, $createdA->getStatusCode(), (string) $createdA->getBody());
        self::assertSame(201, $createdB->getStatusCode(), (string) $createdB->getBody());
        $idA = (int) ($this->json($createdA)['id'] ?? 0);
        $idB = (int) ($this->json($createdB)['id'] ?? 0);
        $this->ids[] = ['id' => $idA, 'supplier_id' => $supplierA];
        $this->ids[] = ['id' => $idB, 'supplier_id' => $supplierB];

        $listA = $this->json($this->action->list($this->request('GET', $supplierA), $this->response()))['templates'] ?? [];
        $listB = $this->json($this->action->list($this->request('GET', $supplierB), $this->response()))['templates'] ?? [];
        self::assertSame('__TEST firma A', $this->templateByKey($listA, $key)['name_cs'] ?? null);
        self::assertSame('__TEST firma B', $this->templateByKey($listB, $key)['name_cs'] ?? null);

        $crossTenantUpdate = $this->action->update(
            $this->request('PUT', $supplierB, $payload),
            $this->response(),
            ['id' => (string) $idA],
        );
        self::assertSame(404, $crossTenantUpdate->getStatusCode());
    }

    /** @return array{string,string} */
    private function validPostingRule(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_key, debit_account_code, credit_account_code
               FROM posting_rules
              WHERE (supplier_id = ? OR supplier_id IS NULL) AND is_active = 1
                AND debit_account_code IS NOT NULL AND credit_account_code IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $debit = (string) $row['debit_account_code'];
            $credit = (string) $row['credit_account_code'];
            if (str_starts_with($debit, '221') && !$this->isSaldo($credit)) return [(string) $row['rule_key'], 'incoming'];
            if (str_starts_with($credit, '221') && !$this->isSaldo($debit)) return [(string) $row['rule_key'], 'outgoing'];
        }
        $this->markTestSkipped('No bank-compatible global posting rule.');
    }

    private function isSaldo(string $account): bool
    {
        foreach (['311', '321', '314', '324', '325'] as $prefix) {
            if (str_starts_with($account, $prefix)) return true;
        }
        return false;
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, int $supplierId, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/admin/bank-rule-templates', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(2, 'Správce banky', 'staff', true, ['bank.rules' => 2]));
        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @param list<array<string,mixed>> $templates @return array<string,mixed> */
    private function templateByKey(array $templates, string $key): array
    {
        foreach ($templates as $template) {
            if (($template['template_key'] ?? null) === $key) return $template;
        }
        return [];
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}

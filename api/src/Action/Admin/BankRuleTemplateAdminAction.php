<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateValidator;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankRuleTemplateAdminAction
{
    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];

    public function __construct(
        private readonly Connection $db,
        private readonly BankRuleTemplateValidator $validator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $err)) return $err;
        $supplierId = $this->supplierId($request);
        return Json::ok($response, [
            'templates' => $this->templates($supplierId),
            'operation_types' => $this->validator->operationTypes(),
            'posting_rules' => array_values($this->postingRuleMap($supplierId)),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->supplierId($request);
        try {
            $data = $this->validator->normalize((array) ($request->getParsedBody() ?? []));
            $this->assertPostingRule($supplierId, $data);
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO bank_rule_templates
                    (supplier_id, template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
                     counterparty_prefix, vs_placeholder, message_contains, rule_key,
                     default_priority, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$supplierId, ...$this->values($data)]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->log($request, $supplierId, 'bank_rule_template.created', $id, ['template_key' => $data['template_key']]);
            return Json::ok($response, $this->find($supplierId, $id), 201);
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'template_key_taken', 'Šablona s tímto klíčem už existuje.', 409);
            }
            throw $e;
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->raw($supplierId, $id);
        if ($current === null) return Json::error($response, 'template_not_found', 'Šablona nenalezena.', 404);
        $body = (array) ($request->getParsedBody() ?? []);
        if (array_key_exists('template_key', $body) && trim((string) $body['template_key']) !== (string) $current['template_key']) {
            return Json::error($response, 'template_key_immutable', 'Klíč použité šablony nelze změnit.', 409);
        }
        try {
            $data = $this->validator->normalize(array_replace($current, $body, ['template_key' => $current['template_key']]));
            $this->assertPostingRule($supplierId, $data);
            $stmt = $this->db->pdo()->prepare(
                'UPDATE bank_rule_templates SET
                    name_cs = ?, name_en = ?, direction = ?, operation_type = ?, counterparty_bank = ?,
                    counterparty_prefix = ?, vs_placeholder = ?, message_contains = ?, rule_key = ?,
                    default_priority = ?, sort_order = ?, is_active = ?
                  WHERE supplier_id = ? AND id = ?'
            );
            $values = array_slice($this->values($data), 1);
            $values[] = $supplierId;
            $values[] = $id;
            $stmt->execute($values);
            $this->log($request, $supplierId, 'bank_rule_template.updated', $id, ['template_key' => $data['template_key']]);
            return Json::ok($response, $this->find($supplierId, $id));
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->raw($supplierId, $id);
        if ($current === null) return Json::error($response, 'template_not_found', 'Šablona nenalezena.', 404);
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_posting_rules WHERE supplier_id = ? AND system_template_key = ?'
        );
        $stmt->execute([$supplierId, (string) $current['template_key']]);
        $usage = (int) $stmt->fetchColumn();
        if ($usage > 0) {
            return Json::error($response, 'template_in_use', 'Použitou šablonu nelze smazat; lze ji deaktivovat.', 409, ['usage_count' => $usage]);
        }
        $this->db->pdo()->prepare('DELETE FROM bank_rule_templates WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
        $this->log($request, $supplierId, 'bank_rule_template.deleted', $id, ['template_key' => $current['template_key']]);
        return Json::ok($response, ['deleted' => true]);
    }

    /** @return list<array<string,mixed>> */
    private function templates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM bank_posting_rules r
                      WHERE r.supplier_id = ? AND r.system_template_key = t.template_key) AS usage_count
               FROM bank_rule_templates t
              WHERE t.supplier_id = ?
              ORDER BY t.sort_order, t.id'
        );
        $stmt->execute([$supplierId, $supplierId]);
        $rules = $this->postingRuleMap($supplierId);
        return array_map(fn (array $row): array => $this->cast($row, $rules), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,array<string,mixed>> */
    private function postingRuleMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_key, description, debit_account_code, credit_account_code, priority, supplier_id
               FROM posting_rules
              WHERE (supplier_id = ? OR supplier_id IS NULL) AND is_active = 1
              ORDER BY (supplier_id IS NULL) DESC, priority ASC, id ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['rule_key'];
            $debit = (string) $row['debit_account_code'];
            $credit = (string) $row['credit_account_code'];
            if (!$this->validPair($debit, $credit)) {
                unset($map[$key]);
                continue;
            }
            $map[$key] = [
                'rule_key' => $key,
                'description' => (string) $row['description'],
                'debit_account_code' => $row['debit_account_code'],
                'credit_account_code' => $row['credit_account_code'],
            ];
        }
        return $map;
    }

    /** @param array<string,mixed> $data */
    private function assertPostingRule(int $supplierId, array $data): void
    {
        $rule = $this->postingRuleMap($supplierId)[(string) $data['rule_key']] ?? null;
        if ($rule === null || $rule['debit_account_code'] === null || $rule['credit_account_code'] === null) {
            throw new PostingException('posting_rule_not_found', 'Vybraná předkontace neexistuje nebo nemá oba účty.', 422);
        }
        $debit = (string) $rule['debit_account_code'];
        $credit = (string) $rule['credit_account_code'];
        $bank = $data['direction'] === 'incoming' ? $debit : $credit;
        $counter = $data['direction'] === 'incoming' ? $credit : $debit;
        if (!str_starts_with($bank, '221')) {
            throw new PostingException('rule_bank_side_required', 'Bankovní strana předkontace musí používat účet 221.', 422);
        }
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($counter, $prefix)) {
                throw new PostingException('rule_saldo_forbidden', 'Šablona nesmí používat saldokontní protiúčet.', 422);
            }
        }
    }

    private function validPair(string $debit, string $credit): bool
    {
        if (str_starts_with($debit, '221')) return !$this->isSaldo($credit);
        if (str_starts_with($credit, '221')) return !$this->isSaldo($debit);
        return false;
    }

    private function isSaldo(string $account): bool
    {
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($account, $prefix)) return true;
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function raw(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_rule_templates WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function find(int $supplierId, int $id): array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_posting_rules WHERE supplier_id = ? AND system_template_key = ?'
        );
        $stmt->execute([$supplierId, (string) $row['template_key']]);
        $row['usage_count'] = (int) $stmt->fetchColumn();
        return $this->cast($row, $this->postingRuleMap($supplierId));
    }

    /** @param array<string,mixed> $row @param array<string,array<string,mixed>> $rules @return array<string,mixed> */
    private function cast(array $row, array $rules): array
    {
        $rule = $rules[(string) $row['rule_key']] ?? null;
        $row['id'] = (int) $row['id'];
        $row['default_priority'] = (int) $row['default_priority'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['usage_count'] = (int) ($row['usage_count'] ?? 0);
        $row['debit_account_code'] = $rule['debit_account_code'] ?? null;
        $row['credit_account_code'] = $rule['credit_account_code'] ?? null;
        return $row;
    }

    /** @param array<string,mixed> $data @return list<mixed> */
    private function values(array $data): array
    {
        return [
            $data['template_key'], $data['name_cs'], $data['name_en'], $data['direction'],
            $data['operation_type'], $data['counterparty_bank'], $data['counterparty_prefix'],
            $data['vs_placeholder'], $data['message_contains'], $data['rule_key'],
            $data['default_priority'], $data['sort_order'], $data['is_active'] ? 1 : 0,
        ];
    }

    private function guard(Request $request, Response $response, AccessLevel $minimum, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, 'bank.rules', $minimum)) {
            $err = Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /** @param array<string,mixed> $payload */
    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function log(Request $request, int $supplierId, string $action, int $id, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            $action,
            isset($user['id']) ? (int) $user['id'] : null,
            'bank_rule_template',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RevenueClassificationRuleRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pravidla výnosů (migrace 2217), REST API. RBAC, tenant scoping i tvar odpovědí
 * zrcadlí {@see ExpenseClassificationRuleAction}: čtení readonly+, zápisy účetní|admin.
 * Pravidlo určuje účet položky vydané faktury, takže je jen pro podvojné účetnictví.
 */
final class RevenueClassificationRuleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly RevenueClassificationRuleRepository $rules,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $active = array_key_exists('active', $q) && $q['active'] !== ''
            ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN)
            : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));
        $result = $this->rules->paginateForTenant($supplierId, $active, $perPage, ($page - 1) * $perPage);
        return Json::ok($response, [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $rule = $this->rules->find($supplierId, (int) $args['id']);
        return $rule === null
            ? Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404)
            : Json::ok($response, ['rule' => $rule]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            $data = $this->normalize($supplierId, null, (array) ($request->getParsedBody() ?? []));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $id = $this->rules->insert($supplierId, $data, $this->userId($request));
        $this->log($request, 'revenue_rule.created', $id, ['name' => $data['name'], 'target_account_code' => $data['target_account_code']]);
        return Json::ok($response, ['rule' => $this->rules->find($supplierId, $id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $existing = $this->rules->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        try {
            $fields = $this->normalize($supplierId, $existing, (array) ($request->getParsedBody() ?? []));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->rules->update($supplierId, $id, $fields);
        $this->log($request, 'revenue_rule.updated', $id, array_keys($fields));
        return Json::ok($response, ['rule' => $this->rules->find($supplierId, $id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        if (!$this->rules->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        $this->log($request, 'revenue_rule.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Založení (`$existing` NULL) bere celé tělo, úprava jen klíče, které v těle jsou.
     * Kritéria se ověřují proti VÝSLEDNÉMU stavu, ať vynulování jediného kritéria
     * neskončí na CHECKu v DB jako 500.
     *
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalize(int $supplierId, ?array $existing, array $body): array
    {
        $create = $existing === null;
        $fields = [];
        if ($create || array_key_exists('name', $body)) {
            $fields['name'] = self::text($body['name'] ?? null, 120) ?? 'Pravidlo';
        }
        if ($create || array_key_exists('item_code', $body)) {
            $fields['item_code'] = self::text($body['item_code'] ?? null, 64);
        }
        if ($create || array_key_exists('description_contains', $body)) {
            $fields['description_contains'] = self::text($body['description_contains'] ?? null, 120);
        }
        if ($create || array_key_exists('vat_rate', $body)) {
            $fields['vat_rate'] = $this->vatRate($body['vat_rate'] ?? null);
        }
        if ($create || array_key_exists('target_account_code', $body)) {
            $fields['target_account_code'] = $this->targetAccount($supplierId, $body['target_account_code'] ?? null);
        }
        if ($create || array_key_exists('priority', $body)) {
            $fields['priority'] = $this->priority($body['priority'] ?? 100);
        }
        if ($create || array_key_exists('is_active', $body)) {
            $fields['is_active'] = !array_key_exists('is_active', $body)
                || (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $merged = array_merge($existing ?? [], $fields);
        if (($merged['item_code'] ?? null) === null
            && ($merged['description_contains'] ?? null) === null
            && ($merged['vat_rate'] ?? null) === null) {
            throw $this->err('rule_criteria_missing', 'Pravidlo musí mít alespoň kód položky, text nebo sazbu DPH.');
        }
        return $fields;
    }

    /** Stejné pravidlo jako účet zadaný přímo na položce vydané faktury. */
    private function targetAccount(int $supplierId, mixed $value): string
    {
        try {
            $code = (new ProductPostingDefaults($this->db))->validateIssuedItemAccount($supplierId, $value);
        } catch (\InvalidArgumentException $e) {
            throw $this->err('invalid_target_account', $e->getMessage());
        }
        if ($code === null) {
            throw $this->err('invalid_target_account', 'Pravidlo musí mít cílový účet.');
        }
        return $code;
    }

    private function vatRate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw $this->err('invalid_vat_rate', 'Sazba DPH musí být číslo v rozsahu 0 až 100.');
        }
        return round((float) $value, 2);
    }

    private function priority(mixed $value): int
    {
        $priority = (int) $value;
        if ($priority < 0 || $priority > 999) {
            throw $this->err('invalid_priority', 'Priorita musí být v rozsahu 0 až 999.');
        }
        return $priority;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function err(string $code, string $message): PostingException
    {
        return new PostingException($code, $message, 422);
    }

    /** @param array<mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'revenue_classification_rule', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\RecurringPrepaidSuggestionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pravidla klasifikace druhu výdaje — REST API (§DM). RBAC i tenant scoping zrcadlí
 * {@see \MyInvoice\Action\Accounting\Bank\BankPostingRuleAction}: čtení readonly+,
 * zápisy účetní|admin (defense-in-depth vedle PermissionMiddleware), vše přes
 * ATTR_CURRENT_ID. Role „client" na tyhle cesty nedosáhne — nemá oprávnění `accounting`,
 * pod které /api/accounting/expense-rules spadá fallbackem v RoutePermissionMap.
 */
final class ExpenseClassificationRuleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ExpenseClassificationRuleRepository $rules,
        private readonly ExpenseClassificationService $classification,
        private readonly RecurringPrepaidSuggestionService $recurringPrepaid,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $kind = ExpenseKind::tryFromNullable(self::nn($q['expense_kind'] ?? null))?->value;
        $active = array_key_exists('active', $q) && $q['active'] !== ''
            ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN)
            : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));
        $result = $this->rules->paginateForTenant($supplierId, $kind, $active, $perPage, ($page - 1) * $perPage);
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
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $data = $this->normalizeRule($supplierId, $body);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $id = $this->rules->insert($supplierId, $data, $this->userId($request));
        $this->log($request, 'expense_rule.created', $id, ['name' => $data['name'], 'expense_kind' => $data['expense_kind']]);
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
            $fields = $this->normalizeUpdate($supplierId, $existing, (array) ($request->getParsedBody() ?? []));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->rules->update($supplierId, $id, $fields);
        $this->log($request, 'expense_rule.updated', $id, array_keys($fields));
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
        $this->log($request, 'expense_rule.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /** Návrh druhu výdaje pro každou položku dokladu — read-only, nic neukládá. */
    public function suggestions(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        if (!$this->ownsPurchaseInvoice($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }
        $suggestions = $this->classification->suggestForInvoice($supplierId, $id);
        // Vedle druhu výdaje nabídneme i návrh časového rozlišení ročního předplatného (381)
        // z pravidel s příznakem recurring_prepaid — samostatná mapa, ať se nepřepíše s `items`.
        $recurring = $this->recurringPrepaid->suggestForInvoice($supplierId, $id);
        // Klíčem je id položky; prázdná mapa se v JSON kóduje jako [], proto explicitně objekt.
        return Json::ok($response, [
            'purchase_invoice_id' => $id,
            'items' => (object) $suggestions,
            'recurring_prepaid' => (object) $recurring,
        ]);
    }

    // ── validace / normalizace ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeRule(int $supplierId, array $body): array
    {
        $kind = ExpenseKind::tryFromNullable(self::nn($body['expense_kind'] ?? null));
        if ($kind === null) {
            throw $this->err('invalid_expense_kind', 'Neplatný druh výdaje.');
        }
        $vendorClientId = $this->assertVendor($supplierId, $body['vendor_client_id'] ?? null);
        $vendorName = self::nn($body['vendor_name_contains'] ?? null);
        $description = self::nn($body['description_contains'] ?? null);
        // Cenové rozpětí samo nematchuje (viz chk_ecr_criteria v 1093) — pravidlo bez
        // dodavatele i textu by chytalo všechno v pásmu.
        if ($vendorClientId === null && $vendorName === null && $description === null) {
            throw $this->err('rule_criteria_missing', 'Pravidlo musí mít alespoň dodavatele nebo fragment textu.');
        }
        $band = $this->assertBand($body['amount_min'] ?? null, $body['amount_max'] ?? null);

        return [
            'name' => self::nn($body['name'] ?? null) ?? 'Pravidlo',
            'vendor_client_id' => $vendorClientId,
            'vendor_name_contains' => $vendorName,
            'description_contains' => $description,
            'amount_min' => $band[0],
            'amount_max' => $band[1],
            'expense_kind' => $kind->value,
            'target_account_code' => $this->assertTargetAccount($supplierId, $body['target_account_code'] ?? null),
            'recurring_prepaid' => array_key_exists('recurring_prepaid', $body)
                ? (bool) filter_var($body['recurring_prepaid'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'application_mode' => ($body['application_mode'] ?? 'auto') === 'suggest' ? 'suggest' : 'auto',
            'priority' => $this->assertPriority($body['priority'] ?? 100),
            'is_active' => array_key_exists('is_active', $body)
                ? (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true,
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeUpdate(int $supplierId, array $existing, array $body): array
    {
        $fields = [];
        if (array_key_exists('name', $body)) {
            $fields['name'] = self::nn($body['name']) ?? 'Pravidlo';
        }
        if (array_key_exists('is_active', $body)) {
            $fields['is_active'] = (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('expense_kind', $body)) {
            $kind = ExpenseKind::tryFromNullable(self::nn($body['expense_kind']));
            if ($kind === null) {
                throw $this->err('invalid_expense_kind', 'Neplatný druh výdaje.');
            }
            $fields['expense_kind'] = $kind->value;
        }
        if (array_key_exists('target_account_code', $body)) {
            $fields['target_account_code'] = $this->assertTargetAccount($supplierId, $body['target_account_code']);
        }
        if (array_key_exists('recurring_prepaid', $body)) {
            $fields['recurring_prepaid'] = (bool) filter_var($body['recurring_prepaid'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('application_mode', $body)) {
            $fields['application_mode'] = $body['application_mode'] === 'suggest' ? 'suggest' : 'auto';
        }
        if (array_key_exists('priority', $body)) {
            $fields['priority'] = $this->assertPriority($body['priority']);
        }
        if (array_key_exists('vendor_client_id', $body)) {
            $fields['vendor_client_id'] = $this->assertVendor($supplierId, $body['vendor_client_id']);
        }
        foreach (['vendor_name_contains', 'description_contains'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = self::nn($body[$key]);
            }
        }
        // Kritéria se validují proti VÝSLEDNÉMU stavu, ne proti tělu: vynulování jediného
        // kritéria patchem by jinak prošlo kolem CHECKu jako 500 z DB místo 422.
        $merged = array_merge($existing, $fields);
        if (($merged['vendor_client_id'] ?? null) === null
            && ($merged['vendor_name_contains'] ?? null) === null
            && ($merged['description_contains'] ?? null) === null) {
            throw $this->err('rule_criteria_missing', 'Pravidlo musí mít alespoň dodavatele nebo fragment textu.');
        }
        if (array_key_exists('amount_min', $body) || array_key_exists('amount_max', $body)) {
            $band = $this->assertBand(
                array_key_exists('amount_min', $body) ? $body['amount_min'] : $existing['amount_min'],
                array_key_exists('amount_max', $body) ? $body['amount_max'] : $existing['amount_max'],
            );
            $fields['amount_min'] = $band[0];
            $fields['amount_max'] = $band[1];
        }
        return $fields;
    }

    /** Dodavatel musí patřit tenantovi — jinak by FK pustilo cizí clients.id. */
    /**
     * Účet, na který pravidlo cílí adresně (NULL = odvodí se z druhu výdaje).
     *
     * Ověřuje se TADY, ne až při účtování: neplatný účet v pravidle by se projevil až
     * `unknown_account` u nic netušícího uživatele nad cizím dokladem. Zakázané prefixy
     * jsou shodné s `PostingService::validatePurchaseDebitOverride` — saldokonta, DPH,
     * banka ani pokladna nákladem přijaté faktury nejsou.
     */
    private function assertTargetAccount(int $supplierId, mixed $value): ?string
    {
        $code = self::nn($value);
        if ($code === null) {
            return null;
        }
        if (preg_match('/^(?:311|321|314|324|325|33|34|221|211)/', $code) === 1) {
            throw $this->err('invalid_target_account', 'Tento účet nelze použít jako náklad přijaté faktury.');
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_active FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || !(bool) $row['is_active']) {
            throw $this->err('invalid_target_account', 'Účet není aktivní v účtové osnově firmy.');
        }
        return $code;
    }

    private function assertVendor(int $supplierId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw $this->err('vendor_not_found', 'Dodavatel nenalezen.');
        }
        return $id;
    }

    /** @return array{0:?float,1:?float} */
    private function assertBand(mixed $min, mixed $max): array
    {
        $lo = self::amount($min);
        $hi = self::amount($max);
        if ($lo !== null && $hi !== null && $lo > $hi) {
            throw $this->err('invalid_amount_band', 'Cena od nesmí být vyšší než cena do.');
        }
        return [$lo, $hi];
    }

    private function assertPriority(mixed $value): int
    {
        $priority = (int) $value;
        if ($priority < 0 || $priority > 999) {
            throw $this->err('invalid_priority', 'Priorita musí být v rozsahu 0 až 999.');
        }
        return $priority;
    }

    private function ownsPurchaseInvoice(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private static function nn(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function amount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return round((float) $v, 2);
    }

    private function err(string $code, string $message): PostingException
    {
        return new PostingException($code, $message, 422);
    }

    /** @param array<mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'expense_classification_rule', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}

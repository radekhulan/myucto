<?php

declare(strict_types=1);

namespace MyInvoice\Action\UserSettings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ukládané filtry — per uživatel + per firma (Epic F5, §3.2/§3.4).
 *
 *   GET    /api/user/filters[?page_key=invoices]   — pole filtrů (page_key volitelný, R6)
 *   POST   /api/user/filters                       — 201 + vytvořený objekt
 *   PUT    /api/user/filters/{id}                   — aktualizovaný objekt
 *   DELETE /api/user/filters/{id}                   — {"deleted": true}
 *
 * Uložený filtr = plochý snapshot URL query (opaque payload). BE payload neinterpretuje,
 * jen validuje tvar/velikost/hloubku. Identita: user_id z tokenu, supplier_id z SupplierGuard.
 * Vždy 404 (nikdy 403) u cizích/neexistujících — anti-enumeration.
 */
final class SavedFilterAction
{
    /**
     * Jediný zdroj pravdy pro page_key (§3.4); FE literály MUSÍ sedět 1:1.
     *
     * Skladové klíče jsou s pomlčkou, zbytek s podtržítkem — nesjednocovat. Skladové
     * stránky je odvozují od názvu routy (`stock-items`) a týmž literálem si drží
     * i sloupcové předvolby přes useTablePrefs(); přejmenování by je rozešlo uvnitř
     * jednoho souboru. Dokud tu chyběly, končilo uložení pohledu ve skladu na 422.
     *
     * Seznam je whitelist i pro `table.*` preference (viz UserPreferenceAction) —
     * stránka volající useTablePrefs() s klíčem, který tu není, dostane na PUT 422
     * a její volba sloupců/hustoty se tiše neuloží. Tak dlouho tiše přicházely
     * o předvolby předvaha, peněžní deník, pokladní kniha i pohledávky/závazky —
     * a stejně tak celá mzdová sekce, dokud to nezačal hlídat
     * {@see \MyInvoice\Tests\Architecture\SavedFilterPageKeyContractTest}.
     */
    public const PAGE_KEYS = ['invoices', 'purchase_invoices', 'journal', 'general_ledger',
        'trial_balance', 'financial_statements', 'clients', 'assets', 'bank_statements', 'projects', 'recurring', 'documents',
        'cash_documents', 'cash_book', 'cash_journal', 'receivables_payables', 'saldo',
        'bank_posting_suggestions', 'bank_posting_rules', 'automation_feed', 'automation_rules',
        'stock-items', 'stock-documents', 'stock-purchase-orders', 'stock-report-status',
        'payroll-documents', 'payroll-payments', 'payroll-quick-inputs',
        'payroll-recurring-components', 'payroll-retention',
        'payroll-health-notifications', 'payroll-time', 'payroll-inputs',
        'payroll-employer-policies', 'payroll-people', 'payroll-travel',
        'payroll-deduction-agreements', 'payroll-enforcement',
        'payroll-submissions', 'payroll-submission-overview',
        'payroll-submission-inbox', 'payroll-erasure-candidates',
        'payroll-erasure-log', 'payroll-dimensions',
        'payroll-health-insurer-accounts', 'payroll-person-dependants',
        'payroll-rulesets', 'payroll-benefit-baskets',
        'payroll-annual-settlement'];

    private const MAX_FILTERS = 30;

    public function __construct(private readonly Connection $db) {}

    public function list(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $pageKey = trim((string) ($request->getQueryParams()['page_key'] ?? ''));

        $pdo = $this->db->pdo();
        if ($pageKey !== '') {
            if (!in_array($pageKey, self::PAGE_KEYS, true)) {
                return Json::error($response, 'invalid_page_key', 'Neznámý page_key.', 422);
            }
            $stmt = $pdo->prepare(
                'SELECT id, page_key, name, payload, is_default, sort_order, updated_at
                   FROM saved_filters
                  WHERE user_id = ? AND supplier_id = ? AND page_key = ?
                  ORDER BY page_key, sort_order, name'
            );
            $stmt->execute([$userId, $supplierId, $pageKey]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, page_key, name, payload, is_default, sort_order, updated_at
                   FROM saved_filters
                  WHERE user_id = ? AND supplier_id = ?
                  ORDER BY page_key, sort_order, name'
            );
            $stmt->execute([$userId, $supplierId]);
        }

        return Json::ok($response, array_map([$this, 'toItem'], $stmt->fetchAll()));
    }

    public function create(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $pageKey = trim((string) ($body['page_key'] ?? ''));
        if (!in_array($pageKey, self::PAGE_KEYS, true)) {
            return Json::error($response, 'invalid_page_key', 'Neznámý page_key.', 422);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return Json::error($response, 'validation_failed', 'Název filtru musí mít 1–100 znaků.', 422);
        }

        $raw = $this->encodePayload($body['payload'] ?? null);
        if (($err = JsonPayloadValidator::validate($raw)) !== null) {
            return Json::error($response, $err, 'Neplatný payload filtru.', 422);
        }
        $payload = JsonPayloadValidator::canonicalize($raw);

        $isDefault = (bool) ($body['is_default'] ?? false);
        $sortOrder = (int) ($body['sort_order'] ?? 0);

        $pdo = $this->db->pdo();

        $count = $this->countFilters($pdo, $userId, $supplierId, $pageKey);
        if ($count >= self::MAX_FILTERS) {
            return Json::error($response, 'filter_limit_reached', 'Dosažen limit 30 uložených filtrů pro tuto stránku.', 422);
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO saved_filters (user_id, supplier_id, page_key, name, payload, is_default, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            // Duplicitní název je očekávaný stav (→ 409 níž), ne chyba do logu.
            \MyInvoice\Infrastructure\Database\DbErrorLogger::expectingDuplicates(
                ['uq_sf_user_supplier_page_name'],
                fn () => $ins->execute([$userId, $supplierId, $pageKey, $name, $payload, $isDefault ? 1 : 0, $sortOrder]),
            );
            $id = (int) $pdo->lastInsertId();

            if ($isDefault) {
                $this->clearDefaults($pdo, $userId, $supplierId, $pageKey, $id);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'filter_name_exists', 'Filtr s tímto názvem už existuje.', 409);
            }
            throw $e;
        }

        return Json::ok($response, $this->findItem($pdo, $id, $userId, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $pdo = $this->db->pdo();
        $row = $this->findRow($pdo, $id, $userId, $supplierId);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Filtr nenalezen.', 404);
        }
        $pageKey = (string) $row['page_key'];

        $set = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                return Json::error($response, 'validation_failed', 'Název filtru musí mít 1–100 znaků.', 422);
            }
            $set[] = 'name = ?';
            $params[] = $name;
        }

        if (array_key_exists('payload', $body)) {
            $raw = $this->encodePayload($body['payload']);
            if (($err = JsonPayloadValidator::validate($raw)) !== null) {
                return Json::error($response, $err, 'Neplatný payload filtru.', 422);
            }
            $set[] = 'payload = ?';
            $params[] = JsonPayloadValidator::canonicalize($raw);
        }

        if (array_key_exists('sort_order', $body)) {
            $set[] = 'sort_order = ?';
            $params[] = (int) $body['sort_order'];
        }

        $hasDefault = array_key_exists('is_default', $body);
        $isDefault = $hasDefault && (bool) $body['is_default'];
        if ($hasDefault) {
            $set[] = 'is_default = ?';
            $params[] = $isDefault ? 1 : 0;
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($set !== []) {
                $params[] = $id;
                $params[] = $userId;
                $params[] = $supplierId;
                $upd = $pdo->prepare(
                    'UPDATE saved_filters SET ' . implode(', ', $set)
                    . ' WHERE id = ? AND user_id = ? AND supplier_id = ?'
                );
                // Přejmenování na existující název je stejně očekávané jako u create (→ 409).
                \MyInvoice\Infrastructure\Database\DbErrorLogger::expectingDuplicates(
                    ['uq_sf_user_supplier_page_name'],
                    fn () => $upd->execute($params),
                );
            }

            if ($isDefault) {
                $this->clearDefaults($pdo, $userId, $supplierId, $pageKey, $id);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'filter_name_exists', 'Filtr s tímto názvem už existuje.', 409);
            }
            throw $e;
        }

        return Json::ok($response, $this->findItem($pdo, $id, $userId, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);

        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM saved_filters WHERE id = ? AND user_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $userId, $supplierId]);
        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'not_found', 'Filtr nenalezen.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function encodePayload(mixed $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ''; // validate('') → validation_failed 422 místo TypeError/500
        }
    }

    private function countFilters(PDO $pdo, int $userId, int $supplierId, string $pageKey): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM saved_filters WHERE user_id = ? AND supplier_id = ? AND page_key = ?'
        );
        $stmt->execute([$userId, $supplierId, $pageKey]);
        return (int) $stmt->fetchColumn();
    }

    private function clearDefaults(PDO $pdo, int $userId, int $supplierId, string $pageKey, int $exceptId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE saved_filters SET is_default = 0
              WHERE user_id = ? AND supplier_id = ? AND page_key = ? AND id <> ?'
        );
        $stmt->execute([$userId, $supplierId, $pageKey, $exceptId]);
    }

    /** @return array<string,mixed>|null */
    private function findRow(PDO $pdo, int $id, int $userId, int $supplierId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, page_key, name, payload, is_default, sort_order, updated_at
               FROM saved_filters
              WHERE id = ? AND user_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $userId, $supplierId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function findItem(PDO $pdo, int $id, int $userId, int $supplierId): array
    {
        return $this->toItem((array) $this->findRow($pdo, $id, $userId, $supplierId));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function toItem(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'page_key'   => (string) $r['page_key'],
            'name'       => (string) $r['name'],
            'payload'    => json_decode((string) $r['payload'], true),
            'is_default' => (bool) $r['is_default'],
            'sort_order' => (int) $r['sort_order'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    private function isDuplicate(PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
    }
}

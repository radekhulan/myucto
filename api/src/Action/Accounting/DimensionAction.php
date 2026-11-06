<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Firma → Dimenze — REST API.
 *
 *   GET    /api/accounting/dimensions                          — přehled (zapnuto, skupina, typy, hodnoty)
 *   PUT    /api/accounting/dimensions/settings                 — zapnout/vypnout sekci (nastavení firmy)
 *   POST   /api/accounting/dimensions/defaults                 — založit výchozí typy
 *   POST   /api/accounting/dimensions/types                    — nový typ
 *   PATCH  /api/accounting/dimensions/types/{id}               — úprava typu
 *   DELETE /api/accounting/dimensions/types/{id}               — smazat (použitý se jen deaktivuje)
 *   POST   /api/accounting/dimensions/types/{id}/values        — nová hodnota
 *   PATCH  /api/accounting/dimensions/values/{id}              — úprava / přesun / uzavření hodnoty
 *   DELETE /api/accounting/dimensions/values/{id}              — smazat (použitá se jen uzavře)
 *   GET    /api/accounting/dimensions/responsible-candidates   — uživatelé firmy (odpovědná osoba)
 *   GET|PUT /api/accounting/dimensions/documents/{doc}/{id}    — dimenze dokladu (hlavička + položky)
 *   POST   /api/accounting/dimensions/documents/{doc}/{id}/preview — náhled dimenzí zaúčtovaných řádků
 *   GET|PUT /api/accounting/dimensions/journal/{id}            — dimenze řádků účetního zápisu
 *   GET|POST|PUT|DELETE /api/accounting/dimensions/group       — skupina firem (globální dimenze)
 *   GET|POST /api/accounting/dimensions/rules, PUT|DELETE …/rules/{id} — pravidla dimenzí podle účtu
 *   GET    /api/accounting/dimensions/rules/audit              — zaúčtované řádky bez povinné dimenze
 *   GET    /api/accounting/dimensions/rules/coverage           — pokrytí účtů dimenzemi (návrh pravidel)
 *   GET    /api/accounting/dimensions/account-map              — účtotvorná dimenze: mapa hodnota × syntetika → analytika
 *   GET    /api/accounting/dimensions/types/{id}/account-candidates — syntetiky v masce typu a jejich analytiky
 *   PUT    /api/accounting/dimensions/values/{id}/account-map  — nahradit mapu hodnoty (firma)
 *
 * Čtení = `accounting` READ, zápisy = `accounting` WRITE (RoutePermissionMap i tady),
 * zapnutí sekce a skupina firem = správa firmy.
 */
final class DimensionAction
{
    use AccountingActionSupport;

    /** Segment URL => typ dokladu v document_dimensions. */
    private const DOCUMENTS = [
        'purchase-invoices' => 'purchase_invoice',
        'invoices' => 'invoice',
        'cash-documents' => 'cash_document',
        'bank-transactions' => 'bank_transaction',
        'journal-templates' => 'journal_template',
        'recurring-templates' => 'recurring_template',
        'other-items' => 'other_item',
        'assets' => 'asset',
    ];

    public function __construct(
        private readonly DimensionService $dimensions,
        private readonly UserSupplierRepository $userSuppliers,
        private readonly DimensionRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DimensionRuleService $rules,
        private readonly DimensionRuleAudit $ruleAudit,
        private readonly DimensionAccountMapService $accountMap,
        private readonly OtherItemRepository $otherItems,
        private readonly OtherItemService $otherItemService,
    ) {}

    // ── účtotvorná dimenze ──────────────────────────────────────────────────

    /** GET …/account-map?value_id= — mapa firmy (volitelně jedné hodnoty). */
    public function accountMap(Request $request, Response $response): Response
    {
        $valueId = (int) ($request->getQueryParams()['value_id'] ?? 0);
        return Json::ok($response, $this->accountMap->list($this->currentSupplierId($request), $valueId > 0 ? $valueId : null));
    }

    public function accountCandidates(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->accountMap->candidates($this->currentSupplierId($request), (int) ($args['id'] ?? 0)));
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /** PUT …/values/{id}/account-map — `{rows: [{synthetic_account_id, analytic_account_id, valid_from, valid_to}]}`. */
    public function saveAccountMap(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $valueId = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $rows = $this->accountMap->saveForValue($supplierId, $valueId, array_values((array) ($body['rows'] ?? [])), $this->userId($request));
            $this->log($request, 'dimension.account_map_updated', $valueId, [
                'rows' => array_map(static fn (array $r): array => [
                    'synthetic' => $r['synthetic_code'],
                    'analytic' => $r['analytic_code'],
                    'valid_from' => $r['valid_from'],
                    'valid_to' => $r['valid_to'],
                ], $rows),
            ]);
            return $rows;
        });
    }

    // ── pravidla dimenzí podle účtu ─────────────────────────────────────────

    public function listRules(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->rules->list($this->currentSupplierId($request)));
    }

    public function createRule(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $rule = $this->rules->create($supplierId, (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'dimension.rule_created', (int) $rule['id'], self::ruleAudit($rule));
            return $rule;
        }, 201);
    }

    public function updateRule(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $rule = $this->rules->update($supplierId, $id, (array) ($request->getParsedBody() ?? []));
            $this->log($request, 'dimension.rule_updated', $id, self::ruleAudit($rule));
            return $rule;
        });
    }

    public function deleteRule(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $this->rules->delete($supplierId, $id);
            $this->log($request, 'dimension.rule_deleted', $id, []);
            return ['deleted' => true];
        });
    }

    /** GET …/rules/audit?date_from&date_to — zaúčtované řádky bez povinné dimenze. */
    public function auditRules(Request $request, Response $response): Response
    {
        $range = $this->dateRange($request, $response, $err);
        if ($range === null) return $err;
        return Json::ok($response, $this->ruleAudit->violations($this->currentSupplierId($request), $range[0], $range[1]));
    }

    /** GET …/rules/coverage?date_from&date_to — pokrytí účtů dimenzemi (návrh pravidel). */
    public function ruleCoverage(Request $request, Response $response): Response
    {
        $range = $this->dateRange($request, $response, $err);
        if ($range === null) return $err;
        return Json::ok($response, $this->ruleAudit->coverage($this->currentSupplierId($request), $range[0], $range[1]));
    }

    /** @return array{0:string,1:string}|null */
    private function dateRange(Request $request, Response $response, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();
        $year = (int) date('Y');
        $from = (string) ($q['date_from'] ?? $year . '-01-01');
        $to = (string) ($q['date_to'] ?? $year . '-12-31');
        foreach ([$from, $to] as $d) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if ($parsed === false || $parsed->format('Y-m-d') !== $d) {
                $err = Json::error($response, 'validation_failed', 'date_from a date_to musí být datum (YYYY-MM-DD).', 422);
                return null;
            }
        }
        if ($to < $from) {
            $err = Json::error($response, 'validation_failed', 'date_to nesmí být před date_from.', 422);
            return null;
        }
        $err = null;
        return [$from, $to];
    }

    /**
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private static function ruleAudit(array $rule): array
    {
        return array_intersect_key($rule, array_flip([
            'dimension_type_id', 'account_mask', 'enforcement', 'default_value_id', 'default_from_card',
            'valid_from', 'valid_to', 'is_active',
        ]));
    }

    public function overview(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->overview($this->currentSupplierId($request)));
    }

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'settings.company.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = (bool) ($body['enabled'] ?? false);
        try {
            $this->dimensions->setEnabled($supplierId, $enabled);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($enabled && !empty($body['create_defaults'])) {
            $this->dimensions->ensureDefaultTypes($supplierId);
        }
        $this->log($request, 'dimension.settings_updated', null, ['enabled' => $enabled]);
        return Json::ok($response, $this->dimensions->overview($supplierId));
    }

    public function defaults(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $ids = $this->dimensions->ensureDefaultTypes($supplierId);
            $this->log($request, 'dimension.defaults_created', null, $ids);
            return $this->dimensions->overview($supplierId);
        });
    }

    public function createType(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $type = $this->dimensions->createType($supplierId, (array) ($request->getParsedBody() ?? []));
            $this->log($request, 'dimension.type_created', (int) $type['id'], ['code' => $type['code'], 'level' => $type['level']]);
            return $type;
        }, 201);
    }

    public function updateType(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $type = $this->dimensions->updateType($supplierId, $id, $body, $this->userId($request), 'http');
            $this->log($request, 'dimension.type_updated', $id, ['fields' => array_keys($body)]);
            return $type;
        });
    }

    public function deleteType(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $result = $this->dimensions->deleteType($supplierId, $id);
            $this->log($request, $result['deleted'] ? 'dimension.type_deleted' : 'dimension.type_deactivated', $id, []);
            return $result;
        });
    }

    public function createValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $value = $this->dimensions->createValue($supplierId, (int) ($args['id'] ?? 0), (array) ($request->getParsedBody() ?? []), $this->userId($request), 'http');
            $this->log($request, 'dimension.value_created', (int) $value['id'], ['type_id' => $value['type_id'], 'code' => $value['code']]);
            return $value;
        }, 201);
    }

    public function updateValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $value = $this->dimensions->updateValue($supplierId, $id, $body, $this->userId($request), 'http');
            $this->log($request, 'dimension.value_updated', $id, ['fields' => array_keys($body)]);
            return $value;
        });
    }

    public function deleteValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $result = $this->dimensions->deleteValue($supplierId, $id, $this->userId($request), 'http');
            $this->log($request, $result['deleted'] ? 'dimension.value_deleted' : 'dimension.value_closed', $id, []);
            return $result;
        });
    }

    public function responsibleCandidates(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->responsibleCandidates($this->currentSupplierId($request)));
    }

    public function getDocument(Request $request, Response $response, array $args): Response
    {
        $docType = self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '';
        try {
            return Json::ok($response, self::withSplitObject($this->dimensions->documentDimensions(
                $this->currentSupplierId($request),
                $docType,
                (int) ($args['id'] ?? 0),
            )));
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function saveDocument(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $docType = self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '';
            $docId = (int) ($args['id'] ?? 0);
            $this->assertTokenMayTagDocument($request, $supplierId, $docType, $docId);
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->dimensions->saveDocument(
                $supplierId,
                $docType,
                $docId,
                (array) ($body['header'] ?? []),
                self::itemsFromBody($body),
                false,
                self::splitsFromBody($body),
            );
            $this->logDocument($request, $docId, $docType, $result, 'document');
            return self::withSplitObject($result);
        });
    }

    /**
     * API token smí dimenze (středisko, projekt…) zapsat jen ke KONCEPTU ostatní
     * pohledávky nebo závazku (ApiScopeMiddleware::BEARER_WRITE_EXCEPTIONS).
     * U zaúčtovaného dokladu by uložení přerazítkovalo řádky deníku a koncept
     * z opakování s automatickým účtováním zaúčtuje cron bez dalšího potvrzení;
     * obojí zůstává webovému rozhraní. Middleware pouští jen cestu other-items,
     * tady se to hlídá znovu, aby výjimka nezávisela jen na regexu cesty.
     */
    private function assertTokenMayTagDocument(Request $request, int $supplierId, string $docType, int $docId): void
    {
        if (!RequestAuthorization::isBearerAuth($request)) {
            return;
        }
        if ($docType !== 'other_item') {
            throw new DimensionException('token_write_forbidden',
                'Přes API token lze dimenze zapsat jen ke konceptu ostatní pohledávky nebo závazku.', 403);
        }
        if (!RequestAuthorization::allows($request, 'other_items', AccessLevel::WRITE)) {
            throw new DimensionException('forbidden', 'Chybí oprávnění k ostatním položkám.', 403);
        }
        $item = $this->otherItems->find($supplierId, $docId);
        if ($item === null) {
            throw new DimensionException('not_found', 'Doklad nebyl nalezen.', 404);
        }
        if ((string) $item['status'] !== 'draft') {
            throw new DimensionException('token_write_forbidden',
                'Přes API token lze dimenze zapsat jen ke konceptu; u potvrzeného nebo zaúčtovaného dokladu je změňte ve webovém rozhraní.', 403);
        }
        if ($this->otherItemService->belongsToAutoPostSchedule($supplierId, $docId)) {
            throw new DimensionException('token_write_forbidden',
                'Koncept patří do opakování s automatickým účtováním. Dimenze mu lze změnit jen ve webovém rozhraní.', 403);
        }
    }

    /**
     * Rozpad jen tehdy, když ho tělo posílá (`splits` = pořadí položky → typ →
     * [{value_id, share}]); bez klíče se dosavadní rozpad ponechá.
     *
     * @param array<string,mixed> $body
     * @return array<int|string,mixed>|null
     */
    private static function splitsFromBody(array $body): ?array
    {
        return array_key_exists('splits', $body) ? (array) $body['splits'] : null;
    }

    /**
     * `splits` indexované pořadím položky (0 = hlavička) by JSON zapsal jako pole —
     * klient čeká objekt.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function withSplitObject(array $result): array
    {
        if (array_key_exists('splits', $result)) {
            $result['splits'] = (object) array_map(static fn (array $byType): object => (object) $byType, $result['splits']);
        }
        return $result;
    }

    /**
     * POST …/documents/{doc}/{id}/preview — co by po uložení dimenzí neslo každý
     * zaúčtovaný řádek dokladu (dialog Přeúčtovat). Nic se neuloží.
     */
    public function previewDocument(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            return self::withSplitObject($this->dimensions->previewDocument(
                $supplierId,
                self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '',
                (int) ($args['id'] ?? 0),
                (array) ($body['header'] ?? []),
                self::itemsFromBody($body),
                self::splitsFromBody($body),
            ));
        });
    }

    /**
     * Položky jen tehdy, když je tělo posílá — panel na detailu dokladu (a dialog
     * Přeúčtovat) mění jen hlavičku a dimenze položek z editoru smazat nesmí.
     *
     * @param array<string,mixed> $body
     * @return array<int|string,mixed>|null
     */
    private static function itemsFromBody(array $body): ?array
    {
        return array_key_exists('items', $body) ? (array) $body['items'] : null;
    }

    /**
     * Audit změny dimenzí dokladu — kdo a kdy změnil analytiku i zaúčtovaných řádků
     * (i v uzavřeném období, kde jinak žádná změna zápisu projít nesmí).
     *
     * @param array{header:array<int,int>, items:array<int,array<int,int>>, restamp:array{lines:int,needs_repost:bool,locked:bool}} $result
     */
    private function logDocument(Request $request, int $docId, string $docType, array $result, string $via): void
    {
        $this->log($request, 'dimension.document_updated', $docId, [
            'doc_type' => $docType,
            'via' => $via,
            'header' => $result['header'],
            'items' => $result['items'],
            'restamped_lines' => $result['restamp']['lines'],
            'locked_period' => $result['restamp']['locked'],
        ]);
    }

    public function getJournal(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, (object) $this->dimensions->entryLineDimensions(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        ));
    }

    public function saveJournal(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $entryId = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $splits = array_key_exists('splits', $body) ? (array) $body['splits'] : null;
            $changed = $this->dimensions->saveEntryLines($supplierId, $entryId, (array) ($body['lines'] ?? []), $splits);
            $this->log($request, 'dimension.journal_lines_updated', $entryId, [
                'lines' => (array) ($body['lines'] ?? []),
                'splits' => $splits,
                'changed' => $changed,
            ]);
            return [
                'changed' => $changed,
                'lines' => (object) $this->dimensions->entryLineDimensions($supplierId, $entryId),
                'splits' => (object) array_map(
                    static fn (array $byType): object => (object) $byType,
                    $this->dimensions->entryLineSplits($supplierId, $entryId),
                ),
            ];
        });
    }

    public function group(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->groupInfo(
            $this->currentSupplierId($request),
            $this->accessibleSupplierIds($request),
        ));
    }

    /** POST — založit novou skupinu s aktuální firmou. */
    public function createGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $id = $this->dimensions->createGroup($supplierId, (string) ($body['name'] ?? ''));
            $this->log($request, 'dimension.group_created', $id, ['name' => $body['name'] ?? null]);
            return $this->dimensions->groupInfo($supplierId, $this->accessibleSupplierIds($request));
        }, 201);
    }

    /** PUT — `{group_id}` připojí firmu k existující skupině, `{name}` skupinu přejmenuje. */
    public function updateGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $accessible = $this->accessibleSupplierIds($request);
            if (isset($body['group_id'])) {
                $groupId = (int) $body['group_id'];
                $this->dimensions->joinGroup($supplierId, $groupId, $accessible, RequestAuthorization::isSuperadmin($request));
                $this->log($request, 'dimension.group_joined', $groupId, []);
            } elseif (isset($body['name'])) {
                $this->dimensions->renameGroup($supplierId, (string) $body['name']);
                $this->log($request, 'dimension.group_renamed', null, ['name' => $body['name']]);
            }
            return $this->dimensions->groupInfo($supplierId, $accessible);
        });
    }

    /** DELETE — firma opustí skupinu (globální dimenze přestane vidět). */
    public function leaveGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $this->dimensions->leaveGroup($supplierId);
            $this->log($request, 'dimension.group_left', null, []);
            return $this->dimensions->groupInfo($supplierId, $this->accessibleSupplierIds($request));
        });
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /** @param callable(int):array<mixed>|object $fn */
    private function run(Request $request, Response $response, callable $fn, int $status = 200): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        try {
            return Json::ok($response, $fn($this->currentSupplierId($request)), $status);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'integrity_violation', 'Hodnota je použitá nebo koliduje s jinou.', 409);
            }
            throw $e;
        }
    }

    private function requireCompanyAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::isCompanyAdmin($request)
            || !RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Skupinu firem spravuje jen správce firmy.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Firmy, ke kterým má uživatel výslovný přístup. Superadmin vidí všechny; účet
     * bez výslovného členství tu nevidí žádnou — připojit firmu ke skupině smí jen
     * ten, kdo firmy skupiny prokazatelně spravuje.
     *
     * @return list<int>
     */
    private function accessibleSupplierIds(Request $request): array
    {
        if (RequestAuthorization::isSuperadmin($request)) {
            return $this->repo->allSupplierIds();
        }
        return $this->userSuppliers->allowedSupplierIds((int) ($this->userId($request) ?? 0));
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'dimension',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

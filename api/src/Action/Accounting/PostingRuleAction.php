<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingRuleChartAlignmentService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kontační pravidla (posting rules) — REST API (Epic F1).
 *
 *   GET  /api/accounting/posting-rules                  — efektivní mapa (globální + override)
 *   PUT  /api/accounting/posting-rules/{rule_key}       — per-tenant override — účetní|admin
 *   GET  /api/accounting/posting-rules/chart-alignment  — náhled srovnání s osnovou (dry-run)
 *   POST /api/accounting/posting-rules/chart-alignment  — zápis potvrzených voleb — účetní|admin
 *
 * Precedent vat_classifications: globální šablona (supplier_id NULL) + per-tenant
 * override (per-tenant vyhrává). Override drží jen MD/D účet (CODE); DPH a protiúčty
 * dle případu řeší PostingService.
 */
final class PostingRuleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly PostingRuleChartAlignmentService $alignment,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $map = $this->rules->effectiveMap($supplierId);
        // Objekt keyed rule_key → pravidlo (JSON object). Prázdné → {}.
        return Json::ok($response, (object) $map);
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $ruleKey = (string) ($args['rule_key'] ?? '');
        if ($ruleKey === '') {
            return Json::error($response, 'validation_failed', 'Chybí rule_key.', 422);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $debit = $this->nullableString($body['debit_account_code'] ?? null);
        $credit = $this->nullableString($body['credit_account_code'] ?? null);
        if ($debit === null && $credit === null) {
            return Json::error($response, 'validation_failed', 'Zadej alespoň debit_account_code nebo credit_account_code.', 422);
        }

        // Účty musí existovat v osnově firmy (jinak by pravidlo bylo nezaúčtovatelné).
        $codeMap = $this->accounts->codeToIdMap($supplierId);
        foreach (['debit_account_code' => $debit, 'credit_account_code' => $credit] as $field => $code) {
            if ($code !== null && !isset($codeMap[$code])) {
                return Json::error($response, 'unknown_account', "Účet {$code} není v účtové osnově firmy — nejdřív ho založ.", 422);
            }
        }

        // Popis: převezmi z existujícího efektivního pravidla, jinak fallback na rule_key.
        $existing = $this->rules->resolve($supplierId, $ruleKey);
        $description = $this->nullableString($body['description'] ?? null)
            ?? ($existing['description'] ?? $ruleKey);

        $id = $this->rules->upsertOverride($supplierId, $ruleKey, $debit, $credit, $description);

        $this->log($request, 'accounting.posting_rule_overridden', $id, [
            'rule_key'            => $ruleKey,
            'debit_account_code'  => $debit,
            'credit_account_code' => $credit,
        ]);

        return Json::ok($response, $this->rules->resolve($supplierId, $ruleKey));
    }

    /**
     * Náhled „Doplnit podle osnovy" — dry-run, nic nezapisuje. Zvlášť od `list()`,
     * protože nese i kandidáty z osnovy a statistiku deníku; mapa kontací se čte
     * na každé druhé obrazovce a tímhle by ztěžkla.
     */
    public function chartAlignment(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        return Json::ok($response, $this->alignment->preview($supplierId));
    }

    public function applyChartAlignment(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return Json::error($response, 'validation_failed', 'Chybí seznam pravidel k doplnění.', 422);
        }

        try {
            $result = $this->alignment->apply($supplierId, array_map(
                static fn (mixed $item): array => (array) $item,
                array_values($items),
            ));
        } catch (\RuntimeException $e) {
            // Zpráva nese strojový důvod (neznámý účet, cizí analytika) — klient ho
            // ukáže u řádku, ať uživatel nehádá, které z desítek voleb se týká.
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        foreach ($result['applied'] as $ruleKey) {
            $rule = $this->rules->resolve($supplierId, $ruleKey);
            $this->log($request, 'accounting.posting_rule_overridden', (int) ($rule['id'] ?? 0), [
                'rule_key'            => $ruleKey,
                'debit_account_code'  => $rule['debit_account_code'] ?? null,
                'credit_account_code' => $rule['credit_account_code'] ?? null,
                'source'              => 'chart_alignment',
            ]);
        }

        return Json::ok($response, $result);
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            $this->userId($request),
            'posting_rule',
            $entityId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

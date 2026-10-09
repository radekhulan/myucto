<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TaxEvidence\TaxEvidenceBankRules;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pravidla bankovních pohybů v daňové evidenci (issue #140, migrace 1990).
 *
 *   GET    /api/tax-evidence/bank-rules            — seznam
 *   POST   /api/tax-evidence/bank-rules            — založení
 *   PUT    /api/tax-evidence/bank-rules/{id}       — úprava
 *   DELETE /api/tax-evidence/bank-rules/{id}       — smazání
 *   POST   /api/tax-evidence/bank-rules/apply      — uplatní pravidla na stávající pohyby
 *
 * Jen v daňové evidenci; zápis sdílí oprávnění s ručním zařazením pohybu.
 */
final class TaxEvidenceBankRuleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly TaxEvidenceBankRules $rules,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, ['data' => $this->rules->list($supplierId)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $v = TaxEvidenceBankRules::validate((array) ($request->getParsedBody() ?? []));
        if ($v['errors'] !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $v['errors']]);
        }
        $id = $this->rules->create($supplierId, $v['data'], $this->userId($request));
        $this->log($request, 'tax_evidence.bank_rule_created', $id);
        return Json::ok($response, $this->rules->find($supplierId, $id), 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);
        if ($this->rules->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        $v = TaxEvidenceBankRules::validate((array) ($request->getParsedBody() ?? []));
        if ($v['errors'] !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $v['errors']]);
        }
        $this->rules->update($supplierId, $id, $v['data']);
        $this->log($request, 'tax_evidence.bank_rule_updated', $id);
        return Json::ok($response, $this->rules->find($supplierId, $id));
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);
        if (!$this->rules->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno.', 404);
        }
        $this->log($request, 'tax_evidence.bank_rule_deleted', $id);
        return Json::ok($response, ['deleted' => true]);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $result = $this->rules->apply($supplierId, null, $this->userId($request));
        $this->log($request, 'tax_evidence.bank_rules_applied', 0, $result);
        return Json::ok($response, $result);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $entityId, array $payload = []): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'tax_evidence_bank_rule',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

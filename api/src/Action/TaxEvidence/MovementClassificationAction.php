<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruční klasifikační override pohybů peněžního deníku (Epic DE, G2 — migrace 1027).
 *
 *   POST   /api/tax-evidence/classification                              — vytvoří/přepíše klasifikaci
 *   DELETE /api/tax-evidence/classification/{source_type}/{source_id}    — smaže override (pohyb se
 *                                                                           vrátí k auto-klasifikaci)
 *
 * Bez legální cesty pohyb zařadit generuje CashJournalService (R10) BLOKUJÍCÍ hlášku
 * u nespárovaných příchozích bankovních pohybů — propaguje se až do DPFO přiznání
 * (kasová báze, IncomeTaxBuilder/DpfoReturnDataProvider).
 *
 * Zápis = účetní|admin (PermissionMiddleware route permission rules `* /api/tax-evidence/classification`);
 * klient DENY (CLIENT_DENY_RULES na celou `/api/tax-evidence` skupinu). Tenant izolace:
 * MovementClassificationRepository::belongsToSupplier — bez ní by šlo klasifikovat cizí
 * bankovní pohyb (bank_transactions nemá supplier_id, žádná FK z 1027 to nehlídá).
 */
final class MovementClassificationAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly MovementClassificationRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $sourceType = (string) ($body['source_type'] ?? '');
        $sourceId = (int) ($body['source_id'] ?? 0);
        $taxBucket = (string) ($body['tax_bucket'] ?? '');
        $note = $this->nullableNote($body['note'] ?? null);

        if (!in_array($sourceType, ['bank', 'cash'], true)) {
            return Json::error($response, 'validation_failed', "source_type musí být 'bank' nebo 'cash'.", 422);
        }
        if ($sourceId <= 0) {
            return Json::error($response, 'validation_failed', 'source_id musí být kladné celé číslo.', 422);
        }
        if (!in_array($taxBucket, MovementClassificationRepository::TAX_BUCKETS, true)) {
            return Json::error($response, 'validation_failed', 'tax_bucket má neplatnou hodnotu.', 422);
        }
        if ($note !== null && mb_strlen($note) > 255) {
            return Json::error($response, 'validation_failed', 'note smí mít nejvýš 255 znaků.', 422);
        }

        $row = $this->withOpenSource($response, $supplierId, $sourceType, $sourceId,
            function () use ($request, $body, $supplierId, $sourceType, $sourceId, $taxBucket, $note): array {
                // Vynechaná poznámka = uložená poznámka (issue #113); smaže ji jen explicitní null/"".
                if (!array_key_exists('note', $body)) {
                    $note = $this->repo->find($supplierId, $sourceType, $sourceId)['note'] ?? null;
                }
                return $this->repo->upsert($supplierId, $sourceType, $sourceId, $taxBucket, $note, $this->userId($request));
            },
        );
        if ($row instanceof Response) {
            return $row;
        }

        $this->log($request, 'tax_evidence.movement_classified', (int) $row['id'], [
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'tax_bucket'  => $taxBucket,
        ]);

        return Json::ok($response, $row, 201);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireTaxEvidence($this->db, $supplierId, $response, $err)) return $err;

        $sourceType = (string) ($args['source_type'] ?? '');
        $sourceId = (int) ($args['source_id'] ?? 0);
        if (!in_array($sourceType, ['bank', 'cash'], true) || $sourceId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatná identifikace pohybu.', 422);
        }

        $deleted = $this->withOpenSource($response, $supplierId, $sourceType, $sourceId,
            fn (): bool => $this->repo->delete($supplierId, $sourceType, $sourceId, $this->userId($request)),
        );
        if ($deleted instanceof Response) {
            return $deleted;
        }

        if ($deleted) {
            $this->log($request, 'tax_evidence.movement_classification_deleted', $sourceId, [
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
            ]);
        }

        return Json::ok($response, ['deleted' => $deleted]);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T|Response
     */
    private function withOpenSource(Response $response, int $supplierId, string $sourceType, int $sourceId, callable $operation): mixed
    {
        return TaxEvidenceYearLock::synchronized($this->db, $supplierId,
            function () use ($response, $supplierId, $sourceType, $sourceId, $operation): mixed {
                $date = $this->repo->sourceDate($supplierId, $sourceType, $sourceId);
                if ($date === null) {
                    return Json::error($response, 'not_found', 'Pohyb nebyl nalezen.', 404);
                }
                $year = (int) substr($date, 0, 4);
                if (TaxEvidenceYearLock::isFinal($this->db, $supplierId, $year)) {
                    return Json::error($response, TaxEvidenceYearLock::ERROR_CODE, TaxEvidenceYearLock::message($year), 409);
                }
                return $operation();
            },
        );
    }

    private function nullableNote(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'de_movement_classification',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

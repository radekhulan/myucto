<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Accounting\Note\JournalNoteActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalNoteRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Poznámky k pohybům peněžního deníku daňové evidence (migrace 1993).
 *
 *   GET    /api/tax-evidence/cash-journal/notes/{source}/{id}           — poznámky pohybu
 *   POST   /api/tax-evidence/cash-journal/notes/{source}/{id}           — nová poznámka {body, pinned?}
 *   PATCH  /api/tax-evidence/cash-journal/notes/{source}/{id}/{noteId}  — úprava {body?, pinned?}
 *   DELETE /api/tax-evidence/cash-journal/notes/{source}/{id}/{noteId}  — smazání (soft)
 *
 * `source` je typ řádku deníku (cash, bank, invoice_payment, purchase_invoice, gopay).
 * Přílohy jdou existující vazbou dokument ↔ entita (document_links), viz
 * {@see CashJournalNoteRepository::ATTACHMENT_ENTITY}. Poznámka nemění částky ani
 * zařazení pohybu, proto ji uzávěrka roku neblokuje (shodně s poznámkami zápisu).
 */
final class CashJournalNoteAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;
    use JournalNoteActionSupport;

    public function __construct(
        private readonly CashJournalNoteRepository $notes,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        $source = $this->source($request, $response, $args, $err);
        if ($source === null) return $err;
        [$supplierId, $type, $id] = $source;
        return Json::ok($response, ['notes' => $this->notes->list($supplierId, $type, $id)]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'tax_evidence', AccessLevel::WRITE, $err)) return $err;
        $source = $this->source($request, $response, $args, $err);
        if ($source === null) return $err;
        [$supplierId, $type, $id] = $source;

        $payload = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('body', $payload)) {
            return Json::error($response, 'validation_failed', 'Chybí pole body.', 422);
        }
        $body = $this->validateBody($payload['body'], $response, $err);
        if ($body === null) return $err;
        if ($this->notes->countLive($supplierId, $type, $id) >= CashJournalNoteRepository::MAX_NOTES_PER_SOURCE) {
            return Json::error($response, 'too_many_notes',
                'Pohyb už má maximální počet poznámek (' . CashJournalNoteRepository::MAX_NOTES_PER_SOURCE . ').', 409);
        }
        $pinned = $this->optionalBool($payload, 'pinned') ?? false;
        $noteId = $this->notes->add($supplierId, $type, $id, $body, $pinned, $this->userId($request));
        $this->log($request, 'tax_evidence.cash_journal_note_created', $supplierId, $type, $id, ['note_id' => $noteId, 'pinned' => $pinned]);
        return Json::ok($response, ['notes' => $this->notes->list($supplierId, $type, $id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'tax_evidence', AccessLevel::WRITE, $err)) return $err;
        $source = $this->source($request, $response, $args, $err);
        if ($source === null) return $err;
        [$supplierId, $type, $id] = $source;

        $payload = (array) ($request->getParsedBody() ?? []);
        $body = null;
        if (array_key_exists('body', $payload)) {
            $body = $this->validateBody($payload['body'], $response, $err);
            if ($body === null) return $err;
        }
        $pinned = $this->optionalBool($payload, 'pinned');
        if ($body === null && $pinned === null) {
            return Json::error($response, 'validation_failed', 'Není co změnit (body nebo pinned).', 422);
        }
        $noteId = (int) ($args['noteId'] ?? 0);
        if (!$this->notes->update($noteId, $supplierId, $type, $id, $body, $pinned, $this->userId($request))) {
            return Json::error($response, 'not_found', 'Poznámka nenalezena.', 404);
        }
        $this->log($request, 'tax_evidence.cash_journal_note_updated', $supplierId, $type, $id, ['note_id' => $noteId]);
        return Json::ok($response, ['notes' => $this->notes->list($supplierId, $type, $id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'tax_evidence', AccessLevel::WRITE, $err)) return $err;
        $source = $this->source($request, $response, $args, $err);
        if ($source === null) return $err;
        [$supplierId, $type, $id] = $source;
        $noteId = (int) ($args['noteId'] ?? 0);
        if (!$this->notes->softDelete($noteId, $supplierId, $type, $id, $this->userId($request))) {
            return Json::error($response, 'not_found', 'Poznámka nenalezena.', 404);
        }
        $this->log($request, 'tax_evidence.cash_journal_note_deleted', $supplierId, $type, $id, ['note_id' => $noteId]);
        return Json::ok($response, ['notes' => $this->notes->list($supplierId, $type, $id)]);
    }

    /** @return array{0:int,1:string,2:int}|null firma, typ pohybu, id pohybu */
    private function source(Request $request, Response $response, array $args, ?Response &$err): ?array
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return null;
        $type = (string) ($args['source'] ?? '');
        $id = (int) ($args['id'] ?? 0);
        if (!in_array($type, CashJournalNoteRepository::SOURCE_TYPES, true)
            || !$this->notes->sourceBelongsToSupplier($type, $id, $supplierId)) {
            $err = Json::error($response, 'not_found', 'Pohyb peněžního deníku nenalezen.', 404);
            return null;
        }
        $err = null;
        return [$supplierId, $type, $id];
    }

    /** @param array<string,mixed> $meta */
    private function log(Request $request, string $event, int $supplierId, string $type, int $id, array $meta): void
    {
        $this->logger->log($event, $this->userId($request), 'cash_journal_movement', $id, ['source_type' => $type] + $meta,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
    }
}

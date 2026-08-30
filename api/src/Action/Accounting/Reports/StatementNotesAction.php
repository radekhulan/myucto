<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Reports\StatementNotesService;
use MyInvoice\Service\ActivityLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příloha k účetní závěrce — § 18 odst. 1 písm. c) ZoÚ, § 39/39a/39b vyhlášky 500/2002.
 *
 *   GET /api/accounting/periods/{id}/statement-notes           — sekce, obsah, co chybí
 *   PUT /api/accounting/periods/{id}/statement-notes/{section} — uložení textu sekce
 *
 * Rozsah sekcí se stupňuje podle kategorie účetní jednotky a povinného auditu; službu
 * o tom rozhoduje {@see StatementNotesService}, akce jen předává.
 */
final class StatementNotesAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly StatementNotesService $notes,
        private readonly ActivityLogger $logger,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        try {
            return Json::ok($response, $this->notes->build($supplierId, (int) ($args['id'] ?? 0)));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Přílohu k závěrce se nepodařilo sestavit');
        }
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $periodId = (int) ($args['id'] ?? 0);
        $section = trim((string) ($args['section'] ?? ''));
        $body = (array) ($request->getParsedBody() ?? []);
        $content = array_key_exists('content', $body) ? (string) $body['content'] : null;

        try {
            $data = $this->notes->build($supplierId, $periodId);
            $this->notes->saveSection($supplierId, (int) $data['fiscal_year'], $section, $content, $userId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Sekci přílohy se nepodařilo uložit');
        }

        $this->logger->log('accounting.statement_note_saved', $userId, 'accounting_period', $periodId, [
            'section' => $section,
            'filled'  => $content !== null && trim($content) !== '',
        ]);

        return Json::ok($response, $this->notes->build($supplierId, $periodId));
    }

    /**
     * Převzetí textů přílohy z minulého roku.
     *
     * Je to POST, ne součást GET: příloha je součástí účetní závěrky a loňská věta
     * může být letos nepravdivá, takže převzetí musí být vědomý krok účetní, ne tiché
     * předvyplnění při otevření stránky. Vlastní text se nikdy nepřepisuje.
     */
    public function carryOver(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;
        $periodId = (int) ($args['id'] ?? 0);

        try {
            $data = $this->notes->build($supplierId, $periodId);
            $result = $this->notes->carryOverFromPreviousYear($supplierId, (int) $data['fiscal_year'], $userId);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e, 'Přílohu se nepodařilo převzít z minulého roku');
        }

        $this->logger->log('accounting.statement_notes_carried_over', $userId, 'accounting_period', $periodId, [
            'source_year' => $result['source_year'],
            'sections'    => $result['copied'],
        ]);

        return Json::ok($response, [
            'carried' => $result['copied'],
            'notes'   => $this->notes->build($supplierId, $periodId),
        ]);
    }
}

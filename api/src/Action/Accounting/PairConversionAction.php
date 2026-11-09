<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Pair\JournalPairConversionService;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Převod starších zápisů na dvojice jednoduchého zápisu (náhled nanečisto i provedení).
 *
 *   GET  /api/accounting/pair-conversion               … poslední běhy
 *   POST /api/accounting/pair-conversion/preview       { date_from?, date_to? } … náhled na pozadí
 *   POST /api/accounting/pair-conversion/apply         { date_from?, date_to? } … převod na pozadí
 *   GET  /api/accounting/pair-conversion/{id}          … stav běhu pro polling
 *   POST /api/accounting/pair-conversion/{id}/cancel
 *
 * Převod mění řádky deníku, proto zápisové cesty chtějí `accounting.journal.post`
 * (shodně s RoutePermissionMap); čtení stavu stačí `accounting`.
 */
final class PairConversionAction
{
    use AccountingActionSupport;

    public function __construct(private readonly JournalPairConversionService $conversions) {}

    /** GET /api/accounting/pair-conversion */
    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        try {
            return Json::ok($response, ['runs' => $this->conversions->runs($this->currentSupplierId($request))]);
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
    }

    /** POST /api/accounting/pair-conversion/preview */
    public function preview(Request $request, Response $response): Response
    {
        return $this->start($request, $response, false);
    }

    /** POST /api/accounting/pair-conversion/apply */
    public function apply(Request $request, Response $response): Response
    {
        return $this->start($request, $response, true);
    }

    /** GET /api/accounting/pair-conversion/{id} */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        try {
            $run = $this->conversions->run($this->currentSupplierId($request), (int) ($args['id'] ?? 0));
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        if ($run === null) {
            return Json::error($response, 'not_found', 'Běh převodu nenalezen.', 404);
        }
        return Json::ok($response, $run);
    }

    /** POST /api/accounting/pair-conversion/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        try {
            $cancelled = $this->conversions->cancel(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                (int) ($this->userId($request) ?? 0),
            );
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        return Json::ok($response, ['cancelled' => $cancelled]);
    }

    private function start(Request $request, Response $response, bool $apply): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $range = [];
        foreach (['date_from', 'date_to'] as $key) {
            $value = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
            if ($value !== '' && !IsoDate::isValid($value)) {
                return Json::error($response, 'validation_failed', $key . ' musí být datum (YYYY-MM-DD).', 422);
            }
            $range[$key] = $value !== '' ? $value : null;
        }
        if ($range['date_from'] !== null && $range['date_to'] !== null && $range['date_from'] > $range['date_to']) {
            return Json::error($response, 'validation_failed', 'date_from nesmí být po date_to.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        $actor = (int) ($this->userId($request) ?? 0);
        try {
            $runId = $apply
                ? $this->conversions->apply($supplierId, $range['date_from'], $range['date_to'], $actor)
                : $this->conversions->preview($supplierId, $range['date_from'], $range['date_to'], $actor);
        } catch (JournalPairException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        return Json::ok($response, ['run_id' => $runId], 202);
    }
}

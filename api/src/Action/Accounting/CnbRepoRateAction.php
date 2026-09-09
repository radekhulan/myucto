<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Repository\CnbRepoRateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník 2týdenní repo sazby ČNB (rozhodná pro úrok z prodlení, NV 351/2013).
 * Globální (napříč firmami), editace jen správce instalace.
 *
 *   GET    /api/accounting/repo-rates            — seznam sazeb
 *   PUT    /api/accounting/repo-rates            — upsert sazby (valid_from, rate)
 *   DELETE /api/accounting/repo-rates/{date}     — smazání sazby
 */
final class CnbRepoRateAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly CnbRepoRateRepository $rates,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, ['rates' => $this->rates->list()]);
    }

    public function upsert(Request $request, Response $response): Response
    {
        if (!\MyInvoice\Security\RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze správce instalace.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $validFrom = trim((string) ($body['valid_from'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
            return Json::error($response, 'validation_failed', 'valid_from musí být datum (YYYY-MM-DD).', 422);
        }
        $rate = round((float) ($body['rate'] ?? -1), 3);
        if ($rate < 0 || $rate > 100) {
            return Json::error($response, 'validation_failed', 'rate musí být 0–100 %.', 422);
        }
        $note = isset($body['note']) && trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null;

        $this->rates->upsert($validFrom, $rate, $note);

        $this->activity->log(
            'accounting.repo_rate_upserted',
            $this->userId($request),
            'cnb_repo_rate',
            null,
            ['valid_from' => $validFrom, 'rate' => $rate],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['rates' => $this->rates->list()]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!\MyInvoice\Security\RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze správce instalace.', 403);
        }

        $date = (string) ($args['date'] ?? '');
        if (!$this->rates->delete($date)) {
            return Json::error($response, 'not_found', 'Repo sazba nenalezena.', 404);
        }
        return Json::ok($response, ['deleted' => true]);
    }
}

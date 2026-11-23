<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapApplyService;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalStore;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Návrh mzdových předkontací odvozený z převzatého zaúčtování (PAM-16).
 *
 * Čtení ukáže, co z převzatých dat vyšlo, i s doložením; zápis promítne do
 * nastavení zaměstnavatele JEN to, co účetní výslovně potvrdila. Samotné
 * převzaté zápisy se nikam neúčtují - mzdy zaúčtuje MyÚčto vlastní cestou.
 */
final class PayrollPostingMapAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPostingMapProposalStore $proposals,
        private readonly PayrollPostingMapApplyService $apply,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }

        $query = (array) $request->getQueryParams();
        $source = trim((string) ($query['source'] ?? ''));
        try {
            $proposal = $this->proposals->find(
                $this->currentSupplierId($request),
                $source === '' ? null : $source,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'proposal' => $proposal,
            'sources' => PayrollPostingMapProposalStore::SOURCES,
        ]);
    }

    public function confirm(Request $request, Response $response): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $source = trim((string) ($body['source'] ?? ''));
        $rowVersion = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($rowVersion === false) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být nezáporné celé číslo.',
                422,
            );
        }

        $confirmations = [];
        foreach ((array) ($body['confirmations'] ?? []) as $key => $code) {
            if (!is_string($key) || !is_string($code)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Potvrzené předkontace musí být dvojice klíč => účet.',
                    422,
                );
            }
            $confirmations[$key] = $code;
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->apply->apply(
                $supplierId,
                $this->userId($request),
                $source,
                $confirmations,
                (int) $rowVersion,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEmployerSettingsConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }

        $this->logger->log(
            'payroll.posting_map.confirmed',
            $this->userId($request),
            'payroll_posting_map_proposals',
            $supplierId,
            ['source' => $source, 'accounts' => array_keys($confirmations)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::actsAsSession($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        if (!$this->requirePermission($request, $response, 'payroll.settings', $level, $error)) {
            return false;
        }

        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}

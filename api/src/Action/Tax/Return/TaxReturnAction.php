<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tax\Return;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Shared\FiledDppoImporter;
use MyInvoice\Service\Tax\Return\DppoReconciliationService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnReportService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxFamilyIdentifierService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * REST API přiznání k dani z příjmů (Epic DP, issue #18):
 *   GET    /api/tax-return/{type}/{year}          → podklady + řádky + vstupy + warnings
 *   PUT    /api/tax-return/{type}/{year}/inputs   → uložit ruční vstupy (draft, row_version)
 *   POST   /api/tax-return/{type}/{year}/finalize → zmrazit computed snapshot
 *   POST   /api/tax-return/{type}/{year}/reopen   → vrátit do draftu
 *   GET    /api/tax-return/{type}/{year}/xml      → validované EPO XML (archivace + log)
 *   POST   /api/tax-return/{type}/{year}/reconcile → Featura A: diff proti nahranému
 *                                                     podanému EPO XML (jen po, read-only)
 *   POST   /api/tax-return/{type}/{year}/filed-import/preview → náhled převzetí podaného
 *                                                     EPO XML do vstupů (jen po, nic neukládá)
 *   POST   /api/tax-return/{type}/{year}/filed-import → převzetí podaného EPO XML do vstupů
 *                                                     a evidence ztrát (jen po)
 *
 * RBAC (defense-in-depth, zrcadlí PermissionMiddleware): čtení/XML admin|účetní|readonly,
 * zápis (vstupy/finalize/reopen) admin|účetní. Klient nemá přístup (CLIENT_DENY).
 */
final class TaxReturnAction
{
    /** Nahraný soubor EPO XML může nést vloženou přílohu (base64 PDF) — desítky KB až pár MB. */
    private const RECONCILE_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly TaxReturnService $service,
        private readonly DppoReconciliationService $reconciliation,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TaxReturnReportService $reports,
        private readonly FiledDppoImporter $filedImporter,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        // Koncept a párování záloh vznikají jen v aplikaci; API token přiznání jen čte.
        $session = RequestAuthorization::isSessionAuth($request);
        return $this->run($response, function () use ($request, $year, $type, $session): array {
            $state = $this->service->getReturn(
                SupplierGuard::currentId($request), $year, $type, $this->userId($request), $this->variant($request), $this->seq($request),
                $session,
            );
            // API token a MCP dostanou z rodiny jen počty měsíců a částky (H28).
            return $session ? $state : TaxFamilyIdentifierService::bearerReturnState($state);
        });
    }

    public function prefinalizeCheck(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->prefinalizeCheck(
            SupplierGuard::currentId($request), $year, $type, $this->variant($request), $this->seq($request)
        ));
    }

    public function putInputs(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        if ($this->service->isNonprofit(SupplierGuard::currentId($request), $year, $type) && ($deny = \MyInvoice\Http\SessionOnlyAccountingAct::deny($request, $response, 'Potvrzení přiznání 504')) !== null) return $deny;
        $body = (array) ($request->getParsedBody() ?? []);
        $inputs = (array) ($body['inputs'] ?? []);
        $rowVersion = (int) ($body['row_version'] ?? 0);
        return $this->run($response, fn () => $this->service->saveInputs(
            SupplierGuard::currentId($request), $year, $type, $inputs, $rowVersion, $this->userId($request), $this->variant($request), $this->seq($request), partial: true
        ));
    }

    public function finalize(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE, 'reports.finalize')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        if ($this->service->isNonprofit(SupplierGuard::currentId($request), $year, $type) && ($deny = \MyInvoice\Http\SessionOnlyAccountingAct::deny($request, $response, 'Potvrzení přiznání 504')) !== null) return $deny;
        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = (int) ($body['row_version'] ?? 0);
        return $this->run($response, fn () => $this->service->finalize(
            SupplierGuard::currentId($request), $year, $type, $rowVersion, $this->userId($request), $this->variant($request), $this->seq($request), is_string($body['nonprofit_filing_proof_hash'] ?? null) ? $body['nonprofit_filing_proof_hash'] : null
        ));
    }

    public function reopen(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE, 'reports.reopen')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        if ($this->service->isNonprofit(SupplierGuard::currentId($request), $year, $type) && ($deny = \MyInvoice\Http\SessionOnlyAccountingAct::deny($request, $response, 'Potvrzení přiznání 504')) !== null) return $deny;
        $body = (array) ($request->getParsedBody() ?? []);
        $rowVersion = (int) ($body['row_version'] ?? 0);
        return $this->run($response, fn () => $this->service->reopen(
            SupplierGuard::currentId($request), $year, $type, $rowVersion, $this->userId($request), $this->variant($request), $this->seq($request)
        ));
    }

    public function xml(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $variant = $this->variant($request);
        $seq = $this->seq($request);
        try {
            $built = $this->service->generateXml($supplierId, $year, $type, $userId, $variant, $seq, RequestAuthorization::isSessionAuth($request));
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        $this->logger->log('tax_return.xml_downloaded', $userId, null, null, [
            'year' => $year, 'type' => $type, 'variant' => $variant,
            'submission_id' => $built['submission_id'],
            'validation_status' => $built['validation_status'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($this->familyScopedXml($request, $type, $built['xml']));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader('X-Validation-Status', $built['validation_status'])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function previewXml(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        try {
            $built = $this->service->previewXml(
                SupplierGuard::currentId($request), $year, $type, $this->variant($request), $this->seq($request)
            );
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        $response->getBody()->write($this->familyScopedXml($request, $type, $built['xml']));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader($type === 'po' ? 'X-DPPO-Preview' : 'X-DPFO-Preview', '1')
            ->withHeader('X-Business-Errors', (string) count($built['business_errors']))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Pracovní PDF sestava přiznání (DPPO i DPFO) pro kontrolu s účetní a archiv. Není
     * podáním: nic nearchivuje, jen čte stejné XML a výpočet jako export.
     */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        try {
            $built = $this->reports->pdf(
                SupplierGuard::currentId($request), $year, $type, $this->variant($request), $this->seq($request)
            );
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        $response->getBody()->write($built['pdf']);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function insurance(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->getInsurance(
            SupplierGuard::currentId($request), $year, $type
        ));
    }

    public function insurancePdf(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        try {
            $built = $this->service->insurancePdf(SupplierGuard::currentId($request), $year, $type);
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        $response->getBody()->write($built['pdf']);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** E11 — PDF Přehled OSVČ pro zdravotní pojišťovnu (jen FO/OSVČ). */
    public function healthPdf(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        try {
            $built = $this->service->healthInsurancePdf(SupplierGuard::currentId($request), $year, $type);
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        $response->getBody()->write($built['pdf']);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** E9 — předpisy záloh na daň a pojistné za rok (period_year). */
    public function advanceSchedules(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->advanceScheduleList(
            SupplierGuard::currentId($request), $year, $type, RequestAuthorization::isSessionAuth($request)
        ));
    }

    /** E9 — ručně (re)vygeneruje předpisy záloh z finalizovaného přiznání za daný rok. */
    public function generateAdvances(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->generateAdvanceSchedules(
            SupplierGuard::currentId($request), $year, $type
        ));
    }

    /** E9 — spáruje bankovní platby s předpisy záloh za rok a předvyplní přiznání. */
    public function matchAdvances(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->matchAdvancePayments(
            SupplierGuard::currentId($request), $year, $type
        ));
    }

    /** E9 — nadcházející zálohy napříč druhy pro dashboard widget. */
    public function upcomingAdvances(Request $request, Response $response, array $args = []): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        return $this->run($response, fn () => $this->service->upcomingAdvances(
            SupplierGuard::currentId($request)
        ));
    }

    /** E9/#42 — (re)vygeneruje předpisy záloh PRO tento rok (z draftu min. roku / override). */
    public function generateAdvancesForPeriod(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->generateAdvanceSchedulesForPeriod(
            SupplierGuard::currentId($request), $year, $type
        ));
    }

    // Pozn.: per-rok varianta override (#43, advances/override singulár) byla odstraněna —
    // plně ji nahradil id-based CRUD s rozsahem OD-DO (#46, advances/overrides).

    /** E9/#43 — ruční úprava předepsané výše NEzaplaceného předpisu. */
    public function updateAdvanceAmount(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        // Jediná hodnota akce — chybějící částka nesmí tiše vynulovat předpis (explicitní null ano).
        $rawAmount = $body['amount'] ?? null;
        if (!array_key_exists('amount', $body)
            || ($rawAmount !== null && $rawAmount !== '' && !is_numeric($rawAmount))) {
            return Json::error($response, 'validation_failed', 'Chybí nebo není číslo: amount.', 400, ['fields' => ['amount' => 'required']]);
        }
        $amount = (float) ($rawAmount ?? 0);
        return $this->run($response, fn () => $this->service->updateAdvanceAmount(
            SupplierGuard::currentId($request), $year, $type, (int) ($args['scheduleId'] ?? 0), $amount
        ));
    }

    /** E9/#43 — ruční potvrzení úhrady předpisu (bez bankovní transakce). */
    public function confirmAdvance(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $amount = isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null ? (float) $body['amount'] : null;
        $paidOn = isset($body['paid_on']) ? (string) $body['paid_on'] : null;
        return $this->run($response, fn () => $this->service->confirmAdvancePaid(
            SupplierGuard::currentId($request), $year, $type, (int) ($args['scheduleId'] ?? 0), $amount, $paidOn
        ));
    }

    /** E9/#43 — hromadné „vše zaplaceno" pro rok/typ (volitelně druh v body.kind). */
    public function confirmAllAdvances(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $kind = isset($body['kind']) ? (string) $body['kind'] : null;
        return $this->run($response, fn () => $this->service->confirmAllAdvancesPaid(
            SupplierGuard::currentId($request), $year, $type, $kind
        ));
    }

    /** E9/#43 — vrátí ručně potvrzený předpis do 'planned'. */
    public function unconfirmAdvance(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->unconfirmAdvance(
            SupplierGuard::currentId($request), $year, $type, (int) ($args['scheduleId'] ?? 0)
        ));
    }

    /** E9/#46 — globální přehled rozhodnutí FÚ (§174) + předpis placení záloh napříč roky. */
    public function advanceOverrides(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->advanceOverridesOverview(
            SupplierGuard::currentId($request), $type
        ));
    }

    /** E9/#46 — založí rozhodnutí FÚ s rozsahem OD-DO. */
    public function createAdvanceOverride(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn () => $this->service->createAdvanceOverrideEntry(
            SupplierGuard::currentId($request), $type, $body
        ));
    }

    /** E9/#46 — upraví rozhodnutí FÚ dle id. */
    public function updateAdvanceOverride(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn () => $this->service->updateAdvanceOverrideEntry(
            SupplierGuard::currentId($request), $type, (int) ($args['overrideId'] ?? 0), $body
        ));
    }

    /** E9/#46 — smaže rozhodnutí FÚ dle id. */
    public function deleteAdvanceOverrideEntry(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::WRITE)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        return $this->run($response, fn () => $this->service->deleteAdvanceOverrideEntry(
            SupplierGuard::currentId($request), $type, (int) ($args['overrideId'] ?? 0)
        ));
    }

    public function csszXml(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ, 'reports.export')) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        try {
            $built = $this->service->generateCsszXml($supplierId, $year, $type, $userId);
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        $this->logger->log('tax_return.cssz_xml_downloaded', $userId, null, null, [
            'year' => $year,
            'submission_id' => $built['submission_id'],
            'validation_status' => $built['validation_status'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($built['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $built['filename'] . '"')
            ->withHeader('X-Validation-Status', $built['validation_status'])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Featura A — rekonciliace proti PODANÉMU přiznání: nahraje se EPO XML DPPDP9 od účetní
     * (multipart pole `file`), aplikace ho porovná se svým výpočtem za dané období. Jen `po`
     * (DPFDP7 parser není v rozsahu). Read-only — nic se neukládá ani neúčtuje.
     */
    public function reconcile(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->requireAccess($request, $response, AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        if ($type !== 'po') {
            return Json::error($response, 'invalid_type', 'Rekonciliace proti podanému přiznání je zatím jen pro DPPO (právnické osoby).', 400);
        }

        $xml = $this->uploadedXml($request, $response);
        if ($xml instanceof Response) {
            return $xml;
        }

        $variant = $this->variant($request);
        $seq = $this->seq($request) ?: 1;

        return $this->run($response, fn () => $this->reconciliation->reconcile(
            SupplierGuard::currentId($request), $year, $xml, $variant, $seq
        ));
    }

    /**
     * Náhled převzetí podaného přiznání k DPPO (multipart pole `file`) do vstupů
     * přiznání: navržené vstupy, dosavadní vstupy a porovnání s podáním. Nic neukládá.
     */
    public function filedImportPreview(Request $request, Response $response, array $args): Response
    {
        return $this->filedImport($request, $response, $args, false);
    }

    /**
     * Převzetí podaného přiznání k DPPO do rozpracovaného přiznání (přepíše vstupy, které
     * převzetí vlastní) a do evidence daňových ztrát.
     */
    public function filedImportApply(Request $request, Response $response, array $args): Response
    {
        return $this->filedImport($request, $response, $args, true);
    }

    private function filedImport(Request $request, Response $response, array $args, bool $apply): Response
    {
        if (($err = $this->requireAccess($request, $response, $apply ? AccessLevel::WRITE : AccessLevel::READ)) !== null) {
            return $err;
        }
        [$type, $year, $bad] = $this->params($args);
        if ($bad !== null) {
            return $bad($response);
        }
        if ($type !== 'po') {
            return Json::error($response, 'invalid_type', 'Převzetí podaného přiznání je jen pro DPPO (právnické osoby).', 400);
        }
        $xml = $this->uploadedXml($request, $response);
        if ($xml instanceof Response) {
            return $xml;
        }
        $supplierId = SupplierGuard::currentId($request);
        $variant = $this->variant($request);
        $seq = $this->seq($request) ?: 1;
        if (!$apply) {
            return $this->run($response, fn () => $this->filedImporter->preview($supplierId, $xml, $year, $variant, $seq));
        }
        $userId = $this->userId($request);
        return $this->run($response, function () use ($supplierId, $xml, $userId, $year, $variant, $seq, $request): array {
            $result = $this->filedImporter->apply($supplierId, $xml, $userId, $year, $variant, $seq, true, true);
            $this->logger->log('tax_return.filed_imported', $userId, null, null, [
                'year' => $year, 'variant' => $variant, 'forma' => $result['filing']['dapdpp_forma'] ?? null, 'status' => $result['status'] ?? null,
            ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
            return $result;
        });
    }

    /** Obsah nahraného XML podaného přiznání (multipart pole `file`), nebo chybová odpověď. */
    private function uploadedXml(Request $request, Response $response): string|Response
    {
        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'bad_file', 'Nahrajte soubor XML podaného přiznání DPPDP9.', 415);
        }
        if ((int) ($file->getSize() ?? 0) > self::RECONCILE_MAX_BYTES) {
            return Json::error($response, 'bad_file', 'Soubor je větší než 10 MB.', 415);
        }
        $filename = (string) ($file->getClientFilename() ?? '');
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '' && $ext !== 'xml') {
            return Json::error($response, 'bad_file', 'Podporovaný formát je jen XML.', 415);
        }
        return (string) $file->getStream()->getContents();
    }

    /** @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }
        return null;
    }

    // ── Interní ────────────────────────────────────────────────────────────

    /**
     * XML přiznání DPFO nese rodná čísla dětí a manžela/manželky, protože je podání
     * vyžaduje. Celé je dostane jen přihlášená relace s právem zápisu do daní, tedy
     * ten, kdo profil vyplňuje a přiznání podává. API token, MCP a role jen ke čtení
     * dostanou totéž XML bez těchto atributů (H28); archiv podání drží úplné XML.
     */
    private function familyScopedXml(Request $request, string $type, string $xml): string
    {
        if ($type !== 'fo'
            || (RequestAuthorization::isSessionAuth($request)
                && RequestAuthorization::allows($request, TaxFamilyIdentifierService::PERMISSION, AccessLevel::WRITE))
        ) {
            return $xml;
        }

        return TaxFamilyIdentifierService::stripXmlIdentifiers($xml);
    }

    /** @return array{0:string,1:int,2:(callable(Response):Response)|null} */
    private function params(array $args): array
    {
        $type = strtolower((string) ($args['type'] ?? ''));
        $year = (int) ($args['year'] ?? 0);
        if (!in_array($type, ['fo', 'po'], true)) {
            return ['', 0, fn (Response $r) => Json::error($r, 'validation_failed', 'Neplatný typ přiznání (fo|po).', 400)];
        }
        if ($year < 2020 || $year > 2050) {
            return [$type, 0, fn (Response $r) => Json::error($r, 'validation_failed', 'Neplatný rok.', 400)];
        }
        return [$type, $year, null];
    }

    /**
     * Druh přiznání z query (?variant=radne|opravne|dodatecne). Aditivní k routám —
     * default 'radne' drží BC. Neplatná hodnota spadne na 'radne' (service validuje dál).
     */
    private function variant(Request $request): string
    {
        $v = strtolower((string) ($request->getQueryParams()['variant'] ?? 'radne'));
        return in_array($v, ['radne', 'opravne', 'dodatecne'], true) ? $v : 'radne';
    }

    /**
     * Pořadí dodatečného přiznání z query (?seq=N, E8). Default 0 = auto (service zvolí
     * poslední existující, nebo č. 1). Řádné/opravné ho ignorují (vždy 1).
     */
    private function seq(Request $request): int
    {
        return max(0, (int) ($request->getQueryParams()['seq'] ?? 0));
    }

    /** @param callable():array<string,mixed> $fn */
    private function run(Response $response, callable $fn): Response
    {
        try {
            return Json::ok($response, $fn());
        } catch (TaxReturnException|\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|\MyInvoice\Service\Accounting\PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'server_error', $e->getMessage(), 500);
        }
    }

    private function requireAccess(
        Request $request,
        Response $response,
        AccessLevel $minimum,
        string $permission = 'reports',
    ): ?Response
    {
        if (!RequestAuthorization::allows($request, $permission, $minimum)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        return null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tax;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use MyInvoice\Service\Tax\ForeignIncome\ForeignIncomeNoticeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Písemnosti k příjmům daňových nerezidentů:
 *
 *   GET  /api/tax/foreign-income/catalog
 *        → číselníky pro formulář (druhy příjmů § 22, typy poplatníka, sazby)
 *   POST /api/tax/foreign-income/dpshl1/xml
 *        → oznámení o příjmech plynoucích do zahraničí (§ 38da ZDP)
 *   POST /api/tax/foreign-income/dpszd1/xml
 *        → hlášení o srážce zajištění daně (§ 38e ZDP)
 *
 * Obě jsou **událostní** — podávají se za konkrétní výplatu nerezidentovi, ne za
 * zdaňovací období — a věcnou část zadává uživatel, protože ji aplikace nemá.
 * Proč přesně, vysvětluje {@see ForeignIncomeNoticeService}.
 *
 * ## Proč tu NENÍ kontrola zapnutého mzdového modulu
 *
 * Sousední {@see \MyInvoice\Action\Payroll\TaxStatementAction} začíná kontrolou
 * `payroll_disabled`, protože vyúčtování bez mezd nedává smysl. Tady by ta
 * kontrola byla škodlivá: ani jedna z těchhle písemností z mezd nevzniká.
 * § 38e odst. 1 věta poslední zajištění daně ze záloh z příjmů ze závislé
 * činnosti výslovně vylučuje a § 38da odst. 5 písm. b) vylučuje z oznamovací
 * povinnosti příjem podle § 6 odst. 4, což je jediný příjem, ze kterého mzdový
 * modul sráží daň zvláštní sazbou. Firma bez mezd, která zaplatí licenční
 * poplatek do třetí země, tu povinnost má úplně stejně — schovat jí podání za
 * vypnutý mzdový modul by znamenalo, že ho nepodá.
 */
final class ForeignIncomeNoticeAction
{
    public function __construct(
        private readonly ForeignIncomeNoticeService $service,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function catalog(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        return Json::ok($response, $this->service->catalog());
    }

    public function download(Request $request, Response $response, array $args = []): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $formCode = strtolower(trim((string) ($args['form'] ?? '')));
        if (!in_array($formCode, ForeignIncomeNoticeService::FORMS, true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Zvol písemnost dpshl1 (oznámení § 38da) nebo dpszd1 (hlášení § 38e).',
                400,
            );
        }
        $payload = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->service->build($supplierId, $formCode, $payload);
        } catch (\DomainException | \InvalidArgumentException | \UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $userId = (int) ($user['id'] ?? 0);
        $archived = $this->archiver->archive(
            $supplierId,
            $formCode,
            $result['period_year'],
            null,
            null,
            $result['xml'],
            $result['summary'] + ['warnings' => $result['warnings']],
            $userId ?: null,
            // Ani jedna písemnost není v `TaxSubmissionArchiver::VAT_LOCK_FORMS`
            // a s daňovým zámkem nemá co do činění — je to podání k jedné platbě.
            false,
            $result['variant'],
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tax.foreign_income_notice_downloaded', $userId, null, null, [
            'form_code' => $formCode,
            'period' => (string) $result['period_year'],
            'variant' => $result['variant'],
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => $formCode,
            'form_variant' => $result['variant'],
            'period_year' => $result['period_year'],
            'period_month' => null,
            'period_quarter' => null,
        ], 'xml');
        $response->getBody()->write($result['xml']);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}

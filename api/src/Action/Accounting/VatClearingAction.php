<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Interní doklad zúčtování DPH z agendy DPH (migrace 1332).
 *
 *   GET  /api/accounting/vat-clearing?year=&month=  — NÁHLED: co by se zaúčtovalo,
 *        co je zaúčtované teď, jestli se to rozchází a jestli se s tím smí hnout
 *   POST /api/accounting/vat-clearing {year, month} — spustí přepočet
 *
 * Ruční spuštění je TŘETÍ cesta vedle podání přiznání (primární spouštěč,
 * {@see \MyInvoice\Service\Accounting\Vat\VatClearingTrigger}) a cronu (záchranná síť).
 * Existuje pro dvě situace, které první dvě nepokryjí: období, za které se přiznání
 * nepodává v aplikaci, a náprava po tom, co účetní vrátila daňový zámek zpět a doklad
 * v období opravila.
 *
 * Náhled je záměrně samostatný krok — účetní musí vidět daň na výstupu, na vstupu
 * a výsledný zůstatek 343.900 DŘÍV, než se cokoli zapíše do deníku.
 */
final class VatClearingAction
{
    public function __construct(
        private readonly VatClearingService $clearing,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/accounting/vat-clearing?year=&month= */
    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $period = self::periodFromQuery($request->getQueryParams());
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        [$year, $month] = $period;

        try {
            $status = $this->clearing->status($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'preview_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $status);
    }

    /** POST /api/accounting/vat-clearing {year, month} */
    public function run(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění účtovat.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $period = self::periodFromQuery($body);
        if ($period === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        [$year, $month] = $period;

        $meta = [
            'user_id'    => $userId ?: null,
            'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'trigger'    => VatClearingService::TRIGGER_MANUAL,
        ];

        try {
            $result = $this->clearing->postForPeriod($supplierId, $year, $month, $meta);
        } catch (PostingException $e) {
            // Zavřené/zamčené období se NEOBCHÁZÍ — uživatel dostane důvod a musí
            // vědomě posunout zámek (admin endpoint), ne aby to udělal tenhle endpoint za něj.
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'clearing_failed', $e->getMessage(), 500);
        }

        $this->logger->log(
            'accounting.vat_clearing_run',
            $userId ?: null,
            'journal_entry',
            $result['entry_id'] !== null ? (int) $result['entry_id'] : null,
            [
                'period'     => $result['period_label'],
                'status'     => $result['status'],
                'input_vat'  => $result['input_vat'],
                'output_vat' => $result['output_vat'],
                'settlement' => $result['settlement'],
            ],
            $meta['ip'],
            $meta['user_agent'],
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /**
     * Rok/měsíc ze vstupu. Kvartální plátce posílá kterýkoli měsíc kvartálu —
     * do období si ho přemapuje {@see VatClearingService::periodBounds()}.
     *
     * @param array<string,mixed> $input
     * @return array{0:int, 1:int}|null
     */
    private static function periodFromQuery(array $input): ?array
    {
        $year = (int) ($input['year'] ?? 0);
        $month = (int) ($input['month'] ?? 0);
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return null;
        }

        return [$year, $month];
    }
}

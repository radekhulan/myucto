<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\ExchangeRateSources;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/exchange-rate
 *
 * Manuálně nastaví exchange_rate + exchange_rate_date + source.
 * Body: { rate: 23.50, rate_date: "2026-05-15", source: … }
 *
 * Doména `source` je ve {@see ExchangeRateSources} a rozhoduje i o tom, jestli smí
 * kurz přepsat automatické přenačtení po změně rozhodného data (jen 'cnb'/'fixed').
 *
 * NULL rate = reset (např. když uživatel změní currency na CZK).
 */
final class SetPurchaseInvoiceExchangeRateAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): kurz mění účetní hodnoty dokladu.
        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
            return $deny;
        }
        // Kurz mění korunové částky dokladu, tenhle úzký endpoint ale deník nepřeúčtuje.
        // Zaúčtovaný doklad se proto opravuje úpravou dokladu ({@see UpdatePurchaseInvoiceAction}),
        // která zápis dorovná; storno je neměnné.
        if ((string) ($existing['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze upravit.', 409);
        }
        if ($lock->posted) {
            return Json::error($response, 'posted_document',
                'Doklad je zaúčtovaný. Kurz změňte úpravou dokladu, která zápis v deníku dorovná.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // Vynechaný klíč = ponech uloženou hodnotu, explicitní null/'' = vymaž (issue #113).
        $rate = array_key_exists('rate', $body) || ($existing['exchange_rate'] ?? null) === null
            ? null
            : (float) $existing['exchange_rate'];
        if (array_key_exists('rate', $body) && $body['rate'] !== null && $body['rate'] !== '') {
            if (!is_numeric($body['rate'])) {
                return Json::error($response, 'validation_failed', 'rate musí být číslo', 400);
            }
            $rate = (float) $body['rate'];
            if ($rate <= 0 || $rate > 100000) {
                return Json::error($response, 'validation_failed', 'rate je mimo rozumný rozsah', 400);
            }
        }

        // Kurz, datum a zdroj tvoří trojici: poslaný (i smazaný) kurz nedědí datum ani
        // zdroj starého kurzu (např. 'cnb', který by smělo přepsat přenačtení).
        $rateSent = array_key_exists('rate', $body);
        $rateDate = array_key_exists('rate_date', $body) || $rateSent || empty($existing['exchange_rate_date'])
            ? null
            : substr((string) $existing['exchange_rate_date'], 0, 10);
        if (!empty($body['rate_date'])) {
            $rateDate = (string) $body['rate_date'];
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $rateDate);
            if ($d === false || $d->format('Y-m-d') !== $rateDate) {
                return Json::error($response, 'validation_failed', 'Neplatné rate_date', 400);
            }
        }

        $source = match (true) {
            array_key_exists('source', $body) => (string) ($body['source'] ?? ExchangeRateSources::DEFAULT),
            $rateSent => ExchangeRateSources::DEFAULT,
            default => ExchangeRateSources::normalize($existing['exchange_rate_source'] ?? null),
        };
        if (!ExchangeRateSources::isValid($source)) {
            return Json::error($response, 'validation_failed', 'Neplatný source', 400);
        }

        $this->repo->setExchangeRate($id, $rate, $rateDate, $source, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.exchange_rate_set', $user['id'] ?? null, 'purchase_invoice', $id,
            ['rate' => $rate, 'rate_date' => $rateDate, 'source' => $source], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}

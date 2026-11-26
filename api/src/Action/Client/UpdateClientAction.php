<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AdvancePaidAccount;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Ares\ClientBankAccountRegistrySynchronizer;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockPriceLevelService;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClientEmailContactRepository $emailContacts,
        private readonly ClientBankAccountRepository $bankAccounts,
        private readonly ClientBankAccountRegistrySynchronizer $bankAccountRegistry,
        private readonly StockPriceLevelService $priceLevels,
        private readonly AdvancePaidAccount $advanceAccounts,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Validuje se karta po sloučení s uloženými hodnotami — chybějící klíč = beze změny (#113).
        $merged = $this->repo->mergeWithStored($existing, $body);
        $merged['is_vendor'] = array_key_exists('is_vendor', $body) ? $body['is_vendor'] : $existing['is_vendor'];
        $errors = Validation::client($merged);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        // Cenová hladina (1833) se zapisuje jen když ji tělo obsahuje.
        $priceLevelId = null;
        $writePriceLevel = array_key_exists('price_level_id', $body);
        if ($writePriceLevel) {
            try {
                $priceLevelId = $this->priceLevels->assignableLevelId(
                    (int) $existing['supplier_id'],
                    $body['price_level_id'],
                    $existing['price_level_id'] ?? null,
                );
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }

        // Účty poskytnutých záloh dodavatele se zapisují jen když je tělo obsahuje.
        try {
            $advanceAccounts = $this->advanceAccounts->vendorSettingsFromBody((int) $existing['supplier_id'], $body, $existing);
        } catch (PostingException $e) {
            return self::accountError($response, $e);
        }
        if ($advanceAccounts !== [] && !RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403, [
                'fields' => array_fill_keys(array_keys($advanceAccounts), ['Účty záloh na kartě dodavatele mění jen účetní.']),
            ]);
        }

        try {
            $backfilled = $this->repo->update($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        if ($writePriceLevel) {
            $this->repo->setPriceLevel($id, (int) $existing['supplier_id'], $priceLevelId);
        }
        $this->repo->setAdvancePaidAccounts($id, (int) $existing['supplier_id'], $advanceAccounts);

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        // E-mailové kontakty dle účelu (#86) — replace-all, jen pokud klíč v payloadu je
        // (partial update bez klíče kontakty nemění). Změny do activity logu.
        $contactsChanged = false;
        if (isset($body['email_contacts']) && is_array($body['email_contacts'])) {
            try {
                $this->emailContacts->replaceForClient($id, $supplierId, $body['email_contacts']);
                $contactsChanged = true;
            } catch (\DomainException $e) {
                return Json::error($response, 'invalid_email_contacts', $e->getMessage(), 422);
            }
        }
        if (!empty($merged['is_vendor']) && !empty($merged['dic'])) {
            try {
                $this->bankAccountRegistry->sync($id, $supplierId, (string) $merged['dic']);
            } catch (\Throwable) {
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.updated', $user['id'] ?? null, 'client', $id,
            $contactsChanged ? ['email_contacts' => $body['email_contacts']] : null,
            $ip, $request->getHeaderLine('User-Agent'));

        // *_category_backfilled = počet faktur, do kterých byla doplněna nově nastavená
        // výchozí kategorie nákladu / tržby (frontend ukáže toast).
        $client = $this->repo->find($id) ?? [];
        $client['email_contacts'] = $this->emailContacts->listForClient($id, $supplierId);
        $client['bank_accounts'] = $this->bankAccounts->listForClient($id, $supplierId);
        $client['expense_category_backfilled'] = $backfilled['expense'];
        $client['revenue_category_backfilled'] = $backfilled['revenue'];

        // Non-blocking varování (IČO mod 11 / DIČ formát — audit 2026-07).
        $warnings = Validation::clientWarnings($merged);
        if (!empty($warnings)) {
            $client['_warnings'] = $warnings;
        }

        // FR 2 (vendor bugreport 2026-08-06) — non-blocking upozornění na JINOU kartu
        // se stejným IČO/DIČ po normalizaci (sama editovaná karta je vyřazená).
        $duplicates = $this->repo->findDuplicateCandidates($supplierId, $merged['ic'] ?? null, $merged['dic'] ?? null, $id);
        if (!empty($duplicates)) {
            $client['_duplicate_candidates'] = $duplicates;
        }

        return Json::ok($response, $client);
    }

    public static function accountError(Response $response, PostingException $e): Response
    {
        $field = (string) ($e->context['field'] ?? '');
        return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus,
            $field !== '' ? ['fields' => [$field => [$e->getMessage()]]] : []);
    }
}

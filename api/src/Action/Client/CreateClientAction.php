<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
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

final class CreateClientAction
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

    public function __invoke(Request $request, Response $response): Response
    {
        // Nejdřív supplier kontext: bez dodavatele jsou currencies prázdné a klientský
        // formulář by spadl na matoucí „Validace selhala" (currency_default_id=0). Vrať
        // jasnou, akční hlášku místo toho (#151). FE onboarding gate sem uživatele nepustí.
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error(
                $response,
                'no_supplier',
                'Nelze vytvořit klienta — nejdříve vytvořte dodavatele (Nastavení → Číselníky → Dodavatelé).',
                400,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validation::client($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }
        // Cenová hladina (1833) jen když ji tělo obsahuje; ověřit PŘED založením karty.
        $priceLevelId = null;
        if (array_key_exists('price_level_id', $body)) {
            try {
                $priceLevelId = $this->priceLevels->assignableLevelId($supplierId, $body['price_level_id'], null);
            } catch (StockException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
        }
        try {
            $advanceAccounts = array_filter(
                $this->advanceAccounts->vendorSettingsFromBody($supplierId, $body),
                static fn (?string $code): bool => $code !== null,
            );
        } catch (PostingException $e) {
            return UpdateClientAction::accountError($response, $e);
        }
        if ($advanceAccounts !== [] && !RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403, [
                'fields' => array_fill_keys(array_keys($advanceAccounts), ['Účty záloh na kartě dodavatele mění jen účetní.']),
            ]);
        }
        try {
            $id = $this->repo->create($body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        if ($priceLevelId !== null) {
            $this->repo->setPriceLevel($id, $supplierId, $priceLevelId);
        }
        $this->repo->setAdvancePaidAccounts($id, $supplierId, $advanceAccounts);

        // E-mailové kontakty dle účelu (#86) — replace-all, validuje repo.
        if (isset($body['email_contacts']) && is_array($body['email_contacts'])) {
            try {
                $this->emailContacts->replaceForClient($id, $supplierId, $body['email_contacts']);
            } catch (\DomainException $e) {
                return Json::error($response, 'invalid_email_contacts', $e->getMessage(), 422);
            }
        }
        if (!empty($body['is_vendor']) && !empty($body['dic'])) {
            try {
                $this->bankAccountRegistry->sync($id, $supplierId, (string) $body['dic']);
            } catch (\Throwable) {
            }
        }
        $client = $this->repo->find($id);
        $client['email_contacts'] = $this->emailContacts->listForClient($id, $supplierId);
        $client['bank_accounts'] = $this->bankAccounts->listForClient($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.created', $user['id'] ?? null, 'client', $id, [
            'company_name' => $body['company_name'],
            'ic' => $body['ic'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Non-blocking varování (IČO mod 11 / DIČ formát — audit 2026-07).
        $warnings = Validation::clientWarnings($body);
        if (!empty($warnings)) {
            $client['_warnings'] = $warnings;
        }

        // FR 2 (vendor bugreport 2026-08-06) — non-blocking upozornění na kartu se
        // stejným IČO/DIČ po normalizaci. Nezablokuje založení (může jít o vědomý
        // duplicitní záznam), ale uživatel dostane šanci to zjistit hned, ne až
        // z rozpadlého salda.
        $duplicates = $this->repo->findDuplicateCandidates($supplierId, $body['ic'] ?? null, $body['dic'] ?? null);
        if (!empty($duplicates)) {
            $client['_duplicate_candidates'] = $duplicates;
        }

        return Json::ok($response, $client, 201);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryBlockedException;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účetní strana zabezpečeného doručení.
 *
 *   GET    /api/payroll/documents/{documentId}/secure-links       — stav odkazů
 *   POST   /api/payroll/documents/{documentId}/secure-links       — zařadit odeslání
 *   DELETE /api/payroll/documents/{documentId}/secure-links/{id}  — zneplatnit
 *
 * Zařazení vyžaduje WRITE, protože je to odchozí akce s osobními údaji, ne čtení.
 *
 * Odpověď NIKDY nenese token ani URL odkazu. Účetní má vidět, že odkaz existuje,
 * komu šel (maskovaně) a jestli si ho zaměstnanec vyzvedl — ale samotný odkaz
 * patří jen do jeho schránky. Kdyby ho vracelo API, stačilo by kompromitované
 * účetní přihlášení k tomu, aby šlo číst cizí výplatnice cestou určenou pro ně.
 */
final class PayrollDocumentDeliveryAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSecureDeliveryService $delivery,
        private readonly PayrollDocumentAccessLinkRepository $links,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $links = $this->links->forDocument(
            $this->currentSupplierId($request),
            (int) ($args['documentId'] ?? 0),
        );

        return $this->noStore(Json::ok($response, [
            'links' => array_map(self::publicLink(...), $links),
        ]));
    }

    /** @param array<string,string> $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $supplierId = $this->currentSupplierId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        $actorUserId = $this->userId($request);

        try {
            $result = $this->delivery->enqueue($supplierId, $documentId, $actorUserId);
        } catch (PayrollSecureDeliveryBlockedException $exception) {
            // 409 a ne 422: vstup je v pořádku, jen je kanál zavřený. UI podle
            // `reason` napoví, který z pěti přepínačů chybí.
            return $this->noStore(Json::error(
                $response,
                'secure_delivery_blocked',
                $exception->getMessage(),
                409,
                ['reason' => $exception->reasonCode()],
            ));
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                'payroll_production_gate',
                $exception->getMessage(),
                409,
            ));
        } catch (\DomainException) {
            return $this->noStore(Json::error(
                $response,
                'not_found',
                'Mzdový dokument nebyl nalezen.',
                404,
            ));
        }

        $this->activity->log(
            'payroll.document_secure_link_queued',
            $actorUserId,
            'payroll_document',
            $documentId,
            ['link_id' => $result['link_id'], 'created' => $result['created']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $this->noStore(Json::ok($response, $result, 202));
    }

    /** @param array<string,string> $args */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $supplierId = $this->currentSupplierId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        $linkId = (int) ($args['linkId'] ?? 0);
        $actorUserId = $this->userId($request);

        // Odkaz musí patřit k dokumentu z cesty. Bez téhle kontroly by šlo přes
        // dokument, na který mám právo, rušit odkazy k jinému.
        $link = $this->links->find($supplierId, $linkId);
        if ($link === null || (int) $link['payroll_document_id'] !== $documentId) {
            return $this->noStore(Json::error(
                $response,
                'not_found',
                'Zabezpečený odkaz nebyl nalezen.',
                404,
            ));
        }

        try {
            $revoked = $this->delivery->revoke($supplierId, $linkId, $actorUserId);
        } catch (\DomainException) {
            return $this->noStore(Json::error(
                $response,
                'not_found',
                'Zabezpečený odkaz nebyl nalezen.',
                404,
            ));
        }

        $this->activity->log(
            'payroll.document_secure_link_revoked',
            $actorUserId,
            'payroll_document',
            $documentId,
            ['link_id' => $linkId, 'changed' => $revoked],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $this->noStore(Json::ok($response, ['revoked' => $revoked]));
    }

    /**
     * Projekce pro UI. Vědomě bez `token_hash`, `recipient_email_hash`
     * a `lease_token` — účetní z nich nic nepotřebuje a v odpovědi nemají co dělat.
     *
     * @param array<string,mixed> $link
     * @return array<string,mixed>
     */
    private static function publicLink(array $link): array
    {
        return [
            'id' => (int) $link['id'],
            'document_id' => (int) $link['payroll_document_id'],
            'employee_id' => (int) $link['employee_id'],
            'recipient_masked' => (string) $link['recipient_masked'],
            'dispatch_state' => (string) $link['dispatch_state'],
            'attempt_count' => (int) $link['attempt_count'],
            'last_error_code' => $link['last_error_code'],
            'expires_at' => $link['expires_at'],
            'sent_at' => $link['sent_at'],
            'revoked_at' => $link['revoked_at'],
            'first_downloaded_at' => $link['first_downloaded_at'],
            'last_downloaded_at' => $link['last_downloaded_at'],
            'download_count' => (int) $link['download_count'],
            'is_live' => (bool) $link['is_live'],
        ];
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

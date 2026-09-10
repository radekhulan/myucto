<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Card\CardClearingSettingsService;
use MyInvoice\Service\Accounting\Card\CardClearingWriteOffService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účtování plateb kartou (stránka Platební karty):
 *   GET    /api/payment-cards/settings                                — nastavení účtování
 *   PUT    /api/payment-cards/settings                                — uložení (?confirm)
 *   PUT    /api/payment-cards/{id}/analytic                           — ruční výběr analytiky mezičlenu
 *   POST   /api/payment-cards/{id}/verify                             — ověření karty založené importem
 *   POST   /api/payment-cards/unmatched-payments/{id}/write-off       — uzavřít platbu bez dokladu
 *   DELETE /api/payment-cards/unmatched-payments/{id}/write-off       — zrušit uzavření
 *
 * Zápisy do účetnictví chtějí stejné právo jako nastavení a zpracování GoPay (`bank.post`).
 */
final class CardClearingAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly CardClearingSettingsService $settings,
        private readonly CardClearingWriteOffService $writeOffs,
        private readonly PaymentCardRepository $cards,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function settings(Request $request, Response $response): Response
    {
        try {
            return Json::ok($response, $this->settings->settings($this->currentSupplierId($request)));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->settings->saveSettings($this->currentSupplierId($request), $body, $this->userId($request));
            $this->log($request, 'payment_card.clearing_settings_updated', null, [
                'enabled'            => $result['settings']['enabled'],
                'effective_from'     => $result['settings']['effective_from'],
                'clearing_synthetic' => $result['settings']['clearing_synthetic'],
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function setAnalytic(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $code = isset($body['account_code']) && $body['account_code'] !== null ? trim((string) $body['account_code']) : null;
        try {
            $cardId = (int) ($args['id'] ?? 0);
            $card = $this->settings->setCardAnalytic($this->currentSupplierId($request), $cardId, $code ?: null, !empty($body['confirm']));
            $this->log($request, 'payment_card.analytic_changed', $cardId, ['account_code' => $code ?: null]);
            return Json::ok($response, ['card' => $card, 'clearing' => $this->settings->cardClearing($this->currentSupplierId($request), $card)]);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $cardId = (int) ($args['id'] ?? 0);
        if ($this->cards->find($supplierId, $cardId) === null) {
            return Json::error($response, 'not_found', 'Platební karta nenalezena.', 404);
        }
        $this->cards->markVerified($supplierId, $cardId);
        $this->log($request, 'payment_card.verified', $cardId, []);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $cardId)]);
    }

    public function writeOff(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $txId = (int) ($args['id'] ?? 0);
        try {
            $result = $this->writeOffs->writeOff(
                $this->currentSupplierId($request),
                $txId,
                (string) ($body['target'] ?? ''),
                isset($body['account_id']) && $body['account_id'] !== null && $body['account_id'] !== '' ? (int) $body['account_id'] : null,
                $this->userId($request),
            );
            $this->log($request, 'payment_card.payment_written_off', $txId, $result + ['target' => (string) ($body['target'] ?? '')]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function cancelWriteOff(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $txId = (int) ($args['id'] ?? 0);
        try {
            $reversalId = $this->writeOffs->cancel($this->currentSupplierId($request), $txId, $this->userId($request));
            $this->log($request, 'payment_card.write_off_cancelled', $txId, ['reversal_id' => $reversalId]);
            return Json::ok($response, ['reversal_id' => $reversalId]);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $entityType = in_array($action, ['payment_card.payment_written_off', 'payment_card.write_off_cancelled'], true)
            ? 'bank_transaction'
            : 'payment_card';
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityType,
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET    /api/admin/imports/anthropic/credentials  — status
 * PUT    /api/admin/imports/anthropic/credentials  — set + test
 * DELETE /api/admin/imports/anthropic/credentials  — remove
 *
 * BYOK — uživatel platí Anthropicu sám. Default model = LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL.
 */
final class AnthropicCredentialsAction
{
    /** Jediný zdroj pravdy je {@see LlmProviderCapabilities::ANTHROPIC_MODELS}. */
    private const ALLOWED_MODELS = LlmProviderCapabilities::ANTHROPIC_MODELS;
    private const DEFAULT_MODEL  = LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.ai_provider', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $creds = $this->anthropic->getCredentials($supplierId);

        // Plus počítadlo úspěšných extrakcí pro transparency
        $stmt = $this->db->pdo()->prepare('SELECT anthropic_extractions_count FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $count = (int) $stmt->fetchColumn();

        return Json::ok($response, [
            'configured'        => $creds !== null,
            'default_model'     => $creds['default_model'] ?? self::DEFAULT_MODEL,
            'extractions_count' => $count,
            'allowed_models'    => self::ALLOWED_MODELS,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.ai_provider', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $apiKey = (string) ($body['api_key'] ?? '');
        $model  = (string) ($body['default_model'] ?? self::DEFAULT_MODEL);

        if (!in_array($model, self::ALLOWED_MODELS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný model.', 400);
        }

        $existing = $this->anthropic->getCredentials($supplierId);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Pouze změna modelu (klíč už existuje, nový klíč prázdný) → neretestujeme.
        if ($apiKey === '' && $existing !== null) {
            $this->anthropic->updateDefaultModel($supplierId, $model);
            $this->logger->log('import.anthropic_model_changed', $userId, 'supplier', $supplierId, [
                'default_model' => $model,
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::ok($response, [
                'saved'      => true,
                'test_ok'    => true,
                'test_error' => null,
                'model'      => $model,
            ]);
        }

        if ($apiKey === '') {
            return Json::error($response, 'validation_failed', 'api_key je povinné.', 400);
        }
        if (!str_starts_with($apiKey, 'sk-ant-') || strlen($apiKey) > 256) {
            return Json::error($response, 'validation_failed', 'api_key má neplatný formát (musí začínat "sk-ant-").', 400);
        }

        $this->anthropic->setCredentials($supplierId, $apiKey, $model);
        $this->logger->log('import.anthropic_credentials_set', $userId, 'supplier', $supplierId, [
            'default_model' => $model,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $test = $this->anthropic->testConnection($supplierId);
        return Json::ok($response, [
            'saved'      => true,
            'test_ok'    => $test['ok'],
            'test_error' => $test['ok'] ? null : ($test['error'] ?? null),
            'model'      => $test['model'] ?? null,
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.ai_provider', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $this->anthropic->setCredentials($supplierId, '', self::DEFAULT_MODEL);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.anthropic_credentials_removed', $userId, 'supplier', $supplierId, null,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

}

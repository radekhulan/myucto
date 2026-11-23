<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierEmailTemplateRepository;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\EmailTemplateCatalog;
use MyInvoice\Service\Mail\EmailTemplateResolver;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierEmailTemplateAction
{
    public function __construct(
        private readonly SupplierEmailTemplateRepository $repo,
        private readonly EmailTemplateResolver $resolver,
        private readonly EmailTemplateCatalog $catalog,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $rows = [];
        foreach (EmailTemplateCatalog::SUPPLIER_CODES as $code) {
            foreach (EmailTemplateCatalog::LOCALES as $locale) {
                $detail = $this->resolver->detail($supplierId, $code, $locale);
                unset($detail['subject'], $detail['body_html'], $detail['body_text'], $detail['variables']);
                $rows[] = $detail;
            }
        }
        return Json::ok($response, ['data' => $rows, 'supplier_id' => $supplierId]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, $args)) !== null) return $error;
        return Json::ok($response, $this->resolver->detail(SupplierGuard::currentId($request), $args['code'], $args['locale']));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, $args)) !== null) return $error;
        $template = (array) ($request->getParsedBody() ?? []);
        if (($error = $this->validate($response, $args, $template)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $this->repo->save($supplierId, $args['code'], $args['locale'], $template['subject'], $template['body_html'], $template['body_text'], $userId);
        $this->audit($request, 'supplier_email_template.saved', $args, $supplierId, $userId);
        return Json::ok($response, ['saved' => true, 'supplier_id' => $supplierId]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, $args)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $this->repo->delete($supplierId, $args['code'], $args['locale']);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->audit($request, 'supplier_email_template.reset', $args, $supplierId, isset($user['id']) ? (int) $user['id'] : null);
        return Json::ok($response, ['deleted' => true, 'supplier_id' => $supplierId]);
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, $args)) !== null) return $error;
        $template = (array) ($request->getParsedBody() ?? []);
        if (($error = $this->validate($response, $args, $template)) !== null) return $error;
        return Json::ok($response, $this->mailer->previewUserTemplate($args['code'], $args['locale'], $template));
    }

    private function validate(Response $response, array $args, array $template): ?Response
    {
        $error = $this->mailer->validateSupplierTemplate($args['code'], $args['locale'], $template);
        return $error === null ? null : Json::error($response, 'validation_failed', $error['message'], 400, ['field' => $error['field']]);
    }

    private function guard(Request $request, Response $response, ?array $args = null): ?Response
    {
        // A deleted/invalid explicit company must not silently redirect a stale editor
        // into the resolver's fallback company. Also applies on a company-bound domain.
        $requested = trim($request->getHeaderLine(SupplierScopeMiddleware::HEADER_NAME));
        if ($requested === '') $requested = (string) ($request->getQueryParams()['supplier_id'] ?? '');
        if ($requested !== '' && (!ctype_digit($requested) || (int) $requested !== SupplierGuard::currentId($request))) {
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáte oprávnění.', 403);
        }
        // SupplierScopeMiddleware has already checked membership and any domain binding.
        if (!OperationalSettingsAccess::emailTemplates($request) || SupplierGuard::currentId($request) <= 0) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové šablony firmy.', 403);
        }
        if ($args !== null && !$this->catalog->isSupplierTemplate((string) ($args['code'] ?? ''), (string) ($args['locale'] ?? ''))) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }
        return null;
    }

    private function audit(Request $request, string $action, array $args, int $supplierId, ?int $userId): void
    {
        $this->logger->log($action, $userId, 'supplier_email_template', null,
            ['supplier_id' => $supplierId, 'code' => $args['code'], 'locale' => $args['locale']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
    }
}

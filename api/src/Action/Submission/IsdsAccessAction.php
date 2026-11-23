<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Signing\CertificateVaultSupplierSharing;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\IsdsAccessService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class IsdsAccessAction
{
    public function __construct(
        private IsdsAccessService $access,
        private CertificateVaultSupplierSharing $sharing,
        private EpoStepUpService $stepUp,
    ) {}

    public function choices(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $query = $request->getQueryParams();
        try {
            $environment = IsdsAccessService::environment($query['environment'] ?? 'production');
            $operation = IsdsAccessService::operation($query['operation'] ?? null);
            $items = $this->access->choices(SupplierGuard::currentId($request), $this->user($request), $environment, $operation);
            return $this->ok($response, ['items' => $items]);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function remember(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $environment = IsdsAccessService::environment($body['environment'] ?? 'production');
            $operation = IsdsAccessService::operation($body['operation'] ?? null);
            $item = $this->access->remember(SupplierGuard::currentId($request), $this->user($request), $environment, $operation, $body);
            return $this->ok($response, ['item' => $item], 201);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function forget(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        try {
            $this->access->forget((int) $args['id'], $this->user($request), 'personal', SupplierGuard::currentId($request));
            return $this->ok($response, ['deleted' => true]);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function adminList(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) {
            return $denied;
        }
        $actor = $this->user($request);
        $companies = $this->companies($request, $actor);
        return $this->ok($response, [
            'items' => $this->access->adminItems($actor, array_column($companies, 'id')),
            'companies' => $companies,
            'certificates' => $this->access->certificates($actor, SupplierGuard::currentId($request)),
        ]);
    }

    public function adminSave(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) {
            return $denied;
        }
        $actor = $this->user($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->verifyStepUp($request, $actor, $body['step_up'] ?? null);
            $id = isset($args['id']) ? (int) $args['id'] : null;
            $allowed = array_column($this->companies($request, $actor), 'id');
            $item = $this->access->saveShared($actor, SupplierGuard::currentId($request), $id, $allowed, $body);
            return $this->ok($response, ['item' => $item], $id === null ? 201 : 200);
        } catch (SubmissionChannelException|EpoSubmissionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function adminRemove(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) {
            return $denied;
        }
        $actor = $this->user($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->verifyStepUp($request, $actor, $body['step_up'] ?? null);
            $this->access->forget((int) $args['id'], $actor, 'shared', SupplierGuard::currentId($request),
                array_column($this->companies($request, $actor), 'id'));
            return $this->ok($response, ['deleted' => true]);
        } catch (SubmissionChannelException|EpoSubmissionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function verifyStepUp(Request $request, int $actor, #[\SensitiveParameter] mixed $proof): void
    {
        $this->stepUp->verify($request, $actor, is_array($proof) ? $proof : [], 'credential_supplier_access');
    }

    private function guard(Request $request, Response $response, bool $admin = false): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request) || $this->user($request) <= 0) {
            return Json::sessionRequired($response, 'Uložené přihlášení k datové schránce je dostupné jen z webového rozhraní.');
        }
        if (!RequestAuthorization::allows($request, 'settings.signing', AccessLevel::WRITE)
            || ($admin && !RequestAuthorization::isSuperadmin($request))) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        return null;
    }

    private function user(Request $request): int
    {
        return (int) ($request->getAttribute(AuthMiddleware::ATTR_USER)['id'] ?? 0);
    }

    /** @return list<array{id:int,name:string}> */
    private function companies(Request $request, int $actor): array
    {
        return array_map(
            static fn (array $company): array => ['id' => $company['supplier_id'], 'name' => $company['name']],
            $this->sharing->permittedSuppliers($request, $actor, AccessLevel::WRITE, allForSuperadmin: true),
        );
    }

    private function ok(Response $response, array $data, int $status = 200): Response
    {
        return Json::ok($response, $data, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}

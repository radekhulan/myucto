<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\Submission\SubmissionEnvironmentPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

/**
 * Vynucení {@see SubmissionEnvironmentPolicy} na všech cestách podání úřadům.
 *
 * Sedí nejhlouběji (za routingem i parsováním těla), takže vidí prostředí ze všech
 * tří míst, kudy ho akce berou: query, tělo i argument cesty. Jedna brána místo
 * kontroly v každé ze zhruba padesáti akcí, které `environment` přijímají.
 *
 * Mazání (DELETE) projde vždy: odstranit dřív uložené testovací přihlašovací údaje
 * musí jít i v produkční instalaci. Podání na EPO (`/api/reports/submissions`) do
 * rozsahu nepatří, zkušební podání EPO je kontrola chyb dostupná všem.
 */
final class SubmissionEnvironmentMiddleware implements MiddlewareInterface
{
    private const PREFIXES = [
        '/api/payroll',
        '/api/submissions',
        '/api/admin/isds/access',
        '/api/settings/databox',
        '/api/settings/isds-gateway',
    ];

    public function __construct(
        private readonly SubmissionEnvironmentPolicy $policy,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->policy->testAllowed()
            || strtoupper($request->getMethod()) === 'DELETE'
            || !$this->inScope(RequestPath::normalize($request->getUri()->getPath()))
            || !$this->requestsTest($request)
        ) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(403),
            SubmissionEnvironmentPolicy::ERROR_CODE,
            $this->policy->rejectionMessage(),
            403,
        );
    }

    private function inScope(string $path): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private function requestsTest(Request $request): bool
    {
        $candidates = [$request->getQueryParams()['environment'] ?? null];
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $candidates[] = $body['environment'] ?? null;
        }
        $route = $request->getAttribute(RouteContext::ROUTE);
        if (is_object($route) && method_exists($route, 'getArgument')) {
            $candidates[] = $route->getArgument('environment');
        }
        foreach ($candidates as $value) {
            if (is_string($value) && strtolower(trim($value)) === SubmissionEnvironmentPolicy::TEST) {
                return true;
            }
        }
        return false;
    }
}

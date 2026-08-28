<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use DateTimeImmutable;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceConflictException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zákonná evidence osoby — prohlášení k dani, daňová rezidence, sociální
 * a zdravotní příslušnost, sleva pracujícího důchodce a měsíční evidence
 * zdravotního minima.
 *
 * Tabulky (migrace 1256) tu byly od začátku, ale nevedla do nich žádná
 * zapisovací cesta: `INSERT` existoval jen v testech. Bez těchto údajů shodí
 * `PayrollRunStatutoryInputAssembler` celý zákonný výpočet do ručního
 * posouzení a uživatel neměl kde je doplnit.
 */
final class PayrollPersonStatutoryEvidenceAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        $effectiveOn = $this->effectiveOn($request->getQueryParams()['effective_on'] ?? null);
        if ($effectiveOn === null) {
            return $this->invalidEffectiveOn($response);
        }

        $view = $this->evidence->editorView(
            $this->currentSupplierId($request),
            (int) $args['id'],
            $effectiveOn,
        );
        if ($view === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }
        if (!RequestAuthorization::allows($request, 'payroll.health_evidence', AccessLevel::READ)) {
            $view = $this->withoutHealthEvidenceDocumentMetadata($view);
        }

        return Json::ok($response, ['evidence' => $view]);
    }

    /** @param array{id:string} $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $payload = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }
        $effectiveOn = $this->effectiveOn($payload['effective_on'] ?? null);
        if ($effectiveOn === null) {
            return $this->invalidEffectiveOn($response);
        }
        if ($this->containsHealthEvidenceDocument($payload)
            && !$this->requirePermission(
                $request,
                $response,
                'payroll.health_evidence',
                AccessLevel::WRITE,
                $error,
            )
        ) {
            return $this->errorResponse($error);
        }

        try {
            $view = $this->evidence->save(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $payload,
                $effectiveOn,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollPersonStatutoryEvidenceConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
                [
                    'collection' => $exception->collection,
                    'row_id' => $exception->rowId,
                    'current_row_version' => $exception->currentVersion,
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
        if (!RequestAuthorization::allows($request, 'payroll.health_evidence', AccessLevel::READ)) {
            $view = $this->withoutHealthEvidenceDocumentMetadata($view);
        }

        return Json::ok($response, ['evidence' => $view]);
    }

    /**
     * Zápis chrání `payroll.person.write`, ne `payroll.employment.write`.
     *
     * Evidence je vedená na OSOBĚ, ne na pracovním vztahu — jedna osoba může mít
     * vztahů víc a prohlášení k dani, rezidence i příslušnost k pojištění platí
     * napříč všemi. Stejné právo proto mají sousední osobní agendy (profil,
     * vyživované osoby, výplatní pravidla). Čtení stačí modulové `payroll`, jak
     * je zvykem u zbytku karty zaměstnance.
     */
    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
            return false;
        }
        $permission = $level === AccessLevel::WRITE ? 'payroll.person.write' : 'payroll';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return false;
        }

        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    private function effectiveOn(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (!is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function invalidEffectiveOn(Response $response): Response
    {
        return Json::error(
            $response,
            'validation_failed',
            'effective_on musí být datum YYYY-MM-DD.',
            422,
        );
    }

    /** @param array<string,mixed> $payload */
    private function containsHealthEvidenceDocument(array $payload): bool
    {
        $sections = $payload['sections'] ?? null;
        if (!is_array($sections)) {
            return false;
        }
        $coverages = $sections['health_coverages'] ?? null;
        if (!is_array($coverages)) {
            return false;
        }
        foreach ($coverages as $coverage) {
            if (is_array($coverage)
                && ($coverage['health_evidence_document_id'] ?? null) !== null
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $view
     *  @return array<string,mixed>
     */
    private function withoutHealthEvidenceDocumentMetadata(array $view): array
    {
        $coverages = $view['sections']['health_coverages'] ?? null;
        if (!is_array($coverages)) {
            return $view;
        }
        foreach ($coverages as $index => $coverage) {
            if (!is_array($coverage)) {
                continue;
            }
            unset(
                $coverage['health_evidence_document_id'],
                $coverage['health_evidence_document_sha256'],
            );
            $coverages[$index] = $coverage;
        }
        $view['sections']['health_coverages'] = $coverages;

        return $view;
    }

    private function clientIp(Request $request): ?string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Případy dávek nemocenského pojištění — NEMPRI a HZUPN
 * (§ 97 zák. č. 187/2006 Sb.).
 *
 * Session-only jako ostatní mzdová podání: odpověď nese jméno zaměstnance
 * a `preview` celý obsah datové věty včetně rodného čísla, adresy zaměstnavatele
 * a údajů o exekuci a insolvenci.
 *
 * Endpoint záměrně NEUMÍ nastavit stav `accepted` přímo. Povinnost splní až
 * PŘEDÁNÍ územní správě sociálního zabezpečení, takže přijetí se zapisuje jen
 * přes `receipt` a vždy se dnem doručení z protokolu.
 *
 * `dispatch` zařadí připravené podání do fronty datové schránky. Je tady, a ne
 * na obrazovce „Stav odeslání": ta patří kanálu VREP/APEP, kterým NEMPRI ani
 * HZUPN odeslat nejde (protokol v1.47 pro ně neuvádí identifikátor třídy
 * podání). Odesílá se proto tam, kde se podání připravilo.
 */
final class PayrollSicknessCaseAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly SicknessCaseService $cases,
        private readonly SicknessSubmissionService $submissions,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
        private readonly PayrollSubmissionTransportAttemptRepository $attempts,
    ) {}

    /**
     * Seznam případů plus to, co o nich potřebuje vědět odesílací lišta:
     * dostupnost datovky pro firmu a stav fronty u už zařazených podání.
     *
     * Obojí se posílá ROVNOU se seznamem schválně. Kdyby si to frontend
     * dotahoval až po kliknutí, musel by do té doby tvrdit něco o kanálu, co
     * ještě neví — a právě to („odešlete ho ve Stavu odeslání") posílalo účetní
     * na obrazovku, kde tahle podání nikdy nebyla.
     */
    public function list(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request): array {
            $supplierId = $this->currentSupplierId($request);
            $environment = $this->environment($request);

            return [
                'items' => $this->cases->list(
                    $supplierId,
                    $environment,
                    self::narrowingId($request->getQueryParams(), 'employment_id'),
                ),
                'transport' => $this->submissions->dataBoxTransport(
                    $supplierId,
                    $environment,
                ),
                'ready_submissions' => $this->attempts->listReadySubmissions(
                    $supplierId,
                    $environment,
                    SicknessSubmissionService::DISPATCHABLE_AGENDA_CODES,
                ),
            ];
        });
    }

    /** @param array<string,string> $args */
    public function dispatch(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request, $args): array {
            $supplierId = $this->currentSupplierId($request);
            $environment = $this->environment($request);
            $this->productionGate->assertEnvironmentActive($supplierId, $environment);

            return $this->submissions->enqueueDataBox(
                $supplierId,
                $environment,
                $this->caseId($args),
                $this->document($request),
                $this->userId($request),
            );
        });
    }

    public function create(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);

            return $this->cases->create(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->positiveInt($body['employment_id'] ?? null, 'employment_id'),
                $this->text($body['benefit_kind'] ?? null, 'benefit_kind'),
                $body,
                $this->userId($request) ?? 0,
            );
        }, 201);
    }

    /** @param array<string,string> $args */
    public function update(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);

            return $this->cases->update(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->caseId($args),
                $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                $body,
            );
        });
    }

    /** @param array<string,string> $args */
    public function preview(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request, $args): array {
            return $this->submissions->preview(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->caseId($args),
                $this->document($request),
            );
        });
    }

    /** @param array<string,string> $args */
    public function prepare(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request, $args): array {
            return $this->submissions->prepare(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->caseId($args),
                $this->document($request),
                $this->userId($request),
            );
        }, 201);
    }

    /** @param array<string,string> $args */
    public function receipt(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);

            return $this->cases->recordReceipt(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->caseId($args),
                $this->text($body['outcome'] ?? null, 'outcome'),
                $this->optionalText($body['accepted_on'] ?? null),
                $this->optionalText($body['reason'] ?? null),
            );
        });
    }

    /** @param array<string,string> $args */
    private function caseId(array $args): int
    {
        return $this->positiveInt($args['caseId'] ?? null, 'caseId');
    }

    private function document(Request $request): SicknessDocumentKind
    {
        $raw = $request->getQueryParams()['document'] ?? null;
        if (!is_string($raw) || $raw === '') {
            $body = (array) ($request->getParsedBody() ?? []);
            $raw = $body['document'] ?? null;
        }
        $kind = is_string($raw)
            ? SicknessDocumentKind::tryFrom(strtolower(trim($raw)))
            : null;
        if ($kind === null) {
            throw new \InvalidArgumentException(
                'Tiskopis musí být nempri nebo hzupn.',
            );
        }

        return $kind;
    }

    /**
     * @param callable():array<string,mixed> $work
     */
    private function run(
        Response $response,
        callable $work,
        int $createdStatus = 200,
    ): Response {
        try {
            $result = $work();
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (SubmissionChannelException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            ));
        } catch (SicknessException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $exception->validationCode === 'sickness_case_conflict'
                    ? 409
                    : 422,
            ));
        } catch (\OutOfBoundsException $exception) {
            return $this->noStore(Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            ));
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            ));
        }

        return $this->noStore(Json::ok($response, $result, $createdStatus));
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            return $error;
        }

        return null;
    }

    /**
     * Prostředí se nikdy neodvozuje z konfigurace serveru — podání odeslané
     * do testovacího prostředí ČSSZ žádnou povinnost nesplnilo.
     */
    private function environment(Request $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['environment']
            ?? ($request->getQueryParams()['environment'] ?? 'test');
        if (!in_array($value, ['test', 'production'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí musí být test nebo production.',
            );
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $name): int
    {
        $number = is_int($value)
            ? $value
            : (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
                ? (int) $value
                : 0);
        if ($number <= 0) {
            throw new \InvalidArgumentException(
                $name . ' musí být kladné celé číslo.',
            );
        }

        return $number;
    }

    private function text(mixed $value, string $name): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw new \InvalidArgumentException($name . ' je povinné.');
        }

        return $text;
    }

    private function optionalText(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

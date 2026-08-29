<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přihlášení pracovního vztahu u ČSSZ (PREZEC / REGZEC).
 *
 * Běžný preview/prepare NEPŘIJÍMÁ kód formuláře ani kód akce. Registrace A1
 * vybírá interakci z faktů o pracovním vztahu; A2–A8 vznikají jen z odděleného
 * schváleného a neměnného eventu.
 *
 * Session-only jako ostatní podání: `preview` vrací celý obsah přihlášky včetně
 * osobních identifikátorů a `prepare` zakládá úřední podání.
 */
final class PayrollRegistrationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRegistrationSubmissionService $registrations,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationChangeDetectionService $changes,
        private readonly PayrollModuleAccess $access,
    ) {}

    /**
     * Co se od posledního podání rozešlo a do kdy se to má nahlásit.
     *
     * Endpoint je čtecí, ale ZAPISUJE návrhy povinností: detekce je jediné
     * místo, které o rozejití ví, a kdyby jen vracela seznam, lhůta by nikde
     * neběžela a po zavření obrazovky by o ní nikdo nevěděl.
     *
     * @param array<string,string> $args
     */
    public function changeDetection(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => $this->changes->detect(
            $this->currentSupplierId($request),
            $this->environment($request),
            $this->employmentId($args),
        ));
    }

    /**
     * Jedno kliknutí: z návrhu vznikne neměnná registrační událost A3.
     *
     * @param array<string,string> $args
     */
    public function fileChange(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => $this->changes->file(
            $this->currentSupplierId($request),
            $this->environment($request),
            $this->employmentId($args),
            $this->proposalId($args),
            $this->userId($request),
        ), 201);
    }

    /** @param array<string,string> $args */
    public function dismissChange(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $note = $body['note'] ?? null;
        if (!is_string($note)) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                'Ruční vyřízení vyžaduje důvod.',
                422,
            ));
        }

        return $this->run($response, fn (): array => $this->changes->dismiss(
            $this->currentSupplierId($request),
            $this->environment($request),
            $this->employmentId($args),
            $this->proposalId($args),
            $this->userId($request),
            $note,
        ));
    }

    /**
     * Nácvik: co by se podalo a do kdy. Nic nezakládá, nic neodesílá.
     *
     * @param array<string,string> $args
     */
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
            return $this->registrations->preview(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
                $this->eventId($request),
            );
        });
    }

    /**
     * Zmrazí přihlášku do odesílatelné podoby. Odpověď záměrně nehlásí
     * „přihlášeno" — podání končí ve stavu `ready` a odeslání je samostatný
     * krok, který si vyžádá potvrzení od ČSSZ.
     *
     * @param array<string,string> $args
     */
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
            return $this->registrations->prepare(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
                $this->userId($request),
                $this->eventId($request),
            );
        }, 201);
    }

    /** @param array<string,string> $args */
    public function events(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => [
            'items' => $this->registrations->listEvents(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
            ),
        ]);
    }

    /** @param array<string,string> $args */
    public function a2EvidenceCandidates(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $effectiveOn = $request->getQueryParams()['effective_on'] ?? null;
        if (!is_string($effectiveOn)) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum RRRR-MM-DD.',
                422,
            ));
        }

        return $this->run($response, fn (): array =>
            $this->registrations->a2EvidenceCandidates(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
                $effectiveOn,
            ));
    }

    /** @param array<string,string> $args */
    public function approveEvent(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array =>
            $this->registrations->approveEvent(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
                (array) ($request->getParsedBody() ?? []),
                $this->userId($request),
            ), 201);
    }

    /** @param array<string,string> $args */
    public function a1Profile(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => [
            'profile' => $this->identities->a1Profile(
                $this->currentSupplierId($request),
                $this->employmentId($args),
            ),
        ]);
    }

    /** @param array<string,string> $args */
    public function saveA1Profile(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => [
            'profile' => $this->identities->saveA1Profile(
                $this->currentSupplierId($request),
                $this->employmentId($args),
                (array) ($request->getParsedBody() ?? []),
                $this->userId($request),
            ),
        ], 201);
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
        } catch (PayrollRegistrationXmlException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            ));
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            $status = $exception->validationCode
                === 'registration_regzec_a1_profile_conflict'
                    ? 409
                    : 422;
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $status,
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
        $status = ($result['created'] ?? null) === true
            ? $createdStatus
            : 200;

        return $this->noStore(Json::ok($response, $result, $status));
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
     * Prostředí se nikdy neodvozuje z konfigurace serveru — testovací
     * a ostrá registrace jsou dvě různé identity a záměna je nevratná.
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

    /** @param array<string,string> $args */
    private function employmentId(array $args): int
    {
        $value = $args['employmentId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'employmentId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    /** @param array<string,string> $args */
    private function proposalId(array $args): int
    {
        $value = $args['proposalId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'proposalId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    private function eventId(Request $request): ?int
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['event_id']
            ?? ($request->getQueryParams()['event_id'] ?? null);
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'event_id musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

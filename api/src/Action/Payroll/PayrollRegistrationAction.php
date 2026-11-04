<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1MasterDataWriter;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
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
        private readonly PayrollRegistrationA1MasterDataWriter $masterData,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    /**
     * Zápis opravené hodnoty z formuláře A1 zpátky do kmenových dat.
     *
     * Oprávnění je `payroll.person.write`, ne `payroll.submissions`: tenhle
     * endpoint mění evidenci osoby a pracovního vztahu, ne podání. Kdo smí
     * podávat, ale ne editovat kartu osoby, sem nesmí.
     *
     * @param array<string,string> $args
     */
    public function writeA1MasterData(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.person.write',
        );
        if ($denied !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $fields = $body['fields'] ?? null;
        if (!is_array($fields)) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                'Chybí seznam údajů, které se mají do kmenových dat zapsat.',
                422,
            ));
        }

        return $this->run($response, fn (): array => $this->masterData->write(
            $this->currentSupplierId($request),
            $this->employmentId($args),
            array_values($fields),
            $this->userId($request),
            $this->ip($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

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

        $body = (array) ($request->getParsedBody() ?? []);
        $effectiveOn = $body['effective_on'] ?? null;
        if ($effectiveOn !== null && !is_string($effectiveOn)) {
            return $this->noStore(Json::error(
                $response,
                'validation_failed',
                'Datum platnosti změny musí mít tvar RRRR-MM-DD.',
                422,
            ));
        }

        return $this->run($response, fn (): array => $this->changes->file(
            $this->currentSupplierId($request),
            $this->environment($request),
            $this->employmentId($args),
            $this->proposalId($args),
            $this->userId($request),
            $effectiveOn === '' ? null : $effectiveOn,
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
                $this->fullRegistrationRequested($request),
                $this->startNotKnownInAdvance($request),
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
                $this->fullRegistrationRequested($request),
                $this->startNotKnownInAdvance($request),
                $this->replacesSubmissionId($request),
            );
        }, 201);
    }

    /**
     * „Připravit znovu": připravená změna, jejíž ročník zastaral, se nahradí
     * novou větou ze stejné události.
     */
    private function replacesSubmissionId(Request $request): ?int
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['replaces_submission_id'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'replaces_submission_id musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    /**
     * Stav existující přihlášky vztahu (připravená, odeslaná, přijatá) pro kartu
     * po načtení. `submission: null` = žádná živá přihláška není.
     *
     * @param array<string,string> $args
     */
    public function current(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array => [
            'submission' => $this->registrations->currentRegistration(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->employmentId($args),
            ),
        ]);
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

        return $this->run($response, fn (): array =>
            $this->identities->a1ProfileView(
                $this->currentSupplierId($request),
                $this->employmentId($args),
            ));
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
     * Kontrola úplnosti profilu A1. Nic neukládá — vrací seznam vadných polí,
     * aby je formulář uměl označit tam, kde se vyplňují.
     *
     * Oprávnění je stejné jako u uložení: kontrola se pouští z rozepsaného
     * formuláře, který smí otevřít jen ten, kdo profil zapisuje.
     *
     * @param array<string,string> $args
     */
    public function checkA1Profile(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, fn (): array =>
            $this->identities->checkA1Profile(
                $this->currentSupplierId($request),
                $this->employmentId($args),
                (array) ($request->getParsedBody() ?? []),
            ));
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
                $exception->problems === []
                    ? []
                    : ['problems' => $exception->problems],
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
        string $permission = 'payroll.submissions',
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
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
            ?? ($request->getQueryParams()['environment'] ?? 'production');
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

    /**
     * Před nástupem českého zaměstnance: `registration_mode=full` volí plnou
     * registraci A1 místo výchozího částečného přihlášení P1. Kód formuláře
     * endpoint dál nepřijímá — o agendě rozhoduje resolver, tohle je jen
     * volba, kterou zákon zaměstnavateli dává.
     */
    private function fullRegistrationRequested(Request $request): bool
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['registration_mode']
            ?? ($request->getQueryParams()['registration_mode'] ?? null);
        if ($value === null || $value === '' || $value === 'auto') {
            return false;
        }
        if ($value !== 'full') {
            throw new \InvalidArgumentException(
                'registration_mode musí být auto, nebo full.',
            );
        }

        return true;
    }

    /**
     * `start_not_known_in_advance=true`: nástup zaměstnance nebyl předem znám
     * (zaměstnanec začal pracovat bez ohlášení), takže lhůta přihlášení je
     * osm dnů od vzniku povinnosti poskytovat plnění, resp. od prvního plnění
     * (§ 19 odst. 1 písm. b) zákona č. 323/2025 Sb.).
     */
    private function startNotKnownInAdvance(Request $request): bool
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['start_not_known_in_advance']
            ?? ($request->getQueryParams()['start_not_known_in_advance'] ?? null);
        if ($value === null || $value === '' || $value === false
            || $value === 'false' || $value === '0' || $value === 0
        ) {
            return false;
        }
        if ($value !== true && $value !== 'true' && $value !== '1' && $value !== 1) {
            throw new \InvalidArgumentException(
                'start_not_known_in_advance musí být true, nebo false.',
            );
        }

        return true;
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

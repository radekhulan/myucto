<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Záměr uplatňovat slevu na pojistném (OZUSPOJ, § 23e zák. č. 589/1992 Sb.).
 *
 * Session-only jako ostatní mzdová podání: odpověď nese jméno zaměstnance
 * a `preview` celý obsah oznámení včetně rodného čísla.
 *
 * Endpoint záměrně NEUMÍ nastavit stav `accepted` přímo. Nárok na slevu podle
 * § 7a odst. 5 vzniká DORUČENÍM oznámení ČSSZ, takže přijetí se zapisuje jen
 * přes `receipt` a vždy se dnem doručení z protokolu.
 */
final class PayrollDiscountIntentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly OzuspojIntentService $intents,
        private readonly OzuspojSubmissionService $submissions,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        return $this->run($response, function () use ($request): array {
            return ['items' => $this->intents->list(
                $this->currentSupplierId($request),
                $this->environment($request),
                self::narrowingId(
                    $request->getQueryParams(),
                    'employment_id',
                ),
            )];
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

            return $this->intents->create(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->positiveInt($body['employment_id'] ?? null, 'employment_id'),
                $this->text($body['intent_from'] ?? null, 'intent_from'),
                $this->optionalText($body['employee_informed_on'] ?? null),
                $this->userId($request) ?? 0,
            );
        }, 201);
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
                $this->intentId($args),
                $this->kind($request),
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
                $this->intentId($args),
                $this->kind($request),
                $this->userId($request),
            );
        }, 201);
    }

    /** @param array<string,string> $args */
    public function end(
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

            return $this->intents->requestEnd(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->intentId($args),
                $this->text($body['intent_to'] ?? null, 'intent_to'),
            );
        });
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

            return $this->intents->recordReceipt(
                $this->currentSupplierId($request),
                $this->environment($request),
                $this->intentId($args),
                $this->text($body['outcome'] ?? null, 'outcome'),
                $this->optionalText($body['accepted_on'] ?? null),
                $this->optionalText($body['reason'] ?? null),
            );
        });
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
        } catch (OzuspojException $exception) {
            return $this->noStore(Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $exception->validationCode === 'ozuspoj_intent_conflict'
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
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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
     * Prostředí se nikdy neodvozuje z konfigurace serveru — záměr oznámený do
     * testovacího prostředí ČSSZ nikomu žádný nárok nezaložil.
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

    private function kind(Request $request): OzuspojSubmissionKind
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['submission_kind']
            ?? ($request->getQueryParams()['submission_kind'] ?? 'start');

        return match ($value) {
            'start' => OzuspojSubmissionKind::Start,
            'end' => OzuspojSubmissionKind::End,
            'cancellation' => OzuspojSubmissionKind::Cancellation,
            default => throw new \InvalidArgumentException(
                'submission_kind musí být start, end nebo cancellation.',
            ),
        };
    }

    /** @param array<string,string> $args */
    private function intentId(array $args): int
    {
        $value = $args['intentId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'intentId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
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

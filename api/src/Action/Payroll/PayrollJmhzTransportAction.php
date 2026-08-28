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
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odeslání měsíčního hlášení na ČSSZ a dotažení výsledku.
 *
 * Tři kroky, schválně oddělené, protože se dějí v různém čase a mají různé
 * důsledky:
 *
 *  * `send` odešle zmrazené podání. Vrací potvrzení o PŘEVZETÍ, ne o přijetí —
 *    zpracování na straně ČSSZ teprve začíná a vydávat to za hotovo je přesně
 *    ta záměna, po které uživatel přestane sledovat výsledek.
 *  * `poll` se ptá na výsledek. Dokud VREP odpovídá potvrzením, běží zpracování
 *    a podání zůstává otevřené.
 *  * `close` uzavře transakci. Podací protokol to vyžaduje výslovně; aplikace,
 *    které transakce neuzavírají, porušují pravidla provozu. Uzavírá se až po
 *    dotažení protokolu — dřív by se výsledek ztratil.
 *
 * `Idempotency-Key` je u odeslání povinný. Bez něj by druhé kliknutí založilo
 * druhé podání za totéž období; ČSSZ takové podání odmítne jako duplicitu
 * a vzít zpět se nedá.
 */
final class PayrollJmhzTransportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzDispatchService $dispatch,
        private readonly JmhzProtocolExplainer $explainer,
        private readonly PayrollSubmissionTransportAttemptRepository $attempts,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /** @param array{submissionId:string} $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '') {
            return $this->invalid($response, 'Hlavička Idempotency-Key je povinná.');
        }
        // Datovou větu klient NEPOSÍLÁ. Odesílá se výhradně ZMRAZENÝ artefakt
        // z archivu — jediný dokument, který prošel XSD i katalogem kontrol a
        // na jehož otisk se odvolává ledger. Dokud se sem `payload_xml` bralo
        // z těla requestu, dalo se pod evidovaným podáním odeslat na VREP
        // libovolné XML a archiv pak tvrdil, že odešlo to zmrazené.
        $variableSymbol = $body['variable_symbol'] ?? null;
        if (!is_string($variableSymbol) || preg_match('/^[0-9]{1,10}$/D', $variableSymbol) !== 1) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }

        return $this->run($request, $response, function (string $environment) use (
            $request,
            $args,
            $variableSymbol,
            $idempotencyKey,
        ): JmhzDispatchOutcome {
            $supplierId = $this->currentSupplierId($request);
            $this->productionGate->assertEnvironmentActive(
                $supplierId,
                $environment,
            );

            return $this->dispatch->send(
                $supplierId,
                $environment,
                $this->id($args, 'submissionId'),
                null,
                $variableSymbol,
                $idempotencyKey,
                $this->userId($request),
            );
        });
    }

    /**
     * Přehled odeslaných podání a jejich stavu.
     *
     * Bez něj odpověď na otázku „co jsem odeslal a jak to dopadlo" existuje
     * jen v databázi. Ledger je append-only, takže se tu ukazuje i to, co
     * selhalo — právě neúspěšné pokusy jsou to, kvůli čemu se sem uživatel
     * podívá.
     */
    public function history(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        // Ledger jen mlčky usekával na dvou stech pokusech. Strop zůstává
        // tvrdý (z URL ho zvednout nejde), ale odpověď teď nese celkový počet
        // a starší pokusy jdou dolistovat.
        $query = $request->getQueryParams();
        $limit = max(1, min(
            PayrollSubmissionTransportAttemptRepository::LIST_MAX_LIMIT,
            (int) ($query['limit']
                ?? PayrollSubmissionTransportAttemptRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $supplierId = $this->currentSupplierId($request);
        $page = $this->attempts->listRecentPage(
            $supplierId,
            $environment,
            $limit,
            $offset,
        );
        $readySubmissions = $this->attempts->listReadyJmhzSubmissions(
            $supplierId,
            $environment,
        );

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'attempts' => $page['items'],
            'ready_submissions' => $readySubmissions,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    /** @param array{attemptId:string} $args */
    public function poll(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $variableSymbol = $this->queryVariableSymbol($request);
        if ($variableSymbol === null) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }

        return $this->run($request, $response, function (string $environment) use (
            $request,
            $args,
            $variableSymbol,
        ): JmhzDispatchOutcome {
            return $this->dispatch->poll(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
                $variableSymbol,
            );
        });
    }

    /** @param array{attemptId:string} $args */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $variableSymbol = $this->queryVariableSymbol($request);
        if ($variableSymbol === null) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        try {
            $result = $this->dispatch->close(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
                $variableSymbol,
            );
        } catch (JmhzTransportException $exception) {
            return $this->transportError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return $this->noStore(Json::ok($response, [
            'closed' => $result['closed'],
            'already_closed' => $result['already_closed'],
            'attempt' => $result['attempt'],
        ]));
    }

    /** @param callable(string):JmhzDispatchOutcome $operation */
    private function run(Request $request, Response $response, callable $operation): Response
    {
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        try {
            $outcome = $operation($environment);
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (JmhzTransportException $exception) {
            return $this->transportError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return $this->noStore(Json::ok($response, [
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
            'report' => $outcome->report === null ? null : [
                'status' => $outcome->report->status->name,
                // Chyby se posílají vysvětlené: samotná hláška z protokolu
                // říká, co je špatně, ale ne u koho a ve kterém údaji.
                'errors' => $this->explainer->explain($outcome->report),
            ],
        ]));
    }

    private function transportError(
        Response $response,
        JmhzTransportException $exception,
    ): Response {
        // Chyba na straně ČSSZ není chyba klienta ani naše: 502 říká, že cesta
        // ven selhala, aniž by to vypadalo jako neplatný požadavek.
        $status = match (true) {
            $exception->errorCode === 'jmhz_dispatch_attempt_unknown' => 404,
            $exception->errorCode === 'jmhz_signing_profile_missing' => 422,
            str_starts_with($exception->errorCode, 'jmhz_signing_') => 422,
            str_starts_with($exception->errorCode, 'jmhz_vrep_') => 502,
            default => 422,
        };

        return $this->noStore(
            Json::error($response, $exception->errorCode, $exception->getMessage(), $status),
        );
    }

    private function queryVariableSymbol(Request $request): ?string
    {
        $value = $request->getQueryParams()['variable_symbol'] ?? null;
        if (!is_string($value) || preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'test';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    /** @param array<string,string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level = AccessLevel::WRITE,
    ): ?Response {
        // Odeslání úředního podání jménem firmy se nikdy nespouští přes token:
        // token se dá odcizit a na rozdíl od relace u něj není druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}

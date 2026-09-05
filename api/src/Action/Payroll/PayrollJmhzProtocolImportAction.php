<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolImportService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Načtení protokolu ČSSZ ze souboru (datová schránka).
 *
 *   POST /api/payroll/submissions/jmhz-protocol-import   multipart, pole `file`
 *   GET  /api/payroll/submissions/jmhz-protocol-import   seznam načtených
 *
 * Podání odeslaná cizím softwarem naše aplikace nezná, takže přehled stavu
 * odeslání u firmy, která podala, ukazuje prázdno. Protokol o zpracování je
 * ale doklad, který uživatel drží v ruce — tohle je cesta, jak ho do přehledu
 * dostat, aniž by se předstíralo, že podání odeslala aplikace.
 */
final class PayrollJmhzProtocolImportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzProtocolImportService $protocols,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function import(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null) {
            return $this->invalid($response, 'Nahrajte XML protokol ČSSZ.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->invalid($response, 'Soubor se nepodařilo nahrát.');
        }
        if ((int) ($file->getSize() ?? 0) > JmhzProtocolImportService::MAX_BYTES) {
            return $this->noStore(Json::error(
                $response,
                'too_large',
                'Soubor je na protokol ČSSZ příliš velký.',
                413,
            ));
        }

        try {
            $result = $this->protocols->import(
                $this->currentSupplierId($request),
                $environment,
                $file->getStream()->getContents(),
                $file->getClientFilename(),
                $this->userId($request),
            );
        } catch (JmhzTransportException $exception) {
            return $this->transportError($response, $exception);
        } catch (\DomainException $exception) {
            return $this->noStore(
                Json::error($response, 'conflict', $exception->getMessage(), 409),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'protocol' => $result['protocol'],
            'created' => $result['created'],
            'errors' => $result['errors'],
        ]));
    }

    public function history(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $query = $request->getQueryParams();
        $limit = max(1, min(
            PayrollImportedJmhzProtocolRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollImportedJmhzProtocolRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $page = $this->protocols->history(
            $this->currentSupplierId($request),
            $environment,
            $limit,
            $offset,
        );

        // Klíč `protocols` zůstává kvůli stávajícím volajícím.
        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'protocols' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    /**
     * Vysvětlené chyby JEDNOHO protokolu.
     *
     * Seznam je záměrně nenese: počítají se z uloženého originálu, a načítat
     * kvůli tomu XML všech protokolů na stránce znamenalo posílat databází
     * desítky dokladů, ze kterých si uživatel otevře nanejvýš jeden.
     *
     * @param array<string,string> $args
     */
    public function errors(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $protocolId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($protocolId)) {
            return $this->invalid($response, 'Protokol musí být kladné celé číslo.');
        }

        $detail = $this->protocols->explain(
            $this->currentSupplierId($request),
            $environment,
            $protocolId,
        );

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'protocol_id' => $protocolId,
            'errors' => $detail['errors'],
            'detail_available' => $detail['detail_available'],
        ]));
    }

    /**
     * Chyba čtení protokolu je vada VSTUPU, ne serveru — 422. Výjimka je
     * nesoulad variabilního symbolu: to není neplatný soubor, ale pokus uložit
     * cizí doklad, a odpověď to má říkat nahlas (403).
     */
    private function transportError(
        Response $response,
        JmhzTransportException $exception,
    ): Response {
        $status = match ($exception->errorCode) {
            'jmhz_protocol_tenant_mismatch' => 403,
            'jmhz_protocol_too_large' => 413,
            default => 422,
        };

        return $this->noStore(
            Json::error($response, $exception->errorCode, $exception->getMessage(), $status),
        );
    }

    /**
     * PSR-7 vrací strom, ne plochý seznam — vnořená úroveň se prohledá taky,
     * aby se soubor neztratil kvůli tomu, jak ho formulář pojmenoval.
     *
     * @param array<array-key,mixed> $uploads
     */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }

        return null;
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'production';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
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
        // Stejně jako u odeslání: úřední doklady firmy se přes token nečtou
        // ani nezakládají — token se dá odcizit a nemá druhý faktor.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileException;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileService;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Personální spis zaměstnance — nahrané dokumenty a poznámky.
 * Session-only, vlastní oprávnění `payroll.personnel`.
 */
final class PayrollPersonnelFileAction
{
    use PayrollActionSupport;

    private const PERMISSION = 'payroll.personnel';

    public function __construct(
        private readonly PayrollPersonnelFileService $service,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/payroll/people/{id}/personnel-file */
    public function show(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, fn (): array => $this->service->overview(
            $this->currentSupplierId($request),
            (int) $args['id'],
        ));
    }

    /** POST /api/payroll/people/{id}/personnel-file/documents (multipart `file` + metadata) */
    public function upload(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? Json::error($response, 'file_too_large', 'Soubor je příliš velký.', 413)
                : Json::error($response, 'upload_failed', 'Soubor se nepodařilo nahrát.', 400);
        }
        $supplierId = $this->currentSupplierId($request);
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];

        $tmpDir = PayrollPersonnelFileStorage::baseDir($supplierId);
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
            return Json::error($response, 'storage_unavailable', 'Úložiště personálního spisu není dostupné.', 500);
        }
        $tmp = $tmpDir . '/.tmp-upload-' . bin2hex(random_bytes(12));
        try {
            $file->moveTo($tmp);

            return $this->run($response, fn (): array => ['document' => $this->service->upload(
                $supplierId,
                (int) $args['id'],
                $tmp,
                (string) $file->getClientFilename(),
                $input,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            )]);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** PUT /api/payroll/people/{id}/personnel-file/documents/{documentId} */
    public function updateDocument(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, fn (): array => ['document' => $this->service->updateDocument(
            $this->currentSupplierId($request),
            (int) $args['id'],
            (int) $args['documentId'],
            $this->body($request),
            $this->userId($request),
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        )]);
    }

    /** DELETE /api/payroll/people/{id}/personnel-file/documents/{documentId} */
    public function deleteDocument(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, function () use ($request, $args): array {
            $this->service->deleteDocument(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['documentId'],
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );

            return ['deleted' => true];
        });
    }

    /**
     * GET /api/payroll/people/{id}/personnel-file/documents/{documentId}/content[?inline=1]
     *
     * Vždy `nosniff` a sandboxované CSP. Inline jen u PDF a obrázků, jejichž
     * obsah odpovídá typu (rozhoduje služba), jinak příloha.
     */
    public function content(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        $inline = ($request->getQueryParams()['inline'] ?? '') === '1';

        try {
            $content = $this->service->content(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['documentId'],
                $inline,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollPersonnelFileException $exception) {
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        $safe = (string) preg_replace('/[\r\n"\\\\]/', '_', $content['file_name']);
        $disposition = ($content['inline'] ? 'inline' : 'attachment')
            . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($content['file_name']);
        $response->getBody()->write($content['bytes']);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $content['content_type'])
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) strlen($content['bytes']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** POST /api/payroll/people/{id}/personnel-file/notes */
    public function createNote(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, fn (): array => $this->service->createNote(
            $this->currentSupplierId($request),
            (int) $args['id'],
            $this->body($request),
            $this->userId($request),
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    /** PUT /api/payroll/people/{id}/personnel-file/notes/{noteId} */
    public function updateNote(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, fn (): array => $this->service->updateNote(
            $this->currentSupplierId($request),
            (int) $args['id'],
            (int) $args['noteId'],
            $this->body($request),
            $this->userId($request),
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    /** DELETE /api/payroll/people/{id}/personnel-file/notes/{noteId} */
    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }

        return $this->run($response, fn (): array => $this->service->deleteNote(
            $this->currentSupplierId($request),
            (int) $args['id'],
            (int) $args['noteId'],
            $this->userId($request),
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    private function run(Response $response, callable $work): Response
    {
        try {
            $view = $work();
        } catch (PayrollPersonNotFoundException) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollPersonnelFileException $exception) {
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return Json::ok($response, $view);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::actsAsSession($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        if (!$this->requirePermission($request, $response, self::PERMISSION, $level, $error)) {
            return false;
        }

        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
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

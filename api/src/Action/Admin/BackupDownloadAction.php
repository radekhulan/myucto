<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\ReauthException;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use MyInvoice\Service\Backup\BackupArchiveCatalog;
use MyInvoice\Service\Backup\BackupRetentionPolicy;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Stažení záloh — automatické zálohy z cronů zpřístupněné z prohlížeče.
 *
 *   GET  /api/admin/backups                  přehled po sekcích (databáze, dokumenty, …)
 *   GET  /api/admin/backups/download/{name}  stažení konkrétní zálohy
 *   POST /api/admin/backups/password         odhalení hesla, kterým jsou zálohy šifrované
 *
 * Proč to existuje: zálohy leží mimo docroot a dosud se k nim šlo dostat jen přes
 * SSH/FTP. Na spravované instalaci to znamenalo, že si zákazník vlastní zálohu
 * stáhnout neuměl — což je přesně ta chvíle, kdy ji potřebuje.
 *
 * Doplněk „Kompletního exportu dat" ({@see \MyInvoice\Action\Export\InstanceExportAction}),
 * ne jeho náhrada: export je jednorázový balíček aktuálního stavu JEDNÉ firmy na
 * vyžádání, tohle je historie automatických záloh CELÉ instalace. Proto se tu
 * taky nefiltruje podle firmy — dump databáze žádnou hranici firem nezná.
 *
 * Přístup: vše pod `/api/admin/` je superadmin (fail-closed fallback
 * v {@see \MyInvoice\Security\RoutePermissionMap::match()}); guard níž je druhá vrstva.
 *
 * Heslo k šifrování se NEVRACÍ v přehledu. Kdo ho zná, otevře dump celé databáze,
 * takže se odhaluje až proti čerstvému ověření (passkey, nebo heslo + TOTP) a
 * s auditní stopou — stejně jako heslo ke stavu podání na EPO.
 */
final class BackupDownloadAction
{
    public function __construct(
        private readonly BackupArchiveCatalog $catalog,
        private readonly Config $config,
        private readonly SensitiveOperationReauth $reauth,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET / — sekce se soubory + kontext (kam se ukládá, jak dlouho se drží). */
    public function list(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }

        $sections = $this->catalog->sections();
        $retention = BackupRetentionPolicy::fromConfig($this->config);

        return Json::ok($response, [
            'directory'  => $this->catalog->directory(),
            'exists'     => $this->catalog->exists(),
            'encrypted'  => BackupEncryption::passwordFromConfig($this->config) !== '',
            'retention'  => $retention->describe(),
            'kinds'      => BackupArchiveCatalog::KINDS,
            'sections'   => $sections,
            'total_files' => array_sum(array_map(static fn (array $s): int => count($s['files']), $sections)),
            'total_size_bytes' => array_sum(array_column($sections, 'size_bytes')),
        ]);
    }

    /** GET /download/{name} — stažení jedné zálohy. */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }

        $name = (string) ($args['name'] ?? '');
        // Cesta vzniká výhradně dohledáním v katalogu; z requestu se bere jen název,
        // který musí být shodný s položkou, kterou katalog sám vylistoval.
        $abs = $this->catalog->resolveForDownload($name);
        if ($abs === null) {
            return Json::error($response, 'not_found', 'Záloha nenalezena.', 404);
        }
        $handle = fopen($abs, 'rb');
        if ($handle === false) {
            return Json::error($response, 'file_unavailable', 'Zálohu nelze otevřít.', 410);
        }

        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', basename($abs)) ?? 'backup.zip';
        $this->log($request, 'backup.downloaded', ['file' => $safeName, 'size' => filesize($abs)]);

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) filesize($abs))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** POST /password — odhalení šifrovacího hesla po čerstvém ověření. */
    public function revealPassword(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->reauth->verify(
                $request,
                $userId,
                $body,
                'backup_password_reveal',
                MfaStepUpService::OPERATION_BACKUP_PASSWORD,
                'backup.reauth_failed',
                'Heslo k zálohám lze zobrazit jen z webového rozhraní.',
            );
        } catch (ReauthException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $password = BackupEncryption::passwordFromConfig($this->config);
        if ($password === '') {
            // Ne 404: prošlé ověření si zaslouží odpověď na otázku, kterou položilo.
            // „Zálohy nejsou šifrované" je platný a pro provozovatele důležitý stav.
            return Json::ok($response, ['encrypted' => false, 'password' => null])
                ->withHeader('Cache-Control', 'private, no-store');
        }

        // Do auditu jde KDO a KDY, nikdy samotné heslo.
        $this->log($request, 'backup.password_revealed', ['cipher' => 'AES-256']);

        return Json::ok($response, [
            'encrypted' => true,
            'password' => $password,
            'cipher' => 'AES-256',
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function guard(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Zálohy smí stahovat jen správce instalace.', 403);
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $this->activity->log(
            $action,
            $userId,
            'user',
            $userId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request),
        );
    }
}

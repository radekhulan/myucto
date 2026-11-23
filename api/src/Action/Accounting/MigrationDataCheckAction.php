<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kontrola dat po převodu z jiného účetního programu ({@see MigrationDataCheckService}).
 *
 *   GET  /api/accounting/migration-checks              — přehled kontrol firmy se souhrnem
 *   GET  /api/accounting/migration-checks/{key}        — detail kontroly, nálezy po stránkách (?page, ?per_page, ?state)
 *   POST /api/accounting/migration-checks/{key}/preview — náhled opravy (nic nezapisuje)
 *   POST /api/accounting/migration-checks/{key}/fix     — oprava; tělo {confirm: true, fingerprint, options}
 *
 * Čtení a náhled: právo `accounting` (čtení). Oprava: správce firmy nebo zápis do
 * účetnictví z přihlášené relace ({@see RequestAuthorization::canFixMigrationData()}).
 */
final class MigrationDataCheckAction
{
    use AccountingActionSupport;

    public function __construct(private readonly MigrationDataCheckService $checks) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Kontrolu dat po převodu smí zobrazit jen role s přístupem do účetnictví.', 403);
        }
        $data = $this->checks->summary($this->currentSupplierId($request));
        $data['can_fix'] = RequestAuthorization::canFixMigrationData($request);
        return Json::ok($response, $data);
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Kontrolu dat po převodu smí zobrazit jen role s přístupem do účetnictví.', 403);
        }
        $q = $request->getQueryParams();
        foreach (['page', 'per_page'] as $name) {
            if (isset($q[$name]) && (!is_string($q[$name]) || preg_match('/^[1-9]\d{0,5}$/D', $q[$name]) !== 1)) {
                return Json::error($response, 'validation_failed', "Parametr {$name} musí být kladné celé číslo.", 422);
            }
        }
        $state = isset($q['state']) && is_string($q['state']) && $q['state'] !== '' ? $q['state'] : null;
        return $this->run($response, fn (): array => $this->checks->detail($this->currentSupplierId($request), (string) $args['key'],
            (int) ($q['page'] ?? 1), (int) ($q['per_page'] ?? 50), $state) + ['can_fix' => RequestAuthorization::canFixMigrationData($request)]);
    }

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Náhled opravy smí zobrazit jen role s přístupem do účetnictví.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $options = $body['options'] ?? [];
        if (!is_array($options)) {
            return Json::error($response, 'validation_failed', 'Volby opravy musí být objekt.', 422);
        }
        return $this->run($response, fn (): array => $this->checks->preview($this->currentSupplierId($request), (string) $args['key'], $options));
    }

    /** @param array<string,string> $args */
    public function fix(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canFixMigrationData($request)) {
            return Json::error($response, 'forbidden', 'Data po převodu smí opravit jen správce firmy nebo role se zápisem do účetnictví v aplikaci.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($body['confirm'] ?? null) !== true) {
            return Json::error($response, 'confirmation_required', 'Opravu je potřeba potvrdit (confirm: true) po kontrole náhledu.', 422);
        }
        $fingerprint = $body['fingerprint'] ?? null;
        if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{16}$/D', $fingerprint) !== 1) {
            return Json::error($response, 'validation_failed', 'Chybí nebo je neplatný otisk náhledu opravy (fingerprint).', 422);
        }
        $options = $body['options'] ?? [];
        if (!is_array($options)) {
            return Json::error($response, 'validation_failed', 'Volby opravy musí být objekt.', 422);
        }
        return $this->run($response, fn (): array => $this->checks->fix($this->currentSupplierId($request), (string) $args['key'], $this->userId($request), $options, $fingerprint));
    }

    /** @param callable():array<string,mixed> $call */
    private function run(Response $response, callable $call): Response
    {
        try {
            return Json::ok($response, $call());
        } catch (MigrationDataCheckException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->status);
        }
    }
}

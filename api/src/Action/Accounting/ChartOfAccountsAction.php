<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\ChartAccountUsage;
use MyInvoice\Service\Accounting\Reports\AccountDetailService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účtová osnova (chart of accounts) — REST API (Epic F1).
 *
 *   GET   /api/accounting/accounts        — seznam (?tree=1 = strom, ?include_inactive=1)
 *   GET   /api/accounting/accounts/{id}   — karta účtu (kmen + analytiky + PS/obraty/KS)
 *   POST  /api/accounting/accounts        — nová analytika (dítě syntetiky) — účetní|admin
 *   PATCH /api/accounting/accounts/{id}   — přejmenování / deaktivace — účetní|admin
 *
 * Založení analytiky a úprava analytiky jsou dostupné i přes API token
 * (ApiScopeMiddleware::BEARER_WRITE_EXCEPTIONS); syntetiku token jen čte.
 *
 * Účty se NEmažou (auditní stopa) — jen deaktivují (is_active=0).
 */
final class ChartOfAccountsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Délka sloupce `chart_of_accounts.name`. */
    private const NAME_MAX = 190;

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountDetailService $detail,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly ChartAccountUsage $usage,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $includeInactive = !empty($q['include_inactive']);

        if (!empty($q['tree'])) {
            return Json::ok($response, $this->accounts->tree($supplierId, $includeInactive));
        }
        return Json::ok($response, $this->accounts->listForTenant($supplierId, $includeInactive));
    }

    /**
     * Karta účtu. `from`/`to` jsou volitelné — bez nich se vezme účetní období,
     * do kterého spadá dnešek, jinak kalendářní rok (aby šel odkaz na kartu
     * poslat bez znalosti období).
     */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID účtu.', 422);
        }

        $q = $request->getQueryParams();
        $range = $this->dateRange($q);
        if ($range === null) {
            return Json::error($response, 'validation_failed', 'from/to musí být datum (YYYY-MM-DD) a from nesmí být větší než to.', 422);
        }

        try {
            $data = $this->detail->build($supplierId, $id, $range[0], $range[1], (string) ($q['after_closing'] ?? '') === '1');
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $data);
    }

    /**
     * @param array<string,mixed> $q
     * @return array{0:string, 1:string}|null
     */
    private function dateRange(array $q): ?array
    {
        $year = (new \DateTimeImmutable('today'))->format('Y');
        $from = trim((string) ($q['from'] ?? '')) ?: $year . '-01-01';
        $to   = trim((string) ($q['to'] ?? '')) ?: $year . '-12-31';
        if (!$this->isDate($from) || !$this->isDate($to) || $from > $to) {
            return null;
        }
        return [$from, $to];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $parentId = (int) ($body['parent_id'] ?? 0);
        // Integrace a AI asistent znají rodiče podle čísla (378), ne podle interního ID.
        $parentCode = trim((string) ($body['parent_code'] ?? ''));
        $code = trim((string) ($body['account_code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));

        if ($parentId <= 0 && $parentCode === '') {
            return Json::error($response, 'validation_failed', 'parent_id nebo parent_code je povinné (analytika je dítě syntetického účtu).', 422);
        }
        if ($code === '' || $name === '') {
            return Json::error($response, 'validation_failed', 'account_code a name jsou povinné.', 422);
        }
        if (!preg_match('/^[0-9A-Za-z.]{3,10}$/', $code)) {
            return Json::error($response, 'validation_failed', 'account_code musí mít 3–10 znaků (číslice/písmena/tečka).', 422);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return Json::error($response, 'validation_failed', 'name může mít nejvýše ' . self::NAME_MAX . ' znaků.', 422);
        }

        $parent = $parentId > 0
            ? $this->accounts->findById($supplierId, $parentId)
            : $this->accounts->findByCode($supplierId, $parentCode);
        if ($parent === null || ($parentId > 0 && $parentCode !== '' && (string) $parent['account_code'] !== $parentCode)) {
            return Json::error($response, 'not_found', 'Rodičovský účet nenalezen.', 404);
        }
        $parentId = (int) $parent['id'];

        // Tečkovaný tvar je od migrace 1322 jediný správný zápis analytiky (211.100),
        // ale ruční založení ho dosud nevynucovalo — vznikla tak 211200 vedle 211.200,
        // což strojově neporovnáš s hlavní knihou účetní. Kód účtu se navíc později
        // měnit nedá (visí na něm kontace, pravidla i karty jako TEXT), takže překlep
        // je nevratný → tečku doplníme rovnou tady.
        $code = self::withAnalyticDot($code, (string) $parent['account_code']);
        // Tečka přidává znak, a délka se kontrolovala PŘED normalizací — u desetiznakového
        // kódu by se výsledek tiše ořízl o poslední číslici (sloupec je varchar(10) a
        // sql_mode nemusí být striktní), takže by vznikl jiný účet, než uživatel zadal.
        if (mb_strlen($code) > 10) {
            return Json::error($response, 'validation_failed',
                'account_code je po doplnění tečky delší než 10 znaků (' . $code . ') — zkraťte analytiku.', 422);
        }

        if ($this->accounts->codeExists($supplierId, $code)) {
            return Json::error($response, 'duplicate_account', 'Účet s tímto kódem už v osnově existuje.', 409);
        }

        // Analytika dědí typ i normální stranu po syntetickém rodiči.
        try {
        $id = $this->accounts->insert($supplierId, [
            'account_code' => $code,
            'name'         => $name,
            'account_type' => (string) $parent['account_type'],
            'normal_side'  => $parent['normal_side'] !== null ? (string) $parent['normal_side'] : null,
            'is_synthetic' => false,
            'parent_id'    => $parentId,
            'is_active'    => true,
        ],date('Y-m-d'),$this->userId($request));
        } catch (\MyInvoice\Service\Accounting\AccountDefinitionException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus); }

        $this->log($request, 'accounting.account_created', $id, ['account_code' => $code, 'parent_id' => $parentId]);
        return Json::ok($response, $this->accounts->findById($supplierId, $id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $existing = $this->accounts->findById($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Účet nenalezen.', 404);
        }
        // Syntetika je z šablony osnovy a míří na ni automatické kontace; její
        // vypnutí přes token by zastavilo účtování dokladů celé firmy.
        if (!empty($existing['is_synthetic']) && RequestAuthorization::isBearerAuth($request)) {
            return Json::error($response, 'token_write_forbidden',
                'Syntetický účet lze přes API token jen číst; přes token se upravují jen analytické účty.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $data = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return Json::error($response, 'validation_failed', 'name nesmí být prázdné.', 422);
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                return Json::error($response, 'validation_failed', 'name může mít nejvýše ' . self::NAME_MAX . ' znaků.', 422);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('is_active', $body)) {
            $data['is_active'] = (bool) $body['is_active'];
        }
        if ($data === []) {
            return Json::ok($response, $existing);
        }

        try { $this->accounts->update($supplierId, $id, $data, date('Y-m-d'), null, $this->userId($request)); }
        catch (\MyInvoice\Service\Accounting\AccountDefinitionException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus); }
        $this->log($request, 'accounting.account_updated', $id, ['fields' => array_keys($data)]);
        return Json::ok($response, $this->accounts->findById($supplierId, $id));
    }

    /**
     * Smaže analytiku, na které ještě nic nevisí — oprava chybně zadaného kódu.
     * Kód účtu totiž nejde přejmenovat (je to textový klíč kontací, pravidel a karet),
     * takže bez mazání by překlep v osnově zůstal navždy.
     *
     * Syntetické účty se nemažou: jsou z šablony osnovy a visí na nich analytiky
     * i kontace, které aplikace zakládá sama.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $account = $this->accounts->findById($supplierId, $id);
        if ($account === null) {
            return Json::error($response, 'not_found', 'Účet nenalezen.', 404);
        }
        if (!empty($account['is_synthetic'])) {
            return Json::error($response, 'account_synthetic',
                'Syntetický účet z osnovy smazat nelze — smazat jde jen analytika bez pohybů.', 422);
        }

        $usages = $this->usage->usages($supplierId, $id, (string) $account['account_code']);
        if ($usages !== []) {
            return Json::error($response, 'account_in_use',
                'Účet už se používá (' . implode(', ', $usages) . ') — smazat lze jen analytiku bez pohybů. Místo mazání ji deaktivujte.', 409);
        }

        try { $this->accounts->delete($supplierId, $id); }
        catch (\MyInvoice\Service\Accounting\AccountDefinitionException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus); }
        $this->log($request, 'accounting.account_deleted', $id, ['account_code' => $account['account_code']]);
        return Json::ok($response, ['deleted' => true]);
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            $this->userId($request),
            'chart_of_accounts',
            $entityId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    /**
     * Doplní tečku za syntetiku, je-li kód zapsaný bez ní (211200 → 211.200).
     * Kód s tečkou, nečíselný kód i kód, který se syntetikou rodiče nezačíná,
     * se nechává být — uživatel může mít vlastní systém značení a přepisovat mu
     * ho by bylo horší než nejednotnost.
     */
    private static function withAnalyticDot(string $code, string $parentCode): string
    {
        if (str_contains($code, '.') || preg_match('/^\d{4,}$/', $code) !== 1) {
            return $code;
        }
        if ($parentCode === '' || !str_starts_with($code, $parentCode) || $code === $parentCode) {
            return $code;
        }
        return $parentCode . '.' . substr($code, strlen($parentCode));
    }

}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingCodeMap;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingException;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingFormat;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingImportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Měsíční zaúčtování mezd z PAMICY (XML dataPack pro POHODU).
 *
 *   POST /api/accounting/payroll-posting-imports/preview  multipart `file` - náhled, nic neukládá
 *   POST /api/accounting/payroll-posting-imports/apply    multipart `file` (+ `create_missing_accounts=1`)
 *   GET  /api/accounting/payroll-posting-imports/code-map - uložené přiřazení zkratek firmy
 *   PUT  /api/accounting/payroll-posting-imports/code-map JSON `{codes:[{code, debit_account_code, credit_account_code}]}`
 *        - přiřazení zkratek předkontací k účtům MD a Dal ({@see PamicaPostingCodeMap})
 *
 * Volá ho průvodce PAMICA i import přijatých faktur, když rozpozná soubor se zaúčtováním
 * mezd ({@see PamicaPostingFormat}); obě místa sdílí tytéž akce a kontroly. Soubor jiného
 * formátu vrací 422 `not_payroll_posting`, podle kterého průvodce pokračuje převodem mezd.
 *
 * Práva: náhled čte účetnictví a ostatní závazky, načtení zakládá a účtuje interní doklad
 * (`accounting.journal.write`, `accounting.journal.post`) a ostatní závazky (`other_items`).
 * Založení chybějících analytik v osnově navíc `accounting`.
 */
final class PayrollPostingImportAction
{
    use AccountingActionSupport;

    private const CODE_MAP_PERMISSIONS = ['accounting.journal.write', 'accounting.journal.post', 'other_items'];

    public function __construct(
        private readonly PamicaPostingImportService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PamicaPostingCodeMap $codeMap,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        foreach (['accounting', 'other_items'] as $permission) {
            if (!$this->requirePermission($request, $response, $permission, AccessLevel::READ, $err)) return $err;
        }
        try {
            [$bytes] = $this->upload($request);
            $preview = $this->service->preview($this->currentSupplierId($request), $bytes);
        } catch (PamicaPostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        $preview['can_write'] = $this->canWrite($request, false);
        $preview['can_create_accounts'] = $this->canWrite($request, true);
        return Json::ok($response, $preview);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $body = $request->getParsedBody();
        $createAccounts = is_array($body) && in_array((string) ($body['create_missing_accounts'] ?? ''), ['1', 'true'], true);
        $required = ['accounting.journal.write', 'accounting.journal.post', 'other_items'];
        if ($createAccounts) $required[] = 'accounting';
        foreach ($required as $permission) {
            if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $err)) return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $audit = $this->auditMeta($request);
        try {
            [$bytes, $name] = $this->upload($request);
            $result = $this->service->apply($supplierId, $bytes, $name, $createAccounts, $audit);
        } catch (PamicaPostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            return $this->mapPostingError($response, $e);
        }
        $this->logger->log('payroll_posting.imported', $audit['user_id'], 'payroll_posting_import', (int) $result['import_id'], [
            'application' => PamicaPostingImportService::APPLICATION,
            'sym_par' => $result['sym_par'],
            'period' => $result['period'],
            'file_name' => $name,
            'internal_document_ids' => array_column($result['internal_documents'], 'id'),
            'other_item_ids' => array_column($result['other_items'], 'id'),
            'created_accounts' => $result['created_accounts'],
            'totals' => $result['totals'],
        ], $audit['ip'], $audit['user_agent'], $supplierId);
        return Json::ok($response, $result, 201);
    }

    /**
     * Uložené přiřazení zkratek předkontací firmy. Editor ho potřebuje i tehdy, když náhled
     * kvůli chybnému přiřazení nejde sestavit; čte ho jen ten, kdo ho smí uložit.
     */
    public function codeMap(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        foreach (self::CODE_MAP_PERMISSIONS as $permission) {
            if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $err)) return $err;
        }
        $rows = $this->codeMap->rows($this->currentSupplierId($request), PamicaPostingImportService::APPLICATION);
        return Json::ok($response, ['code_map' => $rows]);
    }

    /**
     * Uloží přiřazení zkratek předkontací. Mapování určuje, kam import účtuje, proto chce
     * stejná práva jako samotné načtení.
     */
    public function saveCodeMap(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        foreach (self::CODE_MAP_PERMISSIONS as $permission) {
            if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $err)) return $err;
        }
        $body = $request->getParsedBody();
        $codes = is_array($body) && is_array($body['codes'] ?? null) ? array_values($body['codes']) : [];
        $supplierId = $this->currentSupplierId($request);
        $audit = $this->auditMeta($request);
        try {
            $rows = $this->codeMap->save($supplierId, PamicaPostingImportService::APPLICATION, $codes, $audit['user_id']);
        } catch (PamicaPostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        $this->logger->log('payroll_posting.code_map_saved', $audit['user_id'], 'payroll_posting_code_map', null, [
            'application' => PamicaPostingImportService::APPLICATION,
            'codes' => $rows,
        ], $audit['ip'], $audit['user_agent'], $supplierId);
        return Json::ok($response, ['code_map' => $rows]);
    }

    /** Smí uživatel zaúčtování načíst (a případně založit chybějící analytiky)? */
    private function canWrite(Request $request, bool $accounts): bool
    {
        foreach (['accounting.journal.write', 'accounting.journal.post', 'other_items', ...($accounts ? ['accounting'] : [])] as $permission) {
            if (!RequestAuthorization::allows($request, $permission, AccessLevel::WRITE)) return false;
        }
        return true;
    }

    /** @return array{0:string,1:?string} obsah a jméno souboru */
    private function upload(Request $request): array
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new PamicaPostingException('no_file', 'Soubor nebyl odeslán.', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new PamicaPostingException('upload_failed', 'Nahrání souboru selhalo.', 400);
        }
        if ((int) ($file->getSize() ?? 0) > PamicaPostingFormat::MAX_BYTES) {
            throw new PamicaPostingException('file_too_large', 'Soubor je příliš velký (nejvýš 20 MiB).', 413);
        }
        $bytes = (string) $file->getStream()->getContents();
        if ($bytes === '') {
            throw new PamicaPostingException('empty_file', 'Soubor je prázdný.', 400);
        }
        if (strlen($bytes) > PamicaPostingFormat::MAX_BYTES) {
            throw new PamicaPostingException('file_too_large', 'Soubor je příliš velký (nejvýš 20 MiB).', 413);
        }
        $name = basename(str_replace('\\', '/', (string) ($file->getClientFilename() ?? '')));
        return [$bytes, $name !== '' ? $name : null];
    }
}

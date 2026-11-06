<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Abra\AbraConnectionService;
use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\NonprofitMigrationNotice;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AbraMigrationAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = AbraImportJobService::class;
    protected const EXCEPTION_CLASS = AbraException::class;
    protected const TEXT_DENIED = 'Nemáte oprávnění k převodu účetnictví.';
    protected const TEXT_MIGRATION_REQUIRED = 'Nejprve aktualizujte databázi pro převod z ABRA Flexi.';
    protected const RUN_ENTITY = 'abra_flexi_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.abra_flexi_dry_run_deleted';

    public function __construct(ImportJobRepository $jobs, AbraImportRepository $runs, ActivityLogger $logger,
        IpMatcher $ipMatcher, private readonly AbraConnectionService $connection, private readonly Connection $db)
    {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }
    protected function uploads(): ChunkedUploadStore { throw new \LogicException('ABRA Flexi reads the source API.'); }

    public function status(Request $request, Response $response): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, NonprofitMigrationNotice::attach($this->db, $supplierId, $this->connection->status($supplierId)));
    }
    public function update(Request $request, Response $response): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        try {
            return Json::ok($response, $this->connection->save(SupplierGuard::currentId($request), self::userId($request),
                (array) ($request->getParsedBody() ?? [])));
        } catch (AbraException $e) { return $this->sourceError($response, $e); }
        catch (\Throwable) { return Json::error($response, 'connection_failed', 'Připojení se nepodařilo bezpečně uložit.', 422); }
    }
    public function remove(Request $request, Response $response): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        try { $this->connection->delete(SupplierGuard::currentId($request)); }
        catch (AbraException $e) { return $this->sourceError($response, $e); }
        return Json::ok($response, ['deleted' => true]);
    }
    public function discover(Request $request, Response $response): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        try { return Json::ok($response, NonprofitMigrationNotice::attach($this->db, $supplierId, $this->connection->discover($supplierId))); }
        catch (AbraException $e) { return $this->sourceError($response, $e); }
    }
    public function start(Request $request, Response $response): Response { return $this->enqueue($request, $response, false); }
    public function sync(Request $request, Response $response): Response { return $this->enqueue($request, $response, true); }
    public function catalog(Request $request, Response $response): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        if (self::missingRights($request, static::LIVE_IMPORT_RIGHTS) !== []) {
            return Json::error($response, 'forbidden', 'Převod vyžaduje oprávnění k účetnímu deníku a nastavení firmy.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->connection->lockSupplier($supplierId);
            $this->connection->assertIdle($supplierId);
            $status = $this->connection->status($supplierId);
            if (!$status['configured'] || !$status['imported']) {
                throw new AbraException('invalid_state', 'Nejprve dokončete účetní převod ABRA Flexi.', [], 409);
            }
            $stmt = $pdo->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            if (!(bool) $stmt->fetchColumn()) throw new AbraException('stock_module_missing', 'Zapněte modul Sklad pro cílovou firmu.', [], 409);
            $jobId = $this->createRunJob($response, $supplierId, [
                'mode' => AbraImportJobService::MODE_CATALOG, 'connection_version' => $status['connection_version'],
            ], self::userId($request));
            if ($jobId instanceof Response) { $pdo->rollBack(); return $jobId; }
            $pdo->commit();
        } catch (AbraException $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return $this->sourceError($response, $error);
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'job_failed', 'Převod ceníku se nepodařilo spustit.', 500);
        }
        $this->spawnWorker($jobId);
        return Json::ok($response, ['job_id' => $jobId], 202);
    }

    private function enqueue(Request $request, Response $response, bool $sync): Response
    {
        if (($denied = $this->deny($request, $response, AccessLevel::WRITE)) !== null) return $denied;
        if (self::missingRights($request, static::LIVE_IMPORT_RIGHTS) !== []) {
            return Json::error($response, 'forbidden', 'Převod vyžaduje oprávnění k účetnímu deníku a nastavení firmy.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->connection->lockSupplier($supplierId);
            $this->connection->assertIdle($supplierId);
            $status = $this->connection->status($supplierId);
            if (!$status['configured']) throw new AbraException('not_configured', 'Nejprve nastavte připojení k ABRA Flexi.');
            if ($status['ico_mismatch']) {
                throw new AbraException('source_target_ico_mismatch', sprintf(
                    'Připojená firma v ABRA Flexi (IČO %s) není firma, do které převádíte (IČO %s). Přepněte se do správné firmy, případně ji založte, nebo připojte jinou firmu v ABRA Flexi.',
                    ($status['source_company']['ico'] ?? '') ?: '?', $status['target_company']['ico'] ?: '?'), [], 409);
            }
            if ($status['imported'] !== $sync) {
                throw new AbraException('invalid_mode', $sync ? 'Nejprve proveďte první převod.' : 'Firma již byla převzata. Použijte Načíst nová data.', [], 409);
            }
            $body = (array) ($request->getParsedBody() ?? []);
            $years = $sync ? $status['selected_years'] : ($body['years'] ?? []);
            if (!is_array($years) || !array_is_list($years) || $years === []
                || count($years) > 100 || array_filter($years, static fn ($y) => !is_int($y)) !== []) {
                throw new AbraException('invalid_years', 'Vyberte alespoň jeden dostupný rok převodu.');
            }
            $years = array_values(array_unique($years));
            sort($years);
            if (array_diff($years, array_column($status['years'], 'year')) !== []) throw new AbraException('invalid_years', 'Vybraný rok není ve zdroji dostupný.');
            $jobId = $this->createRunJob($response, $supplierId, [
                'mode' => $sync ? 'sync' : 'initial', 'years' => $years, 'connection_version' => $status['connection_version'],
            ], self::userId($request));
            if ($jobId instanceof Response) { $pdo->rollBack(); return $jobId; }
            $pdo->commit();
        } catch (AbraException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return $this->sourceError($response, $e);
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'job_failed', 'Převod se nepodařilo spustit.', 500);
        }
        $this->spawnWorker($jobId);
        return Json::ok($response, ['job_id' => $jobId], 202);
    }
}

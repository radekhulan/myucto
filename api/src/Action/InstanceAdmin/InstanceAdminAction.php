<?php

declare(strict_types=1);

namespace MyInvoice\Action\InstanceAdmin;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\InstanceAdmin\InstanceSmtpRepository;
use MyInvoice\Service\InstanceAdmin\NeutralSmtpTestSender;
use MyInvoice\Service\InstanceAdmin\SmtpTarget;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/** Úzké technické rozhraní; žádná metoda nevrací obecný diagnostický výstup. */
final class InstanceAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly TechnicalSupplierAccessRepository $suppliers,
        private readonly InstanceSmtpRepository $smtp,
        private readonly NeutralSmtpTestSender $sender,
        private readonly ActivityLogger $audit,
        private readonly ManagedModeGuard $managed,
        private readonly LoggerInterface $logger,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        $available = $this->db->ping();
        $version = null;
        if ($available) {
            $rawVersion = (string) $this->db->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            preg_match('/^\d+\.\d+(?:\.\d+)?/', $rawVersion, $matches);
            $version = $matches[0] ?? null;
        }
        $free = @disk_free_space(RuntimePaths::base());
        $license = LicenseMiddleware::state($request);
        $smtp = $this->smtp->view();
        return Json::ok($response, [
            'version' => trim((string) file_get_contents(Bootstrap::rootDir() . '/VERSION')),
            'php_version' => PHP_VERSION,
            'database' => ['available' => $available, 'version' => $version],
            'extensions' => array_map(static fn (string $name): array => ['name' => $name, 'available' => extension_loaded($name)], EnvironmentCheckService::REQUIRED_EXTENSIONS),
            'storage' => ['available' => $free !== false, 'free_bytes' => $free === false ? null : (int) $free],
            'managed' => $this->managed->isManaged(),
            'smtp' => ['configured' => $smtp['configured'], 'read_only' => $smtp['read_only']],
            'license' => $license === null ? null : ['state' => $license->state, 'valid_until' => $license->validUntil],
        ]);
    }

    public function suppliers(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        try {
            [$after, $limit] = $this->page($request);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if (RequestAuthorization::isSuperadmin($request)) {
            $stmt = $this->db->pdo()->prepare('SELECT id AS supplier_id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name
                FROM supplier WHERE id > ? ORDER BY id LIMIT ' . ($limit + 1));
            $stmt->execute([$after]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) $row['supplier_id'] = (int) $row['supplier_id'];
            unset($row);
        } else {
            $rows = $this->suppliers->listForUser($this->userId($request), $after, $limit + 1);
        }
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return Json::ok($response, ['data' => $rows, 'next_cursor' => $more ? end($rows)['supplier_id'] : null]);
    }

    public function supplierStatus(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guardSupplier($request, $response, $args)) !== null) return $denied;
        $id = (int) $args['id'];
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(NULLIF(display_name, \'\'), company_name) FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        $ownTransport = $this->hasSupplierTransport($id);
        return Json::ok($response, ['supplier_id' => $id, 'name' => (string) $stmt->fetchColumn(),
            'mail_transport' => $ownTransport ? 'supplier_profile' : 'instance',
            'smtp_test_supported' => !$ownTransport && $this->smtp->view()['target_id'] !== null]);
    }

    public function smtp(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        return Json::ok($response, $this->smtp->view());
    }

    public function supplierSmtp(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guardSupplier($request, $response, $args)) !== null) return $denied;
        $ownTransport = $this->hasSupplierTransport((int) $args['id']);
        return Json::ok($response, [...$this->smtp->view(), 'supplier_id' => (int) $args['id'],
            'transport_scope' => $ownTransport ? 'supplier_profile' : 'instance',
            'test_supported' => !$ownTransport && $this->smtp->view()['target_id'] !== null]);
    }

    public function saveSmtp(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        if (($locked = $this->smtpLocked($response)) !== null) return $locked;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            SmtpTarget::onlyKeys($body, ['target_id', 'password']);
            $id = filter_var($body['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || (array_key_exists('password', $body) && !is_string($body['password']))) {
                throw new \InvalidArgumentException('Neplatné technické nastavení SMTP.');
            }
            $this->atomic(function () use ($id, $body, $request): void {
                $this->smtp->save($id, array_key_exists('password', $body) ? $body['password'] : null);
                $this->record($request, 'instance_admin.smtp_updated', null, 'ok', ['target_id' => $id, 'password_changed' => array_key_exists('password', $body)]);
            });
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return Json::error($response, $e->getMessage(), 'Pro nový SMTP cíl zadejte nové heslo.', 400);
        }
        return Json::ok($response, $this->smtp->view());
    }

    public function testSmtp(Request $request, Response $response, array $args = []): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        $supplierId = isset($args['id']) ? (int) $args['id'] : null;
        if ($supplierId !== null) {
            if (($denied = $this->guardSupplier($request, $response, $args)) !== null) return $denied;
            if ($this->hasSupplierTransport($supplierId)) {
                return Json::error($response, 'smtp_supplier_profile_unsupported', 'Test vlastního firemního transportu není v technické správě dostupný.', 409);
            }
        }
        try {
            SmtpTarget::onlyKeys((array) ($request->getParsedBody() ?? []), []);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if (($locked = $this->smtpLocked($response)) !== null) return $locked;
        $settings = $this->smtp->runtime();
        if ($settings === null) return Json::error($response, 'smtp_not_configured', 'Nejprve nastavte schválený SMTP cíl.', 409);
        if (!$this->atomic(fn () => $this->smtp->reserveTest($this->userId($request), (int) $settings['id']))) {
            return Json::error($response, 'smtp_test_rate_limited', 'Další test lze odeslat nejdříve za minutu.', 429);
        }
        // Záměr se zapíše před síťovou operací, aby audit zůstal i po přerušení requestu.
        $this->atomic(fn () => $this->record($request, 'instance_admin.smtp_test', $supplierId, 'started'));
        try {
            $this->sender->send($settings);
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            $this->completeTestAudit($request, $supplierId, 'failed');
            return Json::error($response, 'smtp_test_failed', 'Testovací e-mail se nepodařilo odeslat.', 502);
        }
        $this->completeTestAudit($request, $supplierId, 'sent');
        return Json::ok($response, ['status' => 'sent', 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'test_recipient' => $settings['test_recipient']]);
    }

    public function targets(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) return $denied;
        return Json::ok($response, $this->smtp->targets());
    }

    public function createTarget(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) return $denied;
        if (($locked = $this->smtpLocked($response)) !== null) return $locked;
        try {
            $target = $this->atomic(function () use ($request): array {
                $target = $this->smtp->createTarget((array) ($request->getParsedBody() ?? []), $this->userId($request));
                $this->record($request, 'instance_admin.smtp_target_approved', null, 'ok', ['target_id' => $target['id']]);
                return $target;
            });
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        return Json::ok($response, $target, 201);
    }

    public function deleteTarget(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) return $denied;
        if (($locked = $this->smtpLocked($response)) !== null) return $locked;
        $id = (int) ($args['id'] ?? 0);
        if ($this->smtp->target($id) === null) return Json::error($response, 'not_found', 'Schválený SMTP cíl neexistuje.', 404);
        $deleted = $this->atomic(function () use ($id, $request): bool {
            if ($this->smtp->target($id, true) === null) return true;
            if (!$this->smtp->deleteTarget($id)) return false;
            $this->record($request, 'instance_admin.smtp_target_deleted', null, 'ok', ['target_id' => $id]);
            return true;
        });
        return $deleted ? Json::ok($response, ['deleted' => true])
            : Json::error($response, 'smtp_target_in_use', 'Používaný SMTP cíl nelze odstranit.', 409);
    }

    public function resetSmtpOverride(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, true)) !== null) return $denied;
        if (($locked = $this->smtpLocked($response)) !== null) return $locked;
        try {
            SmtpTarget::onlyKeys((array) ($request->getParsedBody() ?? []), []);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->atomic(function () use ($request): void {
            $targetId = $this->smtp->clearOverride();
            $this->record($request, 'instance_admin.smtp_override_reset', null, 'ok', ['previous_target_id' => $targetId]);
        });
        return Json::ok($response, $this->smtp->view());
    }

    public function audit(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        try {
            [$after, $limit] = $this->page($request);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $userId = $this->userId($request);
        $params = [$userId, $after];
        $scope = '';
        if (!RequestAuthorization::isSuperadmin($request)) {
            $scope = ' AND (e.supplier_id IS NULL
                OR EXISTS (SELECT 1 FROM user_technical_access ta WHERE ta.user_id = ? AND ta.all_suppliers = 1)
                OR EXISTS (SELECT 1 FROM user_technical_suppliers ts WHERE ts.user_id = ? AND ts.supplier_id = e.supplier_id))';
            $params = [...$params, $userId, $userId];
        }
        $stmt = $this->db->pdo()->prepare('SELECT e.id, e.action, e.supplier_id, e.created_at, e.result
            FROM instance_admin_events e WHERE e.user_id = ? AND e.id > ?' . $scope . ' ORDER BY e.id LIMIT ' . ($limit + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = $row['supplier_id'] === null ? null : (int) $row['supplier_id'];
        }
        unset($row);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return Json::ok($response, ['data' => $rows, 'next_cursor' => $more ? end($rows)['id'] : null]);
    }

    private function guard(Request $request, Response $response, bool $superadminOnly = false): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        // Na spravované instalaci se o provoz stará provozovatel, plocha tam neexistuje pro nikoho.
        if ($this->managed->isManaged()) return Json::error($response, 'not_found', 'Správa instance není na této instalaci dostupná.', 404);
        if (RequestAuthorization::canManageInstance($request)
            && (!$superadminOnly || RequestAuthorization::isSuperadmin($request))) return null;
        return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    private function guardSupplier(Request $request, Response $response, array $args): ?Response
    {
        if (($denied = $this->guard($request, $response)) !== null) return $denied;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || (!RequestAuthorization::isSuperadmin($request) && !$this->suppliers->allows($this->userId($request), $id))) {
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáš oprávnění.', 403);
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() === false ? Json::error($response, 'not_found', 'Firma neexistuje.', 404) : null;
    }

    private function smtpLocked(Response $response): ?Response
    {
        if (($locked = $this->managed->deny($response, ManagedModeGuard::CAPABILITY_MAIL_TRANSPORT)) !== null) return $locked;
        return $this->smtp->readOnlyReason() === null ? null
            : Json::error($response, 'smtp_environment_locked', 'SMTP je spravováno konfigurací prostředí.', 409);
    }

    private function hasSupplierTransport(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT transport_type FROM email_profiles
            WHERE supplier_id = ? AND deleted_at IS NULL AND is_active = 1 AND is_default = 1 ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId]);
        return in_array($stmt->fetchColumn(), ['smtp', 'sendmail'], true);
    }

    private function userId(Request $request): int
    {
        return (int) ((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'];
    }

    /** @return array{int,int} */
    private function page(Request $request): array
    {
        $query = $request->getQueryParams();
        $after = filter_var($query['after_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($query['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($after === false || $limit === false) throw new \InvalidArgumentException('Neplatné stránkování.');
        return [$after, $limit];
    }

    private function record(Request $request, string $action, ?int $supplierId, string $result, array $payload = []): void
    {
        $userId = $this->userId($request);
        $this->audit->log($action, $userId, null, null, ['result' => $result, ...$payload], null, null, $supplierId);
        $this->db->pdo()->prepare('INSERT INTO instance_admin_events (user_id, supplier_id, action, result) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $supplierId, $action, $result]);
    }

    /** Odeslanou zprávu nelze vrátit; povinný audit záměru vznikl před síťovou operací. */
    private function completeTestAudit(Request $request, ?int $supplierId, string $result): void
    {
        $pdo = $this->db->pdo();
        $outer = $pdo->inTransaction();
        try {
            $this->atomic(fn () => $this->record($request, 'instance_admin.smtp_test', $supplierId, $result));
        } catch (\Throwable $e) {
            // Cizí nebo stále otevřenou transakci musí vyřešit její vlastník.
            if ($outer) throw $e;
            TransactionFailure::rollbackIfAborted($pdo, $e);
            if ($pdo->inTransaction()) throw $e;
            $this->logger->warning('instance_admin.smtp_completion_audit_failed', ['result' => $result]);
        }
    }

    private function atomic(callable $operation): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $result = $operation();
            if ($owns) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

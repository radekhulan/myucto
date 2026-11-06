<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Dohled nad frontou EET firmy: počty podle stavu, nejstarší lhůta, překročené
 * lhůty, poslední potvrzení a platnost certifikátů. Dotazy jdou po indexech
 * (supplier_id, status, deadline_at) a (supplier_id, confirmed_at).
 */
final class EetQueueService
{
    private const OPEN = ['pending', 'sending', 'retry_wait', 'action_required'];

    public function __construct(
        private readonly Connection $db,
        private readonly EetSettingsService $settings,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array<string,mixed> */
    public function summary(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $placeholders = implode(',', array_fill(0, count(self::OPEN), '?'));
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt, MIN(deadline_at) AS oldest_deadline, SUM(overdue) AS overdue
               FROM eet_records
              WHERE supplier_id = ? AND status IN ({$placeholders})
              GROUP BY status"
        );
        $stmt->execute([$supplierId, ...self::OPEN]);
        $counts = array_fill_keys(self::OPEN, 0);
        $oldest = null;
        $overdue = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
            $overdue += (int) $row['overdue'];
            if ($oldest === null || (string) $row['oldest_deadline'] < $oldest) {
                $oldest = (string) $row['oldest_deadline'];
            }
        }

        $actions = $pdo->prepare(
            "SELECT action_code, COUNT(*) FROM eet_records
              WHERE supplier_id = ? AND status = 'action_required' GROUP BY action_code"
        );
        $actions->execute([$supplierId]);
        $byAction = [];
        foreach ($actions->fetchAll(PDO::FETCH_NUM) ?: [] as [$code, $count]) {
            $byAction[(string) ($code ?? 'unknown')] = (int) $count;
        }

        $last = $pdo->prepare('SELECT MAX(confirmed_at) FROM eet_records WHERE supplier_id = ?');
        $last->execute([$supplierId]);
        $lastConfirmed = $last->fetchColumn();

        $certs = $pdo->prepare(
            "SELECT environment, MAX(not_after) AS not_after FROM eet_certificates
              WHERE supplier_id = ? AND status = 'active' GROUP BY environment"
        );
        $certs->execute([$supplierId]);
        $certificates = [];
        $nowDb = EetValues::dbSecond($this->clock->now());
        foreach ($certs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $certificates[(string) $row['environment']] = [
                'valid_until' => EetValues::iso((string) $row['not_after']),
                'expired' => (string) $row['not_after'] < $nowDb,
            ];
        }

        $settings = $this->settings->get($supplierId);
        return [
            'counts' => $counts,
            'open_total' => array_sum($counts),
            'action_required_by_code' => $byAction,
            'overdue' => $overdue,
            'oldest_deadline_at' => EetValues::iso($oldest),
            'last_confirmed_at' => EetValues::iso($lastConfirmed === false ? null : $lastConfirmed),
            'environment' => (string) ($settings['environment'] ?? 'playground'),
            'dispatch_blocked' => !empty($settings['dispatch_blocked']),
            'certificates' => $certificates,
        ];
    }
}

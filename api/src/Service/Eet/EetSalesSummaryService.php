<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Souhrn evidence tržeb pro obrazovku Domů mobilní aplikace (`GET /api/eet/summary`).
 *
 * Den a měsíc zvoleného data (výchozí dnes): počet tržeb, součet potvrzených, čekající,
 * vyžadující zásah a po lhůtě; k tomu poslední potvrzení, platnost certifikátu
 * aktuálního prostředí, stav fronty a prostředí. Bez XML a bez tajemství.
 *
 * Období jsou rozsahy `sale_at >= od AND sale_at < do` po indexu
 * (supplier_id, sale_at, id); fronta po (supplier_id, status, deadline_at, id),
 * poslední potvrzení po (supplier_id, confirmed_at), certifikát po
 * (supplier_id, environment, status, not_after). Počítá se jen aktuální prostředí:
 * zkušební tržby z Playground se s ostrými nesčítají.
 */
final class EetSalesSummaryService
{
    private const CONFIRMED = ['confirmed', 'confirmed_warning'];
    private const WAITING = ['pending', 'sending', 'retry_wait'];

    public function __construct(
        private readonly Connection $db,
        private readonly EetSettingsService $settings,
        private readonly EetRecordingPolicy $policy,
        private readonly EetQueueService $queue,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array<string,mixed>
     * @throws EetCommandException neplatné datum
     */
    public function summary(int $supplierId, ?string $date = null): array
    {
        $now = EetValues::local($this->clock->now());
        $day = $this->day($date, $now);
        $settings = $this->settings->get($supplierId);
        $environment = (string) ($settings['environment'] ?? 'playground');

        $nextDay = $day->modify('+1 day');
        $monthStart = $day->modify('first day of this month');
        $monthEnd = $monthStart->modify('+1 month');

        $queue = $this->queue->summary($supplierId);
        $last = $this->db->pdo()->prepare(
            // Zpětný průchod indexem (supplier_id, confirmed_at) skončí u první tržby prostředí.
            "SELECT confirmed_at FROM eet_records
              WHERE supplier_id = ? AND confirmed_at IS NOT NULL AND environment = ?
              ORDER BY confirmed_at DESC LIMIT 1"
        );
        $last->execute([$supplierId, $environment]);
        $lastConfirmed = $last->fetchColumn();

        return [
            'date' => $day->format('Y-m-d'),
            'active' => $this->policy->activeAt($settings, $now),
            'environment' => $environment,
            'dispatch_blocked' => !empty($settings['dispatch_blocked']),
            'today' => $this->period($supplierId, $environment, $day, $nextDay),
            'month' => $this->period($supplierId, $environment, $monthStart, $monthEnd),
            'last_confirmed_at' => EetValues::iso($lastConfirmed === false ? null : $lastConfirmed),
            'certificate' => $this->certificate($supplierId, $environment, $now),
            'queue' => [
                'open_total' => $queue['open_total'],
                'counts' => $queue['counts'],
                'overdue' => $queue['overdue'],
                'oldest_deadline_at' => $queue['oldest_deadline_at'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function period(int $supplierId, string $environment, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $confirmed = implode(',', array_fill(0, count(self::CONFIRMED), '?'));
        $waiting = implode(',', array_fill(0, count(self::WAITING), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN status IN ({$confirmed}) THEN total_amount END), 0) AS confirmed_total,
                    SUM(status IN ({$confirmed})) AS confirmed_count,
                    SUM(status IN ({$waiting})) AS waiting,
                    SUM(status = 'action_required') AS action_required,
                    SUM(overdue = 1) AS overdue
               FROM eet_records
              WHERE supplier_id = ? AND sale_at >= ? AND sale_at < ?
                AND environment = ? AND kind IN ('sale', 'correction')"
        );
        $stmt->execute([...self::CONFIRMED, ...self::CONFIRMED, ...self::WAITING,
            $supplierId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'count' => (int) ($row['cnt'] ?? 0),
            'confirmed_count' => (int) ($row['confirmed_count'] ?? 0),
            'confirmed_total' => EetValues::amount((string) ($row['confirmed_total'] ?? '0')),
            'waiting' => (int) ($row['waiting'] ?? 0),
            'action_required' => (int) ($row['action_required'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'currency' => 'CZK',
        ];
    }

    /** @return array{valid_until:?string, days_left:?int, expired:bool}|null */
    private function certificate(int $supplierId, string $environment, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(not_after) FROM eet_certificates WHERE supplier_id = ? AND environment = ? AND status = 'active'"
        );
        $stmt->execute([$supplierId, $environment]);
        $notAfter = $stmt->fetchColumn();
        if ($notAfter === false || $notAfter === null) {
            return null;
        }
        $until = new \DateTimeImmutable((string) $notAfter, EetValues::zone());
        $seconds = $until->getTimestamp() - $now->getTimestamp();
        return [
            'valid_until' => EetValues::iso((string) $notAfter),
            'days_left' => (int) floor($seconds / 86400),
            'expired' => $seconds <= 0,
        ];
    }

    /** @throws EetCommandException */
    private function day(?string $date, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($date === null || $date === '' || $date === 'today') {
            return $now->setTime(0, 0);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw EetCommandException::validation('Datum musí být ve formátu RRRR-MM-DD.', 'date');
        }
        return new \DateTimeImmutable($date . ' 00:00:00', EetValues::zone());
    }
}

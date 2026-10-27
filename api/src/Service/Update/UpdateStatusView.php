<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

/** Technická projekce stavu bez cest k zálohám, logům a interních chybových zpráv. */
final class UpdateStatusView
{
    public static function technical(array $status, bool $managed, int $otherRecentSessions = 0): array
    {
        $safe = array_intersect_key($status, array_flip([
            'current', 'latest', 'has_update', 'last_check_at', 'cache_stale', 'upgrade_in_progress',
        ]));
        $safe['check_failed'] = !empty($status['last_check_error']);
        $safe['managed'] = $managed;
        $safe['other_recent_sessions'] = $otherRecentSessions;
        $safe['upgrade_progress'] = is_array($status['upgrade_progress'] ?? null)
            ? array_intersect_key($status['upgrade_progress'], array_flip(['step_index', 'step_count'])) : null;
        $safe['last_upgrade_result'] = is_array($status['last_upgrade_result'] ?? null)
            ? array_intersect_key($status['last_upgrade_result'], array_flip(['status', 'target_version', 'applied_at'])) : null;
        return $safe;
    }
}

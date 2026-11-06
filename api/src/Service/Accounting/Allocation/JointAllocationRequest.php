<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

final class JointAllocationRequest
{
    public static function apply(array $input): array
    {
        $request = self::base($input);
        $request['key_version_id'] = self::id($input['key_version_id'] ?? null);
        $request['source_line_ids'] = self::sourceIds($input['source_line_ids'] ?? []);
        $hash = $input['preview_hash'] ?? null;
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new JointAllocationException('allocation_preview_required', 'Před zaúčtováním načtěte aktuální náhled.');
        }
        $request['preview_hash'] = $hash;
        $request['exact_cells'] = JointAllocationExactCells::normalize($input['exact_cells'] ?? []);
        return self::withHash($request);
    }

    public static function cancel(array $input): array
    {
        $request = self::base($input);
        $request['batch_id'] = self::id($input['batch_id'] ?? null);
        return self::withHash($request);
    }

    public static function sourceIds(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 200) {
            throw new JointAllocationException('allocation_invalid_sources', 'Vyberte jeden až 200 zdrojových řádků.');
        }
        foreach ($ids as $id) {
            self::id($id);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new JointAllocationException('allocation_invalid_sources', 'Zdrojový řádek je vybrán vícekrát.');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function base(array $input): array
    {
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        $token = $input['idempotency_key'] ?? null;
        if ($reason === '' || mb_strlen($reason) > 4000) {
            throw new JointAllocationException('allocation_reason_required', 'Uveďte důvod účetního přeúčtování nebo jeho zrušení.');
        }
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $token)) {
            throw new JointAllocationException('allocation_invalid_idempotency_key', 'Chybí platný identifikátor operace.');
        }
        return ['target_date' => JointAllocationKeyVersion::date($input['target_date'] ?? null), 'reason' => $reason, 'idempotency_key' => $token];
    }

    private static function id(mixed $id): int
    {
        if (!is_int($id) || $id <= 0) {
            throw new JointAllocationException('allocation_invalid_id', 'Identifikátor musí být kladné celé číslo.');
        }
        return $id;
    }

    private static function withHash(array $request): array
    {
        $content = $request;
        unset($content['idempotency_key']);
        return $request + ['request_hash' => JointAllocationKeyVersion::hash($content)];
    }
}

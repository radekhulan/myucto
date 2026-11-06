<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInput;

final class PaymentWorkflowRealization
{
    public static function cash(array $expected, int $id): array
    {
        foreach ($expected['lines'] as &$line) $line = self::line($line, $id);
        unset($line);
        if (isset($expected['credit']['line'])) $expected['credit']['line'] = self::line($expected['credit']['line'], $id);
        return $expected;
    }

    public static function projectedSource(array $expected, array $actual): array
    {
        if (!isset($expected['source'], $actual['source']) || !is_array($expected['source']) || !is_array($actual['source'])) PaymentException::reject('workflow_plan_changed');
        if (($expected['source']['id'] ?? null) === null) {
            $actualId = $actual['source']['id'] ?? null;
            if (!is_int($actualId) || $actualId < 1) {
                if ($actualId !== null) PaymentException::reject('workflow_plan_changed');
            } else {
                $expected['source']['id'] = $actualId;
            }
        }
        foreach (['request','source','source_amount_cents','source_native_minor','clearing_selection','allocations','resolution','payment_parties','credit','reservation','lines'] as $key) {
            if (PropertyInput::hash(['value' => $expected[$key] ?? null]) !== PropertyInput::hash(['value' => $actual[$key] ?? null])) PaymentException::reject('workflow_plan_changed');
        }
        return $expected;
    }

    private static function line(array $line, int $id): array
    {
        foreach ($line['dimension_origins'] ?? [] as $type => $origin) {
            if ($origin === ['origin' => 'module', 'ref' => 'cash:']) $line['dimension_origins'][$type]['ref'] = 'cash:' . $id;
        }
        return $line;
    }
}

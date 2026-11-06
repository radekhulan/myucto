<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class MembershipChargeRequest
{
    public static function issue(array $input): array
    {
        $allowed = ['member_id', 'title_key', 'period_from', 'period_to', 'accounting_date', 'due_date', 'amount',
            'receivable_account', 'revenue_account', 'title_reference', 'evidence', 'nonreciprocal', 'reason', 'classification'];
        if (array_diff(array_keys($input), $allowed) !== []) self::invalid('Požadavek obsahuje neznámé pole.');
        if (!is_int($input['member_id'] ?? null) || $input['member_id'] <= 0) self::invalid('Vyberte člena této organizace.');
        $input['title_key'] = self::key($input['title_key'] ?? null, 'právního titulu');
        foreach (['period_from', 'period_to', 'accounting_date', 'due_date'] as $field) {
            if (!is_string($input[$field] ?? null)) self::invalid('Chybí datum předpisu.');
            $input[$field] = MembershipRevisionTimeline::date($input[$field]);
        }
        if ($input['period_from'] > $input['period_to']) self::invalid('Období členského příspěvku je obrácené.');
        if ($input['due_date'] < $input['accounting_date']) self::invalid('Splatnost nesmí předcházet účetnímu datu.');
        if (!is_array($input['classification'] ?? null)
            || count($input['classification']) !== 3
            || array_diff(array_keys($input['classification']), ['activity_value_id', 'activity_kind_value_id', 'tax_regime_value_id']) !== []) {
            self::invalid('Vyberte všechny tři systémové dimenze členského příspěvku.');
        }
        foreach (['activity_value_id', 'activity_kind_value_id', 'tax_regime_value_id'] as $field) {
            if (!is_int($input['classification'][$field] ?? null) || $input['classification'][$field] <= 0) self::invalid('Vyberte všechny tři systémové dimenze členského příspěvku.');
        }
        if (($input['nonreciprocal'] ?? null) !== true) self::invalid('Minimální modul podporuje jen neprotislužbový členský příspěvek.');
        foreach (['title_reference', 'evidence', 'reason'] as $field) $input[$field] = self::text($input[$field] ?? null, $field, 2000);
        foreach (['receivable_account', 'revenue_account'] as $field) {
            if (!is_string($input[$field] ?? null)) self::invalid('Vyberte účet pohledávky a výnosu.');
            $input[$field] = trim($input[$field]);
            if ($input[$field] === '') self::invalid('Vyberte účet pohledávky a výnosu.');
        }
        $rawAmount = $input['amount'] ?? null;
        if (!is_string($rawAmount) && !is_int($rawAmount) && !is_float($rawAmount)) self::invalid('Částka členského příspěvku musí být číslo.');
        try {
            $amount = JournalLineAmount::decimal($rawAmount);
            $cents = JournalLineAmount::cents($amount);
        } catch (\InvalidArgumentException) {
            self::invalid('Částka členského příspěvku musí být číslo v povoleném rozsahu.');
        }
        if ($cents <= 0 || $cents > 999999999999999) self::invalid('Částka členského příspěvku je mimo povolený rozsah.');
        $input['amount'] = $amount;
        $input['amount_cents'] = $cents;
        unset($input['nonreciprocal']);
        $input['legal_title'] = [
            'organisation_form' => 'association',
            'relationship' => 'nonreciprocal_membership',
            'reference' => $input['title_reference'],
            'evidence' => $input['evidence'],
        ];
        unset($input['title_reference'], $input['evidence']);
        return $input;
    }

    public static function reversal(array $input): array
    {
        if (array_diff(array_keys($input), ['case_id', 'accounting_date', 'reason']) !== []) self::invalid('Požadavek storna obsahuje neznámé pole.');
        if (!is_int($input['case_id'] ?? null) || $input['case_id'] <= 0) self::invalid('Chybí účetní případ členského příspěvku.');
        if (!is_string($input['accounting_date'] ?? null)) self::invalid('Chybí datum storna.');
        $input['accounting_date'] = MembershipRevisionTimeline::date($input['accounting_date']);
        $input['reason'] = self::text($input['reason'] ?? null, 'reason', 2000);
        return $input;
    }

    public static function key(mixed $value, string $label = 'požadavku'): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/D', $value) !== 1) self::invalid('Neplatný identifikátor ' . $label . '.');
        return $value;
    }

    public static function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) self::invalid('Pole ' . $field . ' vyžaduje doložený text.');
        return trim($value);
    }

    private static function invalid(string $message): never
    {
        throw new MembershipException('nonprofit_membership_invalid', $message);
    }
}

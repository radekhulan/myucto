<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;

final class ServiceNoticePolicy
{
    public static function validate(array $notice, array $recipients, string $periodFrom, string $periodTo, bool $changed, string $issuedAt, string $frequency='monthly'): array
    {
        PropertyInput::fields($notice, ['mode','title_ref','written_notice_ref','reason','deliveries','agreement']);
        if(!in_array($frequency,['monthly','quarterly','annual','one_off'],true)) self::reject('frequency');
        $mode = $notice['mode'] ?? null;
        if (!in_array($mode, ['initial','unchanged','change'], true) || ($changed && $mode !== 'change')) self::reject('mode');
        $title = PropertyInput::text($notice['title_ref'] ?? null, 'title_ref', 500);
        $issuedAt = PropertyInstant::normalize($issuedAt);
        $recipients = array_values(array_unique($recipients)); sort($recipients, SORT_NUMERIC);
        if ($mode !== 'change') return ['mode'=>$mode,'title_ref'=>$title,'written_notice_ref'=>'','reason'=>'','deliveries'=>[],
            'earliest_date'=>null,'covered_recipient_ids'=>[],'legal_rule'=>$mode==='initial'?'initial_4_1':'unchanged','agreement'=>null];
        if (!is_string($notice['reason'] ?? null) || trim($notice['reason']) === ''
            || !is_string($notice['written_notice_ref'] ?? null) || trim($notice['written_notice_ref']) === '') self::reject('written_reason');
        $reason = PropertyInput::text($notice['reason'], 'reason', 2000);
        $written = PropertyInput::text($notice['written_notice_ref'], 'written_notice_ref', 500);
        $proofs = PropertyInput::list($notice['deliveries'] ?? null, 'deliveries');
        $covered = []; $out = []; $earliest = null; $lastDelivery=null;
        foreach ($proofs as $proof) {
            if(!is_array($proof)) self::reject('delivery');
            PropertyInput::fields($proof, ['recipient_id','represented_ids','authority_ref','delivered_at','evidence_ref']);
            $recipient = PropertyInput::id($proof['recipient_id'] ?? null, 'recipient_id');
            $ids = PropertyInput::list($proof['represented_ids'] ?? null, 'represented_ids');
            if ($ids === []) self::reject('represented_ids');
            foreach ($ids as $id) {
                PropertyInput::id($id, 'represented_id');
                if (!in_array($id, $recipients, true) || isset($covered[$id])) self::reject('recipient_scope');
                $covered[$id] = true;
            }
            sort($ids, SORT_NUMERIC);
            $authority = PropertyInput::text($proof['authority_ref'] ?? '', 'authority_ref', 500, $ids === [$recipient]);
            $delivered = PropertyInstant::normalize($proof['delivered_at'] ?? null);
            if ($delivered > $issuedAt) self::reject('delivery_after_issue');
            $lastDelivery=max($lastDelivery??$delivered,$delivered);
            $date = (new \DateTimeImmutable($delivered))->setTimezone(new \DateTimeZone('Europe/Prague'));
            $nextMonth = $date->modify('first day of next month')->format('Y-m-d');
            $earliest = max($earliest ?? $nextMonth, $nextMonth);
            $out[] = ['recipient_id'=>$recipient,'represented_ids'=>$ids,'authority_ref'=>$authority,'delivered_at'=>$delivered,
                'evidence_ref'=>PropertyInput::text($proof['evidence_ref'] ?? null, 'evidence_ref', 500)];
        }
        $covered = array_keys($covered); sort($covered, SORT_NUMERIC);
        $agreement=null; $rule='monthly_4_4';
        if($frequency!=='monthly') {
            $raw=$notice['agreement']??null;
            if(!is_array($raw)) self::reject('non_monthly_title');
            PropertyInput::fields($raw,['effective_at','recipient_ids','evidence_ref','legal_basis']);
            $ids=PropertyInput::list($raw['recipient_ids']??null,'recipient_ids');
            foreach($ids as $id) PropertyInput::id($id,'recipient_id');
            sort($ids,SORT_NUMERIC); if($ids!==$recipients) self::reject('agreement_scope');
            $effective=PropertyInstant::normalize($raw['effective_at']??null);
            if($effective>$issuedAt||$lastDelivery===null||$effective<$lastDelivery) self::reject('agreement_effective_at');
            $agreement=['effective_at'=>$effective,'recipient_ids'=>$ids,
                'evidence_ref'=>PropertyInput::text($raw['evidence_ref']??null,'evidence_ref',2000),
                'legal_basis'=>PropertyInput::text($raw['legal_basis']??null,'legal_basis',2000)];
            $earliest=(new \DateTimeImmutable($effective))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
            $rule='non_monthly_title';
        } elseif(($notice['agreement']??null)!==null) self::reject('monthly_title');
        if ($recipients === [] || $covered !== $recipients || $earliest === null || $periodFrom < $earliest || $periodTo < $periodFrom) self::reject('effective_month');
        usort($out, static fn(array $a,array $b): int => [$a['recipient_id'],$a['delivered_at']] <=> [$b['recipient_id'],$b['delivered_at']]);
        return ['mode'=>$mode,'title_ref'=>$title,'written_notice_ref'=>$written,'reason'=>$reason,'deliveries'=>$out,
            'earliest_date'=>$earliest,'covered_recipient_ids'=>$covered,'legal_rule'=>$rule,'agreement'=>$agreement];
    }

    public static function changed(array $charges,int $unit,int $component,string $amount,string $frequency,string $periodFrom): bool
    {
        $targets=[];
        foreach($charges as $charge) if($charge['unit_id']===$unit) self::rememberTarget($targets,$charge,$periodFrom);
        return self::targetChanged($targets[$unit][$component]??null,$amount,$frequency);
    }

    public static function rememberTarget(array &$targets,array $charge,string $periodFrom): void
    {
        if(!in_array($charge['status'],['draft','posted','reversed'],true)||$charge['period_from']>$periodFrom) return;
        $unit=$charge['unit_id']; $order=[$charge['period_from'],$charge['id']];
        foreach($charge['snapshot']['lines'] as $line) {
            $component=$line['component_id'];
            if(!isset($targets[$unit][$component])||$order>$targets[$unit][$component]['order'])
                $targets[$unit][$component]=['order'=>$order,'target'=>[$line['target_cents'],$charge['snapshot']['frequency']]];
        }
    }

    public static function targetChanged(?array $target,string $amount,string $frequency): bool
    {
        return $target!==null&&$target['target']!==[$amount,$frequency];
    }

    private static function reject(string $field): never
    {
        throw new IssuanceException('property_charge_notice_unproven', 'Změna zálohy na službu vyžaduje odůvodněné písemné oznámení, doložené doručení a účinnost nejdříve od dalšího měsíce.', 422, ['field'=>$field]);
    }
}

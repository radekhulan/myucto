<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;

final class StatutoryAllocationPolicy
{
    public const COMPONENTS = ['determined_cost','default_interest','interest','principal'];

    public static function ruleset(string $paymentDate, array $legal): string
    {
        if (empty($legal['evidence']) || !in_array($legal['basis'] ?? null, ['statutory','legal_act'], true)
            || !in_array($legal['regime'] ?? null, ['general','consumer_in_default'], true)) throw new \DomainException('allocation_law_unproven');
        if ($legal['basis'] === 'legal_act' && !self::date($legal['legal_act_date'] ?? null)) throw new \DomainException('legal_act_date_required');
        if (!self::date($paymentDate)) throw new \InvalidArgumentException('payment_date');
        if ($paymentDate < '2021-07-01' || ($legal['basis'] === 'legal_act' && $legal['legal_act_date'] < '2021-07-01')) return 'cz_1932_before_2021_07_01';
        return 'cz_1932_from_2021_07_01';
    }

    public static function allocate(string $amount, array $components, string $paymentDate, array $legal): array
    {
        self::cents($amount);
        if (array_diff(array_keys($components), self::COMPONENTS) !== [] || array_diff(self::COMPONENTS,array_keys($components)) !== []) throw new \InvalidArgumentException('components');
        foreach ($components as $value) self::cents($value);
        $ruleset = self::ruleset($paymentDate,$legal);
        $order = $ruleset === 'cz_1932_from_2021_07_01' && $legal['regime'] === 'consumer_in_default'
            ? ['determined_cost','principal','interest','default_interest'] : self::COMPONENTS;
        $disposition=$legal['component_disposition']??null;
        if($disposition!==null) {
            $proposed=$disposition['order']??null;
            if(!is_array($proposed)||!array_is_list($proposed)||count($proposed)!==4||count(array_unique($proposed))!==4||array_diff(self::COMPONENTS,$proposed)!==[]||empty($disposition['debtor_evidence']))throw new \DomainException('component_disposition_unproven');
            if($ruleset==='cz_1932_from_2021_07_01') {
                if($legal['regime']==='consumer_in_default'&&$proposed!==$order)throw new \DomainException('consumer_statutory_order_required');
                if($legal['regime']==='general'&&empty($disposition['creditor_consent']))throw new \DomainException('creditor_consent_required');
            } elseif($proposed!==$order && (empty($disposition['old_interest_effect']['evidence'])||!in_array($disposition['old_interest_effect']['resolution']??null,['none_documented','separately_evidenced'],true)))throw new \DomainException('old_interest_effect_required');
            $order=$proposed;
        }
        $out = array_fill_keys(self::COMPONENTS,'0'); $remaining=$amount;
        foreach ($order as $component) {
            $part=bccomp($remaining,$components[$component],0)<0?$remaining:$components[$component];
            $out[$component]=$part; $remaining=bcsub($remaining,$part,0);
        }
        return ['ruleset'=>$ruleset,'components'=>$out,'allocated_cents'=>bcsub($amount,$remaining,0),'unallocated_cents'=>$remaining];
    }

    public static function cents(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value)) throw new \InvalidArgumentException('amount_cents');
        return $value;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false && $date->format('Y-m-d')===$value;
    }
}

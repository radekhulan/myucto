<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class SecuritiesDebt504Terms
{
    public static function normalize(array $terms): array
    {
        self::need(array_diff(array_keys($terms),['lot_reference','instrument_identity','contract_reference','classification_reference','debt_type','classification','acquired_on','maturity_on','nominal','clean_cost','acquired_interest','clean_cost_czk','acquired_interest_czk','coupon_periods','amortisation'])===[], 'debt_terms_invalid');
        foreach (['lot_reference','instrument_identity','contract_reference','classification_reference'] as $key) { self::need(SecuritiesDebt504Policy::text($terms[$key] ?? null), 'debt_contract_required'); $terms[$key]=trim($terms[$key]); }
        self::need(mb_strlen($terms['lot_reference'])<=190,'debt_lot_reference_invalid');
        self::need(in_array($terms['debt_type'] ?? null, ['coupon','discount'], true) && in_array($terms['classification'] ?? null, ['trading','held_to_maturity'], true), 'debt_classification_review_required');
        foreach (['acquired_on','maturity_on'] as $key) SecuritiesDebt504Policy::date($terms[$key] ?? null);
        self::need($terms['acquired_on'] < $terms['maturity_on'], 'debt_acquisition_date_invalid');
        foreach (['nominal','clean_cost','acquired_interest'] as $key) { SecuritiesDebt504Policy::decimal($terms[$key] ?? null); $terms[$key] = JournalLineAmount::decimal($terms[$key]); }
        self::need(bccomp($terms['nominal'],'0',2)>0 && bccomp($terms['clean_cost'],'0',2)>0, 'debt_nominal_required');
        self::need(is_array($terms['coupon_periods'] ?? null) && array_is_list($terms['coupon_periods']), 'debt_coupon_period_required');
        if ($terms['debt_type'] === 'discount') self::need($terms['coupon_periods'] === [] && $terms['acquired_interest'] === '0.00', 'debt_acquired_interest_mismatch');
        else {
            self::need($terms['coupon_periods'] !== [], 'debt_coupon_period_required');
            $previous = null;
            foreach ($terms['coupon_periods'] as $period) {
                self::need(is_array($period) && array_diff(array_keys($period),['from','to_exclusive','annual_rate_fraction','day_count','frequency','rounding','terms_reference'])===[], 'debt_coupon_period_required');
                SecuritiesDebt504Policy::coupon($period,$terms['nominal'],$period['from'] ?? '',$period['to_exclusive'] ?? '');
                self::need(($previous === null || $previous === $period['from']) && $period['to_exclusive'] <= $terms['maturity_on'], 'debt_coupon_period_invalid');
                $previous = $period['to_exclusive'];
            }
            self::need($terms['coupon_periods'][0]['from'] <= $terms['acquired_on'] && $previous === $terms['maturity_on'], 'debt_coupon_period_invalid');
            self::need(self::acquiredInterest($terms) === $terms['acquired_interest'], 'debt_acquired_interest_mismatch');
        }
        if ($terms['classification'] === 'held_to_maturity') {
            self::need(is_array($terms['amortisation'] ?? null) && array_diff(array_keys($terms['amortisation']),['method','policy_reference','rounding'])===[], 'debt_amortisation_review_required');
            SecuritiesDebt504Policy::amortisation($terms['amortisation'] + ['acquired_on'=>$terms['acquired_on'],'maturity_on'=>$terms['maturity_on']], $terms['clean_cost'],$terms['nominal'],$terms['acquired_on'],$terms['maturity_on']);
        }
        else self::need(!array_key_exists('amortisation',$terms),'debt_amortisation_ineligible');
        return $terms;
    }

    public static function acquiredInterest(array $terms): string
    {
        foreach ($terms['coupon_periods'] as $period) if ($period['from'] <= $terms['acquired_on'] && $period['to_exclusive'] > $terms['acquired_on'])
            return SecuritiesDebt504Policy::coupon($period,$terms['nominal'],$period['from'],$terms['acquired_on']);
        return '0.00';
    }

    /**
     * Kumulativní naběhlý úrok lotu ke konci dne $date. Po částečném prodeji ($basis z posledního
     * prodeje) se k úroku do dne prodeje přičítá úrok jen ze zbývajícího nominálu, takže prodaná
     * část už dál nenabíhá.
     */
    public static function earned(array $terms,string $date,?array $basis=null): string
    {
        SecuritiesDebt504Policy::date($date);
        $end=min(SecuritiesDebt504Policy::dayAfter($date),$terms['maturity_on']);
        $start=$terms['acquired_on']; $nominal=$terms['nominal']; $amount='0.00';
        if ($basis!==null) { $start=SecuritiesDebt504Policy::dayAfter($basis['date']); $nominal=$basis['nominal']; $amount=$basis['earned']; }
        foreach ($terms['coupon_periods'] as $period) {
            $from=max($period['from'],$start); $to=min($period['to_exclusive'],$end);
            if ($from<$to) $amount=bcadd($amount,SecuritiesDebt504Policy::coupon($period,$nominal,$from,$to),2);
        }
        return $amount;
    }

    /**
     * Kumulativní rozpuštění rozdílu HTM. Po částečném prodeji se zbývající rozdíl mezi účetní
     * hodnotou zbylé jistiny a zbylým nominálem rozpouští stejnou lineární metodou od dne po
     * prodeji do splatnosti; denní sazba je tím poměrná původní a ke splatnosti jistina přesně
     * dosáhne zbylého nominálu bez dvojího rozpuštění prodané části.
     */
    public static function amortised(array $terms,string $date,?array $basis=null): string
    {
        self::need($terms['classification']==='held_to_maturity','debt_amortisation_review_required');
        $end=min(SecuritiesDebt504Policy::dayAfter($date),$terms['maturity_on']);
        if ($basis===null) return SecuritiesDebt504Policy::amortisation($terms['amortisation'] + ['acquired_on'=>$terms['acquired_on'],'maturity_on'=>$terms['maturity_on']],$terms['clean_cost'],$terms['nominal'],$terms['acquired_on'],$end);
        $from=SecuritiesDebt504Policy::dayAfter($basis['date']);
        if ($from>=$terms['maturity_on'] || $end<=$from) return $basis['amortised'];
        $rest=SecuritiesDebt504Policy::amortisation($terms['amortisation'] + ['acquired_on'=>$from,'maturity_on'=>$terms['maturity_on']],$basis['principal_native'],$basis['nominal'],$from,$end);
        return bcadd($basis['amortised'],$rest,2);
    }

    /**
     * Naběhlý úrok běžného kupónového období z daného nominálu ke konci dne $date (kumulativně
     * od začátku období, stejně jako nakoupený úrok při pořízení).
     */
    public static function periodAccrued(array $terms,string $nominal,string $date): string
    {
        if (bccomp($nominal,'0',2)===0) return '0.00';
        foreach ($terms['coupon_periods'] as $period) if ($period['from']<=$date && $date<$period['to_exclusive'])
            return SecuritiesDebt504Policy::coupon($period,$nominal,$period['from'],SecuritiesDebt504Policy::dayAfter($date));
        return '0.00';
    }

    /**
     * Rozpis prodeje ke dni $date (ČÚS 406 bod 3.2.1 a 3.4, směrná osnova vyhlášky 504/2002 Sb.).
     * Sdílí ho náhled prodeje i ověření uloženého prodeje, aby se rozpis nedal podvrhnout.
     *
     * Celý prodej v Kč vyřadí celý zůstatek jako dosud. Částečný prodej vyřadí jistinu poměrem
     * prodaného nominálu ke zbývajícímu nominálu (v měně instrumentu i v Kč) a z úroku jen
     * alikvotní úrok běžného období připadající na prodaný nominál, který zaplatil kupující;
     * splatné neuhrazené kupóny zůstávají prodávajícímu. U cizí měny se před vyřazením přepočte
     * úrok a u pozice v naběhlé hodnotě i jistina kurzem dne prodeje (§ 24 odst. 6 zákona
     * o účetnictví, ČÚS 404); rozdíl proti účetní hodnotě je kurzový rozdíl. Jistina obchodní
     * pozice nese reálnou hodnotu z rozvahového dne včetně kurzu, a proto se nepřepočítává.
     */
    public static function sale(array $terms,array $state,?string $nominal,string $currency,string $rate,string $date): array
    {
        $balance=$state['principal_balance'];
        $nominal??=$balance;
        self::need(bccomp($nominal,'0',2)>0 && bccomp($nominal,$balance,2)<=0,'debt_sale_nominal_invalid');
        $whole=bccomp($nominal,$balance,2)===0;
        $foreign=$currency!=='CZK';
        if ($whole && !$foreign) return ['principal'=>$balance,'interest'=>$state['unpaid_interest'],'principal_czk'=>$state['principal_book_czk'],'interest_czk'=>$state['interest_book_czk']];
        $fxInterest=$foreign?bcsub(self::czk($state['unpaid_interest'],$rate),$state['interest_book_czk'],2):'0.00';
        $fxPrincipal=$foreign && $terms['classification']==='held_to_maturity'?bcsub(self::czk($state['principal_book_native'],$rate),$state['principal_book_czk'],2):'0.00';
        $principalCzk=bcadd($state['principal_book_czk'],$fxPrincipal,2);
        $interestCzk=bcadd($state['interest_book_czk'],$fxInterest,2);
        if ($whole) {
            $principalNative=$state['principal_book_native'];$interest=$state['unpaid_interest'];$soldPrincipalCzk=$principalCzk;$soldInterestCzk=$interestCzk;
        } else {
            $principalNative=self::share($state['principal_book_native'],$nominal,$balance);
            $soldPrincipalCzk=$foreign && $terms['classification']==='held_to_maturity'?self::czk($principalNative,$rate):self::share($principalCzk,$nominal,$balance);
            $interest=bcsub(self::periodAccrued($terms,$balance,$date),self::periodAccrued($terms,bcsub($balance,$nominal,2),$date),2);
            self::need(bccomp($interest,'0',2)>=0 && bccomp($interest,$state['unpaid_interest'],2)<=0,'debt_sale_interest_mismatch');
            $soldInterestCzk=$foreign?self::czk($interest,$rate):$interest;
            self::need(bccomp($soldInterestCzk,$interestCzk,2)<=0 && bccomp($soldPrincipalCzk,$principalCzk,2)<=0,'debt_sale_interest_mismatch');
        }
        return ['principal'=>$nominal,'interest'=>$interest,'principal_czk'=>$soldPrincipalCzk,'interest_czk'=>$soldInterestCzk,
            'principal_native'=>$principalNative,'fx_principal_czk'=>$fxPrincipal,'fx_interest_czk'=>$fxInterest];
    }

    private static function czk(string $amount,string $rate): string
    {
        return JournalLineAmount::decimal(bcmul($amount,$rate,16));
    }

    private static function share(string $amount,string $part,string $whole): string
    {
        return JournalLineAmount::decimal(bcdiv(bcmul($amount,$part,16),$whole,16));
    }

    public static function need(bool $condition,string $code): void
    {
        if (!$condition) throw new ClosingException($code,'Podklady dluhového cenného papíru nejsou úplné nebo aktuální.',409);
    }
}
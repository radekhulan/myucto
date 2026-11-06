<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

final class NonprofitTaxRules
{
    public const VERSION = 'cz-nonprofit-assessment-2024-2026-v2';
    public const PROFILES = ['narrow_vpp', 'broad_vpp', 'non_vpp', 'svj'];
    public const AUTOMATIC_FROM = '2024-01-01';
    public const AUTOMATIC_TO = '2026-12-31';
    public const REVENUE_TITLES = ['business', 'other_taxable', 'nonbusiness', 'rent', 'advertising', 'membership', 'gift', 'public_operating_grant', 'public_investment_grant', 'bank_interest', 'svj_service_payment', 'svj_management_payment'];

    public static function assessment(array $assessment): void
    {
        foreach (['profile', 'decisions', 'activities', 'funding', 'funding_sources', 'funding_costs', 'method'] as $key) {
            if (array_key_exists($key, $assessment) && !is_array($assessment[$key])) self::invalid('Posouzení musí obsahovat strukturované podklady.');
        }
        foreach (['decisions', 'activities', 'funding', 'funding_sources', 'funding_costs'] as $key) {
            foreach ($assessment[$key] ?? [] as $item) {
                if (!is_array($item)) self::invalid('Jednotlivé podklady posouzení musí být strukturované.');
            }
        }
        foreach ($assessment['decisions'] ?? [] as $decision) {
            if (isset($decision['cohort']) && !is_string($decision['cohort'])) self::invalid('Cenová kohorta musí mít textový identifikátor.');
        }
        foreach ($assessment['activities'] ?? [] as $activity) {
            if (isset($activity['cohorts']) && !is_array($activity['cohorts'])) self::invalid('Cenové kohorty musí být strukturované.');
        }
        foreach ($assessment['funding'] ?? [] as $link) {
            if (!is_string($link['source_id'] ?? null) || !is_string($link['cost_id'] ?? null)) self::invalid('Vazba financování musí obsahovat identifikátory zdrojových řádků.');
        }
    }

    public static function profile(array $profile, array $period): string
    {
        $kind = $profile['kind'] ?? '';
        if (!in_array($kind, self::PROFILES, true)) self::invalid('Postavení poplatníka musí být doložené.');
        self::evidence($profile['evidence'] ?? null);
        if (!self::supportsPeriod($period)) {
            self::invalid('Pro zadané období zatím není ověřená metodika daňového posouzení.');
        }
        return $kind;
    }

    public static function supportsPeriod(array $period): bool
    {
        $from = $period['starts_on'] ?? null;
        $to = $period['ends_on'] ?? null;
        return self::date($from) && self::date($to) && $from <= $to && $from >= self::AUTOMATIC_FROM && $to <= self::AUTOMATIC_TO;
    }

    public static function documentedProfile(array $assessment, array $period): string
    {
        if (self::supportsPeriod($period)) self::invalid('Doložené ruční posouzení je určeno pouze pro období mimo ověřenou automatickou metodiku.');
        if (isset($assessment['profile']['entity_kind']) && !in_array($assessment['profile']['entity_kind'], self::PROFILES, true)) self::invalid('Ruční právní postavení musí mít podporovanou doloženou identitu.');
        $method = $assessment['method'];
        if (($method['mode'] ?? null) !== 'documented_manual' || ($assessment['profile']['kind'] ?? null) !== 'documented') self::invalid('Ruční posouzení vyžaduje výslovnou doloženou metodu a postavení poplatníka.');
        self::evidence($assessment['profile']['evidence'] ?? null);
        self::evidence($method['evidence'] ?? null);
        self::evidence($method['legal_basis'] ?? null);
        $from = $method['valid_from'] ?? null;
        $to = $method['valid_to'] ?? null;
        if (!self::date($from) || !self::date($to) || !self::date($period['starts_on'] ?? null) || !self::date($period['ends_on'] ?? null)
            || $from > $period['starts_on'] || $to < $period['ends_on'] || $from > $to || $period['starts_on'] > $period['ends_on']) {
            self::invalid('Podklad ruční metody musí pokrývat celé platné účetní období.');
        }
        return 'documented';
    }

    public static function documentedClassification(array $decision, string $kind): array
    {
        self::evidence($decision['legal_basis'] ?? null);
        $facts = $decision['facts'] ?? [];
        if (!is_array($facts)) self::invalid('Skutkové podklady musí být strukturované.');
        $regime = $decision['final_regime'] ?? null;
        if (!in_array($regime, ['subject', 'exempt', 'out_of_scope'], true)) self::invalid('Ruční posouzení vyžaduje konečný doložený režim každého řádku.');
        $method = $kind === 'revenue' ? ($decision['final_method'] ?? null) : 'cost';
        if ($kind === 'revenue') {
            if (!in_array($decision['title'] ?? null, self::REVENUE_TITLES, true)) self::invalid('Příjem nemá podporovaný a doložený právní titul.');
            $valid = $regime === 'subject' ? in_array($method, ['return', 'final_withholding'], true) : $method === 'none';
            if (!$valid) self::invalid('Konečný způsob zdanění musí souhlasit s doloženým režimem příjmu.');
            if ($decision['title'] === 'gift') {
                if (!is_array($facts) || !is_bool($facts['claim_exemption'] ?? null)) self::invalid('U daru chybí výslovná volba osvobození.');
                if ($facts['claim_exemption'] !== ($regime === 'exempt')) self::invalid('Doložený režim daru musí souhlasit s výslovnou volbou osvobození.');
                if ($facts['claim_exemption']) self::evidence($facts['election_evidence'] ?? null);
            }
        } elseif ($regime !== 'subject' && ($decision['deductible_cents'] ?? null) !== 0) {
            self::invalid('Náklad mimo zdanitelný režim nemůže mít uznatelnou část.');
        }
        return ['regime' => $regime, 'method' => $method, 'legal_basis' => $decision['legal_basis']];
    }

    private static function date(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function revenue(string $profile, array $decision): array
    {
        self::evidence($decision['evidence'] ?? null);
        $facts = $decision['facts'] ?? [];
        if (!is_array($facts)) self::invalid('Skutkové podklady musí být strukturované.');
        $ordinary = ['regime' => 'subject', 'method' => 'return', 'legal_basis' => 'zdp_18'];
        return match ($decision['title'] ?? '') {
            'business', 'other_taxable' => $ordinary,
            'nonbusiness' => $profile === 'narrow_vpp'
                ? ['regime' => 'pending', 'method' => 'annual_activity_test', 'legal_basis' => 'zdp_18a_1a_3'] : $ordinary,
            'rent', 'advertising' => $ordinary + ['always_subject' => true],
            'membership' => self::membership($profile, $facts, $ordinary),
            'gift' => self::gift($profile, $facts, $ordinary),
            'public_operating_grant' => self::publicGrant($profile, $facts, false, $ordinary),
            'public_investment_grant' => self::publicGrant($profile, $facts, true, $ordinary),
            'bank_interest' => self::interest($profile, $facts, $ordinary),
            'svj_management_payment' => $profile === 'svj' ? ['regime' => 'out_of_scope', 'method' => 'none', 'legal_basis' => 'zdp_18_2f_2'] : throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete', 'Příspěvek správy vyžaduje doložené SVJ.'),
            'svj_service_payment' => $profile === 'svj' ? ['regime' => 'out_of_scope', 'method' => 'none', 'legal_basis' => 'zdp_18_2f_3'] : throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete', 'Právní titul služby vyžaduje doložené SVJ.'),
            default => throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete', 'Příjem nemá podporovaný a doložený právní titul.'),
        };
    }

    private static function gift(string $profile, array $facts, array $ordinary): array
    {
        if (!array_key_exists('claim_exemption', $facts) || !is_bool($facts['claim_exemption'])) self::invalid('U daru chybí výslovná volba osvobození.');
        if (!$facts['claim_exemption']) {
            self::evidence($facts['income_attribution_evidence'] ?? null);
            return match ($facts['income_treatment'] ?? '') {
                'nonbusiness_activity' => $profile === 'narrow_vpp'
                    ? ['regime' => 'pending', 'method' => 'annual_activity_test', 'legal_basis' => 'zdp_18a_1a_3_19b_3'] : $ordinary,
                'separate_taxable_income' => $ordinary,
                default => throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete', 'Neosvobozený dar vyžaduje doloženou příslušnost příjmu k činnosti.'),
            };
        }
        if (!in_array($profile, ['narrow_vpp', 'broad_vpp'], true) || ($facts['qualifying_purpose'] ?? null) !== true) self::invalid('Osvobození daru vyžaduje veřejně prospěšného poplatníka a doložený zákonný účel.');
        self::evidence($facts['election_evidence'] ?? null);
        return ['regime' => 'exempt', 'method' => 'none', 'legal_basis' => 'zdp_19b_2b_1_3'];
    }

    private static function membership(string $profile, array $facts, array $ordinary): array
    {
        if (!is_bool($facts['qualifying_membership'] ?? null)) self::invalid('Zákonné podmínky osvobození členského příspěvku musí být výslovně posouzené.');
        return $facts['qualifying_membership'] && in_array($profile, ['narrow_vpp', 'broad_vpp'], true)
            ? ['regime' => 'exempt', 'method' => 'none', 'legal_basis' => 'zdp_19_1a_2'] : $ordinary;
    }

    private static function publicGrant(string $profile, array $facts, bool $investment, array $ordinary): array
    {
        if (($facts['public_budget'] ?? null) !== true) self::invalid('Veřejný zdroj dotace musí být doložen.');
        if ($profile === 'svj') return ['regime' => 'out_of_scope', 'method' => 'none', 'legal_basis' => 'zdp_18_2f_1'];
        if ($profile === 'narrow_vpp' || ($profile === 'broad_vpp' && $investment)) {
            return ['regime' => 'out_of_scope', 'method' => 'none', 'legal_basis' => $profile === 'narrow_vpp' ? 'zdp_18a_1b' : 'zdp_18a_5'];
        }
        return $ordinary;
    }

    private static function interest(string $profile, array $facts, array $ordinary): array
    {
        if (($facts['domestic_bank_account'] ?? null) !== true) self::invalid('Bankovní srážkový režim vyžaduje doložený účet nebo vklad u tuzemské banky.');
        if ($profile === 'svj') return ['regime' => 'subject', 'method' => 'final_withholding', 'legal_basis' => 'zdp_36_5b'];
        if ($profile !== 'narrow_vpp') return $ordinary;
        if (!array_key_exists('foundation_endowment', $facts) || !is_bool($facts['foundation_endowment'])) self::invalid('U úroku musí být doloženo, zda jde o nadační jistinu.');
        if ($facts['foundation_endowment']) {
            if (($facts['foundation_purpose_only'] ?? null) !== true) self::invalid('Osvobození výnosu nadační jistiny vyžaduje doložení použití výhradně pro účel nadace.');
            return ['regime' => 'exempt', 'method' => 'none', 'legal_basis' => 'zdp_19_1r'];
        }
        return ['regime' => 'subject', 'method' => 'final_withholding', 'legal_basis' => 'zdp_36_5'];
    }

    public static function svjClassification(array $source,array $decision,array $classification,string $profile):array
    {
        $owner=$source['svj_owner_facts']??null;$title=$decision['title']??'';
        if($owner===null){if(in_array($title,['svj_service_payment','svj_management_payment'],true))self::invalid('Platba SVJ vyžaduje přesný nativní právní podklad.');return$classification;}
        if($profile!=='svj'||($owner['kind']??null)!==$source['kind'])self::invalid('Právní podklad SVJ nesouhlasí s postavením nebo druhem zdroje.');
        $legal=$owner['legal_basis']??null;$expected=match($legal){'zdp_18_2f_2'=>'svj_management_payment','zdp_18_2f_3'=>'svj_service_payment',default=>throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete','Právní podklad SVJ nemá podporovaný zákonný titul.')};
        if($source['kind']==='revenue'){
            if($title!==$expected||$classification['regime']!=='out_of_scope'||$classification['method']!=='none')self::invalid('Doloženou platbu správy nebo služby SVJ nelze posoudit jako zdanitelný příjem.');
            return['regime'=>'out_of_scope','method'=>'none','legal_basis'=>$legal];
        }
        $related=$owner['related_out_of_scope_cents']??abs($source['amount_cents']);
        if(!is_int($related)||$related!==abs($source['amount_cents'])||($decision['deductible_cents']??null)!==0)self::invalid('Celý doložený náklad správy nebo služby mimo předmět daně je daňově neuznatelný bez ohledu na čerpání záloh.');
        return$classification;
    }

    public static function cumulativeFundingOverlap(int $funded, int $excluded, int $cost, int $deductible): void
    {
        if ($excluded < max(0, $funded + $deductible - $cost)) {
            self::invalid('Financovaná neuznatelná část nákladu je mezi zdroji nebo opravami použita opakovaně.');
        }
    }

    public static function evidence(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) self::invalid('Chybí průkazný podklad rozhodnutí.');
        return trim($value);
    }

    public static function canonicalJson(array $value): string
    {
        $sort = static function (array $items) use (&$sort): array {
            if (!array_is_list($items)) ksort($items, SORT_STRING);
            foreach ($items as &$item) if (is_array($item)) $item = $sort($item);
            return $items;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    public static function invalid(string $message): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_facts_incomplete', $message);
    }
}

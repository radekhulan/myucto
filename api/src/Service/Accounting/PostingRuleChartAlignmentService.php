<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Srovnání předkontací s účtovou osnovou firmy („Doplnit podle osnovy").
 *
 * PROČ. Globální šablona předkontací (seed 1006+) mluví syntetikami — 311, 518,
 * 321. Jakmile si firma založí analytiky, syntetika se stane nepoužitelnou
 * (součet analytik by neseděl na syntetiku), jenže předkontace na ni ukazují dál
 * a jediná cesta ven bylo přepsat každý klíč ručně. Tahle služba tu práci
 * převede na jeden náhled: co engine dořeší sám, co se má doplnit a co doplnit
 * nejde.
 *
 * VZTAH K {@see PostingService::singleAnalyticMap()}. Přesměr „syntetika s jedinou
 * analytikou" existuje a řeší jednoznačné případy za běhu — proto se takový řádek
 * hlásí jako `auto` a NENAVRHUJE se k zápisu: přepsat kontaci by nic nezměnilo,
 * jen by přibyl override, který se musí udržovat. Skutečná díra je opačná: dvě
 * a víc analytik pod syntetikou. Tam přesměr záměrně mlčí a rozhodnout musí
 * člověk — služba mu k tomu dá kandidáty a předvybere ten, který firma v hlavní
 * knize opravdu používá.
 */
final class PostingRuleChartAlignmentService
{
    /** Řádek je v pořádku — kód je analytika, nebo syntetika bez potomků. */
    public const STATUS_OK = 'ok';
    /** Jediná analytika → {@see PostingService::singleAnalyticMap()} přesměruje sám. */
    public const STATUS_AUTO = 'auto';
    /** Dvě a víc analytik → volbu musí potvrdit člověk. */
    public const STATUS_SUGGEST = 'suggest';
    /** Analytiku volí kontext dokladu (banka, pokladna, DPH) — kontace do toho nemluví. */
    public const STATUS_CONTEXT = 'context';
    /** Účet z pravidla není v osnově firmy → pravidlo je nezaúčtovatelné. */
    public const STATUS_MISSING = 'missing';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $rules,
    ) {}

    /**
     * Náhled (dry-run) — nic nezapisuje.
     *
     * @return array{redirect_enabled:bool, counts:array<string,int>, rules:list<array<string,mixed>>}
     */
    public function preview(int $supplierId): array
    {
        $accounts = $this->chart($supplierId);
        $children = $this->childrenBySynthetic($accounts);
        $usage = $this->usageCounts($supplierId);
        $redirectEnabled = $this->redirectEnabled($supplierId);

        $rows = [];
        $counts = [
            self::STATUS_OK => 0, self::STATUS_AUTO => 0, self::STATUS_SUGGEST => 0,
            self::STATUS_CONTEXT => 0, self::STATUS_MISSING => 0,
        ];
        foreach ($this->rules->effectiveMap($supplierId) as $ruleKey => $rule) {
            $debit = $this->side((string) ($rule['debit_account_code'] ?? ''), $accounts, $children, $usage, $redirectEnabled);
            $credit = $this->side((string) ($rule['credit_account_code'] ?? ''), $accounts, $children, $usage, $redirectEnabled);
            // Stav pravidla = nejzávažnější ze stran. Pořadí je pořadí naléhavosti:
            // co nejde zaúčtovat, člověk potřebuje vidět dřív než co si engine dořeší.
            $status = self::STATUS_OK;
            foreach ([self::STATUS_MISSING, self::STATUS_SUGGEST, self::STATUS_CONTEXT, self::STATUS_AUTO] as $candidate) {
                if ($debit['status'] === $candidate || $credit['status'] === $candidate) {
                    $status = $candidate;
                    break;
                }
            }
            $counts[$status]++;
            $rows[] = [
                'rule_key'    => (string) $ruleKey,
                'description' => (string) ($rule['description'] ?? $ruleKey),
                'is_override' => ($rule['supplier_id'] ?? null) !== null,
                'status'      => $status,
                'debit'       => $debit,
                'credit'      => $credit,
            ];
        }

        return ['redirect_enabled' => $redirectEnabled, 'counts' => $counts, 'rules' => $rows];
    }

    /**
     * Zapíše potvrzené volby jako per-tenant override.
     *
     * Validuje se KAŽDÁ hodnota znovu proti osnově — náhled je jen nabídka, ne
     * důkaz, že klient poslal zpátky totéž. Neznámý účet, cizí analytika nebo
     * neaktivní řádek shodí celou dávku, ať nezůstane půl srovnané mapy.
     *
     * @param list<array{rule_key?:string, debit_account_code?:?string, credit_account_code?:?string}> $items
     * @return array{applied:list<string>, skipped:list<string>}
     */
    public function apply(int $supplierId, array $items): array
    {
        $accounts = $this->chart($supplierId);
        $children = $this->childrenBySynthetic($accounts);

        $planned = [];
        $skipped = [];
        foreach ($items as $item) {
            $ruleKey = trim((string) ($item['rule_key'] ?? ''));
            if ($ruleKey === '') {
                throw new \RuntimeException('missing_rule_key');
            }
            $rule = $this->rules->resolve($supplierId, $ruleKey);
            if ($rule === null) {
                throw new \RuntimeException("unknown_rule:{$ruleKey}");
            }
            $currentDebit = (string) ($rule['debit_account_code'] ?? '');
            $currentCredit = (string) ($rule['credit_account_code'] ?? '');
            $debit = $this->validatedCode($item['debit_account_code'] ?? null, $currentDebit, $accounts, $children, $ruleKey);
            $credit = $this->validatedCode($item['credit_account_code'] ?? null, $currentCredit, $accounts, $children, $ruleKey);
            if ($debit === ($currentDebit === '' ? null : $currentDebit)
                && $credit === ($currentCredit === '' ? null : $currentCredit)
            ) {
                $skipped[] = $ruleKey;
                continue;
            }
            $planned[$ruleKey] = [
                'debit'       => $debit,
                'credit'      => $credit,
                'description' => (string) ($rule['description'] ?? $ruleKey),
            ];
        }

        $applied = [];
        foreach ($planned as $ruleKey => $plan) {
            $this->rules->upsertOverride($supplierId, $ruleKey, $plan['debit'], $plan['credit'], $plan['description']);
            $applied[] = $ruleKey;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     * @param array<string,list<array<string,mixed>>> $children
     */
    private function validatedCode(
        mixed $requested,
        string $current,
        array $accounts,
        array $children,
        string $ruleKey,
    ): ?string {
        $code = $requested === null ? null : trim((string) $requested);
        if ($code === null || $code === '' || $code === $current) {
            return $current === '' ? null : $current;
        }
        if (!isset($accounts[$code]) || !$accounts[$code]['is_active']) {
            throw new \RuntimeException("unknown_account:{$code}");
        }
        // Nová hodnota musí být analytikou PŮVODNÍHO účtu. Bez téhle podmínky by
        // „doplnění podle osnovy" umělo přepsat 518 na 501 — což je jiná operace,
        // ne zpřesnění téže; na to je editace pravidla.
        $allowed = array_column(array_filter(
            $children[$current] ?? [],
            static fn (array $c): bool => $c['is_dotted'] && $c['is_deductible'],
        ), 'account_code');
        if (!in_array($code, $allowed, true)) {
            throw new \RuntimeException("not_an_analytic:{$ruleKey}:{$code}");
        }
        return $code;
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     * @param array<string,list<array<string,mixed>>> $children
     * @param array<string,int> $usage
     * @return array{code:?string, status:string, effective_code:?string, candidates:list<array<string,mixed>>, suggested_code:?string}
     */
    private function side(string $code, array $accounts, array $children, array $usage, bool $redirectEnabled): array
    {
        if ($code === '') {
            // NULL v pravidle = účet dourčí PostingService podle případu (DPH, protistrana).
            return ['code' => null, 'status' => self::STATUS_OK, 'effective_code' => null, 'candidates' => [], 'suggested_code' => null];
        }
        $ok = ['code' => $code, 'status' => self::STATUS_OK, 'effective_code' => $code, 'candidates' => [], 'suggested_code' => null];
        if (!isset($accounts[$code])) {
            return ['code' => $code, 'status' => self::STATUS_MISSING, 'effective_code' => null, 'candidates' => [], 'suggested_code' => null];
        }
        if (!$accounts[$code]['is_synthetic'] || str_contains($code, '.')) {
            return $ok;
        }
        if (in_array($code, PostingService::CONTEXT_DRIVEN_SYNTHETICS, true)) {
            return ['code' => $code, 'status' => self::STATUS_CONTEXT, 'effective_code' => $code, 'candidates' => [], 'suggested_code' => null];
        }

        // Kandidáty jsou JEN daňové tečkované analytiky. Nedaňová `518.990` ani
        // účelová `311D` nejsou náhrada syntetiky — první vybírá daňový příznak
        // dokladu (ExpenseClassificationService), druhá je vlastní agenda. Kdyby
        // se nabízely, tlačil by náhled kontace na účet, kam patří jen část
        // případů. Stejné síto má engine v PostingService::singleAnalyticMap().
        $candidates = array_values(array_filter(
            $children[$code] ?? [],
            static fn (array $c): bool => $c['is_dotted'] && $c['is_deductible'],
        ));
        // Nula kandidátů = syntetika je pořád tím správným účtem (šablona osnovy
        // takhle drží 518 vedle 518.990) → není co doplňovat.
        if ($candidates === []) {
            return $ok;
        }
        if (count($candidates) === 1 && $redirectEnabled) {
            return [
                'code' => $code, 'status' => self::STATUS_AUTO,
                'effective_code' => (string) $candidates[0]['account_code'],
                'candidates' => $candidates, 'suggested_code' => null,
            ];
        }

        return [
            'code' => $code, 'status' => self::STATUS_SUGGEST, 'effective_code' => $code,
            'candidates' => $candidates,
            'suggested_code' => $this->mostUsed($candidates, $usage),
        ];
    }

    /**
     * Předvolba = analytika, kterou firma na tom účtu opravdu používá. Kde deník
     * mlčí (nová firma), zůstane předvolba prázdná — hádat pořadím kódů by
     * z náhledu udělalo tichou automatiku.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<string,int> $usage
     */
    private function mostUsed(array $candidates, array $usage): ?string
    {
        $best = null;
        $bestCount = 0;
        foreach ($candidates as $candidate) {
            $code = (string) $candidate['account_code'];
            $count = $usage[$code] ?? 0;
            if ($count > $bestCount) {
                $best = $code;
                $bestCount = $count;
            }
        }
        return $best;
    }

    /** @return array<string,array<string,mixed>> kód → účet */
    private function chart(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code, name, is_synthetic, is_active, parent_id, tax_deductibility
               FROM chart_of_accounts
              WHERE supplier_id = ?
           ORDER BY account_code'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['account_code']] = [
                'id'            => (int) $row['id'],
                'account_code'  => (string) $row['account_code'],
                'name'          => (string) $row['name'],
                'is_synthetic'  => (bool) $row['is_synthetic'],
                'is_active'     => (bool) $row['is_active'],
                'parent_id'     => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'is_deductible' => (string) $row['tax_deductibility'] === 'deductible',
            ];
        }
        return $map;
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     * @return array<string,list<array<string,mixed>>> kód syntetiky → aktivní potomci
     */
    private function childrenBySynthetic(array $accounts): array
    {
        $byId = [];
        foreach ($accounts as $account) {
            $byId[$account['id']] = $account;
        }
        $children = [];
        foreach ($accounts as $account) {
            if ($account['parent_id'] === null || !$account['is_active'] || !isset($byId[$account['parent_id']])) {
                continue;
            }
            $parentCode = (string) $byId[$account['parent_id']]['account_code'];
            $children[$parentCode][] = [
                'account_code'  => $account['account_code'],
                'name'          => $account['name'],
                'is_deductible' => $account['is_deductible'],
                // Tečkovaný tvar drží konvenci osnovy (501.100). Netečkované děti
                // (311D, 461K) jsou úzce účelové podmnožiny — nabídnout je smíme,
                // předvybrat ne. Shodně s PostingService::singleAnalyticMap().
                'is_dotted'     => (bool) preg_match('/^[0-9]{3}[.][0-9]{1,6}$/', (string) $account['account_code']),
            ];
        }
        return $children;
    }

    /** @return array<string,int> kód účtu → počet řádků v deníku */
    private function usageCounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, COUNT(*) AS c
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ?
           GROUP BY a.account_code'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['account_code']] = (int) $row['c'];
        }
        return $map;
    }

    /**
     * Kill switch přesměru (migrace 1326). Chybějící řádek nastavení = default
     * zapnuto — stejný výklad jako v PostingService, aby náhled neukazoval jiný
     * svět, než jaký nastane při účtování.
     */
    private function redirectEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT single_analytic_redirect FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return $value === false || (bool) $value;
    }
}

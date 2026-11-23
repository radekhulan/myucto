<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

use MyInvoice\Repository\PostingRuleRepository;

/**
 * Nabídka „co to je" pro pokladní doklad s purpose=other (Fáze F).
 *
 * Kontace `cash.*` / `*.cash` v `posting_rules` existovaly, ale UI je nenabízelo —
 * u purpose=other se vždy vybíral holý účet z osnovy. Tahle služba z nich udělá
 * předvolby; {@see CashDocumentService::resolveOtherCounter()} pak protiúčet odvodí
 * ze strany kontace, která není 211.
 *
 * ── Proč se katalog odvozuje, a ne píše ručně ───────────────────────────────
 * Předvolby = aktivní kontace, které mají jednu stranu na 211 (pokladna), minus ty,
 * které už automaticky aplikuje vlastní purpose (tržba/nákup/převod). Firma si tak
 * přidáním vlastní kontace s 211 rozšíří nabídku sama, bez zásahu do kódu.
 *
 * Pozn.: `cash.withdrawal.banktocash` (261/221) se do nabídky nedostane — je to
 * BANKOVNÍ strana převodu a žádnou nohu na 211 nemá; pokladní stranu řeší
 * `cash.transfer.frombank` pod purpose=transfer.
 */
final class CashRulePresets
{
    /**
     * Kontace obsluhované vlastním purpose — v nabídce pro „ostatní" by mátly
     * (uživatel má zvolit purpose, který doklad zároveň naváže na fakturu/převod).
     */
    private const HANDLED_BY_PURPOSE = [
        'cash.revenue',              // purpose=sale
        'cash.purchase',             // purpose=purchase
        'cash.transfer.frombank',    // purpose=transfer
        'cash.deposit.cashtobank',   // purpose=transfer
    ];

    public function __construct(private readonly PostingRuleRepository $rules) {}

    /**
     * Předvolby pro purpose=other. `doc_type` filtruje směr: příjmový doklad má
     * 211 na straně MD, výdajový na straně D.
     *
     * @param 'in'|'out'|null $docType null = obojí
     * @return list<array{rule_key:string,description:string,counter_account_code:string,doc_type:string}>
     */
    public function listForOther(int $supplierId, ?string $docType = null): array
    {
        $out = [];
        foreach ($this->rules->effectiveMap($supplierId) as $key => $rule) {
            // Číselný klíč převzaté předkontace (513000) je v poli int.
            $key = (string) $key;
            if (in_array($key, self::HANDLED_BY_PURPOSE, true)) {
                continue;
            }
            $debit  = (string) ($rule['debit_account_code'] ?? '');
            $credit = (string) ($rule['credit_account_code'] ?? '');

            // 211 na MD → peníze přibývají → příjmový doklad; na D → výdajový.
            if (str_starts_with($debit, '211')) {
                $ruleType = 'in';
                $counter  = $credit;
            } elseif (str_starts_with($credit, '211')) {
                $ruleType = 'out';
                $counter  = $debit;
            } else {
                continue; // kontace se pokladny netýká
            }
            if ($counter === '') {
                continue;
            }
            if ($docType !== null && $ruleType !== $docType) {
                continue;
            }

            $out[] = [
                'rule_key'             => $key,
                'description'          => (string) ($rule['description'] ?? $key),
                'counter_account_code' => $counter,
                'doc_type'             => $ruleType,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['rule_key'], $b['rule_key']));
        return $out;
    }

    /** Je kontace použitelná pro purpose=other (má nohu na 211 a neřeší ji jiný purpose)? */
    public function isAllowedForOther(int $supplierId, string $ruleKey): bool
    {
        foreach ($this->listForOther($supplierId) as $preset) {
            if ($preset['rule_key'] === $ruleKey) {
                return true;
            }
        }
        return false;
    }
}

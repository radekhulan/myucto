<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Jednotná hranice aktivních pohybů; zneplatněné potvrzení starou úhradu neobnovuje. */
final class BankNoticeConfirmationScope
{
    public static function activeSql(string $alias = 'bt'): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný SQL alias.');
        }
        // Legacy marker sdílí životní cyklus s účetními čtečkami. Trvalé potvrzení
        // navíc chrání převedenou úhradu i po odstranění autoritativního pohybu.
        return "(" . BankNoticeLifecycle::activeSql($alias) . " AND NOT EXISTS (SELECT 1 FROM bank_notice_confirmations bnc_active
            WHERE bnc_active.notice_transaction_id = {$alias}.id
              AND (bnc_active.state = 'confirmed' OR bnc_active.reason = 'authoritative_removed')))";
    }
    public static function confirmedSql(string $alias = 'bt'): string
    {
        self::activeSql($alias);
        return "EXISTS (SELECT 1 FROM bank_notice_confirmations bnc_hidden WHERE bnc_hidden.notice_transaction_id={$alias}.id AND bnc_hidden.state='confirmed' AND bnc_hidden.authoritative_transaction_id IS NOT NULL)";
    }

    public static function visibleStatementSql(string $alias = 'bs'): string
    {
        self::activeSql($alias);
        return "({$alias}.source<>'email_notice' OR NOT EXISTS (SELECT 1 FROM bank_transactions notice_visible WHERE notice_visible.statement_id={$alias}.id) OR EXISTS (SELECT 1 FROM bank_transactions notice_visible WHERE notice_visible.statement_id={$alias}.id AND NOT " . self::confirmedSql('notice_visible') . '))';
    }

    public static function stateSql(string $state, string $alias = 'bt'): string
    {
        self::activeSql($alias);
        if ($state === 'confirmed') return self::confirmedSql($alias);
        if ($state === 'review') return "EXISTS (SELECT 1 FROM bank_notice_confirmations bnc_state WHERE bnc_state.notice_transaction_id={$alias}.id AND (bnc_state.state='review' OR (bnc_state.state='confirmed' AND bnc_state.authoritative_transaction_id IS NULL)))";
        if ($state === 'pending') return '(NOT ' . self::confirmedSql($alias) . ' AND NOT ' . self::stateSql('review', $alias) . ')';
        throw new \InvalidArgumentException('Neplatný stav potvrzení.');
    }

}

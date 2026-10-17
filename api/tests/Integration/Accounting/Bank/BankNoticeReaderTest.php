<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Bank\BankMovementListService;
use MyInvoice\Service\Bank\BankPaymentCandidateScope;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use MyInvoice\Service\Bank\UnmatchedBankExportService;

final class BankNoticeReaderTest extends BankPostingTestCase
{
    public function testAllMovementsAndUnmatchedExportExcludeLegacyAndUncheckedNotices(): void
    {
        [$notice, $target] = $this->confirmedPair();
        $this->db->pdo()->prepare('DELETE FROM bank_notice_confirmations WHERE notice_transaction_id=?')->execute([$notice]);
        $filters = ['scope' => 'all', 'account' => self::ACCOUNT, 'year' => self::YEAR];
        foreach ([null, 'superseded'] as $origin) {
            $this->db->pdo()->prepare('UPDATE bank_transactions SET ignore_origin=? WHERE id=?')->execute([$origin, $notice]);
            $page = $this->suggestionRepo->paginateUnposted($this->supplierId, 50, 0, $filters);
            self::assertSame([$target], array_column($page['items'], 'id'));
            $list = $this->container->get(BankMovementListService::class)->list($this->supplierId, $filters, 'all');
            self::assertSame([$target], array_column($list['items'], 'id'));
            $export = new UnmatchedBankExportService($this->db);
            self::assertSame(1, $export->buildAll($this->supplierId, ['year' => self::YEAR, 'account' => self::ACCOUNT])['count']);
        }
    }

    public function testCardCandidatesAndExportCannotReuseConfirmedOrInvalidatedNotice(): void
    {
        [$notice, $target] = $this->confirmedPair(-200.0);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4='1234' WHERE id IN (?, ?)")->execute([$notice, $target]);
        $cards = $this->container->get(CardPaymentOverview::class);
        $export = new UnmatchedBankExportService($this->db);
        foreach ([false, true] as $invalidated) {
            if ($invalidated) $this->invalidate($notice);
            $query = $this->db->pdo()->prepare('SELECT bt.id FROM bank_transactions bt WHERE bt.id IN (?, ?) AND ' . BankPaymentCandidateScope::sql($this->supplierId));
            $query->execute([$notice, $target]);
            self::assertSame([$target], array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN)));
            self::assertNull($cards->findCardTransaction($this->supplierId, $notice));
            self::assertNotNull($cards->findCardTransaction($this->supplierId, $target));
            self::assertSame(1, $cards->unmatched($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31')['count']);
            self::assertSame(1, $export->buildAll($this->supplierId, ['year' => self::YEAR, 'account' => self::ACCOUNT])['count']);
        }
    }

    public function testActiveScopePreservesLegacyLifecycleAndConfirmedHistory(): void
    {
        [$notice, $target] = $this->confirmedPair();
        $pdo = $this->db->pdo();
        $active = $pdo->prepare('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id=? AND ' . \MyInvoice\Service\Bank\BankNoticeConfirmationScope::activeSql());
        $pdo->prepare("UPDATE bank_transactions SET ignore_origin='superseded',match_status='manual' WHERE id=?")->execute([$notice]);
        $active->execute([$notice]);
        self::assertSame(0, (int) $active->fetchColumn(), 'Persisted confirmation remains history even with legacy matching metadata.');
        $pdo->prepare('DELETE FROM bank_notice_confirmations WHERE notice_transaction_id=?')->execute([$notice]);
        foreach (['unmatched' => 0, 'ignored' => 0, 'manual' => 1, 'auto_exact' => 1] as $status => $expected) {
            $pdo->prepare('UPDATE bank_transactions SET match_status=? WHERE id=?')->execute([$status, $notice]);
            $active->execute([$notice]);
            self::assertSame($expected, (int) $active->fetchColumn(), $status);
        }
        $active->execute([$target]);
        self::assertSame(1, (int) $active->fetchColumn());
    }

    /** @return array{int,int} */
    private function confirmedPair(float $amount = 200.0): array
    {
        $statement = $this->statement();
        $noticeStatement = $this->statement();
        $this->db->pdo()->prepare("UPDATE bank_statements SET source='email_notice' WHERE id=?")->execute([$noticeStatement]);
        $notice = $this->transaction($noticeStatement, $amount);
        $target = $this->transaction($statement, $amount);
        $sourceRef = 'synthetic-confirmed-' . $notice;
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source='email_notice',source_ref=? WHERE id=?")->execute([$sourceRef, $notice]);
        // Stav párování je nezávislý na potvrzení: i starší záznam s 'unmatched' se nesmí započítat.
        $this->db->pdo()->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,authoritative_transaction_id,original_authoritative_transaction_id,state,confirmed_at) VALUES (?,?,?,?,?,?,'confirmed',NOW())")
            ->execute([$this->supplierId, $notice, $sourceRef, hash('sha256', $sourceRef), $target, $target]);
        return [$notice, $target];
    }

    private function invalidate(int $notice): void
    {
        $this->db->pdo()->prepare("UPDATE bank_notice_confirmations SET state='review',reason='authoritative_removed',authoritative_transaction_id=NULL WHERE notice_transaction_id=?")
            ->execute([$notice]);
    }
}

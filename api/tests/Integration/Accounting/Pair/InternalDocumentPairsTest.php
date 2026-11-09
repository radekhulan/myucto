<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Interní doklad po dvojicích: vstup `pairs` jde stejnou cestou jako ruční zápis
 * (normalizePairs), koncept si dvojice a jejich text nese do zaúčtování a firma
 * ve složené formě k datu zápisu zaúčtuje stejný koncept bez čísel dvojic.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class InternalDocumentPairsTest extends PairsTestCase
{
    private function service(): InternalDocumentService
    {
        return $this->container->get(InternalDocumentService::class);
    }

    /** @param list<array<string,mixed>> $pairs */
    private function input(array $pairs): array
    {
        return [
            'kind' => 'other',
            'title' => 'Interní doklad po dvojicích',
            'document_date' => self::YEAR . '-04-10',
            'pairs' => $pairs,
        ];
    }

    private function audit(): array
    {
        $uid = $this->userId > 0 ? $this->userId : null;
        return ['user_id' => $uid, 'posted_by' => $uid, 'ip' => '127.0.0.1', 'user_agent' => 'phpunit'];
    }

    public function testPairsDraftKeepsPairsAndPostsPairedEntry(): void
    {
        $draft = $this->service()->create($this->supplierId, $this->input([
            ['debit_account_code' => '518', 'credit_account_code' => '211', 'amount' => 300, 'line_text' => 'Služba hotově'],
            ['debit_account_code' => '221', 'credit_account_code' => '211', 'amount' => '1200.50'],
        ]), $this->userId ?: null);

        self::assertSame(1500.5, (float) $draft['amount']);
        self::assertSame(
            [['518', 'debit', 1, 'Služba hotově'], ['211', 'credit', 1, 'Služba hotově'], ['221', 'debit', 2, null], ['211', 'credit', 2, null]],
            array_map(static fn (array $l): array => [$l['account_code'], $l['side'], $l['pair_no'] ?? null, $l['line_text'] ?? null], $draft['lines']),
        );

        // Částečná úprava bez řádků dvojice zachová.
        $updated = $this->service()->update($this->supplierId, (int) $draft['id'], ['title' => 'Přejmenovaný'], $this->userId ?: null);
        self::assertSame([1, 1, 2, 2], array_map(static fn (array $l): ?int => $l['pair_no'] ?? null, $updated['lines']));

        $posted = $this->service()->post($this->supplierId, (int) $draft['id'], $this->audit());
        $entryId = (int) $posted['journal_entry_id'];
        self::assertSame(
            [['518', '211', '300.00'], ['221', '211', '1200.50']],
            $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId, '1500.50'),
        );
        self::assertSame(['Služba hotově', 'Služba hotově', null, null], $this->lineTexts($entryId));
    }

    public function testCompositeFirmPostsPairsDraftWithoutPairNumbers(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        EntryFormService::forgetShared($this->supplierId);

        $draft = $this->service()->create($this->supplierId, $this->input([
            ['debit_account_code' => '518', 'credit_account_code' => '211', 'amount' => 300, 'line_text' => 'Služba'],
        ]), $this->userId ?: null);
        $posted = $this->service()->post($this->supplierId, (int) $draft['id'], $this->audit());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? AND (pair_no IS NOT NULL OR line_text IS NOT NULL)');
        $stmt->execute([$this->supplierId, (int) $posted['journal_entry_id']]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Složená firma nesmí dostat čísla dvojic (I6).');
    }

    public function testInvalidPairIsRejectedWithPairCode(): void
    {
        try {
            $this->service()->create($this->supplierId, $this->input([
                ['debit_account_code' => '211', 'credit_account_code' => '211', 'amount' => 100],
            ]), null);
            self::fail('Dvojice se stejným účtem musí být odmítnuta.');
        } catch (InternalDocumentException $e) {
            self::assertSame(JournalPairInvariant::SAME_ACCOUNT, $e->errorCode);
        }

        $this->expectException(InternalDocumentException::class);
        $this->service()->create($this->supplierId, $this->input([
            ['debit_account_code' => '518', 'credit_account_code' => '211', 'amount' => 100],
        ]) + ['lines' => [['account_code' => '518', 'side' => 'debit', 'amount' => 100]]], null);
    }

    /** @return list<?string> */
    private function lineTexts(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT line_text FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $stmt->execute([$this->supplierId, $entryId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

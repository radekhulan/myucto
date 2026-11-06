<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\StatementMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankEmailNoticeMarkSeenTest extends TestCase
{
    #[DataProvider('matchingResults')]
    public function testStoredNoticePassesImportContextToImap(bool $matched, ?string $imapError): void
    {
        $repo = $this->createMock(BankEmailNoticeRepository::class);
        $parsers = $this->createStub(BankEmailNoticeParserRepository::class);
        $imap = $this->createMock(ImapMailboxClientInterface::class);
        $message = $this->message();
        $settings = ['id' => 7, 'mark_seen_after_import' => true, 'success_action' => 'none'];
        $repo->method('knownMessage')->willReturn(null);
        $repo->method('accountMappings')->willReturn([]);
        $repo->method('mappingForRecipientAccount')->willReturn(['currency_code' => 'CZK']);
        $parsers->method('parse')->willReturn($this->parsed());
        $stored = false;
        $repo->expects(self::once())->method('createTransactionFromNotice')->willReturnCallback(
            static function () use (&$stored, $matched): array {
                $stored = true;
                return [
                    'statement_id' => 12,
                    'transaction_id' => 34,
                    'match_result' => $matched ? ['status' => 'auto_exact', 'invoice_id' => 56] : ['status' => 'unmatched'],
                ];
            },
        );
        $imap->expects(self::once())->method('postProcess')->with(
            $settings,
            $message,
            $matched ? 'success' : 'failure',
            true,
        )->willReturnCallback(static function () use (&$stored, $imapError): ?string {
            self::assertTrue($stored, 'IMAP zápis smí následovat až po uložení pohybu.');
            return $imapError;
        });
        $status = $matched ? ($imapError === null ? 'processed_success' : 'postprocess_failed') : 'match_failed';
        $repo->expects(self::once())->method('recordMessage')->with(self::callback(
            static fn (array $data): bool => ($data['bank_transaction_id'] ?? null) === 34
                && $data['status'] === $status && $data['error_message'] === $imapError,
        ))->willReturn(78);

        $result = $this->process($repo, $parsers, $imap, $settings, $message);

        self::assertSame($status, $result['status']);
        self::assertSame($matched, $result['matched']);
        self::assertSame($imapError, $result['postprocess_error']);
    }

    public static function matchingResults(): iterable
    {
        yield 'nespárované avízo' => [false, null];
        yield 'spárované avízo' => [true, null];
        yield 'chyba IMAP u nespárovaného avíza' => [false, 'Syntetická chyba zápisu'];
        yield 'chyba IMAP u spárovaného avíza' => [true, 'Syntetická chyba zápisu'];
    }

    #[DataProvider('rejections')]
    public function testRejectedNoticeIsNotTreatedAsImported(string $rejection): void
    {
        $repo = $this->createMock(BankEmailNoticeRepository::class);
        $parsers = $this->createMock(BankEmailNoticeParserRepository::class);
        $imap = $this->createMock(ImapMailboxClientInterface::class);
        $settings = ['id' => 7, 'mark_seen_after_import' => true];
        $repo->method('knownMessage')->willReturn(null);
        $repo->method('accountMappings')->willReturn([]);
        $repo->expects(self::never())->method('createTransactionFromNotice');
        if ($rejection === 'security_rejected') {
            $settings['require_email_auth'] = true;
            $parsers->expects(self::never())->method('parse');
        } elseif ($rejection === 'parse_failed') {
            $parsers->expects(self::once())->method('parse')->willThrowException(new \RuntimeException('Syntetická chyba parseru'));
        } else {
            $parsers->expects(self::once())->method('parse')->willReturn($this->parsed());
            $repo->method('mappingForRecipientAccount')->willReturn(null);
        }
        $imap->expects(self::once())->method('postProcess')->willReturnCallback(
            static function (array $settings, BankEmailNoticeMessage $message, string $kind, bool $imported = false): ?string {
                self::assertSame('failure', $kind);
                self::assertFalse($imported);
                return null;
            },
        );

        self::assertSame($rejection, $this->process($repo, $parsers, $imap, $settings, $this->message())['status']);
    }

    public static function rejections(): iterable
    {
        yield ['security_rejected'];
        yield ['parse_failed'];
        yield ['match_failed'];
    }

    private function process(
        BankEmailNoticeRepository $repo,
        BankEmailNoticeParserRepository $parsers,
        ImapMailboxClientInterface $imap,
        array $settings,
        BankEmailNoticeMessage $message,
    ): array {
        // Párování zde zastupuje výsledek repository; nesmí provést žádnou úhradu.
        $matcher = (new \ReflectionClass(StatementMatcher::class))->newInstanceWithoutConstructor();
        $scanner = new BankEmailNoticeScanner($repo, $parsers, $imap, $matcher);
        return (new \ReflectionMethod($scanner, 'processMessage'))->invoke($scanner, 1, $settings, $message);
    }

    private function message(): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(42, '<notice@example.test>', new \DateTimeImmutable('2026-10-23'),
            'bank@example.test', 'Syntetické avízo', 'Příjem 100 CZK', '');
    }

    private function parsed(): array
    {
        return [
            'provider' => new BankEmailNoticeProvider(null, null, 'builtin:test', 'test', 'Test', 'test', true,
                null, null, null, [], [], true),
            'parsed' => new ParsedBankEmailNotice('20990001', 100.0, 'CZK', '2026-10-23', '1000000005/0100'),
        ];
    }
}

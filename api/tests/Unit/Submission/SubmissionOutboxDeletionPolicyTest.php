<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\Submission\SubmissionOutboxDeletionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hranice mazání zrušené odchozí zprávy — bez databáze, ať je vidět samotné
 * pravidlo a ne jeho okolí.
 */
final class SubmissionOutboxDeletionPolicyTest extends TestCase
{
    public function testCancelledMessageThatNeverLeftTheApplicationCanGo(): void
    {
        self::assertNull(SubmissionOutboxDeletionPolicy::blockingReason(
            self::row(),
            self::attempts(),
            self::links(),
        ));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('evidenceOfLeavingTheApplication')]
    public function testAnythingThatLeftTheApplicationBlocksDeletion(
        string $expectedReason,
        array $overrides,
    ): void {
        $reason = SubmissionOutboxDeletionPolicy::blockingReason(
            self::row($overrides['row'] ?? []),
            self::attempts(...array_values($overrides['attempts'] ?? [])),
            self::links($overrides['links'] ?? []),
        );

        self::assertSame($expectedReason, $reason);
    }

    /** @return iterable<string,array{0:string,1:array<string,mixed>}> */
    public static function evidenceOfLeavingTheApplication(): iterable
    {
        yield 'ještě nezrušená zpráva' => ['state', ['row' => ['dispatch_state' => 'ready']]];
        yield 'ID datové zprávy' => ['sent', ['row' => ['external_message_id' => 'DM-1']]];
        yield 'čas odeslání' => ['sent', ['row' => ['sent_at' => '2026-08-01 10:00:00']]];
        yield 'doručeno' => ['sent', ['row' => ['delivered_at' => '2026-08-01 11:00:00']]];
        yield 'pokus, který mohl odejít' => ['sent', ['attempts' => ['total' => 1, 'left' => 1]]];
        yield 'doručenka' => ['receipt', ['row' => ['receipt_document_id' => 7]]];
        yield 'příchozí zpráva doručenky' => ['receipt', ['row' => ['receipt_inbox_message_id' => 9]]];
        yield 'navázaný příchozí dokument' => ['receipt', ['links' => ['inbox_messages' => 1]]];
        yield 'rozhodnutí úřadu' => ['decided', ['row' => ['acceptance_state' => 'accepted']]];
        yield 'neúspěšný pokus v ledgeru' => ['attempt', ['attempts' => ['total' => 1, 'left' => 0]]];
        yield 'relace odesílací brány' => ['gateway', ['links' => ['gateway_sessions' => 1]]];
        yield 'výzva k odstranění vad' => ['linked', ['links' => ['defect_notices' => 1]]];
        yield 'navazující exekuční podání' => ['linked', ['links' => ['enforcement_dispatches' => 1]]];
    }

    /**
     * Pořadí důvodů má smysl: „odešlo" přebíjí všechno ostatní, protože je to
     * ten důvod, kvůli kterému se doklad nemaže nikdy.
     */
    public function testDispatchEvidenceWinsOverEveryOtherReason(): void
    {
        $reason = SubmissionOutboxDeletionPolicy::blockingReason(
            self::row(['external_message_id' => 'DM-1', 'receipt_document_id' => 3, 'acceptance_state' => 'accepted']),
            self::attempts(2, 1),
            self::links(['inbox_messages' => 1, 'defect_notices' => 1]),
        );

        self::assertSame('sent', $reason);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'dispatch_state' => 'cancelled',
            'acceptance_state' => 'unknown',
            'external_message_id' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'receipt_document_id' => null,
            'receipt_inbox_message_id' => null,
        ], $overrides);
    }

    /** @return array{total:int,left_application:int} */
    private static function attempts(int $total = 0, int $left = 0): array
    {
        return ['total' => $total, 'left_application' => $left];
    }

    /**
     * @param array<string,int> $overrides
     * @return array{inbox_messages:int,defect_notices:int,gateway_sessions:int,enforcement_dispatches:int}
     */
    private static function links(array $overrides = []): array
    {
        return array_merge([
            'inbox_messages' => 0,
            'defect_notices' => 0,
            'gateway_sessions' => 0,
            'enforcement_dispatches' => 0,
        ], $overrides);
    }
}

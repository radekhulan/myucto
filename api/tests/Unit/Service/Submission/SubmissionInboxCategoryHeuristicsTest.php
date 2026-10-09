<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\SubmissionInboxCategoryHeuristics as H;
use MyInvoice\Service\Submission\SubmissionInboxCategoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozpoznání kategorie zprávy datové schránky. Jména odesílatelů jsou
 * smyšlená, stačí, že nesou typické označení druhu úřadu.
 */
final class SubmissionInboxCategoryHeuristicsTest extends TestCase
{
    /** @return iterable<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function cases(): iterable
    {
        yield 'doručenka vlastního podání' => [
            ['classification' => 'delivery_receipt', 'sender_box_id' => 'own0001', 'sender_type' => 20],
            H::OWN_SUBMISSIONS, 'content',
        ];
        yield 'kopie odeslané zprávy podle obálky' => [
            ['envelope_direction' => 'sent', 'sender_name' => 'Testovací firma s.r.o.'],
            H::OWN_SUBMISSIONS, 'content',
        ];
        yield 'zpráva z vlastní schránky' => [['own_box' => true, 'sender_box_id' => 'own0001'], H::OWN_SUBMISSIONS, 'sender'];
        yield 'systémová schránka ISDS' => [['sender_box_id' => 'aaaaaaa', 'sender_type' => 0], H::ISDS_SYSTEM, 'sender'];
        yield 'adresát z číselníku podání' => [
            ['sender_box_id' => 'abc1234', 'recipient_kind' => 'tax_office', 'sender_name' => 'Úřad bez názvu'],
            H::TAX_OFFICE, 'sender',
        ];
        yield 'protokol ČSSZ podle automatu' => [
            ['classification' => 'cssz_protocol', 'sender_name' => 'Hlášení zaměstnavatele'],
            H::SOCIAL_SECURITY, 'sender',
        ];
        yield 'finanční úřad podle jména' => [
            ['sender_name' => 'Finanční úřad pro Testovací kraj', 'sender_type' => 10],
            H::TAX_OFFICE, 'sender',
        ];
        yield 'celní úřad podle jména' => [['sender_name' => 'Celní úřad pro Testov', 'sender_type' => 10], H::TAX_OFFICE, 'sender'];
        yield 'okresní správa sociálního zabezpečení' => [
            ['sender_name' => 'Okresní správa sociálního zabezpečení Testov', 'sender_type' => 10],
            H::SOCIAL_SECURITY, 'sender',
        ];
        yield 'zdravotní pojišťovna' => [
            ['sender_name' => 'Testovací zdravotní pojišťovna', 'sender_type' => 16],
            H::HEALTH_INSURANCE, 'sender',
        ];
        yield 'zkratka pojišťovny jen jako celé slovo' => [
            ['sender_name' => 'Kozpa Trading s.r.o.', 'sender_type' => 20],
            H::BUSINESS_PARTNERS, 'sender',
        ];
        yield 'soud podle jména' => [['sender_name' => 'Okresní soud v Testově', 'sender_type' => 10], H::COURTS_ENFORCEMENT, 'sender'];
        yield 'exekutor podle typu schránky' => [['sender_name' => 'Mgr. Jan Testovací', 'sender_type' => 12], H::COURTS_ENFORCEMENT, 'sender'];
        yield 'exekuce ve věci od úřadu' => [
            ['sender_name' => 'Městský úřad Testov', 'sender_type' => 10, 'subject' => 'Exekuční příkaz přikázáním pohledávky'],
            H::COURTS_ENFORCEMENT, 'content',
        ];
        yield 'exekuce ve věci od firmy nerozhoduje' => [
            ['sender_name' => 'Testovací firma a.s.', 'sender_type' => 20, 'subject' => 'Nabídka vymáhání a exekucí'],
            H::BUSINESS_PARTNERS, 'sender',
        ];
        yield 'jiný orgán veřejné moci' => [['sender_name' => 'Městský úřad Testov', 'sender_type' => 10], H::PUBLIC_AUTHORITY, 'sender'];
        yield 'veřejná moc podle závěru o doručení' => [
            ['sender_name' => 'Úřad bez typu', 'sender_is_public_authority' => true],
            H::PUBLIC_AUTHORITY, 'sender',
        ];
        yield 'podnikající fyzická osoba' => [['sender_name' => 'Jana Testovací', 'sender_type' => 30], H::BUSINESS_PARTNERS, 'sender'];
        yield 'bez údajů' => [['sender_name' => 'Neznámý'], H::OTHER, 'content'];
    }

    /** @param array<string,mixed> $facts */
    #[DataProvider('cases')]
    public function testDecidesCategory(array $facts, string $code, string $basis): void
    {
        self::assertSame(['code' => $code, 'basis' => $basis], H::decide($facts));
    }

    public function testUserRulesBeatAutomaticAndSubjectBeatsSender(): void
    {
        $rules = [
            ['id' => 1, 'category_id' => 10, 'match_field' => 'sender_box', 'pattern' => 'abc1234', 'origin' => 'auto'],
            ['id' => 2, 'category_id' => 20, 'match_field' => 'sender_name', 'pattern' => 'Testov', 'origin' => 'user'],
            ['id' => 3, 'category_id' => 30, 'match_field' => 'subject', 'pattern' => 'vyzva', 'origin' => 'user'],
        ];
        $facts = ['sender_box_id' => 'ABC1234', 'sender_name' => 'Městský úřad Testov', 'subject' => 'Výzva k doplnění'];

        self::assertSame(3, SubmissionInboxCategoryService::matchRule($facts, $rules)['id'] ?? null);
        self::assertSame(2, SubmissionInboxCategoryService::matchRule([...$facts, 'subject' => 'Oznámení'], $rules)['id'] ?? null);
        self::assertSame(
            1,
            SubmissionInboxCategoryService::matchRule(['sender_box_id' => 'abc1234', 'sender_name' => 'Jiný'], $rules)['id'] ?? null,
        );
        self::assertNull(SubmissionInboxCategoryService::matchRule(['sender_box_id' => 'zzz9999'], $rules));
    }
}

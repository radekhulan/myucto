<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\EetDelegation;
use MyInvoice\Service\Eet\EetPolicyDecision;
use MyInvoice\Service\Eet\EetPolicyInput;
use MyInvoice\Service\Eet\EetRecordingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodovací matice evidence tržeb (analýza 4.2). Každý řádek je samostatný
 * případ: kanál × kontakt × účel × měna × původ × profil firmy.
 */
final class EetRecordingPolicyTest extends TestCase
{
    private const ACTIVE = ['enabled' => 1, 'regime' => 'standard', 'active_from' => '2026-01-01', 'active_to' => null, 'eic' => 'CZ8551015704'];

    /** @return iterable<string, array{0:array<string,mixed>, 1:string, 2:string}> */
    public static function matrix(): iterable
    {
        // Hotovost se eviduje vždy, žádný kontext ji nevypne.
        foreach (['in_person', 'on_premises', 'in_vehicle', 'remote', 'unknown'] as $ctx) {
            yield "hotovost / {$ctx}" => [['channel' => 'cash', 'context' => $ctx], 'required', 'cash'];
        }
        foreach (['in_person', 'on_premises', 'in_vehicle'] as $ctx) {
            yield "terminál / {$ctx}" => [['channel' => 'card_terminal', 'context' => $ctx], 'required', 'card_terminal_contact'];
            yield "převod na místě / {$ctx}" => [['channel' => 'bank_transfer', 'context' => $ctx], 'required', 'cashless_contact'];
            yield "online karta na místě / {$ctx}" => [['channel' => 'card_online', 'context' => $ctx], 'required', 'cashless_contact'];
            yield "QR na místě / {$ctx}" => [['channel' => 'qr_in_person', 'context' => $ctx], 'required', 'qr_in_person'];
        }
        yield 'QR na místě bez kontextu' => [['channel' => 'qr_in_person', 'context' => 'unknown'], 'required', 'qr_in_person'];
        yield 'QR na místě, ale vzdáleně' => [['channel' => 'qr_in_person', 'context' => 'remote'], 'unknown', 'context_conflict'];
        yield 'vzdálený převod' => [['channel' => 'bank_transfer', 'context' => 'remote'], 'not_subject', 'remote_payment'];
        yield 'online karta vzdáleně' => [['channel' => 'card_online', 'context' => 'remote'], 'not_subject', 'remote_payment'];
        yield 'terminál vzdáleně' => [['channel' => 'card_terminal', 'context' => 'remote'], 'not_subject', 'remote_payment'];
        yield 'převod bez kontextu' => [['channel' => 'bank_transfer', 'context' => 'unknown'], 'unknown', 'contact_context_required'];
        yield 'terminál bez kontextu' => [['channel' => 'card_terminal', 'context' => 'unknown'], 'unknown', 'contact_context_required'];
        yield 'poštovní dobírka' => [['channel' => 'cash_on_delivery', 'context' => 'remote'], 'not_subject', 'postal_cash_on_delivery'];
        yield 'poštovní dobírka osobně' => [['channel' => 'cash_on_delivery', 'context' => 'in_person'], 'not_subject', 'postal_cash_on_delivery'];
        yield 'jiný kanál' => [['channel' => 'other', 'context' => 'in_person'], 'unknown', 'channel_unclassified'];
        yield 'chybí kanál' => [['channel' => null, 'context' => 'in_person'], 'unknown', 'channel_missing'];

        // Bankovní párování jen ověřuje, tržbu nezakládá.
        yield 'bankovní párování hotovosti' => [['channel' => 'cash', 'context' => 'in_person', 'origin' => 'bank_reconciliation'], 'not_subject', 'bank_reconciliation'];

        // Cizí měna povinnost nemění (tržba se eviduje v CZK přepočtená kurzem úhrady).
        yield 'cizí měna hotově' => [['channel' => 'cash', 'context' => 'in_person', 'currency' => 'EUR'], 'required', 'cash'];
        yield 'cizí měna terminál na místě' => [['channel' => 'card_terminal', 'context' => 'in_person', 'currency' => 'EUR'], 'required', 'card_terminal_contact'];

        // Mimo MVP, ale jen u jinak povinné tržby.
        yield 'cizí měna vzdáleně' => [['channel' => 'bank_transfer', 'context' => 'remote', 'currency' => 'EUR'], 'not_subject', 'remote_payment'];
        yield 'dobití kreditu hotově' => [['channel' => 'cash', 'context' => 'in_person', 'purpose' => 'credit_topup'], 'required', 'cash'];
        foreach (['in_person', 'on_premises', 'in_vehicle'] as $ctx) {
            yield "čerpání kreditu na místě / {$ctx}" => [['channel' => 'stored_value', 'context' => $ctx, 'purpose' => 'credit_draw'], 'required', 'stored_value_contact'];
        }
        yield 'čerpání kreditu na dálku' => [['channel' => 'stored_value', 'context' => 'remote', 'purpose' => 'credit_draw'], 'not_subject', 'remote_stored_value'];
        yield 'čerpání kreditu bez kontextu' => [['channel' => 'stored_value', 'context' => 'unknown', 'purpose' => 'credit_draw'], 'unknown', 'contact_context_required'];
        yield 'čerpání kreditu hotovostí' => [['channel' => 'cash', 'context' => 'in_person', 'purpose' => 'credit_draw'], 'unknown', 'stored_value_purpose_conflict'];
        yield 'poukaz jako prodej' => [['channel' => 'stored_value', 'context' => 'in_person'], 'unknown', 'stored_value_purpose_conflict'];
        yield 'kauce' => [['channel' => 'cash', 'context' => 'in_person', 'purpose' => 'security_deposit'], 'unsupported', 'security_deposit'];
        // Pověření (situace 4 a 5 metodiky FS) povinnost nemění, jen hlídá pravidla vyplnění.
        yield 'pověření jedním poplatníkem' => [['channel' => 'cash', 'context' => 'in_person', 'delegating' => 'CZ00000019'], 'required', 'cash'];
        yield 'pověření více poplatníky bez EIČ' => [['channel' => 'cash', 'context' => 'in_person', 'multiple' => true], 'required', 'cash'];
        yield 'pověření více poplatníky s EIČ' => [['channel' => 'cash', 'context' => 'in_person', 'delegating' => 'CZ00000019', 'multiple' => true], 'required', 'cash'];
        yield 'pověření, vzdálený převod' => [['channel' => 'bank_transfer', 'context' => 'remote', 'delegating' => 'CZ00000019'], 'not_subject', 'remote_payment'];
        yield 'pověřující shodný s poplatníkem' => [['channel' => 'cash', 'context' => 'in_person', 'delegating' => 'CZ8551015704'], 'unknown', 'delegation_invalid'];
        yield 'pověření jedním poplatníkem bez EIČ' => [['channel' => 'cash', 'context' => 'in_person', 'mode' => 'single'], 'unknown', 'delegation_invalid'];
        yield 'pověřující se špatným EIČ' => [['channel' => 'cash', 'context' => 'in_person', 'delegating' => 'CZ12'], 'unknown', 'delegation_invalid'];
        yield 'jiný účel' => [['channel' => 'cash', 'context' => 'in_person', 'purpose' => 'other'], 'unknown', 'purpose_unclassified'];
        yield 'záloha na zakázku hotově' => [['channel' => 'cash', 'context' => 'in_person', 'purpose' => 'order_deposit'], 'required', 'cash'];
        yield 'vratka přes politiku' => [['channel' => 'cash', 'context' => 'in_person', 'kind' => 'refund'], 'unsupported', 'refund_requires_correction'];

        // Profil firmy.
        yield 'EET vypnuto' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['enabled' => 0] + self::ACTIVE], 'inactive', 'eet_disabled'];
        yield 'vynětí' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['regime' => 'exempt'] + self::ACTIVE], 'inactive', 'regime_exempt'];
        yield 'EET OFF' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['regime' => 'eet_off'] + self::ACTIVE], 'inactive', 'regime_eet_off'];
        yield 'před účinností' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['active_from' => '2026-12-01'] + self::ACTIVE], 'inactive', 'outside_active_period'];
        yield 'po účinnosti' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['active_to' => '2026-10-31'] + self::ACTIVE], 'inactive', 'outside_active_period'];
        yield 'poslední den účinnosti' => [['channel' => 'cash', 'context' => 'in_person', 'settings' => ['active_to' => '2026-11-02'] + self::ACTIVE], 'required', 'cash'];
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('matrix')]
    public function testDecisionMatrix(array $case, string $decision, string $reason): void
    {
        $result = (new EetRecordingPolicy())->evaluate(new EetPolicyInput(
            $case['settings'] ?? self::ACTIVE,
            new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('Europe/Prague')),
            $case['channel'],
            $case['context'],
            $case['purpose'] ?? 'sale',
            $case['currency'] ?? 'CZK',
            '1210.00',
            $case['origin'] ?? 'invoice_payment',
            $case['kind'] ?? 'payment',
            $case['mode'] ?? null
                ? new EetDelegation($case['mode'], $case['delegating'] ?? null)
                : EetDelegation::fromItems($case['delegating'] ?? null, $case['multiple'] ?? false),
        ));

        self::assertSame([$decision, $reason], [$result->decision, $result->reasonCode]);
        self::assertNotSame('', $result->messageCs);
        self::assertSame(in_array($decision, ['required', 'voluntary'], true), $result->createsRecord());
        self::assertSame(in_array($decision, ['unknown', 'unsupported'], true), $result->blocksAcceptance());
    }

    /** Okamžik platby se posuzuje v pražské zóně: 23:30 UTC 31. 10. je už 1. 11. */
    public function testActivePeriodUsesPragueDate(): void
    {
        $result = (new EetRecordingPolicy())->evaluate(new EetPolicyInput(
            ['active_from' => '2026-11-01'] + self::ACTIVE,
            new \DateTimeImmutable('2026-10-31 23:30:00', new \DateTimeZone('UTC')),
            'cash', 'in_person',
        ));
        self::assertSame(EetPolicyDecision::REQUIRED, $result->decision);
    }

    /** @return iterable<string, array{0:?string, 1:string, 2:string}> */
    public static function refunds(): iterable
    {
        yield 'vrácení hotově' => ['cash', 'unknown', 'required'];
        yield 'vrácení na terminál na místě' => ['card_terminal', 'in_person', 'required'];
        yield 'vrácení převodem na účet' => ['bank_transfer', 'remote', 'not_subject'];
        yield 'vrácení kartou bez kontextu' => ['card_terminal', 'unknown', 'unknown'];
        yield 'vrácení bez kanálu' => [null, 'in_person', 'unknown'];
    }

    /** Vrácení peněz: kontaktní vrácení se eviduje, převod na účet ne (metodika k § 8 ZoET). */
    #[DataProvider('refunds')]
    public function testRefundDecision(?string $channel, string $context, string $decision): void
    {
        $at = new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('Europe/Prague'));
        self::assertSame($decision, (new EetRecordingPolicy())->refundDecision(self::ACTIVE, $at, $channel, $context)->decision);
        self::assertSame('inactive', (new EetRecordingPolicy())->refundDecision(['enabled' => 0] + self::ACTIVE, $at, 'cash', 'in_person')->decision);
    }

    public function testVersionIsExposedInDecision(): void
    {
        $result = (new EetRecordingPolicy())->evaluate(new EetPolicyInput(self::ACTIVE, new \DateTimeImmutable('2026-11-02'), 'cash', 'in_person'));
        self::assertSame(EetRecordingPolicy::VERSION, $result->toArray()['policy_version']);
    }
}

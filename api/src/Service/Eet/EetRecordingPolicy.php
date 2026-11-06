<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Jediné pravidlo, zda konkrétní platba podléhá evidenci tržeb (SSOT pro náhled,
 * přijetí platby, potvrzení QR záměru i budoucí cesty z pokladny a banky).
 *
 * Pořadí vyhodnocení:
 *  1. vratka se zadává jen jako oprava EET (EetCorrectionService), politika ji odmítne;
 *  2. firma bez aktivní evidence k času platby (vypnuto, vynětí, EET OFF, mimo účinnost) → inactive;
 *  3. bankovní párování jen propojí důkaz s již zachycenou platbou, novou tržbu nezakládá;
 *  4. kanál a kontakt: hotovost vždy, terminál / QR / převod / úhrada poukazem či
 *     kreditem při osobním kontaktu, vzdálená bezhotovostní platba a poštovní dobírka
 *     ne, neznámý kontext → unknown;
 *  5. povinná tržba mimo podporu (kauce) → unsupported; pověření zařízení, které
 *     porušuje pravidla vyplnění ({@see EetDelegation}), → unknown. Cizí měna
 *     povinnost nemění, tržba se eviduje v CZK přepočtená kurzem úhrady.
 *
 * Pověření (situace 4 a 5 metodiky) povinnost evidovat nemění: pověřený poplatník
 * eviduje tržbu stejně jako vlastní, jen se zprávou pošle EIČ pověřujícího nebo
 * příznak více poplatníků.
 *
 * Metodikou se tu i dál myslí dokument FS „Popis položek datové zprávy s příklady
 * jejich vyplnění“ v1.0 z 5. 10. 2026 (zdroj a otisk v api/resources/eet/README.md).
 *
 * Kredit (metodika, kap. 1 bod 13 a 14, k § 17 odst. 2 ZoET): dobití kreditu nebo prodej hodnotového
 * poukazu je tržba (`credit_topup`, podíl ve `urceno_cerp_zuct`), uplatnění poukazu
 * či čerpání kreditu kontaktním způsobem je také tržba (`credit_draw`, `cerp_zuct`).
 * Čerpání jde jen kanálem `stored_value` a kanál `stored_value` jen jako čerpání.
 * Dárkový poukaz na konkrétní plnění je běžný prodej a jeho uplatnění se neeviduje.
 *
 * Vrácení peněz posuzuje {@see refundDecision()} stejnými pravidly kanálu a kontaktu.
 *
 * Pouhé `payment_method` ani checkbox klienta povinnost neurčují. Výsledek nese
 * kód důvodu a verzi pravidel, obojí se ukládá k platební události i k záznamu.
 */
final class EetRecordingPolicy
{
    public const VERSION = '2026.10-4';

    private const CONTACT = ['in_person', 'on_premises', 'in_vehicle'];
    private const UNSUPPORTED_PURPOSES = [
        'security_deposit' => 'Vratnou kauci zatím MyÚčto neeviduje.',
    ];
    private const SUPPORTED_PURPOSES = ['sale', 'order_deposit', 'credit_topup', 'credit_draw'];

    public function evaluate(EetPolicyInput $in): EetPolicyDecision
    {
        if ($in->kind === 'refund' || $in->origin === 'correction') {
            return new EetPolicyDecision(EetPolicyDecision::UNSUPPORTED, 'refund_requires_correction',
                'Vrácení evidované tržby zadejte jako opravu EET.');
        }

        $inactive = $this->inactiveReason($in->settings, $in->at);
        if ($inactive !== null) {
            return new EetPolicyDecision(EetPolicyDecision::INACTIVE, $inactive[0], $inactive[1]);
        }

        if ($in->origin === 'bank_reconciliation') {
            return new EetPolicyDecision(EetPolicyDecision::NOT_SUBJECT, 'bank_reconciliation',
                'Bankovní párování jen ověřuje již zachycenou platbu a novou tržbu nezakládá.');
        }

        if (($in->channel === 'stored_value') !== ($in->businessPurpose === 'credit_draw')) {
            return new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'stored_value_purpose_conflict',
                'Uplatnění poukazu nebo čerpání kreditu zadejte způsobem platby „Poukaz / kredit" a účelem čerpání kreditu.');
        }

        $subject = $this->subjectByChannel($in->channel, $in->contactContext);
        if ($subject->decision !== EetPolicyDecision::REQUIRED) {
            return $subject;
        }

        // Cizí měna povinnost nemění: částka se do EET uvádí v CZK přepočtená kurzem,
        // kterým se úhrada zaúčtuje ({@see EetCurrency}).
        if (isset(self::UNSUPPORTED_PURPOSES[$in->businessPurpose])) {
            return new EetPolicyDecision(EetPolicyDecision::UNSUPPORTED, $in->businessPurpose,
                self::UNSUPPORTED_PURPOSES[$in->businessPurpose]);
        }
        $violation = $in->delegation->violation(isset($in->settings['eic']) ? (string) $in->settings['eic'] : null);
        if ($violation !== null) {
            return new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'delegation_invalid',
                $violation['message'] . ' Opravte pověření v nastavení pokladního zařízení EET.');
        }
        if (!in_array($in->businessPurpose, self::SUPPORTED_PURPOSES, true)) {
            return new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'purpose_unclassified',
                'Upřesněte účel platby (prodej, záloha na zakázku, dobití kreditu nebo čerpání kreditu).');
        }

        return $subject;
    }

    /**
     * Vrácení peněz k evidované tržbě (dobropis, reklamace): eviduje se jako záporná
     * tržba, když je vrácení kontaktní platbou (hotovost vždy, terminál, QR či převod
     * zadaný při osobním kontaktu). Vrácení převodem na účet bez osobního kontaktu
     * evidovat nemusí (metodika, kap. 4, k § 8 ZoET), proto `not_subject`. Zda vůbec existuje
     * evidovaná tržba k opravě, posuzuje volající. Neúplný kontext → unknown.
     *
     * @param array<string,mixed> $settings
     */
    public function refundDecision(array $settings, \DateTimeImmutable $at, ?string $channel, string $contactContext): EetPolicyDecision
    {
        $inactive = $this->inactiveReason($settings, $at);
        if ($inactive !== null) {
            return new EetPolicyDecision(EetPolicyDecision::INACTIVE, $inactive[0], $inactive[1]);
        }
        return $this->subjectByChannel($channel, $contactContext);
    }

    /**
     * Eviduje firma k danému okamžiku tržby? Jediná odpověď pro napojené cesty plateb
     * (faktura, pokladna, banka): neaktivní firma má mít jejich chování beze změny.
     *
     * @param array<string,mixed> $settings
     */
    public function activeAt(array $settings, \DateTimeImmutable $at): bool
    {
        return $this->inactiveReason($settings, $at) === null;
    }

    /**
     * Firma eviduje k času platby? Vrací [kód, zpráva] důvodu neaktivity, nebo null.
     *
     * @param array<string,mixed> $settings
     * @return array{0:string,1:string}|null
     */
    private function inactiveReason(array $settings, \DateTimeImmutable $at): ?array
    {
        if (empty($settings['enabled'])) {
            return ['eet_disabled', 'Firma nemá evidenci tržeb zapnutou.'];
        }
        $regime = (string) ($settings['regime'] ?? 'standard');
        if ($regime === 'exempt') {
            return ['regime_exempt', 'Firma je z evidence tržeb vyňata.'];
        }
        if ($regime === 'eet_off') {
            return ['regime_eet_off', 'Firma je v režimu EET OFF.'];
        }
        $day = EetValues::local($at)->format('Y-m-d');
        $from = (string) ($settings['active_from'] ?? '');
        $to = (string) ($settings['active_to'] ?? '');
        if (($from !== '' && $day < substr($from, 0, 10)) || ($to !== '' && $day > substr($to, 0, 10))) {
            return ['outside_active_period', 'Platba je mimo období, kdy firma eviduje tržby.'];
        }
        return null;
    }

    private function subjectByChannel(?string $channel, string $context): EetPolicyDecision
    {
        $contact = in_array($context, self::CONTACT, true);
        $remote = $context === 'remote';

        return match ($channel) {
            'cash' => new EetPolicyDecision(EetPolicyDecision::REQUIRED, 'cash',
                'Platba v hotovosti se eviduje vždy.'),
            'card_terminal' => $contact
                ? new EetPolicyDecision(EetPolicyDecision::REQUIRED, 'card_terminal_contact',
                    'Platba kartou na terminálu při osobním kontaktu se eviduje.')
                : ($remote
                    ? new EetPolicyDecision(EetPolicyDecision::NOT_SUBJECT, 'remote_payment',
                        'Vzdálená bezhotovostní platba se neeviduje.')
                    : self::contextRequired()),
            'qr_in_person' => $remote
                ? new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'context_conflict',
                    'QR platba na místě nemůže být zároveň vzdálená. Upřesněte okolnosti platby.')
                : new EetPolicyDecision(EetPolicyDecision::REQUIRED, 'qr_in_person',
                    'Platba QR kódem na místě se eviduje okamžikem zadání příkazu.'),
            'bank_transfer', 'card_online' => $remote
                ? new EetPolicyDecision(EetPolicyDecision::NOT_SUBJECT, 'remote_payment',
                    'Vzdálená bezhotovostní platba se neeviduje.')
                : ($contact
                    ? new EetPolicyDecision(EetPolicyDecision::REQUIRED, 'cashless_contact',
                        'Bezhotovostní platba zadaná při osobním kontaktu se eviduje.')
                    : self::contextRequired()),
            'stored_value' => $contact
                ? new EetPolicyDecision(EetPolicyDecision::REQUIRED, 'stored_value_contact',
                    'Uplatnění poukazu nebo čerpání kreditu při osobním kontaktu se eviduje (čerpání kreditu).')
                : ($remote
                    ? new EetPolicyDecision(EetPolicyDecision::NOT_SUBJECT, 'remote_stored_value',
                        'Čerpání kreditu na dálku (bez osobního kontaktu) se neeviduje.')
                    : self::contextRequired()),
            'cash_on_delivery' => new EetPolicyDecision(EetPolicyDecision::NOT_SUBJECT, 'postal_cash_on_delivery',
                'Poštovní dobírka převáděná na účet se neeviduje. Výběr hotovosti vlastním řidičem zadejte jako hotovost.'),
            'other' => new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'channel_unclassified',
                'Upřesněte způsob platby.'),
            default => new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'channel_missing',
                'Uveďte způsob platby.'),
        };
    }

    private static function contextRequired(): EetPolicyDecision
    {
        return new EetPolicyDecision(EetPolicyDecision::UNKNOWN, 'contact_context_required',
            'Uveďte, zda zákazník platil osobně (na místě, v provozovně, ve vozidle) nebo na dálku.');
    }
}

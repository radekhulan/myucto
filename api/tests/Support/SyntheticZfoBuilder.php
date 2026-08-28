<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Vyrábí SYNTETICKÉ ZFO doručenky pro testy.
 *
 * ⚠️ Žádná hodnota tady nepochází ze skutečné datové schránky. ID schránek
 * (`test111`, `test999`) jsou zjevně vymyšlená, jména institucí taky. Skutečné
 * datové zprávy do repozitáře nepatří — nesou identifikátory konkrétních osob
 * a firem a v testech nemají co dělat.
 *
 * Soubor je platná PKCS#7 SignedData obálka **bez podpisu**: `signerInfos` je
 * prázdná množina. To je záměr a zároveň přesný obraz reality —
 * {@see \MyInvoice\Service\Document\ZfoExtractor} podpis neověřuje, jen
 * rozbaluje obsah. Test, který by běžel na podepsaném vzorku, by předstíral
 * ověření, které se neděje.
 */
final class SyntheticZfoBuilder
{
    /** OID 1.2.840.113549.1.7.1 — pkcs7-data. */
    private const OID_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x01";
    /** OID 1.2.840.113549.1.7.2 — signedData. */
    private const OID_SIGNED_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";

    /**
     * @param array{
     *   message_id?:string, sender_box_id?:string, sender_name?:string,
     *   recipient_box_id?:string, recipient_name?:string, sender_ident?:?string,
     *   annotation?:string, delivery_time?:?string, acceptance_time?:?string,
     *   status?:string
     * } $fields
     */
    public static function receipt(array $fields = []): string
    {
        return self::wrapInCms(self::envelopeXml($fields));
    }

    /**
     * @param list<array{name:string,mime:string,bytes:string,meta_type?:string}> $attachments
     */
    public static function receivedMessage(array $attachments, array $fields = []): string
    {
        $values = $fields + [
            'message_id' => '9900002',
            'sender_box_id' => 'test111',
            'sender_name' => 'Zkušební odesílatel (syntetická data)',
            'recipient_box_id' => 'test999',
            'recipient_name' => 'Zkušební příjemce (syntetická data)',
            'annotation' => 'Syntetická zpráva s přílohami',
        ];
        $files = '';
        foreach ($attachments as $attachment) {
            $files .= '<p:dmFile dmFileMetaType="' . self::esc($attachment['meta_type'] ?? 'enclosure')
                . '" dmFileDescr="' . self::esc($attachment['name'])
                . '" dmMimeType="' . self::esc($attachment['mime']) . '">'
                . '<p:dmEncodedContent>' . base64_encode($attachment['bytes']) . '</p:dmEncodedContent>'
                . '</p:dmFile>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:MessageDownloadResponse xmlns:q="http://isds.czechpoint.cz/v20/ReceivedMessage">'
            . '<q:dmReturnedMessage><p:dmDm xmlns:p="http://isds.czechpoint.cz/v20">'
            . '<p:dmID>' . self::esc((string) $values['message_id']) . '</p:dmID>'
            . '<p:dbIDSender>' . self::esc((string) $values['sender_box_id']) . '</p:dbIDSender>'
            . '<p:dmSender>' . self::esc((string) $values['sender_name']) . '</p:dmSender>'
            . '<p:dbIDRecipient>' . self::esc((string) $values['recipient_box_id']) . '</p:dbIDRecipient>'
            . '<p:dmRecipient>' . self::esc((string) $values['recipient_name']) . '</p:dmRecipient>'
            . '<p:dmAnnotation>' . self::esc((string) $values['annotation']) . '</p:dmAnnotation>'
            . '<p:dmFiles>' . $files . '</p:dmFiles>'
            . '</p:dmDm></q:dmReturnedMessage></q:MessageDownloadResponse>';

        return self::wrapInCms($xml);
    }

    /** @param array<string,mixed> $fields */
    public static function envelopeXml(array $fields = []): string
    {
        $values = $fields + [
            'message_id' => '9900001',
            'sender_box_id' => 'test111',
            'sender_name' => 'Zkušební firma s.r.o. (syntetická data)',
            'recipient_box_id' => 'test999',
            'recipient_name' => 'Zkušební finanční úřad (syntetická data)',
            'sender_ident' => null,
            'annotation' => 'DPHDP3 — zkušební podání',
            'delivery_time' => '2026-08-01T09:15:00.000+02:00',
            'acceptance_time' => '2026-08-01T11:20:00.000+02:00',
            // dmMessageStatus je v ISDS ČÍSELNÝ kód, ne slovo (6 = doručeno).
            // Slovní hodnota by se do `document_dms_messages.dm_status` ani nevešla.
            'status' => '6',
        ];

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<q:GetDeliveryInfoResponse xmlns:q="http://isds.czechpoint.cz/v20" xmlns:p="http://isds.czechpoint.cz/v20">',
            '  <q:dmDelivery>',
            '    <p:dmDm>',
            '      <p:dmID>' . self::esc((string) $values['message_id']) . '</p:dmID>',
            '      <p:dbIDSender>' . self::esc((string) $values['sender_box_id']) . '</p:dbIDSender>',
            '      <p:dmSender>' . self::esc((string) $values['sender_name']) . '</p:dmSender>',
            '      <p:dbIDRecipient>' . self::esc((string) $values['recipient_box_id']) . '</p:dbIDRecipient>',
            '      <p:dmRecipient>' . self::esc((string) $values['recipient_name']) . '</p:dmRecipient>',
            '      <p:dmAnnotation>' . self::esc((string) $values['annotation']) . '</p:dmAnnotation>',
        ];
        if ($values['sender_ident'] !== null && $values['sender_ident'] !== '') {
            $lines[] = '      <p:dmSenderIdent>' . self::esc((string) $values['sender_ident']) . '</p:dmSenderIdent>';
        }
        $lines[] = '      <p:dmType>V</p:dmType>';
        $lines[] = '    </p:dmDm>';
        if ($values['delivery_time'] !== null) {
            $lines[] = '    <q:dmDeliveryTime>' . self::esc((string) $values['delivery_time']) . '</q:dmDeliveryTime>';
        }
        if ($values['acceptance_time'] !== null) {
            $lines[] = '    <q:dmAcceptanceTime>' . self::esc((string) $values['acceptance_time']) . '</q:dmAcceptanceTime>';
        }
        $lines[] = '    <q:dmMessageStatus>' . self::esc((string) $values['status']) . '</q:dmMessageStatus>';
        $lines[] = '  </q:dmDelivery>';
        $lines[] = '</q:GetDeliveryInfoResponse>';

        return implode("\n", $lines);
    }

    /** Obálka, ve které chybí dmID — doručenka, kterou nejde k ničemu vztáhnout. */
    public static function receiptWithoutMessageId(): string
    {
        return self::wrapInCms(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:GetDeliveryInfoResponse xmlns:q="http://isds.czechpoint.cz/v20">'
            . '<q:dmDelivery><q:dmMessageStatus>6</q:dmMessageStatus></q:dmDelivery>'
            . '</q:GetDeliveryInfoResponse>',
        );
    }

    /** Platná CMS obálka s nečitelným obsahem — poškozený download. */
    public static function corruptedInsideValidEnvelope(): string
    {
        return self::wrapInCms('<q:GetDeliveryInfoResponse><q:dmDelivery</q:GetDeliveryInfoResponse>');
    }

    /** Něco, co ZFO vůbec není (PDF hlavička). */
    public static function notAZfo(): string
    {
        return "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
    }

    // ───────────────────────── DER ─────────────────────────

    /**
     * ContentInfo { signedData, [0] SignedData { 1, {}, encapContentInfo { data, [0] OCTET STRING }, {} } }
     */
    private static function wrapInCms(string $payload): string
    {
        $eContent = self::tlv(0xA0, self::tlv(0x04, $payload));
        $encap = self::tlv(0x30, self::tlv(0x06, self::OID_DATA) . $eContent);

        $signedData = self::tlv(
            0x30,
            self::tlv(0x02, "\x01")   // version
            . self::tlv(0x31, '')     // digestAlgorithms — prázdné
            . $encap
            . self::tlv(0x31, ''),    // signerInfos — prázdné, viz docblock třídy
        );

        return self::tlv(
            0x30,
            self::tlv(0x06, self::OID_SIGNED_DATA) . self::tlv(0xA0, $signedData),
        );
    }

    private static function tlv(int $tag, string $content): string
    {
        return chr($tag) . self::length(strlen($content)) . $content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

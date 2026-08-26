<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Kanál podání po jednotlivých zdravotních pojišťovnách.
 *
 * Datová věta je od 1. 1. 2026 společná, její skutečné přijetí ale není u
 * všech sedmi pojišťoven stejné: zákon
 * (§ 25 odst. 7 zákona č. 592/1992 Sb., § 10c zákona č. 48/1997 Sb.) nechává
 * formát i způsob zaručené identity na pojišťovně. Pět pojišťoven stojí pod
 * Portálem ZP, VZP a ZP MV ČR mají vlastní cestu.
 *
 * **Katalog je fail-closed pro přímé portálové API.** ISDS je samostatná,
 * uživatelem potvrzovaná cesta. Příloha se volí podle účinnosti: společné XML
 * nebo vytěžitelné PDF, nikdy odhadem.
 *
 * Měřítkem je JMHZ: tam produkční adresa VREP zůstávala `null` přesně do
 * chvíle, než ji doložily nezávislé zdroje ({@see JmhzVrepClient}) — a teprve
 * pak se odesílat začalo. U zdravotních pojišťoven ten doklad zatím není,
 * takže se odeslání na hádaný endpoint nepřipravuje.
 *
 * Co katalog naopak umožňuje: vyrobit soubor a nechat ho účetní odeslat
 * doloženou cestou — datovou schránkou nebo ručním nahráním do portálu.
 */
final class HealthInsurerChannelCatalog
{
    public const REASON_TRANSPORT_UNDOCUMENTED =
        'zp_transport_envelope_undocumented';
    public const REASON_PORTAL_GATEWAY_ON_REQUEST =
        'zp_portal_gateway_description_on_request';
    public const REASON_B2B_NOT_PUBLISHED =
        'zp_b2b_interface_not_published';
    public const REASON_SHARED_MESSAGE_UNCONFIRMED =
        'zp_shared_data_message_acceptance_unconfirmed';

    private const RECIPIENT_CODES = [
        '111' => 'zp_vzp_111',
        '201' => 'zp_vozp_201',
        '205' => 'zp_cpzp_205',
        '207' => 'zp_ozp_207',
        '209' => 'zp_zpskoda_209',
        '211' => 'zp_zpmvcr_211',
        '213' => 'zp_rbp_213',
    ];

    /** @var array<string,HealthInsurerChannel>|null */
    private static ?array $channels = null;

    /** @return array<string,HealthInsurerChannel> */
    public function channels(): array
    {
        return self::$channels ??= self::build();
    }

    public function forInsurer(string $insurerCode): HealthInsurerChannel
    {
        $channel = $this->channels()[$insurerCode] ?? null;
        if ($channel === null) {
            throw new HealthNotificationException(
                'zp_insurer_code_unknown',
                'Kód zdravotní pojišťovny není v jednotné datové větě.',
            );
        }

        return $channel;
    }

    public function recipientCodeFor(string $insurerCode): string
    {
        $this->forInsurer($insurerCode);

        return self::RECIPIENT_CODES[$insurerCode];
    }

    /**
     * Smí aplikace podání odeslat sama? Nikdy — a vždy s důvodem, který
     * pojmenuje, co přesně chybí.
     */
    public function assertDispatchable(string $insurerCode): never
    {
        $channel = $this->forInsurer($insurerCode);

        throw new HealthNotificationException(
            $channel->undocumentedReasonCode,
            sprintf(
                'Automatické odeslání pojišťovně %s není doložené: %s '
                . 'Soubor se vyrobí a stáhne, odeslání zůstává na účetní.',
                $channel->insurerCode,
                $channel->note,
            ),
        );
    }

    /** @return array<string,HealthInsurerChannel> */
    private static function build(): array
    {
        $channels = [
            new HealthInsurerChannel(
                insurerCode: '111',
                kind: HealthInsurerChannelKind::OwnPortal,
                portalUrl: 'https://point.vzp.cz',
                isdsAttachmentRules: [self::pdfSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_TRANSPORT_UNDOCUMENTED,
                note: 'VZP zveřejňuje aktuální formulář i XDP šablonu pro '
                    . 'hromadné vyplnění. Pro datovou schránku MyÚčto '
                    . 'vyplní přímo připnutý oficiální PDF formulář a před '
                    . 'odesláním ověří jeho integritu i hodnoty polí.',
            ),
            new HealthInsurerChannel(
                insurerCode: '201',
                kind: HealthInsurerChannelKind::SharedPortal,
                portalUrl: 'https://portal.vozp.cz',
                isdsAttachmentRules: [self::pdfSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'VoZP výslovně umožňuje podání datovou schránkou '
                    . 'a zveřejňuje formulář i XDP šablonu. MyÚčto pro '
                    . 'ISDS připraví strojově čitelné PDF.',
            ),
            new HealthInsurerChannel(
                insurerCode: '205',
                kind: HealthInsurerChannelKind::SharedPortal,
                portalUrl: 'https://portal.cpzp.cz',
                isdsAttachmentRules: [self::xmlSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'XML lze podat E-přepážkou nebo datovou schránkou. '
                    . 'Předmět datové zprávy má obsahovat PPPZ a zpráva nemá '
                    . 'obsahovat další přílohy.',
            ),
            new HealthInsurerChannel(
                insurerCode: '207',
                kind: HealthInsurerChannelKind::SharedPortal,
                portalUrl: 'https://portal.ozp.cz',
                isdsAttachmentRules: [self::xmlSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_PORTAL_GATEWAY_ON_REQUEST,
                note: 'XML lze podat Portálem ZP nebo datovou schránkou OZP.',
            ),
            new HealthInsurerChannel(
                insurerCode: '209',
                kind: HealthInsurerChannelKind::SharedPortal,
                portalUrl: 'https://portal.zpskoda.cz',
                isdsAttachmentRules: [self::pdfSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_SHARED_MESSAGE_UNCONFIRMED,
                note: 'Datová schránka přijímá přehled ve formátu PDF. '
                    . 'Společné XML se pro tento kanál nenabízí.',
            ),
            new HealthInsurerChannel(
                insurerCode: '211',
                kind: HealthInsurerChannelKind::OwnPortal,
                portalUrl: 'https://eforms.zpmvcr.cz',
                isdsAttachmentRules: [self::pdfSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode: self::REASON_B2B_NOT_PUBLISHED,
                note: 'ZP MV přijímá přes datovou schránku strojově čitelné '
                    . 'PDF i po spuštění nového XML/B2B rozhraní plánovaného '
                    . 'od 1. 10. 2026. MyÚčto proto ISDS automaticky na XML '
                    . 'nepřepíná; B2B zůstává samostatný kanál.',
            ),
            new HealthInsurerChannel(
                insurerCode: '213',
                kind: HealthInsurerChannelKind::SharedPortal,
                portalUrl: 'https://portal.rbp-zp.cz',
                isdsAttachmentRules: [self::xmlSince('2026-01-01')],
                automatedDispatchDocumented: false,
                undocumentedReasonCode:
                    self::REASON_SHARED_MESSAGE_UNCONFIRMED,
                note: 'Přehled lze podat Portálem ZP, aplikací my213 nebo '
                    . 'datovou schránkou ve formátu XML nebo vytěžitelného PDF.',
            ),
        ];

        $indexed = [];
        foreach ($channels as $channel) {
            $indexed[$channel->insurerCode] = $channel;
        }

        return $indexed;
    }

    /** @return array{from:string,to:null,format:HealthInsurerIsdsAttachmentFormat} */
    private static function xmlSince(string $from): array
    {
        return [
            'from' => $from,
            'to' => null,
            'format' => HealthInsurerIsdsAttachmentFormat::Xml,
        ];
    }

    /** @return array{from:string,to:null,format:HealthInsurerIsdsAttachmentFormat} */
    private static function pdfSince(string $from): array
    {
        return [
            'from' => $from,
            'to' => null,
            'format' => HealthInsurerIsdsAttachmentFormat::TextPdf,
        ];
    }

}

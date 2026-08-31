<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;

/**
 * Podání JMHZ datovou schránkou.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tady zbylo tak málo
 * ═══════════════════════════════════════════════════════════════════════════
 * Zařazení do fronty (zmrazený artefakt, příjemce z číselníku, věc, spisová
 * značka, dostupnost brány/Mobilního klíče) je pro každé e-Podání ČSSZ stejné,
 * takže žije v {@see PayrollIsdsSubmissionService}. Dokud bylo jádro schované
 * tady, byla datová schránka dostupná JEN pro JMHZ — NEMPRI a HZUPN měly kanál
 * ISDS doložený úplně stejně a odeslat se nedaly vůbec.
 *
 * Tady zůstává jen to, co je opravdu JMHZ-specifické: rozsah agendy pro zařazení
 * a párování odpovědi ČSSZ podle třídy podání `CSSZ_JMHZ`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Lhůta a povinnost se kanálem NEMĚNÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Zařazení nezakládá druhé podání ani druhý termín — pracuje se ZMRAZENÝM
 * artefaktem téhož podání, které už v evidenci existuje. Volba mezi VREP a
 * datovou schránkou je rozhodnutí o dopravě, ne o tom, co a dokdy se podává.
 */
final readonly class JmhzIsdsSubmissionService
{
    /** Kód agendy je shodný s cestou VREP — je to totéž podání. */
    public const AGENDA_CODE = JmhzSubmissionBridgeService::AGENDA_CODE;

    public function __construct(
        private PayrollIsdsSubmissionService $isds,
    ) {}

    /**
     * Zařadí zmrazené podání do fronty a vrátí i hotovou datovou zprávu, aby ji
     * uživatel mohl v ručním režimu přesně opsat do své datové schránky.
     *
     * @return array{
     *   outbox_id:int,created:bool,row:array<string,mixed>,agenda_code:string,
     *   recipient:array{box_id:string,name:string,note:string},
     *   subject:string,sender_ident:string,
     *   attachment:array{filename:string,mime:string,sha256:string,bytes:int},
     *   transport:array{automatic:bool,channel:string,reason:?string}
     * }
     */
    public function enqueue(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $userId,
    ): array {
        return $this->isds->enqueue(
            $supplierId,
            $environment,
            $submissionId,
            // Rozsah obrazovky „Stav odeslání". Bez něj by se tudy dalo pod
            // hlavičkou JMHZ zařadit hlášení o nemoci konkrétního člověka.
            [self::AGENDA_CODE],
            $userId,
        );
    }

    /**
     * Je tahle zpráva ve schránce odpovědí ČSSZ na naše odeslané podání?
     *
     * V ručním režimu je tohle jediná pomoc, kterou uživateli umíme dát: ISDS
     * neumí došlé zprávy filtrovat podle věci ani podle spisové značky (protokol
     * ČSSZ v1.47, strana 24), takže odpověď musí najít člověk okem mezi ostatní
     * poštou. Rozhoduje `dmId` NAŠÍ odeslané zprávy, ne čas ani pořadí — vzít
     * „poslední od ČSSZ“ by u zaměstnavatele, který podává každý měsíc,
     * přiřadilo protokol jiného období.
     *
     * Kladná odpověď NEZNAMENÁ přijetí podání. Věc datové zprávy je nepodepsaný
     * text; obsah přílohy musí projít ověřením podpisu úplně stejně jako u VREP.
     *
     * @return array{matches:bool,reference:?array{class_name:string,correlation_id:string,original_message_id:string}}
     */
    public function matchResponse(?string $subject, string $sentMessageId): array
    {
        $matcher = new JmhzIsdsResponseMatcher();
        $reference = $matcher->parseSubject($subject);

        return [
            'matches' => $matcher->matches(
                $subject,
                $sentMessageId,
                JmhzDispatchService::SUBMISSION_CLASS,
            ),
            'reference' => $reference === null ? null : [
                'class_name' => $reference->className,
                'correlation_id' => $reference->correlationId,
                'original_message_id' => $reference->originalMessageId,
            ],
        ];
    }

    /**
     * Podle čeho uživatel odpověď ve schránce POZNÁ.
     *
     * Tvary slibuje protokol ČSSZ v1.47 na straně 24; posílají se do UI, aby
     * návod nebyl napsaný natvrdo ve frontendu, kde by se při změně protokolu
     * nedohledal.
     *
     * @return array{subject_prefix:string,attachment_prefix:string,note:string}
     */
    public function responseHint(): array
    {
        return [
            'subject_prefix' => JmhzIsdsResponseMatcher::SUBJECT_PREFIX,
            'attachment_prefix' => JmhzIsdsResponseMatcher::ATTACHMENT_PREFIX,
            'note' => 'ČSSZ pošle odpověď do schránky, ze které podání odešlo.'
                . ' Ve věci uvede v hranaté závorce ID vaší odeslané zprávy.'
                . ' Zpráva zůstává ve schránce 90 dnů.',
        ];
    }
}

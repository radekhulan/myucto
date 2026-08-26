<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsResponseMatcher;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;

/**
 * Rozpozná, co za zprávu přišlo, a jestli patří k některému našemu podání.
 *
 * ── Pravidlo, které tuhle třídu tvaruje ─────────────────────────────────────
 * **Když si nejsme jistí, nehádáme.** Zpráva skončí jako `unclassified`
 * a čeká na člověka. Špatně navázaná doručenka je horší než nenavázaná:
 * tvrdí totiž něco o podání, o kterém nic neví.
 *
 * Proto je jediná AUTOMATICKÁ vazba na podání ta přes naši vlastní spisovou
 * značku, kterou jsme do odchozí zprávy sami razítkovali (`dmSenderIdent`).
 * Shoda odesílatele, předmětu ani času se k vazbě nepoužívá — to všechno jsou
 * domněnky. Druh zprávy se podle nich odhadovat smí, protože špatný ŠTÍTEK
 * zprávu jen zařadí do jiné složky, kdežto špatná VAZBA přepíše stav podání.
 *
 * ⚠️ Že úřad naši značku v odpovědi zopakuje, není zaručeno — a u výzvy
 * k odstranění vad podle § 74 DŘ, která přijde po dnech jako běžná zpráva
 * pro člověka, se to spíš nestane. Většina příchozích zpráv proto legitimně
 * skončí v „nezařazeno". Není to selhání rozpoznávání, je to jeho správný
 * výsledek: zprávu zařadí člověk, který ji přečte.
 */
final readonly class InboxMessageClassifier
{
    public const DELIVERY_RECEIPT = 'delivery_receipt';
    public const CSSZ_PROTOCOL = 'cssz_protocol';
    public const HEALTH_INSURER_RESPONSE = 'health_insurer_response';
    public const TAX_OFFICE_RESPONSE = 'tax_office_response';
    public const UNCLASSIFIED = 'unclassified';

    /** ID schránek zdravotních pojišťoven z číselníku (migrace 1381). */
    private const HEALTH_INSURER_HINTS = ['pojišťov', 'pojistov', 'vzp', 'čpzp', 'cpzp', 'ozp', 'vozp', 'rbp'];
    private const CSSZ_HINTS = ['čssz', 'cssz', 'správa sociálního', 'sprava socialniho', 'okresní správa'];
    private const TAX_OFFICE_HINTS = ['finanční úřad', 'financni urad', 'finanční správa', 'financni sprava'];
    private const RECEIPT_HINTS = ['doručenka', 'dorucenka', 'delivery info', 'doručení zprávy'];

    public function __construct(private SubmissionOutboxRepository $outbox) {}

    /**
     * @param array<string,string> $recipientBoxIds ID schránky → druh (`cssz`, `health_insurer`, …)
     * @return array{classification:string,matched_outbox_id:?int}
     */
    public function classify(
        int $supplierId,
        string $environment,
        InboxMessageHeader $header,
        array $recipientBoxIds = [],
    ): array {
        $classification = $this->guessKind($header, $recipientBoxIds);
        $matchedOutboxId = $this->matchByReference(
            $supplierId,
            $environment,
            $header,
        );
        if ($matchedOutboxId === null
            && $classification === self::CSSZ_PROTOCOL
        ) {
            $matchedOutboxId = $this->matchCsszResponse(
                $supplierId,
                $environment,
                $header,
            );
        }

        // Zpráva bez rozpoznaného druhu se NIKDY neváže na podání — DB to
        // stejně odmítne (trigger `trg_submission_inbox_tenant_guard`).
        if ($classification === self::UNCLASSIFIED) {
            return ['classification' => self::UNCLASSIFIED, 'matched_outbox_id' => null];
        }

        return ['classification' => $classification, 'matched_outbox_id' => $matchedOutboxId];
    }

    /**
     * Vazba na podání — výhradně přes naši vlastní spisovou značku.
     * Cokoliv jiného by bylo hádání.
     */
    private function matchByReference(
        int $supplierId,
        string $environment,
        InboxMessageHeader $header,
    ): ?int
    {
        $reference = trim((string) $header->senderIdent);
        if ($reference === '') {
            return null;
        }
        $row = $this->outbox->findByCorrelation($supplierId, $reference);
        if ($row === null || (string) $row['environment'] !== $environment) {
            return null;
        }
        return (int) $row['id'];
    }

    /**
     * ČSSZ u odpovědi na e-Podání garantuje dmID původní zprávy v předmětu.
     * Je to stejně přesná vazba jako vlastní spisová značka: dmID přidělilo
     * ISDS a v odchozí frontě je pro daný kanál jednoznačné.
     */
    private function matchCsszResponse(
        int $supplierId,
        string $environment,
        InboxMessageHeader $header,
    ): ?int {
        $matcher = new JmhzIsdsResponseMatcher();
        $reference = $matcher->parseSubject($header->subject);
        if ($reference === null) {
            return null;
        }
        $row = $this->outbox->findByExternalMessageId(
            $supplierId,
            'isds',
            $reference->originalMessageId,
        );
        if ($row === null
            || (string) $row['environment'] !== $environment
            || AgendaReceiptCapability::forChannel(
                (string) $row['channel'],
                (string) $row['agenda_code'],
            ) !== AgendaReceiptCapability::ProcessingProtocol
            || !in_array(
                strtoupper((string) $row['agenda_code']),
                ['JMHZ', 'JMHZ25'],
                true,
            )
            || !$matcher->matches(
                $header->subject,
                $reference->originalMessageId,
                JmhzDispatchService::SUBMISSION_CLASS,
            )
        ) {
            return null;
        }

        return (int) $row['id'];
    }

    /** @param array<string,string> $recipientBoxIds */
    private function guessKind(InboxMessageHeader $header, array $recipientBoxIds): string
    {
        $haystack = mb_strtolower(trim(
            (string) $header->subject . ' ' . (string) $header->senderName,
        ));

        if ($this->containsAny($haystack, self::RECEIPT_HINTS)) {
            return self::DELIVERY_RECEIPT;
        }

        // Číselník má přednost před textem: ID schránky je fakt, předmět dojem.
        $box = $header->senderBoxId !== null ? strtolower($header->senderBoxId) : null;
        if ($box !== null) {
            $kind = $this->kindForBox($box, $recipientBoxIds);
            if ($kind !== null) {
                return $kind;
            }
        }

        if ($this->containsAny($haystack, self::CSSZ_HINTS)) {
            return self::CSSZ_PROTOCOL;
        }
        if ($this->containsAny($haystack, self::HEALTH_INSURER_HINTS)) {
            return self::HEALTH_INSURER_RESPONSE;
        }
        if ($this->containsAny($haystack, self::TAX_OFFICE_HINTS)) {
            return self::TAX_OFFICE_RESPONSE;
        }

        return self::UNCLASSIFIED;
    }

    /** @param array<string,string> $recipientBoxIds mapa boxId → kind z číselníku */
    private function kindForBox(string $box, array $recipientBoxIds): ?string
    {
        /** @var array<string,string> $map */
        $map = $recipientBoxIds;
        $kind = $map[$box] ?? null;
        return match ($kind) {
            'cssz' => self::CSSZ_PROTOCOL,
            'health_insurer' => self::HEALTH_INSURER_RESPONSE,
            'tax_office' => self::TAX_OFFICE_RESPONSE,
            default => null,
        };
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}

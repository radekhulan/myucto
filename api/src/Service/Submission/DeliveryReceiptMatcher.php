<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionOutboxRepository;

/**
 * Ke kterému podání patří nahraná doručenka.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Pravidlo, které tuhle třídu tvaruje: NEHÁDÁME
 * ═══════════════════════════════════════════════════════════════════════════
 * Špatně spárovaná doručenka je horší než nespárovaná. Nespárovaná leží
 * v „nezařazeno", je vidět a člověk ji přiřadí. Špatně spárovaná posune stav
 * cizího podání a tváří se přitom jako důkaz — a všimne si toho až ten, komu
 * uteče lhůta.
 *
 * Proto se automaticky páruje **jedině přes přesný identifikátor**:
 *
 *   1. **`dmSenderIdent` = naše spisová značka.** Nejsilnější vazba, protože
 *      si ji razítkujeme do odchozí zprávy sami a je v rámci firmy jedinečná
 *      (`uq_submission_outbox_correlation`).
 *   2. **`dmID` = identifikátor zprávy**, který uživatel opsal při „označit
 *      jako odesláno ručně". Taky přesný — přiděluje ho ISDS a je jedinečný.
 *
 * Cokoliv slabšího (shoda schránky příjemce, období, věci) je **jen nabídka
 * kandidátů člověku**. Ani jediný kandidát není důvod spárovat sám: dvě podání
 * téže agendy do téže schránky vypadají zvenčí stejně a jedno z nich by
 * dostalo cizí důkaz.
 *
 * ── Proč se automatická shoda ještě jednou přezkoumává ──────────────────────
 * I u přesného identifikátoru se ověřuje, že schránka příjemce z doručenky
 * sedí na tu z podání. Když nesedí, vazba se NEUDĚLÁ automaticky, ale podání
 * se nabídne jako kandidát s vysvětlením. Rozpor mezi dvěma důkazy není
 * detail, který se má potichu překlopit na jeden z nich.
 */
final readonly class DeliveryReceiptMatcher
{
    public const BY_CORRELATION = 'correlation_reference';
    public const BY_MESSAGE_ID = 'external_message_id';
    public const BY_MANUAL = 'manual';

    /**
     * Doručenku si k podání vyžádala sama aplikace z ISDS podle `dmID`, které
     * u toho podání drží odchozí fronta.
     *
     * Není to `manual` (soubor nevybral člověk) ani `external_message_id`
     * (nikdo nic nepároval — o zprávu jsme si řekli jménem). Rozlišuje se to
     * proto, že UI u ručního nahrání píše „přiřadili jste ručně"; u staženého
     * dokladu by ta věta byla nepravdivá.
     */
    public const BY_ISDS_DOWNLOAD = 'isds_download';

    /** Doručenka se přiřadila sama — přes přesný identifikátor. */
    public const STATUS_MATCHED = 'matched';
    /** Nabízíme kandidáty, rozhoduje člověk. Nic se nezměnilo. */
    public const STATUS_CANDIDATES = 'candidates';
    /** Nemáme co nabídnout. Doručenka zůstane v „nezařazeno". */
    public const STATUS_UNMATCHED = 'unmatched';

    public function __construct(private SubmissionOutboxRepository $outbox) {}

    /**
     * @return array{
     *   status:string,
     *   outbox_id:?int,
     *   matched_by:?string,
     *   reason:string,
     *   candidates:list<array{id:int,subject:string,agenda_code:string,recipient_box_id:?string,
     *     dispatch_state:string,correlation_reference:string,created_at:string,reasons:list<string>}>
     * }
     */
    public function match(int $supplierId, string $environment, DeliveryReceipt $receipt): array
    {
        $exact = $this->matchByExactIdentifier($supplierId, $environment, $receipt);
        if ($exact !== null) {
            return $exact;
        }

        $candidates = $this->candidates($supplierId, $environment, $receipt);
        if ($candidates === []) {
            return [
                'status' => self::STATUS_UNMATCHED,
                'outbox_id' => null,
                'matched_by' => null,
                'reason' => 'no_candidate',
                'candidates' => [],
            ];
        }

        return [
            'status' => self::STATUS_CANDIDATES,
            'outbox_id' => null,
            'matched_by' => null,
            'reason' => count($candidates) === 1 ? 'single_candidate_needs_confirmation' : 'ambiguous',
            'candidates' => $candidates,
        ];
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @return array{status:string,outbox_id:?int,matched_by:?string,reason:string,candidates:list<array<string,mixed>>}|null
     */
    private function matchByExactIdentifier(int $supplierId, string $environment, DeliveryReceipt $receipt): ?array
    {
        $byReference = $receipt->hasSenderIdent()
            ? $this->outbox->findByCorrelation($supplierId, (string) $receipt->senderIdent)
            : null;
        if ($byReference !== null) {
            return $this->verdictFor($byReference, $environment, $receipt, self::BY_CORRELATION);
        }

        $byMessageId = $this->outbox->findByExternalMessageId($supplierId, 'isds', $receipt->messageId);
        if ($byMessageId !== null) {
            return $this->verdictFor($byMessageId, $environment, $receipt, self::BY_MESSAGE_ID);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{status:string,outbox_id:?int,matched_by:?string,reason:string,candidates:list<array<string,mixed>>}
     */
    private function verdictFor(array $row, string $environment, DeliveryReceipt $receipt, string $matchedBy): array
    {
        // Podání z jiného prostředí je jiné podání. Testovací doručenka nesmí
        // hnout produkčním záznamem, ani kdyby značka náhodou seděla.
        if ((string) $row['environment'] !== $environment || (string) $row['channel'] !== 'isds') {
            return $this->needsHuman($row, 'environment_mismatch');
        }

        $expectedBox = $row['recipient_box_id'] !== null ? strtolower((string) $row['recipient_box_id']) : null;
        if ($receipt->recipientBoxId !== null && $expectedBox !== null && $receipt->recipientBoxId !== $expectedBox) {
            // Přesný identifikátor říká A, schránka příjemce B. Rozpor
            // nepřeklápíme na jednu stranu — ať rozhodne člověk, který vidí obojí.
            return $this->needsHuman($row, 'recipient_box_mismatch');
        }

        return [
            'status' => self::STATUS_MATCHED,
            'outbox_id' => (int) $row['id'],
            'matched_by' => $matchedBy,
            'reason' => $matchedBy,
            'candidates' => [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{status:string,outbox_id:?int,matched_by:?string,reason:string,candidates:list<array<string,mixed>>}
     */
    private function needsHuman(array $row, string $reason): array
    {
        return [
            'status' => self::STATUS_CANDIDATES,
            'outbox_id' => null,
            'matched_by' => null,
            'reason' => $reason,
            'candidates' => [$this->describe($row, [$reason])],
        ];
    }

    /**
     * Nabídka pro člověka. Nikdy se z ní nepáruje samo.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, string $environment, DeliveryReceipt $receipt): array
    {
        $rows = $this->outbox->listReceiptCandidates(
            $supplierId,
            $environment,
            $receipt->recipientBoxId,
            $receipt->sentAt() ?? $receipt->deliveredAt(),
        );

        $subject = self::normalizeText($receipt->subject);

        $described = [];
        foreach ($rows as $row) {
            $reasons = [];
            if ($receipt->recipientBoxId !== null && $row['recipient_box_id'] !== null
                && strtolower((string) $row['recipient_box_id']) === $receipt->recipientBoxId
            ) {
                $reasons[] = 'recipient_box';
            }
            if ($subject !== '' && self::normalizeText((string) $row['subject']) === $subject) {
                $reasons[] = 'subject';
            }
            if ($receipt->sentAt() !== null) {
                $reasons[] = 'period';
            }
            $described[] = $this->describe($row, $reasons);
        }

        // Nejvíc shodných signálů nahoru — pořadí je pomůcka pro oko, ne verdikt.
        usort($described, static fn (array $a, array $b): int => count($b['reasons']) <=> count($a['reasons']));

        return $described;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function describe(array $row, array $reasons): array
    {
        return [
            'id' => (int) $row['id'],
            'subject' => (string) $row['subject'],
            'agenda_code' => (string) $row['agenda_code'],
            'recipient_box_id' => $row['recipient_box_id'] !== null ? (string) $row['recipient_box_id'] : null,
            'dispatch_state' => (string) $row['dispatch_state'],
            'correlation_reference' => (string) $row['correlation_reference'],
            'created_at' => (string) $row['created_at'],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private static function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }
}

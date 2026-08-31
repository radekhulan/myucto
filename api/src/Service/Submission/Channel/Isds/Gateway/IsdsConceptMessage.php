<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Obsah konceptu datové zprávy pro `SetConcept`.
 *
 * Je to čistá hodnota — postaví se a zkontroluje bez sítě, takže se dá
 * validovat proti `SetConcept.xsd` v testu. Skládá se výhradně z toho, co už
 * fronta o podání ví ({@see OutboundSubmission}); tahle třída žádný obsah
 * nevymýšlí a nesmí. Zprávu pro mzdová podání staví
 * {@see \MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsMessageBuilder}
 * a jeho výstup se sem dostane přes frontu, ne druhou cestou.
 *
 * ── Limity, které vynucuje ISDS ─────────────────────────────────────────────
 * `odesilaci_brana_ISDS.pdf` v. 1.11, kap. 3.4:
 *   - nejvýš 50 příloh,
 *   - součet příloh nejvýš 20 MB (nad to jedině `SetBigConcept` přes VoDZ),
 *   - typ zprávy se NESMÍ uvádět jako komerční — ISDS ho označí sám až při
 *     schválení konceptu, takže atribut `dmType` se do obálky vůbec nepíše.
 * `dmBaseTypes.xsd` / `SetConcept.xsd`:
 *   - `dmAnnotation` má `maxLength=255`, počítáno ve ZNACÍCH (proto `mb_substr`,
 *     ne `substr` — naivní ořez by českou větu rozsekl uprostřed znaku),
 *   - `dmSenderIdent` má `maxLength=50`,
 *   - `dbIDRecipient` má přesně 7 znaků.
 */
final readonly class IsdsConceptMessage
{
    public const MAX_ATTACHMENTS = 50;
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
    public const MAX_ANNOTATION_CHARS = 255;
    public const MAX_SENDER_IDENT_CHARS = 50;

    /**
     * @param list<array{filename:string,mime:string,bytes:string}> $files
     *        První soubor je hlavní příloha (`dmFileMetaType="main"`).
     */
    public function __construct(
        public string $recipientBoxId,
        public string $annotation,
        public string $senderIdent,
        public array $files,
    ) {}

    public static function fromOutboundSubmission(OutboundSubmission $submission): self
    {
        if ($submission->recipientBoxId === null || $submission->recipientBoxId === '') {
            throw new SubmissionChannelException(
                'recipient_missing',
                'Podání nemá vyplněnou datovou schránku příjemce.',
                400,
            );
        }

        return new self(
            recipientBoxId: strtolower(trim($submission->recipientBoxId)),
            annotation: $submission->subject,
            senderIdent: $submission->correlationReference,
            files: [[
                'filename' => $submission->artifactFilename,
                'mime' => $submission->artifactMimeType,
                'bytes' => $submission->artifactBytes,
            ]],
        );
    }

    /**
     * Kontroly, které se dají udělat bez sítě.
     *
     * Dělají se PŘED odesláním schválně: ISDS by je sice odmítl taky, ale to už
     * bychom měli v ISDS spotřebované `timeLimitedId` a uživatele v půlce
     * přesměrování. Levná chyba tady je lepší než drahá tam.
     *
     * @throws SubmissionChannelException
     */
    public function assertValid(): void
    {
        if (preg_match('/^[a-z0-9]{7}$/', $this->recipientBoxId) !== 1) {
            throw new SubmissionChannelException(
                'isds_gateway_recipient_invalid',
                'ID datové schránky příjemce nemá platný tvar (7 znaků, písmena a číslice).',
                422,
            );
        }
        if (trim($this->annotation) === '') {
            throw new SubmissionChannelException(
                'isds_gateway_annotation_missing',
                'Datová zpráva musí mít vyplněnou věc.',
                422,
            );
        }
        if (mb_strlen($this->annotation) > self::MAX_ANNOTATION_CHARS) {
            throw new SubmissionChannelException(
                'isds_gateway_annotation_too_long',
                'Věc datové zprávy je delší než 255 znaků, které datová schránka připouští.',
                422,
            );
        }
        if ($this->senderIdent === '' || mb_strlen($this->senderIdent) > self::MAX_SENDER_IDENT_CHARS) {
            throw new SubmissionChannelException(
                'isds_gateway_sender_ident_invalid',
                'Spisová značka podání musí být vyplněná a nejvýš 50 znaků dlouhá.',
                422,
            );
        }
        if ($this->files === []) {
            throw new SubmissionChannelException(
                'isds_gateway_attachment_missing',
                'Datová zpráva musí mít alespoň jednu přílohu.',
                422,
            );
        }
        if (count($this->files) > self::MAX_ATTACHMENTS) {
            throw new SubmissionChannelException(
                'isds_gateway_too_many_attachments',
                'Odesílací brána připouští nejvýš 50 příloh v jedné zprávě.',
                422,
            );
        }

        $total = 0;
        foreach ($this->files as $file) {
            if (trim($file['filename']) === '' || $file['bytes'] === '') {
                throw new SubmissionChannelException(
                    'isds_gateway_attachment_invalid',
                    'Příloha datové zprávy nemá název nebo je prázdná.',
                    422,
                );
            }
            $total += strlen($file['bytes']);
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new SubmissionChannelException(
                'isds_gateway_attachments_too_large',
                'Součet příloh přesahuje 20 MB, které odesílací brána připouští. '
                . 'Takovou zprávu je nutné odeslat ručně jako velkoobjemovou.',
                422,
            );
        }
    }

    /** Otisk toho, co do konceptu opravdu šlo — důkaz, že schválené = připravené. */
    public function payloadSha256(): string
    {
        $parts = [$this->recipientBoxId, $this->annotation, $this->senderIdent];
        foreach ($this->files as $file) {
            $parts[] = $file['filename'] . '|' . $file['mime'] . '|' . hash('sha256', $file['bytes']);
        }

        return hash('sha256', implode("\n", $parts));
    }
}

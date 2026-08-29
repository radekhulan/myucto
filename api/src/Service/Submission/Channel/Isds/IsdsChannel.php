<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelEvidenceStrength;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchProbe;
use MyInvoice\Service\Submission\Channel\DispatchResult;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\InboxListing;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\Channel\SubmissionInboxChannel;

/**
 * Datová schránka jako kanál podání.
 *
 * Používá se pro všechno, co jde poslat datovkou: přiznání k DPH, kontrolní
 * a souhrnné hlášení, DPPO, přehledy zdravotním pojišťovnám i mzdová podání.
 * Není to mzdová odbočka.
 *
 * ── Co tenhle kanál NIKDY neudělá ────────────────────────────────────────────
 * Neposune podání do „zpracováno". Datová schránka je doručovací služba: ví,
 * že zpráva dorazila, a nic víc. {@see evidenceStrength()} vrací
 * {@see ChannelEvidenceStrength::DeliveryOnly}, takže i kdyby budoucí adaptér
 * začal vracet cokoliv o přijetí, orchestrace to zahodí.
 *
 * `dmAcceptanceTime` (fikce doručení) je past: jmenuje se „acceptance", ale
 * znamená DORUČENÍ, ne přijetí úřadem. Proto se mapuje na `deliveredAt`.
 */
final readonly class IsdsChannel implements SubmissionChannel, SubmissionInboxChannel
{
    public const CODE = 'isds';

    /**
     * Stavy ISDS, od kterých je zpráva prokazatelně doručená.
     *
     * `SUBSTITUTED` (doručení fikcí podle § 17 odst. 4) a `RECEIVED` (doručení
     * přihlášením podle § 17 odst. 3) tu původně chyběly, přestože jsou to
     * právě ty dva stavy, které doručení zakládají. Bez nich by podání zůstalo
     * viset v „odesláno, doručenka nedorazila" i po skutečném doručení.
     */
    private const DELIVERED_STATES = [
        'DELIVERED',
        'SUBSTITUTED',
        'RECEIVED',
        'READ',
        'UNDELIVERABLE_READ',
        'IN_SAFE',
        'IN_ARCHIVE',
    ];

    public function __construct(private IsdsTransport $transport) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function evidenceStrength(): ChannelEvidenceStrength
    {
        return ChannelEvidenceStrength::DeliveryOnly;
    }

    /**
     * ISDS příjemce vždy adresuje, takže `null` z rozhraní tady nikdy nevznikne —
     * ten je pro kanály mířící na bránu (EPO).
     *
     * @return array{usable:bool,reason:?string,owner_name:?string}
     */
    public function verifyRecipient(?string $recipientBoxId, ChannelContext $context): array
    {
        if ($recipientBoxId === null || $recipientBoxId === '') {
            throw new SubmissionChannelException(
                'recipient_missing',
                'Podání nemá vyplněnou datovou schránku příjemce.',
                400,
            );
        }

        $check = $this->transport->checkRecipientBox($context, $recipientBoxId);

        return [
            'usable' => $check->usable,
            'reason' => $check->reason,
            'owner_name' => $check->ownerName,
        ];
    }

    public function send(OutboundSubmission $submission, ChannelContext $context): DispatchResult
    {
        if ($submission->recipientBoxId === null || $submission->recipientBoxId === '') {
            return DispatchResult::failed(
                'recipient_missing',
                'Podání nemá vyplněnou datovou schránku příjemce.',
            );
        }
        // ISDS limit pole dmSenderIdent. Delší značka by se ořízla a dohledání
        // po timeoutu by přestalo fungovat právě tehdy, kdy je potřeba.
        if (strlen($submission->correlationReference) > 50) {
            return DispatchResult::failed(
                'sender_ident_too_long',
                'Spisová značka podání je delší než 50 znaků, které datová schránka připouští.',
            );
        }

        try {
            // Spisová značka se razítkuje do dmSenderIdent PŘED odesláním —
            // viz probe(). Bez toho by přerušené volání nešlo dořešit jinak
            // než hádáním.
            $receipt = $this->transport->createMessage(
                $context,
                $submission->recipientBoxId,
                $submission->subject,
                $submission->correlationReference,
                [[
                    'filename' => $submission->artifactFilename,
                    'mime' => $submission->artifactMimeType,
                    'bytes' => $submission->artifactBytes,
                ]],
            );
        } catch (IsdsTransportTimeout $e) {
            return DispatchResult::uncertain(
                $e->errorCode,
                'Spojení s datovou schránkou se přerušilo a není jisté, jestli zpráva odešla. '
                . 'Podání zůstává rozpracované, dokud se to nedohledá — neodesílejte ho znovu ručně.',
            );
        } catch (SubmissionChannelException $e) {
            return DispatchResult::failed($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            // Neúplná odpověď shodí přístup k neinicializované property, což je
            // `\Error` mimo hierarchii výjimek knihovny — a stane se to až POTÉ,
            // co zpráva mohla odejít. Cokoliv nečekaného je tedy nevědomost,
            // ne selhání. Kdybychom to spolkli jako `failed`, uživatel by odeslal
            // podruhé a úřad by dostal duplicitu.
            return DispatchResult::uncertain(
                'isds_unexpected_error',
                'Datová schránka odpověděla nečekaně a není jisté, jestli zpráva odešla. '
                . 'Podání zůstává rozpracované, dokud se to nedohledá — neodesílejte ho znovu ručně.',
            );
        }

        return DispatchResult::sent($receipt->messageId);
    }

    public function probe(string $correlationReference, ChannelContext $context): DispatchProbe
    {
        try {
            $messageId = $this->transport->findSentBySenderIdent($context, $correlationReference);
        } catch (SubmissionChannelException $e) {
            // Nedovolali jsme se → nevědomost trvá. Vrátit „neodešlo" by
            // svedlo k odeslání duplicity.
            return DispatchProbe::inconclusive($e->getMessage());
        }

        if ($messageId === null) {
            return DispatchProbe::notSent('V odeslaných zprávách taková spisová značka není.');
        }
        return DispatchProbe::found($messageId);
    }

    public function fetchStatus(string $externalMessageId, ChannelContext $context): ChannelStatus
    {
        $state = $this->transport->messageState($context, $externalMessageId);
        $code = strtoupper(trim($state['state']));

        $deliveredAt = $this->parseTime($state['delivered_at'])
            // dmAcceptanceTime = fikce doručení. Patří na osu DOPRAVY, ne
            // vyřízení — jméno „acceptance" je v ISDS matoucí.
            ?? $this->parseTime($state['accepted_at']);

        if (in_array($code, self::DELIVERED_STATES, true) && $deliveredAt !== null) {
            return ChannelStatus::deliveredOnly(
                $deliveredAt,
                'Doručeno do datové schránky příjemce. O vyřízení úřadem to nevypovídá.',
            );
        }

        return new ChannelStatus(
            DispatchState::Sent,
            note: 'Odesláno, doručenka zatím nedorazila.',
        );
    }

    public function downloadConfirmation(string $externalMessageId, ChannelContext $context): ?array
    {
        $bytes = $this->transport->downloadDeliveryReceipt($context, $externalMessageId);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        return [
            'filename' => 'dorucenka-' . $externalMessageId . '.zfo',
            'mime' => 'application/vnd.software602.filler.form-xml-zip',
            'bytes' => $bytes,
        ];
    }

    // ───────────────────────── příchozí ─────────────────────────

    /**
     * ⚠️ Vyzvednutí seznamu je PŘIHLÁŠENÍ do schránky, a tím DORUČENÍ všech
     * dodaných zpráv podle § 17 odst. 3 zák. 300/2008 Sb. Rozjíždí zákonné
     * lhůty. Že se sem vůbec smí zavolat, rozhoduje
     * {@see \MyInvoice\Service\Submission\SubmissionInboxService} podle
     * výslovného souhlasu uživatele; kanál sám žádnou takovou bránu nemá.
     */
    public function listNew(ChannelContext $context): InboxListing
    {
        // Případné selhání dotazu propadne jako SubmissionChannelException —
        // schválně se tu NECHYTÁ. Prázdný seznam smí znamenat jedinou věc.
        $rows = $this->transport->listReceived($context);

        $messages = [];
        foreach ($rows as $row) {
            $id = trim($row['message_id']);
            if ($id === '') {
                continue;
            }
            $messages[] = new InboxMessageHeader(
                externalMessageId: $id,
                senderBoxId: $this->nullableString($row['sender_box_id']),
                senderName: $this->nullableString($row['sender_name']),
                subject: $this->nullableString($row['subject']),
                senderIdent: $this->nullableString($row['sender_ident']),
                deliveredAt: $this->parseTime($row['delivered_at']),
                acceptedAt: $this->parseTime($row['accepted_at']),
            );
        }

        return new InboxListing($messages, new \DateTimeImmutable('now'));
    }

    public function download(string $externalMessageId, ChannelContext $context): string
    {
        $bytes = $this->transport->downloadMessage($context, $externalMessageId);
        if ($bytes === '') {
            throw new SubmissionChannelException(
                'empty_message',
                'Datová schránka vrátila prázdnou zprávu.',
            );
        }
        return $bytes;
    }

    // ───────────────────────── pomocné ─────────────────────────

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

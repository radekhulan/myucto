<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\IsdsBoxCheck;
use MyInvoice\Service\Submission\Channel\Isds\IsdsSendReceipt;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransport;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Skriptovatelná náhrada za skutečnou datovou schránku.
 *
 * ⚠️ Žádný test v tomhle modulu nesmí sáhnout na síť — ani na produkční
 * `datovka.gov.cz`, ani na testovací `datovka-test.gov.cz`. Skutečné odeslání
 * dělá výhradně člověk. Tahle třída je jediný způsob, jak se v testech
 * k „datové schránce" dostat, a nemá jedinou síťovou operaci.
 *
 * Zároveň slouží jako spustitelný popis kontraktu {@see IsdsTransport}:
 * umí přesně ty patologie, které bezpečnostní audit knihovny našel —
 * timeout uprostřed odeslání, odmítnutí, `\Error` z neúplné odpovědi
 * i nedostupnou schránku.
 */
final class FakeIsdsTransport implements IsdsTransport
{
    /** @var list<array{sender_ident:string,message_id:string,recipient:string}> */
    public array $sentMessages = [];

    /** @var list<string> */
    public array $callLog = [];

    public string $nextMessageId = 'DM-1000';

    /** Co udělá další volání createMessage(): 'ok'|'timeout'|'refuse'|'fatal'|'timeout_but_delivered' */
    public string $sendBehaviour = 'ok';

    /** Co udělá další volání listReceived(): 'ok'|'fail' */
    public string $inboxBehaviour = 'ok';

    /** Co udělá další volání checkRecipientBox(): 'ok'|'unusable'|'fail' */
    public string $boxBehaviour = 'ok';

    /** Co udělá další volání findSentBySenderIdent(): 'auto'|'fail' */
    public string $probeBehaviour = 'auto';

    /** @var list<array<string,mixed>> */
    public array $inboxMessages = [];

    /** @var array<string,array{state:string,delivered_at:?string,accepted_at:?string}> */
    public array $states = [];

    /** @var array<string,string> */
    public array $downloads = [];

    /** @var array<string,SubmissionChannelException> */
    public array $downloadFailures = [];

    public ?string $deliveryReceipt = null;

    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        $this->callLog[] = 'checkRecipientBox';

        return match ($this->boxBehaviour) {
            'unusable' => IsdsBoxCheck::unusable($boxId, 'schránka je znepřístupněná'),
            'fail' => throw new SubmissionChannelException('isds_unreachable', 'Na ISDS se nedovoláme.'),
            default => IsdsBoxCheck::usable($boxId, 'Testovací úřad'),
        };
    }

    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        $this->callLog[] = 'createMessage';

        switch ($this->sendBehaviour) {
            case 'timeout':
                // Spojení spadlo a zpráva NEodešla.
                throw new IsdsTransportTimeout('isds_timeout', 'Spojení vypršelo.');

            case 'timeout_but_delivered':
                // Nejzrádnější případ: zpráva odešla, ale odpověď se ztratila.
                // Dohledání ji proto musí najít.
                $this->sentMessages[] = [
                    'sender_ident' => $senderIdent,
                    'message_id' => $this->nextMessageId,
                    'recipient' => $recipientBoxId,
                ];
                throw new IsdsTransportTimeout('isds_timeout', 'Spojení vypršelo.');

            case 'refuse':
                throw new SubmissionChannelException('isds_rejected', 'ISDS zprávu odmítlo.');

            case 'fatal':
                // Neúplná odpověď knihovny — `\Error` MIMO hierarchii jejích
                // výjimek, a to až poté, co zpráva mohla odejít.
                $this->sentMessages[] = [
                    'sender_ident' => $senderIdent,
                    'message_id' => $this->nextMessageId,
                    'recipient' => $recipientBoxId,
                ];
                throw new \Error('Typed property DataMessageResponse::$dmStatus must not be accessed before initialization');

            default:
                $this->sentMessages[] = [
                    'sender_ident' => $senderIdent,
                    'message_id' => $this->nextMessageId,
                    'recipient' => $recipientBoxId,
                ];
                return IsdsSendReceipt::accepted($this->nextMessageId, IsdsSendReceipt::STATUS_ACCEPTED);
        }
    }

    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
    {
        $this->callLog[] = 'findSentBySenderIdent';
        if ($this->probeBehaviour === 'fail') {
            throw new SubmissionChannelException('isds_unreachable', 'Na ISDS se nedovoláme.');
        }
        foreach ($this->sentMessages as $sent) {
            if ($sent['sender_ident'] === $senderIdent) {
                return $sent['message_id'];
            }
        }
        return null;
    }

    public function messageState(ChannelContext $context, string $messageId): array
    {
        $this->callLog[] = 'messageState';
        return $this->states[$messageId]
            ?? ['state' => 'POSTED', 'delivered_at' => null, 'accepted_at' => null];
    }

    public function listReceived(ChannelContext $context): array
    {
        $this->callLog[] = 'listReceived';
        if ($this->inboxBehaviour === 'fail') {
            throw new SubmissionChannelException('isds_unreachable', 'Na schránku se nedovoláme.');
        }
        return $this->inboxMessages;
    }

    public function downloadMessage(ChannelContext $context, string $messageId): string
    {
        $this->callLog[] = 'downloadMessage';
        if (isset($this->downloadFailures[$messageId])) {
            throw $this->downloadFailures[$messageId];
        }
        return $this->downloads[$messageId] ?? 'ZFO-' . $messageId;
    }

    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
    {
        $this->callLog[] = 'downloadDeliveryReceipt';
        return $this->deliveryReceipt;
    }
}

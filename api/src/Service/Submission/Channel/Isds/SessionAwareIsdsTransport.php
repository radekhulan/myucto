<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;

/**
 * Rozcestník mezi přímým přístupem k ISDS a náhradní cestou.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se rozhoduje až za běhu, a ne v kontejneru
 * ═══════════════════════════════════════════════════════════════════════════
 * Jestli se smí použít přímý transport, nezávisí na nastavení instalace, ale
 * na TOM KONKRÉTNÍM volání: přímo se odesílá jen v relaci, kterou člověk právě
 * potvrdil v Mobilním klíči (nebo SMS kódem). Taková relace žije pár minut a
 * do kontejneru se nikdy nedostane — přijde v {@see ChannelContext} až s
 * požadavkem. Rozhodnutí proto nemůže padnout při sestavení kontejneru a
 * musí být tady.
 *
 * Pravidlo je jedno jediné a je pojmenované v
 * {@see DirectIsdsInboxTransport::hasConfirmedSession()}: nese-li kontext
 * živou relaci potvrzenou člověkem, jede se přímo; jinak náhradní cestou
 * (odesílací brána, nebo „není nasazeno"). Fail-closed zůstává: náhradní
 * cesta hází vlastní pojmenované chyby a tenhle rozcestník žádnou z nich
 * nepřebíjí ani nepolyká.
 */
final readonly class SessionAwareIsdsTransport implements IsdsTransport
{
    public function __construct(
        private DirectIsdsInboxTransport $direct,
        private IsdsTransport $fallback,
    ) {}

    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        return $this->pick($context)->checkRecipientBox($context, $boxId);
    }

    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        return $this->pick($context)->createMessage($context, $recipientBoxId, $subject, $senderIdent, $files);
    }

    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
    {
        return $this->pick($context)->findSentBySenderIdent($context, $senderIdent);
    }

    public function messageState(ChannelContext $context, string $messageId): array
    {
        return $this->pick($context)->messageState($context, $messageId);
    }

    public function listReceived(ChannelContext $context): array
    {
        return $this->pick($context)->listReceived($context);
    }

    public function downloadMessage(ChannelContext $context, string $messageId): string
    {
        return $this->pick($context)->downloadMessage($context, $messageId);
    }

    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
    {
        return $this->pick($context)->downloadDeliveryReceipt($context, $messageId);
    }

    private function pick(ChannelContext $context): IsdsTransport
    {
        return DirectIsdsInboxTransport::hasConfirmedSession($context)
            ? $this->direct
            : $this->fallback;
    }
}

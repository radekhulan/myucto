<?php

declare(strict_types=1);

namespace MyInvoice\Service\InstanceAdmin;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Neutrální zpráva nesahá na šablony, firmy, podpisy, fronty ani archiv IMAP. */
class NeutralSmtpTestSender
{
    public function send(array $settings): void
    {
        $transport = self::transport($settings);
        try {
            $message = self::message($settings);
            $transport->send($message, Envelope::create($message));
        } finally {
            $transport->stop();
        }
    }

    public static function message(array $settings): Email
    {
        return (new Email())->from(new Address($settings['from_email'], 'MyÚčto'))
            ->to(new Address($settings['test_recipient']))
            ->subject('MyÚčto — SMTP test')
            ->text('MyÚčto SMTP connection test. This message contains no company data or attachments.');
    }

    /** Stejný ověřovaný transport používá test i běžný Mailer. */
    public static function transport(array $settings): EsmtpTransport
    {
        $ssl = $settings['encryption'] === 'ssl';
        $transport = new EsmtpTransport($settings['host'], (int) $settings['port'], $ssl ? true : null);
        $transport->setAutoTls(true);
        $transport->setRequireTls(!$ssl);
        $transport->setUsername($settings['username']);
        $transport->setPassword($settings['password']);
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(15);
            $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        }
        return $transport;
    }
}

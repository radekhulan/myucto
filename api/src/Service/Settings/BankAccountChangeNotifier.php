<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settings;

use MyInvoice\Service\Mail\Mailer;
use Psr\Log\LoggerInterface;

/**
 * E-mailové upozornění na změnu bankovního spojení firmy provedenou API tokenem.
 *
 * Změna čísla účtu nebo IBAN přesměruje platby z každé další faktury a QR platby.
 * Token s výslovně udělenou schopností `allow_bank_account_write` ji provést smí,
 * ale vlastník tokenu se o ní musí dozvědět mimo kanál, kterým přišla — jinak by
 * uniklý token stačil na tiché přesměrování plateb.
 *
 * Selhání odeslání změnu nevrací (ta už je uložená a zapsaná v auditu); vrací
 * FALSE, aby si ho volající poznamenal do auditu jako nedoručené upozornění.
 */
final class BankAccountChangeNotifier
{
    public const TEMPLATE = 'bank_account_changed';

    public function __construct(
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array{email:string,name?:?string,locale?:?string} $recipient
     * @param array{supplier_id:int,currency_id:int,currency_code:string,token_name:?string,token_prefix:?string,
     *              ip:?string,changed_at:string,before:array<string,?string>,after:array<string,?string>} $change
     */
    public function notify(array $recipient, array $change): bool
    {
        $email = trim($recipient['email']);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('bank_account_change.notice_no_recipient', [
                'currency_id' => $change['currency_id'],
            ]);
            return false;
        }
        $locale = ($recipient['locale'] ?? 'cs') === 'en' ? 'en' : 'cs';
        $subject = $locale === 'en'
            ? 'Company bank account changed via API token'
            : 'Bankovní spojení firmy bylo změněno přes API token';

        $rows = [];
        foreach ($change['after'] as $field => $new) {
            $rows[] = ['field' => $field, 'before' => $change['before'][$field] ?? null, 'after' => $new];
        }

        try {
            $this->mailer->sendTemplate(
                self::TEMPLATE,
                $locale,
                [$email],
                [
                    'name'          => $recipient['name'] ?? null,
                    'currency_code' => $change['currency_code'],
                    'token_name'    => $change['token_name'],
                    'token_prefix'  => $change['token_prefix'],
                    'ip'            => $change['ip'],
                    'changed_at'    => $change['changed_at'],
                    'changes'       => $rows,
                ],
                $subject,
                templateSupplierId: $change['supplier_id'],
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('bank_account_change.notice_failed', [
                'currency_id' => $change['currency_id'],
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }
}

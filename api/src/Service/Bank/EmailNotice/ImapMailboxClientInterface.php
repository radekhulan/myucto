<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

interface ImapMailboxClientInterface
{
    /**
     * @param array<string,mixed> $settings Settings including decrypted password.
     * @return list<BankEmailNoticeMessage>
     */
    public function latest(array $settings, int $limit): array;

    /**
     * @param array<string,mixed> $settings Settings including decrypted password.
     * @return array{ok:bool,message:string,folders?:list<string>}
     */
    public function test(array $settings): array;

    /**
     * @param array<string,mixed> $settings Settings including decrypted password.
     * @param 'success'|'failure' $kind
     * @param bool $noticeImported Bankovní pohyb z avíza je bezpečně uložený, i když není spárovaný.
     */
    public function postProcess(array $settings, BankEmailNoticeMessage $message, string $kind, bool $noticeImported = false): ?string;
}

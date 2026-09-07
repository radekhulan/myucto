<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class BankEmailNoticeMessage
{
    /**
     * @param list<string> $authResults Hodnoty hlaviček Authentication-Results shora dolů
     *                                   (nejnovější hop = přijímací server = index 0).
     * @param bool $allowForwarded Schránka přijímá přeposlaná (FW) avíza — povolí
     *                             detekci banky z těla, když `From` nesedí (opt-in per účet).
     * @param string $forwardedFrom Volitelný whitelist přeposílatele (adresa nebo doména);
     *                              prázdný = libovolný. Uplatní se jen v přeposlané větvi.
     * @param list<EmailAttachment> $attachments Přílohy zprávy. Plní se JEN u účtů se
     *                              zapnutým `ingest_pdf_invoices` — jinak zůstává prázdné
     *                              pole a obsah příloh se z IMAPu vůbec netahá.
     */
    public function __construct(
        public readonly ?int $uid,
        public readonly ?string $messageId,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $sender,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $raw,
        public readonly array $authResults = [],
        public readonly bool $allowForwarded = false,
        public readonly string $forwardedFrom = '',
        public readonly array $attachments = [],
    ) {}

    /**
     * Datum zprávy převedené do zóny aplikace — pro odvození kalendářního DNE.
     *
     * Hlavička `Date:` nese offset odesílatele; Fio posílá aviza v UTC. Bez převodu
     * se `format('Y-m-d')` ptá na den v CIZÍ zóně, takže platba přijatá ve 23:30
     * dostala datum zaúčtování následujícího dne.
     *
     * Do {@see self::fallbackHash()} tahle podoba NEVSTUPUJE — ten musí zůstat
     * bajtově stejný jako před zavedením převodu, jinak by se už zpracovaná avíza
     * po nasazení naimportovala znovu.
     */
    public function dateInAppTimezone(): ?\DateTimeImmutable
    {
        return $this->date?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function fallbackHash(): string
    {
        $date = $this->date?->format('c') ?? '';
        $base = strtolower($this->sender) . "\n" . $this->subject . "\n" . $date . "\n" . $this->text;
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($base)) ?? $base);
    }
}

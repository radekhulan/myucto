<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RegexBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'regex';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return null;
    }

    protected function parserLabel(): string
    {
        return 'Regex';
    }

    /**
     * Odesílatel je jediná vrstva, která u regex provideru odděluje avízo banky od
     * cizí zprávy — vzory předmětu/těla jsou veřejně opsatelné z faktury. Provider
     * BEZ vyplněného whitelistu proto neprojde vůbec (`senderAllowedForwarded` je
     * fail-closed); `NULL` sloupec se cestou přetypuje na prázdný řetězec, který
     * má tentýž význam „nic nepustit".
     */
    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderAllowedForwarded($message, (string) ($provider->senderWhitelist ?? ''))) {
            return false;
        }
        $subjectPattern = trim((string) ($provider->subjectPattern ?? ''));
        if ($subjectPattern !== '' && !$this->patternMatches($message->subject, $subjectPattern)) {
            return false;
        }
        $bodyPattern = trim((string) ($provider->bodyPattern ?? ''));
        if ($bodyPattern !== '' && !$this->patternMatches($this->normalizeText($message->text), $bodyPattern)) {
            return false;
        }
        return true;
    }

    /**
     * Diakritiku-tolerantní detekce: nejdřív zkus pattern proti původnímu textu,
     * a když nesedí (přeposlané avízo v legacy kódování / s chybějící diakritikou),
     * zkus ho proti ASCII-sklopené variantě obojího. Bez toho stačilo, aby se cestou
     * rozbilo jediné „ě"/„č" a `supports()` selhal s „žádný parser provider" (#158).
     */
    private function patternMatches(string $haystack, string $pattern): bool
    {
        if (preg_match('~' . $pattern . '~iu', $haystack) === 1) {
            return true;
        }
        return preg_match('~' . $this->foldDiacritics($pattern) . '~iu', $this->foldDiacritics($haystack)) === 1;
    }

    /**
     * Match field patternu se stejnou diakritiku-tolerantní logikou: strict varianta
     * (zachová diakritiku v zachycených hodnotách u čistého UTF-8 avíza), jinak fallback
     * na ASCII-sklopený text i pattern. Vrací pojmenované skupiny shodného matche, nebo null.
     *
     * @return array<int|string,string>|null
     */
    private function matchField(string $text, string $foldedText, string $pattern): ?array
    {
        if (preg_match('~' . $pattern . '~u', $text, $m) === 1) {
            return $m;
        }
        if (preg_match('~' . $this->foldDiacritics($pattern) . '~u', $foldedText, $m) === 1) {
            return $m;
        }
        return null;
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);
        $foldedText = $this->foldDiacritics($text);
        $patterns = $provider->fieldPatterns;

        $data = [];
        foreach ($patterns as $field => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            $m = $this->matchField($text, $foldedText, $pattern);
            if ($m === null) {
                continue;
            }
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = trim((string) $value);
                }
            }
            if (!isset($data[$field]) && isset($m[1])) {
                $data[$field] = trim((string) $m[1]);
            }
        }

        // Některé banky (např. Česká spořitelna) datum platby v těle avíza neuvádějí —
        // jako fallback použij datum doručení e-mailu, ať povinné pole nechybí.
        if (trim((string) ($data['posted_at'] ?? '')) === '' && $message->date instanceof \DateTimeImmutable) {
            $data['posted_at'] = $message->dateInAppTimezone()->format('d.m.Y H:i');
        }

        // #110: šablona ČS „Odešla platba" nemusí obsahovat řádek „Číslo účtu:" —
        // jako fallback vytáhni vlastní účet z úvodní věty („z účtu NÁZEV 123/0800 právě
        // odešla platba…" / „na účet NÁZEV 123/0800 právě dorazila platba…").
        // Matchujeme nad ASCII-sklopeným textem (#158: diakritika v avízu se cestou
        // mohla rozbít); číslo účtu je stejně ASCII, takže sklopení nic neztratí.
        if (trim((string) ($data['recipient_account'] ?? '')) === ''
            && preg_match('/(?:z\s+uctu|na\s+ucet)\s+[^\n]{0,120}?(?<value>\d[\d\-]*\/\d{4})/iu', $foldedText, $m) === 1
        ) {
            $data['recipient_account'] = trim($m['value']);
        }

        // #147: novější šablona ČS „Odešla platba" uvádí v bloku transakce řádky
        // „Z účtu:" (odesílatel) a „Na účet:" (příjemce) místo „Číslo účtu:" /
        // „Číslo účtu protistrany:". Která strana je vlastní účet a která protistrana
        // se prohazuje podle směru platby, proto je mapujeme až podle „Směr platby"
        // (dvojtečka v popisku odliší tyto řádky od úvodní věty bez dvojtečky).
        $fromAccount = preg_match('/Z\s+uctu:\s*(?<value>\d[\d\-]*\/\d{4})/iu', $foldedText, $m) === 1 ? trim($m['value']) : '';
        $toAccount = preg_match('/Na\s+ucet:\s*(?<value>\d[\d\-]*\/\d{4})/iu', $foldedText, $m) === 1 ? trim($m['value']) : '';
        $outgoing = preg_match('/odchoz|výdej|vydej|výdaj|vydaj|debet|odepsán|odepsan|outgoing/u', mb_strtolower((string) ($data['direction'] ?? ''), 'UTF-8')) === 1;
        $ownLineAccount = $outgoing ? $fromAccount : $toAccount;
        $counterpartyLineAccount = $outgoing ? $toAccount : $fromAccount;
        if (trim((string) ($data['recipient_account'] ?? '')) === '' && $ownLineAccount !== '') {
            $data['recipient_account'] = $ownLineAccount;
        }
        if (trim((string) ($data['counterparty_account'] ?? '')) === '' && $counterpartyLineAccount !== '') {
            $data['counterparty_account'] = $counterpartyLineAccount;
        }

        // VS je VOLITELNÝ (#45). Banka ho u odchozích plateb, poplatků a karetních
        // transakcí legitimně neposílá — ČSOB CEB („CEB Info: Zaúčtování platby") je
        // přesně ten případ. Zahodit kvůli tomu celé avízo znamená díru v posloupnosti
        // zůstatků; správně se pohyb zaeviduje bez VS a párování skončí na
        // `no_invoice_with_vs`, což je korektní stav (StatementMatcher s prázdným VS počítá).
        // Zbytek povinný zůstává: bez částky, měny, data a cílového účtu není co založit.
        foreach (['amount', 'currency', 'posted_at', 'recipient_account'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new \RuntimeException("Parser nenašel povinné pole {$required}.");
            }
        }

        [$cpAccount, $cpBank] = $this->splitAccount((string) ($data['counterparty_account'] ?? ''));
        $postedAt = $this->parseDate((string) $data['posted_at']);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->digitsOnly((string) ($data['variable_symbol'] ?? '')),
            amount: $this->applyDirection($this->parseAmount((string) $data['amount']), (string) ($data['direction'] ?? '')),
            currency: $this->normalizeCurrency((string) $data['currency']),
            postedAt: $postedAt,
            recipientAccount: $this->normalizeAccount((string) $data['recipient_account']),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $this->cleanNullable((string) ($data['counterparty_name'] ?? '')),
            constantSymbol: $this->cleanNullable((string) ($data['constant_symbol'] ?? '')),
            message: $this->cleanNullable((string) ($data['message'] ?? '')),
            bankRef: $this->cleanNullable((string) ($data['bank_ref'] ?? '')),
            balance: trim((string) ($data['balance'] ?? '')) !== ''
                ? $this->parseAmount((string) $data['balance'])
                : null,
        );
    }
}

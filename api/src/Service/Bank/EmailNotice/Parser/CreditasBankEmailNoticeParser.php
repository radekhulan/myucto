<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

/**
 * Banka CREDITAS — avízo „Notifikace o změně na účtu" (info@creditas.cz).
 *
 * Tělo je úvodní věta + odrážkový blok „Detail platby" (čísla níže jsou smyšlená):
 *   zůstatek na účtu 1000000005/2250 se zvýšil o částku 23 000,00 CZK (Příchozí úhrada).
 *   Disponibilní zůstatek 23.06.2026 18:41 je 96 163,53 CZK.
 *
 *   Detail platby:
 *   - změna na účtu: 1000000005/2250
 *   - účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:41
 *   - částka: 23 000,00 CZK
 *   - VS: 123456 - disponibilní zůstatek: 96 163,53 CZK
 *
 * Směr platby nese úvodní věta slovem „snížil" (odchozí/záporná) vs. „zvýšil"
 * (příchozí/kladná), NE znaménkem u částky. Protistrana (účet + kód banky),
 * VS a KS jsou volitelné a mohou být uprostřed řádku za „ - " — u jednoduchých
 * avíz (blokace karty) chybí úplně. Závorka za částkou v úvodní větě
 * („(Blokace)" / „(Příchozí úhrada)") nese typ pohybu — uloží se do `message`
 * (Blokace = dočasná autorizace platební kartou, skutečné zaúčtování může
 * dorazit dalším avízem; deduplikaci řeší scanner).
 *
 * Pro robustnost proti rozbité/chybějící diakritice v přeposlaných avízech (#58)
 * se detekce i extrakce dělají nad ASCII-foldnutým textem.
 */
final class CreditasBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'creditas';
    }

    protected function parserLabel(): string
    {
        return 'CREDITAS';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Banka CREDITAS - Notifikace o změně na účtu',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'info@creditas.cz',
            subjectPattern: 'Notifikace\\s+o\\s+změně\\s+na\\s+účtu|Notifikace\\s+o\\s+zmene\\s+na\\s+uctu',
            bodyPattern: 'změna\\s+na\\s+účtu|zmena\\s+na\\s+uctu',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'creditas.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (!str_contains($subject, 'notifikace') || !str_contains($subject, 'zmene na uctu')) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'zmena na uctu')
            && str_contains($text, 'castka')
            && (str_contains($text, 'snizil') || str_contains($text, 'zvysil'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        // $raw drží diakritiku (čte se z něj člověčí popisek typu pohybu);
        // $text je ASCII-foldnutý a slouží k matchování labelů/čísel (#58).
        $raw = $this->normalizeText($message->text);
        $text = $this->foldDiacritics($raw);

        $account = $this->match($text, '/zmena\s+na\s+uctu\s*:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu');
        if ($account === null) {
            throw new \RuntimeException('CREDITAS parser nenašel cílový účet.');
        }

        $amountCurrency = $this->match(
            $text,
            '/(?:^|\R|-)\s*castka\s*:\s*(?<amount>[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3})?/iu',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException('CREDITAS parser nenašel částku.');
        }

        $isOutgoing = preg_match('/se\s+snizil/iu', $text) === 1;
        $isIncoming = preg_match('/se\s+zvysil/iu', $text) === 1;
        if (!$isOutgoing && !$isIncoming) {
            throw new \RuntimeException('CREDITAS parser nerozpoznal směr platby (snížil/zvýšil).');
        }

        // „datum:" může být na začátku odrážky i uprostřed řádku za „ - " (varianta
        // s protistranou), proto bez kotvy na začátek řádku — v těle je jen jedno.
        $postedAt = $this->optional($text, '/datum\s*:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4}(?:\s+\d{1,2}:\d{2})?)/iu');
        if ($postedAt === null) {
            if (!$message->date instanceof \DateTimeImmutable) {
                throw new \RuntimeException('CREDITAS parser nenašel datum platby.');
            }
            $postedAt = $message->dateInAppTimezone()->format('d.m.Y');
        }

        // Protistrana (jen u příchozích úhrad): účet a kód banky jsou samostatné labely.
        $cpAccountRaw = $this->optional($text, '/ucet\s+protistrany\s*:\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/iu');
        $cpBankRaw = $this->optional($text, '/banka\s+protistrany\s*:\s*(?<value>[0-9]{4})/iu');
        [$cpAccount, $cpBank] = $this->splitAccount((string) $cpAccountRaw);
        if ($cpBank === null && $cpBankRaw !== null) {
            $cpBank = $cpBankRaw;
        }

        // Symboly (volitelné, mohou být uprostřed řádku za „ - ").
        $variableSymbol = $this->optional($text, '/(?<![A-Za-z])VS\s*:\s*(?<value>[0-9]+)/iu');
        $constantSymbol = $this->optional($text, '/(?<![A-Za-z])KS\s*:\s*(?<value>[0-9]+)/iu');

        // Typ pohybu z úvodní věty („…o částku 23 000,00 CZK (Příchozí úhrada)") —
        // čte se z $raw, aby se zachovala diakritika popisku.
        $type = $this->optional($raw, '/[0-9][0-9\x{00A0} .]*,[0-9]{2}\s*[A-Za-z]{3}\s*\((?<value>[^)]+)\)/u');

        // Disponibilní zůstatek — v úvodní větě („Disponibilní zůstatek 23.06.2026 18:41
        // je 96 163,53 CZK.") i v odrážce („- disponibilní zůstatek: 96 163,53 CZK");
        // stačí první výskyt, hodnota je stejná.
        $balance = $this->optional(
            $text,
            '/disponibilni\s+zustatek(?:\s+\d{1,2}\.\d{1,2}\.\d{4}(?:\s+\d{1,2}:\d{2})?)?\s*(?::|je)\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu',
        );

        [$recipientAccount, $recipientBank] = $this->splitAccount((string) $account['value']);
        $currency = trim((string) ($amountCurrency['currency'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection(
                $this->parseAmount((string) $amountCurrency['amount']),
                $isOutgoing ? 'odchozí' : 'příchozí',
            ),
            currency: $currency !== '' ? $this->normalizeCurrency($currency) : 'CZK',
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount . ($recipientBank !== null ? '/' . $recipientBank : ''),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            constantSymbol: $constantSymbol,
            message: $type,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            currencyStated: $currency !== '',
        );
    }

}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RaiffeisenbankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'raiffeisenbank';
    }

    protected function parserLabel(): string
    {
        return 'Raiffeisenbank';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Raiffeisenbank - Pohyb na účtě',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'info@rb.cz',
            subjectPattern: 'Pohyb\\s+na\\s+účtě|Pohyb\\s+na\\s+ucte',
            // Řádek s částkou má každé avízo; VS chybí u úhrady jen se zprávou pro příjemce (#234).
            bodyPattern: 'Částka\\s+v\\s+měně\\s+účtu|Variabilní\\s+symbol|Debetní\\s+karta',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'rb.cz')) {
            return false;
        }
        $subject = mb_strtolower($message->subject);
        if (!str_contains($subject, 'pohyb na účtě') && !str_contains($subject, 'pohyb na ucte')) {
            return false;
        }
        $text = $this->normalizeText($message->text);
        $folded = mb_strtolower($this->foldDiacritics($text));
        if (!str_contains($folded, 'castka v mene uctu')) {
            return false;
        }

        $isTransfer = str_contains($folded, 'na ucet')
            && str_contains($folded, 'z uctu');

        return $isTransfer || $this->isCardTransaction($text);
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        // Karetní platby uvádějí datum jako (opakovaný) ISO timestamp; bere se první výskyt.
        $postedAt = $this->required($text, '/Datum\s+a\s+čas\s*(?<value>\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2}|\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/iu', 'datum');
        $amountCurrency = $this->match($text, '/Částka\s+v\s+měně\s+účtu\s*(?<amount>[+\-]?[0-9 .]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/iu');
        if ($amountCurrency === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel částku a měnu.');
        }
        $amount = $this->parseAmount((string) $amountCurrency['amount']);
        $constantSymbol = $this->optional($text, '/Konstantní\s+symbol\s*(?<value>[0-9]+)/iu');
        $balance = $this->optional($text, '/Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');

        if ($this->isCardTransaction($text)) {
            $ownAccount = $this->required(
                $text,
                '/(?:^|\R)\s*Účet\s*(?<value>[0-9\-]+\/[0-9]{4})/iu',
                'vlastní účet',
            );
            $details = $this->optional(
                $text,
                '/Detaily\s*(?<value>.*?)Disponibilní\s+zůstatek/isu',
            );

            return new ParsedBankEmailNotice(
                variableSymbol: '',
                amount: $amount,
                currency: strtoupper((string) $amountCurrency['currency']),
                postedAt: $this->parseDate($postedAt),
                recipientAccount: $ownAccount,
                counterpartyName: $details,
                constantSymbol: $constantSymbol,
                balance: $balance !== null ? $this->parseAmount($balance) : null,
            );
        }

        // Jméno protistrany končí následujícím návěstím; bez VS za účtem příjemce
        // následuje rovnou zpráva nebo zůstatek (#234).
        $nextLabel = '(?=Částka\s+v\s+měně\s+účtu|Variabilní\s+symbol|Konstantní\s+symbol|Specifický\s+symbol'
            . '|Zpráva\s+pro|Kategorie\s+pohybu|Typ\s+pohybu|Disponibilní\s+zůstatek|$)';
        $to = $this->match(
            $text,
            '/Na\s+účet\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)' . $nextLabel . '/isu',
        );
        if ($to === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel účet příjemce.');
        }
        $from = $this->match(
            $text,
            '/Z\s+účtu\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)' . $nextLabel . '/isu',
        );
        $variableSymbol = $this->optional($text, '/Variabilní\s+symbol\s*(?<value>[0-9]+)/iu') ?? '';
        $note = $this->optional($text, '/Zpráva\s+pro\s+příjemce\s*(?<value>.*?)(?:Zpráva\s+pro\s+mne|Disponibilní\s+zůstatek)/isu');

        $isOutgoing = $this->isOutgoingTransfer($text, $amount);
        if ($isOutgoing && $from === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel vlastní účet plátce.');
        }
        $ownAccount = (string) ($isOutgoing ? $from['account'] : $to['account']);
        $counterparty = $isOutgoing ? $to : $from;
        [$counterpartyAccount, $counterpartyBank] = $this->splitAccount((string) ($counterparty['account'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $variableSymbol,
            amount: $amount,
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $ownAccount,
            counterpartyAccount: $counterpartyAccount,
            counterpartyBank: $counterpartyBank,
            counterpartyName: $this->cleanNullable((string) ($counterparty['name'] ?? '')),
            constantSymbol: $constantSymbol,
            message: $note,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }

    private function isCardTransaction(string $text): bool
    {
        $folded = mb_strtolower($this->foldDiacritics($text));
        return str_contains($folded, 'odchozi karetni transakce')
            && str_contains($folded, 'debetni karta')
            && str_contains($folded, 'platba kartou');
    }

    private function isOutgoingTransfer(string $text, float $amount): bool
    {
        $folded = mb_strtolower($this->foldDiacritics($text));
        if (preg_match(
            '/na\s+ucte\s+byla\s+provedena\s+nasledujici\s+(?<direction>prichozi|odchozi)\s+platba/u',
            $folded,
            $match,
        ) === 1) {
            return $match['direction'] === 'odchozi';
        }

        return $amount < 0;
    }

}

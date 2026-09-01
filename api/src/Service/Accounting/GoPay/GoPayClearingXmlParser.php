<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class GoPayClearingXmlParser
{
    private const NAMESPACE = 'https://www.gopay.cz/clearing';
    private const MAX_BYTES = 2_097_152;

    /**
     * @return array{
     *   clearing_id:string,account_name:string,currency:string,variable_symbol:string,
     *   cleared_from:string,cleared_to:string,performed_on:string,
     *   amount_gross:string,amount_credit_note:string,amount_fee:string,
     *   amount_fee_external:string,amount_storno:string,amount_storno_fee:string,
     *   amount_transfer:string,amount_sent:string,
     *   movements:list<array<string,?string>>
     * }
     */
    public function parse(string $xml): array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new GoPayException('invalid_file_size', 'GoPay XML je prázdné nebo překračuje limit 2 MB.');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new GoPayException('unsafe_xml', 'GoPay XML obsahuje zakázanou DTD nebo entitu.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument();
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
                throw new GoPayException('invalid_xml', 'Soubor není platné XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $dom->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'clearing' || $root->namespaceURI !== self::NAMESPACE) {
            throw new GoPayException('invalid_root', 'Soubor není GoPay clearing XML.');
        }

        $accountName = $this->required($root, 'accountName');
        if (preg_match('/(?:^|\s)([A-Z]{3})$/', trim($accountName), $currencyMatch) !== 1) {
            throw new GoPayException('currency_unknown', 'Z názvu GoPay účtu nelze bezpečně určit měnu.');
        }

        $data = [
            'clearing_id'         => $this->token($root, 'clearingId', 40),
            'account_name'        => mb_substr($accountName, 0, 190),
            'currency'            => $currencyMatch[1],
            'variable_symbol'     => $this->digits($root, 'variableSymbol', 20),
            'cleared_from'        => $this->date($root, 'dateClearedFrom'),
            'cleared_to'          => $this->date($root, 'dateClearedTo'),
            'performed_on'        => $this->date($root, 'datePerformed'),
            'amount_gross'        => $this->money($root, 'amount'),
            'amount_credit_note'  => $this->money($root, 'amountCreditNote'),
            'amount_fee'          => $this->money($root, 'amountFee'),
            'amount_fee_external' => $this->money($root, 'amountFeeExternal'),
            'amount_storno'       => $this->money($root, 'amountStorno'),
            'amount_storno_fee'   => $this->money($root, 'amountStornoFee'),
            'amount_transfer'     => $this->money($root, 'amountTransfer'),
            'amount_sent'         => $this->money($root, 'amountSent'),
        ];

        foreach (['amount_gross', 'amount_credit_note', 'amount_fee', 'amount_fee_external', 'amount_storno', 'amount_storno_fee', 'amount_transfer', 'amount_sent'] as $field) {
            if ($this->cents($data[$field]) < 0) {
                throw new GoPayException('invalid_amount', 'Souhrnná částka ' . $field . ' nesmí být záporná.');
            }
        }
        if ($this->cents($data['amount_fee_external']) > $this->cents($data['amount_fee'])) {
            throw new GoPayException('invalid_fee_summary', 'Externí poplatek je vyšší než celkový poplatek.');
        }

        $expectedTransfer = $this->cents($data['amount_gross'])
            - $this->cents($data['amount_storno'])
            - $this->cents($data['amount_storno_fee'])
            - $this->cents($data['amount_fee'])
            + $this->cents($data['amount_credit_note']);
        if ($expectedTransfer !== $this->cents($data['amount_transfer'])) {
            throw new GoPayException('summary_mismatch', 'Souhrn GoPay XML neodpovídá částce clearingu.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('g', self::NAMESPACE);
        $movements = [];
        $externalIds = [];
        $creditCents = 0;
        foreach ($xpath->query('/g:clearing/g:paymentChannel') ?: [] as $channel) {
            if (!$channel instanceof DOMElement) {
                continue;
            }
            $channelType = $this->token($channel, 'type', 40);
            foreach ($xpath->query('./g:movements/g:movement', $channel) ?: [] as $node) {
                if (!$node instanceof DOMElement || $this->required($node, 'type') !== 'credit') {
                    throw new GoPayException('unsupported_movement', 'XML obsahuje nepodporovaný platební pohyb.');
                }
                $amount = $this->money($node, 'amount', true);
                if ($this->cents($amount) <= 0) {
                    throw new GoPayException('invalid_movement_amount', 'Přijatá platba musí mít kladnou částku.');
                }
                $creditCents += $this->cents($amount);
                $movements[] = $this->movement($node, 'credit', $amount, $channelType, $externalIds);
            }
        }

        $stornoCents = 0;
        $stornoFeeCents = 0;
        foreach ($xpath->query('/g:clearing/g:storno/g:stornoMovement') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $rawType = $this->required($node, 'type');
            $type = match ($rawType) {
                'storno' => 'storno',
                'stornoFee' => 'storno_fee',
                default => throw new GoPayException('unsupported_movement', 'XML obsahuje nepodporovaný storno pohyb.'),
            };
            $amount = $this->money($node, 'amount', true);
            $amountCents = $this->cents($amount);
            if ($amountCents >= 0) {
                throw new GoPayException('invalid_movement_amount', 'Vratka a její poplatek musí mít zápornou částku.');
            }
            if ($type === 'storno') {
                $stornoCents += abs($amountCents);
            } else {
                $stornoFeeCents += abs($amountCents);
            }
            $movements[] = $this->movement($node, $type, $amount, null, $externalIds);
        }

        if ($creditCents !== $this->cents($data['amount_gross'])
            || $stornoCents !== $this->cents($data['amount_storno'])
            || $stornoFeeCents !== $this->cents($data['amount_storno_fee'])) {
            throw new GoPayException('movement_summary_mismatch', 'Součet pohybů neodpovídá souhrnu GoPay XML.');
        }

        $this->appendSynthetic($movements, $externalIds, $data, 'clearing_fee', -$this->cents($data['amount_fee']));
        $this->appendSynthetic($movements, $externalIds, $data, 'fee_credit', $this->cents($data['amount_credit_note']));
        $this->appendSynthetic($movements, $externalIds, $data, 'payout', -$this->cents($data['amount_sent']));

        return $data + ['movements' => $movements];
    }

    /** @param array<string,true> $externalIds @return array<string,?string> */
    private function movement(DOMElement $node, string $type, string $amount, ?string $channel, array &$externalIds): array
    {
        $externalId = $this->token($node, 'accountMovementId', 40);
        if (isset($externalIds[$externalId])) {
            throw new GoPayException('duplicate_movement_id', 'XML obsahuje duplicitní accountMovementId.');
        }
        $externalIds[$externalId] = true;

        return [
            'external_id'         => $externalId,
            'movement_type'       => $type,
            'performed_on'        => $this->date($node, 'datePerformed'),
            'amount'              => $amount,
            'order_id'            => $this->optional($node, 'orderId', 80, true),
            'payment_session_id'  => $this->optional($node, 'paymentSessionId', 40, true),
            'account_movement_id' => $externalId,
            'payment_channel'     => $channel,
            'counterparty_name'   => $this->optional($node, 'counterpartyName', 190),
        ];
    }

    /** @param list<array<string,?string>> $movements @param array<string,true> $externalIds @param array<string,string> $data */
    private function appendSynthetic(array &$movements, array &$externalIds, array $data, string $type, int $amountCents): void
    {
        if ($amountCents === 0) {
            return;
        }
        $externalId = 'clearing:' . $data['clearing_id'] . ':' . $type;
        if (isset($externalIds[$externalId])) {
            throw new GoPayException('duplicate_movement_id', 'XML vytváří duplicitní identifikátor pohybu.');
        }
        $externalIds[$externalId] = true;
        $movements[] = [
            'external_id'         => $externalId,
            'movement_type'       => $type,
            'performed_on'        => $data['performed_on'],
            'amount'              => $this->decimal($amountCents),
            'order_id'            => null,
            'payment_session_id'  => null,
            'account_movement_id' => null,
            'payment_channel'     => null,
            'counterparty_name'   => 'GoPay',
        ];
    }

    private function required(DOMElement $node, string $name): string
    {
        $value = trim($node->getAttribute($name));
        if ($value === '') {
            throw new GoPayException('missing_attribute', 'V GoPay XML chybí atribut ' . $name . '.');
        }
        return $value;
    }

    private function token(DOMElement $node, string $name, int $maxLength): string
    {
        $value = $this->required($node, $name);
        if (mb_strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new GoPayException('invalid_attribute', 'Atribut ' . $name . ' má neplatný formát.');
        }
        return $value;
    }

    private function digits(DOMElement $node, string $name, int $maxLength): string
    {
        $value = $this->required($node, $name);
        if (strlen($value) > $maxLength || preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new GoPayException('invalid_attribute', 'Atribut ' . $name . ' musí obsahovat pouze číslice.');
        }
        return $value;
    }

    private function optional(DOMElement $node, string $name, int $maxLength, bool $token = false): ?string
    {
        $value = trim($node->getAttribute($name));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength
            || ($token && preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1)
            || (!$token && preg_match('/[\x00-\x1f\x7f]/u', $value) === 1)) {
            throw new GoPayException('invalid_attribute', 'Atribut ' . $name . ' má neplatný formát.');
        }
        return $value;
    }

    private function date(DOMElement $node, string $name): string
    {
        $value = $this->required($node, $name);
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if ($date === false || $date->format('d.m.Y') !== $value) {
            throw new GoPayException('invalid_date', 'Atribut ' . $name . ' nemá platné datum.');
        }
        return $date->format('Y-m-d');
    }

    private function money(DOMElement $node, string $name, bool $signed = false): string
    {
        $value = $this->required($node, $name);
        $pattern = $signed ? '/^-?[0-9]+\.[0-9]{2}$/' : '/^[0-9]+\.[0-9]{2}$/';
        if (preg_match($pattern, $value) !== 1) {
            throw new GoPayException('invalid_amount', 'Atribut ' . $name . ' nemá platnou částku.');
        }
        $this->cents($value);
        return $value;
    }

    private function cents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $plain = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = explode('.', $plain, 2);
        if (strlen($whole) > 12) {
            throw new GoPayException('amount_too_large', 'Částka v GoPay XML je příliš vysoká.');
        }
        $cents = ((int) $whole * 100) + (int) $fraction;
        return $negative ? -$cents : $cents;
    }

    private function decimal(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        return ($negative ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}

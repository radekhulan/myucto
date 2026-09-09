<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use Symfony\Component\Mime\Address;

final readonly class SenderAddress
{
    private function __construct(
        public string $address,
        public string $domain,
    ) {}

    public static function parse(string $sender): ?self
    {
        $sender = trim($sender);
        if ($sender === '' || preg_match('/[\x00-\x1F\x7F]/', $sender) === 1) {
            return null;
        }

        $address = $sender;
        if (str_contains($sender, '<') || str_contains($sender, '>')) {
            if (preg_match('/^(?:(?:"(?:[^"\\\\]|\\\\.)*"|[^<>"\r\n]+)\s*)?<(?<address>[^<>\r\n]+)>$/u', $sender, $match) !== 1) {
                return null;
            }
            $address = trim($match['address']);
        }

        if (substr_count($address, '@') !== 1) {
            return null;
        }

        try {
            $validated = new Address($address);
        } catch (\Throwable) {
            return null;
        }

        $address = mb_strtolower($validated->getAddress(), 'UTF-8');
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }
        $domain = rtrim(substr($address, $at + 1), '.');
        if ($domain === '') {
            return null;
        }

        return new self($address, $domain);
    }
}

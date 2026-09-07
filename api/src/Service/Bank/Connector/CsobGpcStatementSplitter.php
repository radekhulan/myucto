<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\GpcParser;

final class CsobGpcStatementSplitter
{
    public function __construct(private readonly GpcParser $parser) {}

    /** @return list<array{content:string,parsed:array}> */
    public function split(#[\SensitiveParameter] string $content): array
    {
        if ($content === '' || strlen($content) > 10 * 1024 * 1024
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1
            || !str_starts_with($content, '074') || preg_match('/\r(?!\n)/', $content) === 1) {
            throw $this->invalid();
        }
        $blocks = preg_split('/(?<=\n)(?=074)/', $content);
        if (!is_array($blocks) || count($blocks) > 500) throw $this->invalid();
        $result = [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\r\n|\n/', $block);
            if (!is_array($lines)) throw $this->invalid();
            if (end($lines) === '') array_pop($lines);
            $account = null;
            $hasTransaction = false;
            foreach ($lines as $index => $line) {
                $type = substr($line, 0, 3);
                if (strlen($line) < 127 || strlen($line) > 128) throw $this->invalid();
                if ($index === 0) {
                    if ($type !== '074' || preg_match('/^\d{16}$/D', substr($line, 3, 16)) !== 1
                        || trim(substr($line, 3, 16), '0') === '') throw $this->invalid();
                    $account = substr($line, 3, 16);
                    foreach ([45, 60, 75, 90] as $offset) {
                        if (preg_match('/^\d{14}$/D', substr($line, $offset, 14)) !== 1) throw $this->invalid();
                    }
                    if (!in_array($line[59], ['+', '-'], true) || !in_array($line[74], ['+', '-'], true)
                        || $line[89] !== '0' || $line[104] !== '0'
                        || preg_match('/^\d{3}$/D', substr($line, 105, 3)) !== 1) throw $this->invalid();
                    $this->date(substr($line, 39, 6));
                    $this->date(substr($line, 108, 6));
                    continue;
                }
                if ($type === '075') {
                    if (strlen($line) !== 128 || substr($line, 3, 16) !== $account
                        || preg_match('/^\d{41}$/D', substr($line, 19, 41)) !== 1
                        || !in_array($line[60], ['1', '2', '4', '5'], true)
                        || preg_match('/^\d{30}$/D', substr($line, 61, 30)) !== 1
                        || preg_match('/^\d{5}$/D', substr($line, 117, 5)) !== 1) throw $this->invalid();
                    $this->date(substr($line, 91, 6));
                    $this->date(substr($line, 122, 6));
                    $hasTransaction = true;
                } elseif (!in_array($type, ['076', '078', '079'], true) || !$hasTransaction) {
                    throw $this->invalid();
                }
            }
            if (!$hasTransaction) throw $this->invalid();
            try {
                $parsed = $this->parser->parse($block);
            } catch (\Throwable) {
                throw $this->invalid();
            }
            $currency = null;
            foreach ($parsed['transactions'] as $transaction) {
                $actual = $transaction['currency'] ?? null;
                if (!is_string($actual) || ($currency !== null && $actual !== $currency)) throw $this->invalid();
                $currency = $actual;
            }
            if ($currency === null) throw $this->invalid();
            $parsed['header']['currency'] = $currency;
            $result[] = ['content' => $block, 'parsed' => $parsed];
        }
        return $result;
    }

    private function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!dmy', $value);
        if ($date === false || $date->format('dmy') !== $value) throw $this->invalid();
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException('statement_invalid', 'Bankovní výpis nemá platný formát ČSOB GPC.');
    }
}

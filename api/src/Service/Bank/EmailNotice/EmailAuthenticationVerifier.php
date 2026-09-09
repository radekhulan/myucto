<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Vyhodnotí výsledek DKIM/DMARC, který už provedl přijímací mailserver.
 *
 * Samotná přítomnost `Authentication-Results` nic nedokazuje. Hodnotí se jen
 * první hlavička s přesně připnutým authserv-id přijímacího serveru. Přijímací
 * server musí při vstupu do své trust domény odstranit podvržené hlavičky se
 * svým authserv-id a vlastní výsledek vložit navrch (RFC 8601).
 */
final class EmailAuthenticationVerifier
{
    /**
     * @param list<string> $authResults
     * @return array{checked:bool, pass:bool, detail:string}
     */
    public function verify(array $authResults, ?string $expectedDomain, ?string $trustedAuthServId = null): array
    {
        if ($authResults === []) {
            return ['checked' => false, 'pass' => false, 'detail' => 'no_authentication_results'];
        }

        $trustedAuthServId = $trustedAuthServId !== null ? trim($trustedAuthServId) : '';
        if ($trustedAuthServId === '') {
            return ['checked' => true, 'pass' => false, 'detail' => 'authserv_id_required'];
        }

        $line = trim($authResults[0]);
        $authServId = $this->authServId($line);
        if ($authServId === null || strcasecmp($authServId, $trustedAuthServId) !== 0) {
            return ['checked' => true, 'pass' => false, 'detail' => 'authserv_id_mismatch'];
        }

        if ($expectedDomain === null || trim($expectedDomain) === '') {
            return ['checked' => true, 'pass' => false, 'detail' => 'sender_domain_missing'];
        }

        $dmarcFailure = null;
        $dkimPassed = false;
        foreach ($this->resultSegments($line) as $segment) {
            $parsed = $this->methodResult($segment);
            if ($parsed === null || $parsed['result'] !== 'pass') {
                continue;
            }

            if ($parsed['method'] === 'dmarc') {
                $domain = $this->property($parsed['rest'], 'header.from');
                if ($domain === null || $domain === '') {
                    $dmarcFailure = 'dmarc_domain_missing';
                    continue;
                }
                if ($this->domainEquals($domain, $expectedDomain)) {
                    return ['checked' => true, 'pass' => true, 'detail' => 'dmarc_pass_aligned'];
                }
                $dmarcFailure = 'dmarc_domain_mismatch';
                continue;
            }

            if ($parsed['method'] === 'dkim') {
                $dkimPassed = true;
                $domain = $this->property($parsed['rest'], 'header.d');
                if ($domain !== null && $this->domainAligns($domain, $expectedDomain)) {
                    return ['checked' => true, 'pass' => true, 'detail' => 'dkim_pass_aligned'];
                }
            }
        }

        if ($dmarcFailure !== null) {
            return ['checked' => true, 'pass' => false, 'detail' => $dmarcFailure];
        }
        if ($dkimPassed) {
            return ['checked' => true, 'pass' => false, 'detail' => 'dkim_domain_mismatch'];
        }

        return ['checked' => true, 'pass' => false, 'detail' => 'auth_failed'];
    }

    public function domainFromSender(string $sender): ?string
    {
        return SenderAddress::parse($sender)?->domain;
    }

    private function authServId(string $line): ?string
    {
        $segments = $this->splitTopLevel($line);
        if ($segments === []) {
            return null;
        }
        $prefix = trim($this->withoutComments($segments[0]));
        if (preg_match('/^(?<id>[!#$%&\'*+\-.^_`|~0-9A-Za-z:]+)(?:\s+1)?$/D', $prefix, $match) !== 1) {
            return null;
        }
        return $match['id'];
    }

    /**
     * @return list<string>
     */
    private function resultSegments(string $line): array
    {
        $segments = $this->splitTopLevel($line);
        array_shift($segments);
        return $segments;
    }

    /**
     * @return array{method:string,result:string,rest:string}|null
     */
    private function methodResult(string $segment): ?array
    {
        $segment = trim($this->withoutComments($segment));
        if (preg_match(
            '/^(?<method>[a-z][a-z0-9_-]*)(?:\s*\/\s*\d+)?\s*=\s*(?<result>[a-z][a-z0-9_-]*)\b(?<rest>.*)$/iD',
            $segment,
            $match,
        ) !== 1) {
            return null;
        }

        return [
            'method' => strtolower($match['method']),
            'result' => strtolower($match['result']),
            'rest' => $match['rest'],
        ];
    }

    private function property(string $text, string $name): ?string
    {
        $length = strlen($text);
        $nameLength = strlen($name);
        $quoted = false;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quoted && $char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted || ($i > 0 && !ctype_space($text[$i - 1]))) {
                continue;
            }
            if (strncasecmp(substr($text, $i, $nameLength), $name, $nameLength) !== 0) {
                continue;
            }

            $cursor = $i + $nameLength;
            while ($cursor < $length && ctype_space($text[$cursor])) {
                ++$cursor;
            }
            if ($cursor >= $length || $text[$cursor] !== '=') {
                continue;
            }
            ++$cursor;
            while ($cursor < $length && ctype_space($text[$cursor])) {
                ++$cursor;
            }
            if ($cursor >= $length) {
                return '';
            }

            if ($text[$cursor] === '"') {
                return $this->quotedValue($text, $cursor + 1);
            }

            $end = $cursor;
            while ($end < $length && !ctype_space($text[$end]) && !str_contains('();', $text[$end])) {
                ++$end;
            }
            return strtolower(rtrim(substr($text, $cursor, $end - $cursor), '.'));
        }

        return null;
    }

    private function quotedValue(string $text, int $offset): string
    {
        $value = '';
        $length = strlen($text);
        $escaped = false;
        for ($i = $offset; $i < $length; ++$i) {
            $char = $text[$i];
            if ($escaped) {
                $value .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                return strtolower(rtrim(trim($value), '.'));
            }
            $value .= $char;
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $value): array
    {
        $segments = [];
        $start = 0;
        $length = strlen($value);
        $commentDepth = 0;
        $quoted = false;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($commentDepth > 0) {
                if ($char === '(') {
                    ++$commentDepth;
                } elseif ($char === ')') {
                    --$commentDepth;
                }
                continue;
            }
            if (!$quoted && $char === '(') {
                $commentDepth = 1;
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                continue;
            }
            if (!$quoted && $char === ';') {
                $segments[] = substr($value, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $segments[] = substr($value, $start);
        return $segments;
    }

    private function withoutComments(string $value): string
    {
        $out = '';
        $length = strlen($value);
        $commentDepth = 0;
        $quoted = false;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($escaped) {
                if ($commentDepth === 0) {
                    $out .= $char;
                }
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                if ($commentDepth === 0) {
                    $out .= $char;
                }
                $escaped = true;
                continue;
            }
            if (!$quoted && $char === '(') {
                ++$commentDepth;
                continue;
            }
            if (!$quoted && $commentDepth > 0 && $char === ')') {
                --$commentDepth;
                continue;
            }
            if ($commentDepth > 0) {
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
            }
            $out .= $char;
        }

        return $out;
    }

    private function domainAligns(string $domain, string $expected): bool
    {
        $domain = strtolower(ltrim(rtrim($domain, '.'), '.'));
        $expected = strtolower(ltrim(rtrim($expected, '.'), '.'));
        if ($domain === '' || $expected === '') {
            return false;
        }
        return $domain === $expected
            || str_ends_with($domain, '.' . $expected)
            || str_ends_with($expected, '.' . $domain);
    }

    private function domainEquals(string $domain, string $expected): bool
    {
        return strtolower(rtrim(trim($domain), '.')) === strtolower(rtrim(trim($expected), '.'));
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\RecursiveItems;

final class CanonicalJsonSpool
{
    private const NODE_RECORD_BYTES = 24;
    private const COPY_BYTES = 1_048_576;

    private int $nodeCount = 0;
    private int $rawMemberCount = 0;

    /** @param iterable<string> $chunks @param resource|null $output */
    public function canonicalize(iterable $chunks, CanonicalJsonProfile $profile, $output = null): CanonicalJsonResult
    {
        if ($output !== null && !is_resource($output)) throw new \InvalidArgumentException('Výstup canonical JSON musí být stream.');
        $directory = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'myucto-json-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700) && !is_dir($directory)) throw new \RuntimeException('Dočasný JSON spool nelze vytvořit.');
        $paths = [
            'values' => $directory . DIRECTORY_SEPARATOR . 'values.bin',
            'nodes' => $directory . DIRECTORY_SEPARATOR . 'nodes.bin',
            'raw' => $directory . DIRECTORY_SEPARATOR . 'members.raw',
            'a' => $directory . DIRECTORY_SEPARATOR . 'members.a',
            'b' => $directory . DIRECTORY_SEPARATOR . 'members.b',
        ];

        $values = $raw = $nodes = null;
        try {
            $values = fopen($paths['values'], 'w+b');
            $raw = fopen($paths['raw'], 'w+b');
            if (!is_resource($values) || !is_resource($raw)) throw new \RuntimeException('Dočasný JSON spool nelze otevřít.');
            $root = RecursiveItems::fromIterable($chunks, ['decoder' => new ExtJsonDecoder(true, 512, 0)]);
            $rootId = $this->captureNode($root, $profile, $values, $raw, 1);
            if ($rootId !== 0) throw new \LogicException('Kořen JSON spoolu nemá očekávanou identitu.');
            fclose($raw);
            $raw = null;

            $nodes = fopen($paths['nodes'], 'w+b');
            if (!is_resource($nodes)) throw new \RuntimeException('Dočasný JSON spool nelze otevřít.');
            $this->initializeNodes($nodes);

            $sorted = CanonicalJsonRecordTape::sort($paths['raw'], $paths['a'], $paths['b'], self::compareIdentity(...));
            $this->deduplicate($sorted, $paths['raw']);
            $sorted = CanonicalJsonRecordTape::sort($paths['raw'], $paths['a'], $paths['b'], self::compareInsertion(...));
            $members = $this->markOriginalLists($sorted, $paths['raw'], $nodes);
            $sorted = CanonicalJsonRecordTape::sort($paths['raw'], $paths['a'], $paths['b'], self::compareCanonical(...));
            $this->indexCanonicalNodes($sorted, $nodes);

            $hash = hash_init('sha256');
            $bytes = 0;
            $memberTape = fopen($sorted, 'rb');
            if (!is_resource($memberTape)) throw new \RuntimeException('Dočasný JSON spool nelze otevřít.');
            try {
                $runCount = $this->readRunCount($memberTape);
                if ($runCount !== $members) throw new \RuntimeException('Poškozený JSON spool.');
                $this->emitNode(0, $profile, $nodes, $memberTape, $values, $hash, $output, $bytes, 1);
            } finally {
                fclose($memberTape);
            }

            return new CanonicalJsonResult(hash_final($hash), $bytes, $this->nodeCount, $members);
        } finally {
            foreach ([$raw, $values, $nodes] as $handle) if (is_resource($handle)) fclose($handle);
            foreach ($paths as $path) if (is_file($path)) unlink($path);
            if (is_dir($directory)) rmdir($directory);
            $this->nodeCount = 0;
            $this->rawMemberCount = 0;
        }
    }

    /** @param resource $values @param resource $raw */
    private function captureNode(RecursiveItems $items, CanonicalJsonProfile $profile, $values, $raw, int $depth): int
    {
        if ($depth > 512) throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        $nodeId = $this->nodeCount++;
        $ordinal = 0;
        foreach ($items as $key => $value) {
            $key = self::normalizeKey($key);
            $record = ['n' => $nodeId, 'k' => $key, 'i' => is_int($key) ? 0 : 1, 'o' => $ordinal++];
            if ($value instanceof RecursiveItems) {
                $record['t'] = 1;
                $record['a'] = $this->captureNode($value, $profile, $values, $raw, $depth + 1);
                $record['b'] = 0;
            } else {
                $encoded = json_encode($value, $profile->flags(), 512);
                $offset = ftell($values);
                if (!is_int($offset)) throw new \RuntimeException('Dočasný JSON spool nelze číst.');
                self::writeAll($values, $encoded);
                $record['t'] = 0;
                $record['a'] = $offset;
                $record['b'] = strlen($encoded);
            }
            CanonicalJsonRecordTape::writeRecord($raw, $record);
            $this->rawMemberCount++;
        }
        return $nodeId;
    }

    /** @param resource $nodes */
    private function initializeNodes($nodes): void
    {
        $record = self::packNode(0, 0, true, true);
        $chunk = str_repeat($record, 4096);
        $remaining = $this->nodeCount;
        while ($remaining >= 4096) {
            self::writeAll($nodes, $chunk);
            $remaining -= 4096;
        }
        if ($remaining > 0) self::writeAll($nodes, str_repeat($record, $remaining));
    }

    private function deduplicate(string $sorted, string $raw): void
    {
        $output = fopen($raw, 'w+b');
        if (!is_resource($output)) throw new \RuntimeException('Dočasný JSON spool nelze otevřít.');
        $current = null;
        try {
            foreach (CanonicalJsonRecordTape::singleRun($sorted) as $entry) {
                $record = $entry['record'];
                if ($current === null || !self::sameIdentity($current, $record)) {
                    if ($current !== null) CanonicalJsonRecordTape::writeRecord($output, $current);
                    $current = $record;
                    continue;
                }
                $record['o'] = $current['o'];
                $current = $record;
            }
            if ($current !== null) CanonicalJsonRecordTape::writeRecord($output, $current);
        } finally {
            fclose($output);
        }
    }

    /** @param resource $nodes */
    private function markOriginalLists(string $sorted, string $raw, $nodes): int
    {
        $nodeId = null;
        $expected = 0;
        $isList = true;
        $members = 0;
        foreach (CanonicalJsonRecordTape::singleRun($sorted) as $entry) {
            $record = $entry['record'];
            if ($nodeId !== $record['n']) {
                if ($nodeId !== null) $this->setNodeOriginalList($nodes, $nodeId, $isList);
                $nodeId = $record['n'];
                $expected = 0;
                $isList = true;
            }
            if (!is_int($record['k']) || $record['k'] !== $expected) $isList = false;
            $expected++;
            $members++;
        }
        if ($nodeId !== null) $this->setNodeOriginalList($nodes, $nodeId, $isList);

        $input = CanonicalJsonRecordTape::singleRun($sorted);
        $output = fopen($raw, 'w+b');
        if (!is_resource($output)) throw new \RuntimeException('Dočasný JSON spool nelze otevřít.');
        $nodeId = null;
        $isList = true;
        try {
            foreach ($input as $entry) {
                $record = $entry['record'];
                if ($nodeId !== $record['n']) {
                    $nodeId = $record['n'];
                    $isList = $this->readNode($nodes, $nodeId)['original'];
                }
                $record['l'] = $isList ? 1 : 0;
                CanonicalJsonRecordTape::writeRecord($output, $record);
            }
        } finally {
            fclose($output);
        }
        return $members;
    }

    /** @param resource $nodes */
    private function indexCanonicalNodes(string $sorted, $nodes): void
    {
        $nodeId = null;
        $offset = 0;
        $count = 0;
        $expected = 0;
        $canonicalList = true;
        foreach (CanonicalJsonRecordTape::singleRun($sorted) as $entry) {
            $record = $entry['record'];
            if ($nodeId !== $record['n']) {
                if ($nodeId !== null) $this->writeNode($nodes, $nodeId, $offset, $count, $canonicalList);
                $nodeId = $record['n'];
                $offset = $entry['offset'];
                $count = 0;
                $expected = 0;
                $canonicalList = true;
            }
            if (!is_int($record['k']) || $record['k'] !== $expected) $canonicalList = false;
            $expected++;
            $count++;
        }
        if ($nodeId !== null) $this->writeNode($nodes, $nodeId, $offset, $count, $canonicalList);
    }

    /** @param resource $nodes @param resource $memberTape @param resource $values @param resource|false|null $output */
    private function emitNode(int $nodeId, CanonicalJsonProfile $profile, $nodes, $memberTape, $values, $hash, $output, int &$bytes, int $depth): void
    {
        if ($depth > 512) throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        $node = $this->readNode($nodes, $nodeId);
        $this->emit($node['canonical'] ? '[' : '{', $hash, $output, $bytes);
        if ($node['count'] > 0 && fseek($memberTape, $node['offset']) !== 0) throw new \RuntimeException('Poškozený JSON spool.');
        for ($index = 0; $index < $node['count']; $index++) {
            $record = CanonicalJsonRecordTape::readRecord($memberTape);
            if ($record === null || $record['n'] !== $nodeId) throw new \RuntimeException('Poškozený JSON spool.');
            $next = ftell($memberTape);
            if (!is_int($next)) throw new \RuntimeException('Poškozený JSON spool.');
            if ($index > 0) $this->emit(',', $hash, $output, $bytes);
            if (!$node['canonical']) {
                $this->emit(json_encode((string) $record['k'], $profile->flags(), 512) . ':', $hash, $output, $bytes);
            }
            if ($record['t'] === 1) {
                $this->emitNode((int) $record['a'], $profile, $nodes, $memberTape, $values, $hash, $output, $bytes, $depth + 1);
                if (fseek($memberTape, $next) !== 0) throw new \RuntimeException('Poškozený JSON spool.');
            } else {
                $this->emitValue($values, (int) $record['a'], (int) $record['b'], $hash, $output, $bytes);
            }
        }
        $this->emit($node['canonical'] ? ']' : '}', $hash, $output, $bytes);
    }

    /** @param resource $values @param resource|false|null $output */
    private function emitValue($values, int $offset, int $length, $hash, $output, int &$bytes): void
    {
        if (fseek($values, $offset) !== 0) throw new \RuntimeException('Poškozený JSON spool.');
        while ($length > 0) {
            $part = fread($values, min(self::COPY_BYTES, $length));
            if (!is_string($part) || $part === '') throw new \RuntimeException('Poškozený JSON spool.');
            $this->emit($part, $hash, $output, $bytes);
            $length -= strlen($part);
        }
    }

    /** @param resource|false|null $output */
    private function emit(string $bytesOut, $hash, $output, int &$bytes): void
    {
        hash_update($hash, $bytesOut);
        $bytes += strlen($bytesOut);
        if (is_resource($output)) self::writeAll($output, $bytesOut);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareIdentity(array $left, array $right): int
    {
        return ($left['n'] <=> $right['n'])
            ?: ($left['i'] <=> $right['i'])
            ?: ($left['i'] === 0 ? ($left['k'] <=> $right['k']) : strcmp($left['k'], $right['k']))
            ?: ($left['o'] <=> $right['o']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareInsertion(array $left, array $right): int
    {
        return ($left['n'] <=> $right['n']) ?: ($left['o'] <=> $right['o']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareCanonical(array $left, array $right): int
    {
        $node = $left['n'] <=> $right['n'];
        if ($node !== 0) return $node;
        if ($left['l'] === 1) return $left['o'] <=> $right['o'];
        return strcmp((string) $left['k'], (string) $right['k']) ?: ($left['o'] <=> $right['o']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameIdentity(array $left, array $right): bool
    {
        return $left['n'] === $right['n'] && $left['i'] === $right['i'] && $left['k'] === $right['k'];
    }

    private static function normalizeKey(mixed $key): int|string
    {
        if (!is_int($key) && !is_string($key)) throw new \InvalidArgumentException('Canonical JSON obsahuje neplatný klíč.');
        $probe = [];
        $probe[$key] = true;
        return array_key_first($probe);
    }

    /** @param resource $nodes */
    private function setNodeOriginalList($nodes, int $nodeId, bool $isList): void
    {
        $node = $this->readNode($nodes, $nodeId);
        $this->writeNodeRecord($nodes, $nodeId, self::packNode($node['offset'], $node['count'], $isList, $node['canonical']));
    }

    /** @param resource $nodes */
    private function writeNode($nodes, int $nodeId, int $offset, int $count, bool $canonical): void
    {
        $node = $this->readNode($nodes, $nodeId);
        $this->writeNodeRecord($nodes, $nodeId, self::packNode($offset, $count, $node['original'], $canonical));
    }

    /** @param resource $nodes @return array{offset:int,count:int,original:bool,canonical:bool} */
    private function readNode($nodes, int $nodeId): array
    {
        if ($nodeId < 0 || $nodeId >= $this->nodeCount || fseek($nodes, $nodeId * self::NODE_RECORD_BYTES) !== 0) {
            throw new \RuntimeException('Poškozený JSON spool.');
        }
        $record = self::readExact($nodes, self::NODE_RECORD_BYTES);
        $unpacked = unpack('Joffset/Jcount/Coriginal/Ccanonical', $record);
        return [
            'offset' => (int) $unpacked['offset'],
            'count' => (int) $unpacked['count'],
            'original' => $unpacked['original'] === 1,
            'canonical' => $unpacked['canonical'] === 1,
        ];
    }

    /** @param resource $nodes */
    private function writeNodeRecord($nodes, int $nodeId, string $record): void
    {
        if (fseek($nodes, $nodeId * self::NODE_RECORD_BYTES) !== 0) throw new \RuntimeException('Poškozený JSON spool.');
        self::writeAll($nodes, $record);
    }

    private static function packNode(int $offset, int $count, bool $original, bool $canonical): string
    {
        return pack('J2CCxxxxxx', $offset, $count, $original ? 1 : 0, $canonical ? 1 : 0);
    }

    /** @param resource $memberTape */
    private function readRunCount($memberTape): int
    {
        $header = fread($memberTape, 8);
        if ($header === '' && feof($memberTape)) return 0;
        if (!is_string($header) || strlen($header) !== 8) throw new \RuntimeException('Poškozený JSON spool.');
        return (int) unpack('Jcount', $header)['count'];
    }

    /** @param resource $handle */
    private static function readExact($handle, int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $part = fread($handle, $length - strlen($out));
            if (!is_string($part) || $part === '') throw new \RuntimeException('Poškozený JSON spool.');
            $out .= $part;
        }
        return $out;
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) throw new \RuntimeException('Dočasný JSON spool nelze zapsat.');
            $offset += $written;
        }
    }
}

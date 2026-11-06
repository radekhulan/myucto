<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

final class CanonicalJsonRecordTape
{
    private const RUN_ITEMS = 2048;
    private const RUN_BYTES = 2_097_152;
    private const MERGE_FAN_IN = 8;

    /** @param resource $handle @param array<string,mixed> $record */
    public static function writeRecord($handle, array $record): void
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::writeAll($handle, pack('N', strlen($json)) . $json);
    }

    /** @param resource $handle @return array<string,mixed>|null */
    public static function readRecord($handle): ?array
    {
        $header = fread($handle, 4);
        if ($header === '' && feof($handle)) return null;
        if (!is_string($header) || strlen($header) !== 4) throw new \RuntimeException('Poškozený JSON spool.');
        $length = unpack('Nlength', $header)['length'];
        $json = self::readExact($handle, $length);
        $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record)) throw new \RuntimeException('Poškozený JSON spool.');
        return $record;
    }

    /** @param callable(array<string,mixed>,array<string,mixed>):int $compare */
    public static function sort(string $raw, string $tapeA, string $tapeB, callable $compare): string
    {
        $input = fopen($raw, 'rb');
        $output = fopen($tapeA, 'w+b');
        if (!is_resource($input) || !is_resource($output)) throw new \RuntimeException('JSON spool nelze otevřít.');
        $runs = 0;
        try {
            while (true) {
                $batch = [];
                $bytes = 0;
                while (count($batch) < self::RUN_ITEMS && $bytes < self::RUN_BYTES) {
                    $record = self::readRecord($input);
                    if ($record === null) break;
                    $batch[] = $record;
                    $bytes += strlen(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                if ($batch === []) break;
                usort($batch, $compare);
                self::writeRunHeader($output, count($batch));
                foreach ($batch as $record) self::writeRecord($output, $record);
                $runs++;
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($runs === 0) {
            file_put_contents($tapeA, '');
            return $tapeA;
        }

        $current = $tapeA;
        $other = $tapeB;
        while ($runs > 1) {
            $runs = self::mergePass($current, $other, $compare);
            [$current, $other] = [$other, $current];
        }
        return $current;
    }

    /** @return \Generator<int,array{record:array<string,mixed>,offset:int}> */
    public static function singleRun(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('JSON spool nelze otevřít.');
        try {
            $count = self::readRunHeader($handle);
            if ($count === null) return;
            for ($i = 0; $i < $count; $i++) {
                $offset = ftell($handle);
                $record = self::readRecord($handle);
                if ($record === null) throw new \RuntimeException('Poškozený JSON spool.');
                yield ['record' => $record, 'offset' => $offset];
            }
            if (self::readRunHeader($handle) !== null) throw new \RuntimeException('JSON spool obsahuje více než jeden běh.');
        } finally {
            fclose($handle);
        }
    }

    /** @param callable(array<string,mixed>,array<string,mixed>):int $compare */
    private static function mergePass(string $inputPath, string $outputPath, callable $compare): int
    {
        $scan = fopen($inputPath, 'rb');
        $output = fopen($outputPath, 'w+b');
        if (!is_resource($scan) || !is_resource($output)) throw new \RuntimeException('JSON spool nelze otevřít.');
        $outRuns = 0;
        try {
            while (true) {
                $group = [];
                for ($i = 0; $i < self::MERGE_FAN_IN; $i++) {
                    $count = self::readRunHeader($scan);
                    if ($count === null) break;
                    $offset = ftell($scan);
                    $group[] = ['offset' => $offset, 'count' => $count];
                    self::skipRecords($scan, $count);
                }
                if ($group === []) break;
                self::writeRunHeader($output, array_sum(array_column($group, 'count')));
                self::mergeGroup($inputPath, $group, $output, $compare);
                $outRuns++;
            }
        } finally {
            fclose($scan);
            fclose($output);
        }
        return $outRuns;
    }

    /**
     * @param list<array{offset:int,count:int}> $group
     * @param resource $output
     * @param callable(array<string,mixed>,array<string,mixed>):int $compare
     */
    private static function mergeGroup(string $path, array $group, $output, callable $compare): void
    {
        $readers = [];
        try {
            foreach ($group as $index => $run) {
                $handle = fopen($path, 'rb');
                if (!is_resource($handle) || fseek($handle, $run['offset']) !== 0) throw new \RuntimeException('JSON spool nelze číst.');
                $readers[$index] = ['handle' => $handle, 'remaining' => $run['count'], 'record' => self::readRecord($handle)];
            }
            while ($readers !== []) {
                $selected = null;
                foreach ($readers as $index => $reader) {
                    if ($reader['record'] === null) throw new \RuntimeException('Poškozený JSON spool.');
                    if ($selected === null || $compare($reader['record'], $readers[$selected]['record']) < 0) $selected = $index;
                }
                self::writeRecord($output, $readers[$selected]['record']);
                $readers[$selected]['remaining']--;
                if ($readers[$selected]['remaining'] === 0) {
                    fclose($readers[$selected]['handle']);
                    unset($readers[$selected]);
                } else {
                    $readers[$selected]['record'] = self::readRecord($readers[$selected]['handle']);
                }
            }
        } finally {
            foreach ($readers as $reader) if (is_resource($reader['handle'])) fclose($reader['handle']);
        }
    }

    /** @param resource $handle */
    private static function skipRecords($handle, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $header = self::readExact($handle, 4);
            $length = unpack('Nlength', $header)['length'];
            if (fseek($handle, $length, SEEK_CUR) !== 0) throw new \RuntimeException('Poškozený JSON spool.');
        }
    }

    /** @param resource $handle */
    private static function writeRunHeader($handle, int $count): void
    {
        self::writeAll($handle, pack('J', $count));
    }

    /** @param resource $handle */
    private static function readRunHeader($handle): ?int
    {
        $header = fread($handle, 8);
        if ($header === '' && feof($handle)) return null;
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
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) throw new \RuntimeException('JSON spool nelze zapsat.');
            $offset += $written;
        }
    }
}

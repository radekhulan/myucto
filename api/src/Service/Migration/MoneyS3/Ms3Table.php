<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Čtečka datových souborů Money S3 (formát `.DAT`, magic `\xD2BF13`).
 *
 * Čte VÝHRADNĚ ze zálohy agendy, kterou předá klient ({@see Ms3Backup}) — nikdy ze
 * živé instalace Money.
 *
 * Formát (ověřeno na Money S3 26.600):
 *   - hlavička: magic `BF13` od bajtu 1, bajt 7 = počet polí
 *   - slovník polí od offsetu 0x28, položky po 26 B:
 *       [0] délka názvu | [1..10] název | [11] typ | [12] délka | [24..25] offset v záznamu (uint16 LE)
 *   - datová oblast: záznamy pevné délky, KAŽDÝ BAJT BITOVĚ NEGOVANÝ (XOR 0xFF),
 *     texty v CP1250, částky v Borland Extended (80bit), data = dny od 31. 12. 1899
 *
 * Formát není dokumentovaný. Parser je proto vázaný na verzi Money a výsledek
 * převodu se VŽDY rekonciliuje ({@see MoneyS3Reconciler}).
 */
final class Ms3Table
{
    /**
     * Den nula kalendáře Money: hodnota 1 = 1. 1. 1900. NE 30. 12. 1899 jako Delphi
     * TDateTime — s tou epochou vycházejí všechna data o den dřív a rekonciliace
     * částek to neodhalí (odhalí to až DUZP na skenu, datum ve VS nebo rozložení
     * dnů v týdnu). Hlídá {@see \MyInvoice\Tests\Unit\Migration\MoneyS3\Ms3TableTest}.
     */
    public const DATE_EPOCH = '1899-12-31';

    private const HEADER_MAGIC = 'BF13';
    private const FIELD_DICT_OFFSET = 0x28;
    private const FIELD_DICT_ENTRY = 26;

    /** @var list<array{name:string,type:string,len:int,offset:int,size:int}> */
    private array $fields = [];
    private int $recordSize = 0;
    private int $dataOffset = -1;
    private int $recordCount = 0;
    private int $skippedDeleted = 0;

    private function __construct(private readonly string $raw, public readonly string $tableName)
    {
    }

    public static function open(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new MoneyS3Exception('table_unreadable', 'Soubor ' . basename($path) . ' nelze přečíst.');
        }
        return self::fromString($raw, strtoupper(pathinfo($path, PATHINFO_FILENAME)));
    }

    public static function fromString(string $raw, string $tableName): self
    {
        $t = new self($raw, $tableName);
        $t->parseHeader();
        return $t;
    }

    public static function isMs3Table(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 5);
        fclose($fh);
        return is_string($head) && strlen($head) === 5 && substr($head, 1, 4) === self::HEADER_MAGIC;
    }

    /**
     * Počet dnů z Money → datum `Y-m-d`. Nula a nesmysly (záporné, za rokem ~2447)
     * jsou „nevyplněno".
     */
    public static function dateFromDays(int $days): ?string
    {
        if ($days <= 0 || $days > 200000) {
            return null;
        }
        return (new \DateTimeImmutable(self::DATE_EPOCH))
            ->add(new \DateInterval('P' . $days . 'D'))
            ->format('Y-m-d');
    }

    private function parseHeader(): void
    {
        if (strlen($this->raw) < self::FIELD_DICT_OFFSET || substr($this->raw, 1, 4) !== self::HEADER_MAGIC) {
            throw new MoneyS3Exception('not_ms3_table', "{$this->tableName}: není tabulka Money S3.");
        }
        $fieldCount = ord($this->raw[7]);
        $off = self::FIELD_DICT_OFFSET;
        for ($i = 0; $i < $fieldCount; $i++) {
            $entry = substr($this->raw, $off, self::FIELD_DICT_ENTRY);
            if (strlen($entry) < self::FIELD_DICT_ENTRY) {
                break;
            }
            $nameLen = min(ord($entry[0]), 10);
            $name = rtrim(substr($entry, 1, $nameLen), "\x00 ");
            $type = $entry[11];
            $len = ord($entry[12]);
            $recOffset = unpack('v', substr($entry, 24, 2))[1];
            $this->fields[] = [
                'name' => $name,
                'type' => $type,
                'len' => $len,
                'offset' => $recOffset,
                'size' => self::typeSize($type, $len),
            ];
            $off += self::FIELD_DICT_ENTRY;
        }
        if ($this->fields === []) {
            throw new MoneyS3Exception('not_ms3_table', "{$this->tableName}: prázdný slovník polí.");
        }
        foreach ($this->fields as $f) {
            $this->recordSize = max($this->recordSize, $f['offset'] + $f['size']);
        }
        $this->locateData($off);
    }

    /**
     * Za slovníkem polí následuje blok definic indexů proměnné délky. Datový
     * offset se dopočítá hrubou silou: musí být zarovnaný na délku záznamu
     * a u >97 % řetězcových polí musí délkový bajt sedět na deklarovanou délku.
     * Prázdná tabulka žádného kandidáta nemá — to je zároveň detekce „bez dat".
     */
    private function locateData(int $headerEnd): void
    {
        $size = strlen($this->raw);
        if ($this->recordSize <= 0) {
            return;
        }
        $limit = min($size, $headerEnd + 8192);
        for ($cand = $headerEnd; $cand < $limit; $cand++) {
            if (($size - $cand) % $this->recordSize !== 0) {
                continue;
            }
            if ($this->validateAt($cand)) {
                $this->dataOffset = $cand;
                $this->recordCount = intdiv($size - $cand, $this->recordSize);
                return;
            }
        }
    }

    private function validateAt(int $offset): bool
    {
        $count = intdiv(strlen($this->raw) - $offset, $this->recordSize);
        if ($count < 1) {
            return false;
        }
        $ok = 0;
        $total = 0;
        $nonEmpty = 0;
        for ($i = 0, $n = min($count, 40); $i < $n; $i++) {
            $rec = $this->rawRecord($offset + $i * $this->recordSize);
            if ($this->isBlank($rec)) {
                continue;
            }
            $nonEmpty++;
            foreach ($this->fields as $f) {
                if ($f['type'] === 'C' || $f['type'] === 'S') {
                    $total++;
                    if (ord($rec[$f['offset']]) <= $f['len']) {
                        $ok++;
                    }
                }
            }
        }
        if ($nonEmpty === 0) {
            return false;
        }
        return $total === 0 || ($ok / $total) > 0.97;
    }

    private function rawRecord(int $absOffset): string
    {
        return ~substr($this->raw, $absOffset, $this->recordSize);
    }

    /**
     * Volné místo je po negaci samé 0x00; hlavičkový záznam free-listu (a smazané
     * sloty) jsou naopak téměř samé 0xFF. Reálný záznam má 0xFF jen výjimečně,
     * takže podíl 0xFF nad 90 % spolehlivě odděluje režii od dat.
     */
    private function isBlank(string $rec): bool
    {
        if (trim($rec, "\x00") === '') {
            return true;
        }
        $len = strlen($rec);
        return $len > 0 && (substr_count($rec, "\xFF") / $len) > 0.9;
    }

    public function hasData(): bool
    {
        return $this->dataOffset >= 0 && $this->recordCount > 0;
    }

    public function recordSize(): int
    {
        return $this->recordSize;
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn (array $f): string => $f['name'], $this->fields);
    }

    /** Kolik smazaných (`Del`) a volných (`Free`) záznamů přeskočil poslední průchod {@see rows()}. */
    public function skippedDeleted(): int
    {
        return $this->skippedDeleted;
    }

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(): \Generator
    {
        $this->skippedDeleted = 0;
        if (!$this->hasData()) {
            return;
        }
        for ($i = 0; $i < $this->recordCount; $i++) {
            $rec = $this->rawRecord($this->dataOffset + $i * $this->recordSize);
            if ($this->isBlank($rec)) {
                continue;
            }
            $row = [];
            foreach ($this->fields as $f) {
                $row[$f['name']] = self::decode($rec, $f);
            }
            // Money drží v souboru i režijní záznamy: hlavičku free-listu a smazané
            // sloty. Nepoznají se podle obsahu (bývají plné pseudonáhodných bajtů),
            // ale podle vlastních příznaků `Free` / `Del`. Smazané doklady do sestav
            // Money nepatří, takže je správné je vynechat i při převodu.
            if (!empty($row['Free']) || !empty($row['Del'])) {
                $this->skippedDeleted++;
                continue;
            }
            yield $row;
        }
    }

    /**
     * @param array{name:string,type:string,len:int,offset:int,size:int} $f
     */
    private static function decode(string $rec, array $f): mixed
    {
        $o = $f['offset'];
        switch ($f['type']) {
            case 'C':
            case 'S':
                $len = min(ord($rec[$o]), $f['len']);
                $txt = substr($rec, $o + 1, $len);
                return trim((string) @iconv('CP1250', 'UTF-8//TRANSLIT', $txt));
            case 'L':
            case 'O':
            case 'M':
            case 'N':
            case 'U':
                return unpack('l', substr($rec, $o, 4))[1];
            case 'E':
                return self::ext80(substr($rec, $o, 10));
            case 'B':
            case 'V':
                return ord($rec[$o]);
            case 'D':
                return self::dateFromDays(unpack('v', substr($rec, $o, 2))[1]);
            case 'Z':
            case 'Y':
            case 'W':
            case 'I':
            case 'T':
                return unpack('v', substr($rec, $o, 2))[1];
            case 'G':
                return bin2hex(substr($rec, $o, 16));
            default:
                return bin2hex(substr($rec, $o, $f['size']));
        }
    }

    /** Borland Extended, 80bit: 64bit mantisa (s explicitním celým bitem) + 15bit exponent + znaménko. */
    private static function ext80(string $b): float
    {
        if (strlen($b) < 10) {
            return 0.0;
        }
        $lo = unpack('V', substr($b, 0, 4))[1];
        $hi = unpack('V', substr($b, 4, 4))[1];
        $se = unpack('v', substr($b, 8, 2))[1];
        $exp = $se & 0x7FFF;
        $mant = ((float) $hi) * 4294967296.0 + (float) $lo;
        if ($exp === 0x7FFF || ($exp === 0 && $mant === 0.0)) {
            return 0.0;
        }
        $val = $mant * (2.0 ** ($exp - 16383 - 63));
        return ($se & 0x8000) ? -$val : $val;
    }

    private static function typeSize(string $type, int $len): int
    {
        return match ($type) {
            'C', 'S' => $len + 1,
            'L', 'O', 'M', 'N', 'U' => 4,
            'E' => 10,
            'B', 'V' => 1,
            'D', 'Z', 'Y', 'W', 'I', 'T' => 2,
            'G' => 16,
            default => max(1, $len),
        };
    }
}

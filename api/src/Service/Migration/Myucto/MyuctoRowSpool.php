<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use RuntimeException;

/**
 * Dočasný soubor záznamů s délkovou hlavičkou pro {@see MyuctoRowStore}. Soubor se jen
 * prodlužuje; zanikne (i s dočasnými soubory příloh) spolu s posledním úložištěm, které
 * ho používá.
 */
final class MyuctoRowSpool
{
    /** @var resource */
    private $handle;
    private string $path;
    private int $end = 0;
    private int $position = 0;
    /** @var list<string> */
    private array $files = [];

    public function __construct(?string $dir = null)
    {
        $dir ??= sys_get_temp_dir();
        $path = @tempnam($dir, 'myucto-rows-');
        if ($path === false) {
            throw new RuntimeException('Dočasné úložiště obnovy nelze vytvořit.');
        }
        $handle = @fopen($path, 'w+b');
        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Dočasné úložiště obnovy nelze otevřít.');
        }
        $this->path = $path;
        $this->handle = $handle;
    }

    public function append(string $data): int
    {
        if ($this->position !== $this->end && fseek($this->handle, $this->end) !== 0) {
            throw new RuntimeException('Zápis do dočasného úložiště obnovy selhal.');
        }
        $offset = $this->end;
        $record = pack('N', strlen($data)) . $data;
        $length = strlen($record);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($this->handle, $written === 0 ? $record : substr($record, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Zápis do dočasného úložiště obnovy selhal.');
            }
            $written += $bytes;
        }
        $this->end += $length;
        $this->position = $this->end;
        return $offset;
    }

    public function read(int $offset): string
    {
        if ($this->position !== $offset && fseek($this->handle, $offset) !== 0) {
            throw new RuntimeException('Čtení dočasného úložiště obnovy selhalo.');
        }
        $header = $this->exactly(4);
        $length = unpack('N', $header)[1];
        $data = $length === 0 ? '' : $this->exactly($length);
        $this->position = $offset + 4 + $length;
        return $data;
    }

    public function tempFile(): string
    {
        $path = @tempnam(dirname($this->path), 'myucto-file-');
        if ($path === false) {
            throw new RuntimeException('Dočasný soubor obnovy nelze vytvořit.');
        }
        $this->files[] = $path;
        return $path;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        @unlink($this->path);
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function exactly(int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($this->handle, $length - strlen($out));
            if ($chunk === false || $chunk === '') {
                $this->position = -1;
                throw new RuntimeException('Dočasné úložiště obnovy je poškozené.');
            }
            $out .= $chunk;
        }
        return $out;
    }
}

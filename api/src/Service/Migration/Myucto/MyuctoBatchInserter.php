<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use PDO;

/**
 * Vkládá řádky jedné tabulky vícařádkovým INSERTem po dávkách. Pořadí řádků se zachová:
 * dávka se odešle před řádkem s jinou sadou sloupců a při dosažení limitu řádků,
 * parametrů nebo bajtů (řádky s binárním obsahem výpisu jdou samostatně).
 */
final class MyuctoBatchInserter
{
    private const MAX_ROWS = 500;
    private const MAX_PARAMS = 60_000;
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** @var list<string> */
    private array $columns = [];
    /** @var list<mixed> */
    private array $values = [];
    private int $rows = 0;
    private int $bytes = 0;

    public function __construct(private readonly PDO $pdo, private readonly string $table)
    {
    }

    /** @param array<string,mixed> $row */
    public function add(array $row): void
    {
        $columns = array_keys($row);
        $size = 0;
        foreach ($row as $value) {
            $size += is_string($value) ? strlen($value) : 8;
        }
        if ($this->rows > 0 && ($columns !== $this->columns || $this->rows >= self::MAX_ROWS
            || ($this->rows + 1) * count($columns) > self::MAX_PARAMS || $this->bytes + $size > self::MAX_BYTES)) {
            $this->flush();
        }
        $this->columns = $columns;
        foreach ($row as $value) {
            $this->values[] = $value;
        }
        $this->rows++;
        $this->bytes += $size;
    }

    public function flush(): void
    {
        if ($this->rows === 0) {
            return;
        }
        $tuple = '(' . implode(', ', array_fill(0, count($this->columns), '?')) . ')';
        $this->pdo->prepare('INSERT INTO `' . $this->table . '` (`' . implode('`, `', $this->columns) . '`) VALUES '
            . implode(', ', array_fill(0, $this->rows, $tuple)))->execute($this->values);
        $this->values = [];
        $this->rows = 0;
        $this->bytes = 0;
    }
}

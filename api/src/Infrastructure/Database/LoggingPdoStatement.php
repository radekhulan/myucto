<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * PDOStatement subclass aktivovaný přes PDO::ATTR_STATEMENT_CLASS v LoggingPdo.
 * Při PDOException v execute() pošle strukturovaný záznam do Monologu
 * a pak chybu znovu vyhodí — fail-fast chování callerů zůstává.
 */
final class LoggingPdoStatement extends PDOStatement
{
    protected function __construct(private readonly LoggerInterface $logger, private readonly ?\Closure $schemaChanged = null) {}

    public function execute(?array $params = null): bool
    {
        try {
            $ok = parent::execute($params);
        } catch (PDOException $e) {
            DbErrorLogger::log($this->logger, $e, (string) $this->queryString, $params ?? []);
            throw $e;
        }

        // Invalidace entity cache — až po úspěšném provedení. Detekce write
        // příkazu je uvnitř, čtení odfiltruje hned první regex, takže SELECTy
        // (drtivá většina volání) platí jen jedno porovnání.
        if ($ok) {
            WriteWatcher::noteStatement((string) $this->queryString);
            ($this->schemaChanged)?->__invoke((string) $this->queryString);
        }

        return $ok;
    }
}

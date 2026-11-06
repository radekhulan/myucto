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
    protected function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $schemaChanged = null,
        private readonly ?SlowQueryLog $slowLog = null,
        private readonly string $database = '',
        private readonly ?\Closure $transactionState = null,
        private readonly ?\Closure $statementSucceeded = null,
        private readonly ?\Closure $statementFailed = null,
    ) {}

    public function execute(?array $params = null): bool
    {
        $before = ($this->transactionState)?->__invoke() ?? false;
        $started = $this->slowLog !== null ? hrtime(true) : 0;
        try {
            $ok = $this->executeRetryingReprepare($params);
        } catch (PDOException $e) {
            ($this->statementFailed)?->__invoke((string) $this->queryString, $before, $e->errorInfo);
            DbErrorLogger::log($this->logger, $e, (string) $this->queryString, $params ?? []);
            throw $e;
        }
        $this->slowLog?->record((string) $this->queryString, $params ?? [], $started, $this->database);

        // Invalidace entity cache — až po úspěšném provedení. Detekce write
        // příkazu je uvnitř, čtení odfiltruje hned první regex, takže SELECTy
        // (drtivá většina volání) platí jen jedno porovnání.
        if ($ok) {
            ($this->statementSucceeded)?->__invoke((string) $this->queryString, $before);
            WriteWatcher::noteStatement((string) $this->queryString);
            ($this->schemaChanged)?->__invoke((string) $this->queryString);
        } else {
            ($this->statementFailed)?->__invoke((string) $this->queryString, $before, $this->errorInfo());
        }

        return $ok;
    }

    /**
     * MariaDB 1615 (Prepared statement needs to be re-prepared): definice tabulky se mezi
     * přípravou a provedením změnila nebo vypadla z cache definic (paralelní zátěž,
     * sekvenční engine). Chyba vzniká PŘED provedením, takže opakování nic nezdvojí
     * a server při něm příkaz připraví znovu; jiné chyby jdou dál beze změny.
     */
    private function executeRetryingReprepare(?array $params): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::execute($params);
            } catch (PDOException $retry) {
                if ($attempt >= 2 || (int) ($retry->errorInfo[1] ?? 0) !== 1615) {
                    throw $retry;
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Formátuje PDOException do Monolog kontextu: sqlstate, query (single-line),
 * params (s redakcí citlivých hodnot), caller (první frame mimo Infrastructure\Database).
 *
 * Volá se z LoggingPdo / LoggingPdoStatement při zachycené chybě. Caller je
 * povinen exception rethrownout — DbErrorLogger jen loguje.
 */
final class DbErrorLogger
{
    /**
     * Heuristika: pokud SQL obsahuje sloupec, jehož název odpovídá těmto patternům,
     * VŠECHNY parametry se nahradí placeholderem. Nemůžeme spolehlivě mapovat
     * pozici `?` na konkrétní sloupec (ne u INSERTu, ne u UPDATE SET-listů s expr.),
     * tak raději redaktujeme celou množinu.
     */
    private const SENSITIVE_PATTERNS = [
        '/\bpassword\b/i',
        '/\bpassword_hash\b/i',
        '/\bsecret\b/i',
        '/\btoken\b/i',
        '/\btoken_hash\b/i',
        '/\btotp_secret\b/i',
        '/\brecovery_codes\b/i',
        '/\bapi_token\b/i',
        '/\bcredential_id(?:_hash)?\b/i',
        '/\bpublic_key\b/i',
        '/\bchallenge\b/i',
        '/\boptions_json\b/i',
        '/\bsession_id_hash\b/i',
        '/\bflow_token_hash\b/i',
        '/\bbirth_number\b/i',
        '/\bbirth_surname\b/i',
        '/\bstreet_line\b/i',
        '/\bcontact_value(?:_ciphertext|_hash|_masked)?\b/i',
        '/\bpersonal_identifier(?:_ciphertext|_hash)?\b/i',
        '/\bnational_id(?:_ciphertext|_hash)?\b/i',
        '/\bforeign_tax_(?:id|identifier)(?:_ciphertext|_hash)?\b/i',
        '/\bbank_account(?:_ciphertext|_hash)?\b/i',
        '/\biban(?:_ciphertext|_hash)?\b/i',
        '/\bdiagnosis\b/i',
        '/\bmedical_code\b/i',
        '/\b(?:enforcement|insolvency)_case_number\b/i',
        '/\bciphertext\b/i',
    ];

    /**
     * Zásobník jmen indexů z {@see expectingDuplicates()}. Zásobník, ne jedna
     * hodnota: zanořená volání (repository uvnitř služby, která si duplicitu taky
     * hlídá) nesmí potlačení vypnout dřív, než skončí to vnější.
     *
     * @var list<list<string>>
     */
    private static array $expectedDuplicates = [];

    /**
     * Rozsah, ve kterém je kolize JMENOVANÉHO unikátního klíče očekávaný výsledek,
     * ne chyba aplikace.
     *
     * PROČ. Vzor „vlož a při 1062 se spokoj s tím, co už tam je" (fronta AI úloh,
     * bankovní návrhy), stejně jako „duplicitní doklad → 409 uživateli", je
     * legitimní chování. Caller ho ošetří, jenže logovací PDO chybu zapíše dřív,
     * než se k ní caller dostane — a v produkčním logu pak stojí ERROR nad
     * situací, kterou nemá kdo řešit.
     *
     * PROČ SE JMÉNEM INDEXU. Potlačit každou duplicitu v rozsahu by ztišilo
     * i kolizi na klíči, který nikdo neošetřuje — a ta se pak projeví jen jako
     * 500 bez jediného záznamu. Downgraduje se proto výhradně 1062 na uvedených
     * indexech; cokoli jiného (jiný index, cizí klíč 1452 pod týmž SQLSTATE
     * 23000) zůstává ERROR.
     *
     * @template T
     * @param list<string> $indexNames jména UNIQUE indexů, jejichž kolize je očekávaná
     * @param callable():T $fn
     * @return T
     */
    public static function expectingDuplicates(array $indexNames, callable $fn): mixed
    {
        self::$expectedDuplicates[] = $indexNames;
        try {
            return $fn();
        } finally {
            array_pop(self::$expectedDuplicates);
        }
    }

    /** @param array<array-key,mixed> $params */
    public static function log(LoggerInterface $logger, PDOException $e, string $sql, array $params): void
    {
        $context = [
            'sqlstate' => $e->getCode(),
            'sql'      => self::normalize($sql),
            'params'   => self::redact($sql, $params),
            'caller'   => self::caller($e),
        ];
        if (self::isExpectedDuplicate($e)) {
            $logger->debug('DB duplicate (očekávaná): ' . $e->getMessage(), $context);
            return;
        }
        $logger->error('DB error: ' . $e->getMessage(), $context);
    }

    /**
     * Jen 1062 (duplicate entry) na indexu, který si caller vyžádal. SQLSTATE 23000
     * samo nestačí — nese i porušení cizího klíče, což očekávaný výsledek nikdy není.
     */
    private static function isExpectedDuplicate(PDOException $e): bool
    {
        if (self::$expectedDuplicates === [] || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }
        $message = $e->getMessage();
        foreach (self::$expectedDuplicates as $scope) {
            foreach ($scope as $index) {
                // MariaDB hlásí „... for key 'uq_xyz'", takže jméno indexu je ve zprávě.
                if ($index !== '' && str_contains($message, $index)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normalize(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }

    /**
     * @param array<array-key,mixed> $params
     * @return array<array-key,mixed>
     */
    private static function redact(string $sql, array $params): array
    {
        foreach (self::SENSITIVE_PATTERNS as $pat) {
            if (preg_match($pat, $sql) === 1) {
                return ['__redacted__' => '*** params hidden (sensitive column referenced) ***'];
            }
        }
        return $params;
    }

    /**
     * První frame stack-trace, který není uvnitř Infrastructure\Database namespace —
     * to je skutečný caller (Repository/Action/cron). Bez tohoto by `caller` ukazoval
     * na LoggingPdoStatement::execute, což je k ničemu.
     */
    private static function caller(PDOException $e): string
    {
        foreach ($e->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '') continue;
            if (str_contains($file, DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR)) continue;
            return $file . ':' . (int) ($frame['line'] ?? 0);
        }
        return $e->getFile() . ':' . $e->getLine();
    }
}

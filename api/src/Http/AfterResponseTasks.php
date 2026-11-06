<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Log\LoggerInterface;

/**
 * Práce, která se má udělat až PO odeslání odpovědi klientovi.
 *
 * Slouží tam, kde by délka odpovědi prozradila, kterou větví request prošel
 * (typicky SMTP odeslání jen pro existující účet). Úloha se spustí z
 * `public/index.php` hned po `$app->run()`: nejdřív se spojení s klientem
 * uzavře přes `fastcgi_finish_request()` (PHP-FPM), teprve pak se úloha
 * provede. Kde SAPI uzavření nepodporuje, {@see canDetach()} vrací false
 * a volající musí práci udělat synchronně (a časování srovnat jinak).
 *
 * Pojistka: kdyby úlohu z index.php nikdo nespustil (jiný vstupní bod),
 * doběhne ve shutdown handleru, takže se neztratí.
 */
final class AfterResponseTasks
{
    /** @var list<callable():void> */
    private array $tasks = [];
    private bool $shutdownRegistered = false;

    /** @param ?bool $detachable null = podle SAPI; explicitní hodnota jen pro testy. */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?bool $detachable = null,
    ) {}

    /** Umí aktuální SAPI odpojit klienta před dokončením skriptu? */
    public function canDetach(): bool
    {
        return $this->detachable
            ?? (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
    }

    /**
     * Instance s čekající prací. Statický seznam proto, aby index.php nemusel
     * kvůli kontrole na každém requestu stavět službu z kontejneru.
     *
     * @var array<int,self>
     */
    private static array $pending = [];

    /** Spustí odloženou práci všech instancí; volá `public/index.php` po `$app->run()`. */
    public static function runPending(): void
    {
        while (self::$pending !== []) {
            $instance = array_shift(self::$pending);
            $instance->run();
        }
    }

    /** @param callable():void $task */
    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
        self::$pending[spl_object_id($this)] = $this;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $this->run(false);
            });
        }
    }

    public function hasPending(): bool
    {
        return $this->tasks !== [];
    }

    /**
     * Uzavře odpověď a provede odložené úlohy. Chyba jedné úlohy nezastaví další.
     */
    public function run(bool $finishRequest = true): void
    {
        unset(self::$pending[spl_object_id($this)]);
        if ($this->tasks === []) {
            return;
        }
        if ($finishRequest) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        }
        // Odpojení klienta nesmí úlohu přerušit.
        ignore_user_abort(true);

        while ($this->tasks !== []) {
            $task = array_shift($this->tasks);
            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger->error('after_response.task_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

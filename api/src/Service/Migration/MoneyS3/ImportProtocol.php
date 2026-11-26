<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Protokol převodu — to, co účetní dostane jako důkaz: kroky s počty, upozornění
 * a chyby, rekonciliace, uzávěrka historických let a stav automatiky.
 *
 * Zprávy mají tři váhy:
 *  - upozornění ({@see warn()}) převod nezastaví,
 *  - chyba ({@see error()}) převod shodí vždy,
 *  - rozdíl k přijetí ({@see difference()}) je chyba, kterou účetní smí vědomě přijmout:
 *    část dat se nepřevedla nebo nesedí na zdroj, zbytek převodu je ale v pořádku.
 *    Bez přijetí se chová jako chyba. Ostrý převod spuštěný s přijetím rozdílů
 *    (`$acceptDifferences`) ho zapíše jako upozornění a vypíše ho v sekci
 *    `accepted_differences`.
 */
final class ImportProtocol
{
    /** Strop položek v seznamech (osiřelé doklady apod.) — protokol se ukládá do DB. */
    public const LIST_LIMIT = 200;

    /** @var array<string,array{key:string,status:string,counts:array<string,int|float>,messages:list<array{level:string,code:string,text:string,context:array<string,mixed>}>}> */
    private array $steps = [];

    /** @var array<string,mixed> */
    private array $sections = [];

    private ?string $failure = null;

    /** @var array<string,int> krok => počet chyb */
    private array $hardErrors = [];

    /** @var array<string,int> krok => počet nepřijatých rozdílů */
    private array $openDifferences = [];

    /** @var list<array{step:string,code:string,text:string,context:array<string,mixed>}> */
    private array $acceptedDifferences = [];

    private int $acceptedCount = 0;

    public readonly bool $acceptDifferences;

    /**
     * @param bool $acceptDifferences rozdíly k přijetí se zapíšou jako upozornění
     *        (jen ostrý převod, zkouška nanečisto je vždy ukáže jako chybu)
     */
    public function __construct(public readonly string $mode, bool $acceptDifferences = false)
    {
        $this->acceptDifferences = $acceptDifferences && $mode !== 'dry_run';
    }

    /**
     * Založí krok, pokud ještě není. Stav existujícího kroku NEMĚNÍ — počitadla
     * a zprávy se volají i po chybě a upozornění a nesmí je přepsat zpět na „běží".
     */
    public function begin(string $step): void
    {
        $this->steps[$step] ??= ['key' => $step, 'status' => 'running', 'counts' => [], 'messages' => []];
    }

    public function finish(string $step, string $status = 'ok'): void
    {
        $this->begin($step);
        $current = $this->steps[$step]['status'];
        if ($current === 'error' || ($current === 'warning' && $status === 'ok')) {
            return;
        }
        $this->steps[$step]['status'] = $status;
    }

    public function count(string $step, string $name, int|float $by = 1): void
    {
        $this->begin($step);
        $this->steps[$step]['counts'][$name] = ($this->steps[$step]['counts'][$name] ?? 0) + $by;
    }

    public function setCount(string $step, string $name, int|float $value): void
    {
        $this->begin($step);
        $this->steps[$step]['counts'][$name] = $value;
    }

    /** @param array<string,mixed> $context */
    public function info(string $step, string $code, string $text, array $context = []): void
    {
        $this->message($step, 'info', $code, $text, $context);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $step, string $code, string $text, array $context = []): void
    {
        $this->message($step, 'warning', $code, $text, $context);
        if (($this->steps[$step]['status'] ?? '') !== 'error') {
            $this->steps[$step]['status'] = 'warning';
        }
    }

    /** @param array<string,mixed> $context */
    public function error(string $step, string $code, string $text, array $context = []): void
    {
        $this->message($step, 'error', $code, $text, $context);
        $this->steps[$step]['status'] = 'error';
        $this->hardErrors[$step] = ($this->hardErrors[$step] ?? 0) + 1;
    }

    /**
     * Rozdíl k přijetí: doklad nebo zápis se nepřevedl, nebo převod nesedí na zdroj, ale
     * zbytek převodu je v pořádku. Bez přijetí je to chyba (převod skončí `failed`),
     * s přijetím upozornění a záznam v `accepted_differences`.
     *
     * @param array<string,mixed> $context čísla dokladů, účty a částky rozdílu
     */
    public function difference(string $step, string $code, string $text, array $context = []): void
    {
        if (!$this->acceptDifferences) {
            $this->message($step, 'error', $code, $text, $context, true);
            $this->steps[$step]['status'] = 'error';
            $this->openDifferences[$step] = ($this->openDifferences[$step] ?? 0) + 1;
            return;
        }
        $this->message($step, 'warning', $code, $text, $context, true);
        if (($this->steps[$step]['status'] ?? '') !== 'error') {
            $this->steps[$step]['status'] = 'warning';
        }
        $this->acceptedCount++;
        if (count($this->acceptedDifferences) < self::LIST_LIMIT) {
            $this->acceptedDifferences[] = ['step' => $step, 'code' => $code, 'text' => $text, 'context' => $context];
        }
    }

    /** Převod narazil na rozdíl k přijetí (přijatý i nepřijatý). */
    public function hasDifferences(): bool
    {
        return $this->openDifferences !== [] || $this->acceptedCount > 0;
    }

    /**
     * Krok skončil tak, že převod nesmí pokračovat dalšími kroky: má chybu, nebo ostrý
     * převod narazil na rozdíl, který nikdo nepřijal. Zkouška nanečisto u rozdílu
     * pokračuje, aby účetní viděla všechny rozdíly najednou a mohla je přijmout.
     */
    public function blocks(string $step): bool
    {
        if (($this->hardErrors[$step] ?? 0) > 0) {
            return true;
        }
        return $this->mode !== 'dry_run' && ($this->openDifferences[$step] ?? 0) > 0;
    }

    /**
     * Převod selhal JEN na rozdílech k přijetí: žádná chyba, žádné přerušení, aspoň jeden
     * nepřijatý rozdíl. Ostrý převod pak smí běžet s přijetím rozdílů.
     */
    public function acceptableOnly(): bool
    {
        return $this->failure === null && $this->hardErrors === [] && $this->openDifferences !== [];
    }

    public function fail(string $reason): void
    {
        $this->failure ??= $reason;
    }

    public function failed(): bool
    {
        return $this->failure !== null;
    }

    public function set(string $section, mixed $value): void
    {
        $this->sections[$section] = $value;
    }

    public function get(string $section): mixed
    {
        return $this->sections[$section] ?? null;
    }

    /**
     * Úplný seznam položek nálezu (např. čísel dokladů) pod klíčem `$code` v oddílu `lists`
     * ({@see toArray()}). Na rozdíl od zpráv kroku ho neomezuje {@see LIST_LIMIT}, takže
     * souhrn na konci kroku nezmizí ani u velké firmy. Opakované volání seznam doplní.
     *
     * @param list<string|int> $items
     */
    public function addList(string $code, array $items): void
    {
        $lists = (array) ($this->sections['lists'] ?? []);
        $lists[$code] = array_values(array_unique([...((array) ($lists[$code] ?? [])), ...array_map('strval', $items)]));
        $this->sections['lists'] = $lists;
    }

    /** @return list<string> seznam z {@see addList()} */
    public function listOf(string $code): array
    {
        return array_values((array) ($this->sections['lists'][$code] ?? []));
    }

    public function hasErrors(): bool
    {
        if ($this->failure !== null) {
            return true;
        }
        foreach ($this->steps as $s) {
            if ($s['status'] === 'error') {
                return true;
            }
        }
        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->steps as $s) {
            if ($s['status'] === 'warning') {
                return true;
            }
        }
        return false;
    }

    /** completed | completed_with_warnings | failed */
    public function status(): string
    {
        if ($this->hasErrors()) {
            return 'failed';
        }
        return $this->hasWarnings() ? 'completed_with_warnings' : 'completed';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = array_merge($this->sections, [
            'mode' => $this->mode,
            'status' => $this->status(),
            'failure' => $this->failure,
            'steps' => array_values($this->steps),
            'acceptable_only' => $this->acceptableOnly(),
        ]);
        if ($this->acceptedCount > 0) {
            $out['accepted_differences'] = $this->acceptedDifferences;
            if ($this->acceptedCount > count($this->acceptedDifferences)) {
                $out['accepted_differences_truncated'] = $this->acceptedCount - count($this->acceptedDifferences);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $context */
    private function message(string $step, string $level, string $code, string $text, array $context, bool $acceptable = false): void
    {
        $this->begin($step);
        if (count($this->steps[$step]['messages']) >= self::LIST_LIMIT) {
            $this->steps[$step]['counts']['messages_truncated'] = ($this->steps[$step]['counts']['messages_truncated'] ?? 0) + 1;
            return;
        }
        $message = ['level' => $level, 'code' => $code, 'text' => $text, 'context' => $context];
        if ($acceptable) {
            $message['acceptable'] = true;
        }
        $this->steps[$step]['messages'][] = $message;
    }
}

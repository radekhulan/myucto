<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Protokol převodu — to, co účetní dostane jako důkaz: kroky s počty, upozornění
 * a chyby, rekonciliace, uzávěrka historických let a stav automatiky.
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

    public function __construct(public readonly string $mode) {}

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
        return array_merge($this->sections, [
            'mode' => $this->mode,
            'status' => $this->status(),
            'failure' => $this->failure,
            'steps' => array_values($this->steps),
        ]);
    }

    /** @param array<string,mixed> $context */
    private function message(string $step, string $level, string $code, string $text, array $context): void
    {
        $this->begin($step);
        if (count($this->steps[$step]['messages']) >= self::LIST_LIMIT) {
            $this->steps[$step]['counts']['messages_truncated'] = ($this->steps[$step]['counts']['messages_truncated'] ?? 0) + 1;
            return;
        }
        $this->steps[$step]['messages'][] = ['level' => $level, 'code' => $code, 'text' => $text, 'context' => $context];
    }
}

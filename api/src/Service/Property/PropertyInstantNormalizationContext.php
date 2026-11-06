<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyInstantNormalizationContext
{
    private const LIMIT = 1024;
    private array $values = [];

    public function __construct(private readonly \Closure $normalizer) {}

    public function normalize(mixed $input): string
    {
        if (is_string($input) && isset($this->values[$input])) return $this->values[$input];
        $result = ($this->normalizer)($input);
        if (is_string($input) && count($this->values) < self::LIMIT) $this->values[$input] = $result;
        return $result;
    }
}

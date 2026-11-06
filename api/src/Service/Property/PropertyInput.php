<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyInput
{
    public static function text(mixed $value, string $field, int $max = 190, bool $optional = false): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $max || (!$optional && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) self::invalid($field);
        return trim($value);
    }

    public static function id(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) self::invalid($field);
        return $value;
    }

    public static function code(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $value)) self::invalid($field);
        return $value;
    }

    public static function fields(array $input, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) self::invalid((string) $unknown[0]);
    }

    public static function list(mixed $value, string $field, int $max = 200): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) self::invalid($field);
        return $value;
    }

    public static function invalid(string $field): never
    {
        throw new PropertyException('property_invalid_input', 'Zkontrolujte označený vstup registru.', details: ['field' => $field]);
    }

    public static function hash(array $payload): string
    {
        return hash('sha256',self::canonicalJson($payload));
    }

    public static function hashLists(array $payload,array $lists): string
    {
        if(array_is_list($payload)) return self::hash($payload);
        ksort($payload,SORT_STRING);
        if(array_is_list($payload)) return self::hash($payload);
        $hash=hash_init('sha256');hash_update($hash,'{');$separator='';
        foreach($payload as $key=>$value) {
            hash_update($hash,$separator.json_encode((string)$key,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).':');$separator=',';
            if(in_array($key,$lists,true)&&is_array($value)&&array_is_list($value)) {
                hash_update($hash,'[');$itemSeparator='';
                foreach($value as $item){hash_update($hash,$itemSeparator.self::canonicalJson($item,510));$itemSeparator=',';}
                hash_update($hash,']');
            } else hash_update($hash,self::canonicalJson($value,511));
        }
        hash_update($hash,'}');return hash_final($hash);
    }

    public static function sameJsonGraph(array $expected,array $actual): bool
    {
        self::assertJsonGraph($expected,512);self::assertJsonGraph($actual,512);
        $flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION;
        $left=json_encode($expected,$flags,512);$right=json_encode($actual,$flags,512);
        if($left===$right)return true;
        return self::hash($expected)===self::hash($actual);
    }
    private static function assertJsonGraph(array $value,int $remaining): void
    {
        if($remaining<1)throw new \JsonException('Maximum stack depth exceeded',JSON_ERROR_DEPTH);
        foreach($value as $item){if(is_scalar($item)||$item===null)continue;if(!is_array($item))throw new \InvalidArgumentException('Canonical materialization requires decoded JSON values.');self::assertJsonGraph($item,$remaining-1);}
    }
    private static function canonicalJson(mixed $payload,int $depth=512): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($sort, $value);
        };
        return json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,$depth);
    }
}

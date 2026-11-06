<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
final class FrameworkConversionException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode,public readonly int $httpStatus=409,public readonly array $details=[])
    {parent::__construct($errorCode.($details===[]?'':': '.json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));}
    public static function need(bool $condition,string $code,int $status=409,array $details=[]):void
    {if(!$condition)throw new self($code,$status,$details);}
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/** Za koho a v jakém prostředí kanál jedná. */
final readonly class ChannelContext
{
    public function __construct(
        public int $supplierId,
        public string $environment,
        public ChannelCredentials $credentials,
        public ?string $verifiedBoxId = null,
    ) {}

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    /** @return array{supplier_id:int,environment:string,box_id:string,auth_mode:string} */
    public function toLogContext(): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'environment' => $this->environment,
        ] + $this->credentials->toLogContext();
    }
}

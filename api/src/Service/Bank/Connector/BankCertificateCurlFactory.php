<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

final class BankCertificateCurlFactory implements CurlFactoryInterface
{
    private readonly CurlFactory $factory;

    public function __construct(private readonly bool $allowTokenOnly = false)
    {
        $this->factory = new CurlFactory(0);
    }

    public function create(
        #[\SensitiveParameter] RequestInterface $request,
        #[\SensitiveParameter] array $options,
    ): EasyHandle {
        if (
            strtolower($request->getUri()->getScheme()) !== 'https'
            || ($options['verify'] ?? null) !== true
            || ($options['allow_redirects'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('Bank certificate transport requires HTTPS, TLS verification and disabled redirects.');
        }
        $curl = $options['curl'] ?? null;
        if (!is_array($curl)) {
            throw new InvalidArgumentException('Bank certificate transport options are missing.');
        }

        $certificateOptions = $this->extractCertificateOptions($curl);
        $options['curl'] = $curl;
        $easy = $this->factory->create($request, $options);
        try {
            if (!curl_setopt_array($easy->handle, $certificateOptions)) {
                throw new \RuntimeException('Unable to apply bank certificate transport options.');
            }
        } catch (\Throwable) {
            try {
                $this->factory->release($easy);
            } catch (\Throwable) {
            }
            throw new InvalidArgumentException('Unable to apply bank certificate transport options.');
        }
        return $easy;
    }

    public function release(#[\SensitiveParameter] EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }

    /**
     * @param array<int|string,mixed> $curl
     * @return array<int,mixed>
     */
    private function extractCertificateOptions(#[\SensitiveParameter] array &$curl): array
    {
        $required = [
            $this->curlConstant('CURLOPT_SSLCERT_BLOB'),
            $this->curlConstant('CURLOPT_SSLKEY_BLOB'),
            $this->curlConstant('CURLOPT_SSLCERTTYPE'),
            $this->curlConstant('CURLOPT_SSLKEYTYPE'),
        ];
        if ($this->allowTokenOnly && array_intersect($required, array_keys($curl)) === []) {
            return [$this->curlConstant('CURLOPT_SSLVERSION') => $this->tls12()];
        }
        $certificate = [];
        foreach ($required as $option) {
            if (!array_key_exists($option, $curl)) {
                throw new InvalidArgumentException('Bank certificate transport options are incomplete.');
            }
            $certificate[$option] = $curl[$option];
            unset($curl[$option]);
        }

        $certBlob = $certificate[$required[0]];
        $keyBlob = $certificate[$required[1]];
        if (
            !is_string($certBlob) || $certBlob === '' || strlen($certBlob) > 2 * 1024 * 1024
            || !is_string($keyBlob) || $keyBlob === '' || strlen($keyBlob) > 2 * 1024 * 1024
            || $certificate[$required[2]] !== 'PEM'
            || $certificate[$required[3]] !== 'PEM'
        ) {
            throw new InvalidArgumentException('Bank certificate transport options are invalid.');
        }

        $sslVersionOption = $this->curlConstant('CURLOPT_SSLVERSION');
        if (array_key_exists($sslVersionOption, $curl)) {
            $requested = $curl[$sslVersionOption];
            unset($curl[$sslVersionOption]);
            if ($requested !== $this->tls12()) {
                throw new InvalidArgumentException('Bank certificate transport requires TLS 1.2 or newer.');
            }
        }
        $certificate[$sslVersionOption] = $this->tls12();
        return $certificate;
    }

    private function tls12(): int
    {
        return $this->curlConstant('CURL_SSLVERSION_TLSv1_2');
    }

    private function curlConstant(string $name): int
    {
        if (!defined($name)) {
            throw new InvalidArgumentException('Required bank certificate transport capability is unavailable.');
        }
        $value = constant($name);
        if (!is_int($value)) {
            throw new InvalidArgumentException('Required bank certificate transport capability is unavailable.');
        }
        return $value;
    }
}

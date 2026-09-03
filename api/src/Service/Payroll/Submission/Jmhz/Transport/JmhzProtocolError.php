<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlId;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;

/**
 * Jedna chyba z protokolu. Kód chyby je podle katalogu kontrol offsetovaný:
 * DIS = ID kontroly + 20000, cJMHZ = ID kontroly + 40000, takže se z něj dá ID
 * kontroly spočítat zpět. Kulaté 20000 a 40000 jsou v ukázkách obálkové
 * „Technická chyba" s detailem v textu, ne kontroly — ID proto nemají.
 *
 * Ostatní kódy jsou platformní (odmítnutí na vstupu, obálka, podpis, šifrování)
 * a musí být v doloženém katalogu; neznámý kód je tvrdá chyba, ne „nezařazeno".
 */
final readonly class JmhzProtocolError
{
    private const DIS_OFFSET = 20_000;
    private const CJMHZ_OFFSET = 40_000;
    private const RANGE = 19_999;

    /**
     * Prefix, kterým ČSSZ v textu popisu uvádí propustnost KONKRÉTNÍ chyby
     * z protokolu. Propustnost není samostatný strukturovaný element — ČSSZ
     * to v oficiální diskuzi potvrdila — a náš připnutý katalog kontrol se
     * v čase mění (kontroly se ruší, suspendují, mění propustnost), takže
     * není spolehlivým zdrojem pravdy o konkrétním podání. Text protokolu je
     * skutečnost od protistrany a má přednost.
     */
    private const PASSABILITY_PREFIX
        = '/^\(\s*propustnost\s*:\s*(nepropustn[áa]|propustn[áa])\s*\)/iu';

    /** Doložené platformní kódy z pokynů (atribut 10018 „Důvod odmítnutí"). */
    private const PLATFORM_CODES = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 18, 19, 20, 21,
        22, 23, 24, 25, 26, 27, 61, 62, 63, 64,
        101, 102, 103, 104, 105, 201, 202, 300, 302, 305, 310, 400,
        17800, 17801, 17803, 17804, 17805, 17806, 17807, 17808, 17810, 17814,
        17820, 17824, 17830, 17832, 17833, 17835, 17836, 17837, 17839, 17840,
    ];

    private function __construct(
        public int $code,
        public string $message,
        public JmhzProtocolErrorOrigin $origin,
        public ?JmhzControlId $controlId,
        public JmhzControlPassability $passability,
    ) {}

    public static function fromCode(int $code, string $message): self
    {
        $passability = self::derivePassability($message);

        if ($code === self::DIS_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Dis, null, $passability);
        }
        if ($code === self::CJMHZ_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Cjmhz, null, $passability);
        }
        if ($code > self::DIS_OFFSET && $code <= self::DIS_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Dis,
                new JmhzControlId($code - self::DIS_OFFSET),
                $passability,
            );
        }
        if ($code > self::CJMHZ_OFFSET && $code <= self::CJMHZ_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Cjmhz,
                new JmhzControlId($code - self::CJMHZ_OFFSET),
                $passability,
            );
        }
        if (in_array($code, self::PLATFORM_CODES, true)) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Platform,
                null,
                $passability,
            );
        }

        throw new JmhzTransportException(
            'jmhz_protocol_error_code_unknown',
            "Kód chyby {$code} není v doloženém katalogu ani v rozsahu kontrol DIS a cJMHZ.",
        );
    }

    /**
     * Propustnost z textu popisu, ne z připnutého katalogu (viz komentář
     * u {@see self::PASSABILITY_PREFIX}). Jediné sdílené místo, kudy prochází
     * všechna volání {@see self::fromCode()} — parser sem posílá text popisu
     * beze změny.
     *
     * Záměrně fail-closed: co nejde rozpoznat (starší protokol prefix
     * neuvádí, neočekávaný tvar), je „neuvedeno", nikdy tiše „propustná".
     */
    private static function derivePassability(string $message): JmhzControlPassability
    {
        if (preg_match(self::PASSABILITY_PREFIX, $message, $matches) !== 1) {
            return JmhzControlPassability::Unspecified;
        }

        return stripos($matches[1], 'ne') === 0
            ? JmhzControlPassability::Blocking
            : JmhzControlPassability::Passable;
    }

    public function requireControlId(): JmhzControlId
    {
        if ($this->controlId === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_not_a_control',
                "Kód chyby {$this->code} neodkazuje na kontrolu z katalogu.",
            );
        }

        return $this->controlId;
    }
}

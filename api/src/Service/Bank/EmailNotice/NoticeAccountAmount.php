<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Částka avíza v měně účtu, na který pohyb patří (#390).
 *
 * Pohyb na účtu se eviduje vždy v měně účtu. Avízo o platbě kartou v cizí měně ale
 * často nese jen částku nákupu (18,00 EUR na korunovém účtu): banka ji v tu chvíli
 * jen blokuje a korunovou částku určí až zúčtováním svým kurzem. Dřív se taková
 * částka zapsala s měnou účtu, takže z 18 EUR vznikl pohyb −18 Kč.
 *
 * Pravidla:
 *   - měna avíza odpovídá měně účtu (nebo ji avízo neuvádí a parser dosadil výchozí,
 *     #160) → částka se bere, jak je; původní částka se uloží, uvádí-li ji avízo,
 *   - avízo uvádí částku v cizí měně a vedle ní i částku v měně účtu → pohyb nese
 *     částku v měně účtu a cizí částka jde do `original_amount`/`original_currency`,
 *   - avízo nese jen cizí částku → pohyb nevznikne (výjimka). Přepočet kurzem ČNB by
 *     dal jinou částku, než jakou banka odepíše, pohyb by se pak se zúčtováním z výpisu
 *     nespároval a v evidenci by zůstal dvakrát. Pohyb doplní výpis.
 */
final class NoticeAccountAmount
{
    private function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?float $originalAmount,
        public readonly ?string $originalCurrency,
    ) {}

    /**
     * @throws ForeignCurrencyNoticeException avízo nemá částku v měně účtu
     */
    public static function resolve(ParsedBankEmailNotice $notice, ?string $accountCurrency): self
    {
        $account = strtoupper(trim((string) $accountCurrency));
        $noticeCurrency = strtoupper(trim($notice->currency));
        $original = strtoupper(trim((string) $notice->originalCurrency));
        $hasOriginal = $notice->originalAmount !== null && $original !== '';

        if ($account === '' || !$notice->currencyStated || $noticeCurrency === $account) {
            $currency = $account !== '' ? $account : $noticeCurrency;
            $keepOriginal = $hasOriginal && $original !== $currency;
            return new self(
                $notice->amount,
                $currency,
                $keepOriginal ? self::signed((float) $notice->originalAmount, $notice->amount) : null,
                $keepOriginal ? $original : null,
            );
        }

        // Šablona zachytila do `amount` cizí částku a do `original_amount` tu v měně účtu.
        if ($hasOriginal && $original === $account) {
            $amount = self::signed((float) $notice->originalAmount, $notice->amount);
            return new self($amount, $account, self::signed($notice->amount, $amount), $noticeCurrency);
        }

        throw new ForeignCurrencyNoticeException(sprintf(
            'Avízo uvádí částku %s %s, účet je ale vedený v %s a částku v měně účtu avízo nenese. '
            . 'Pohyb nebyl založen, aby se cizí částka nezapsala jako %s; doplní ho bankovní výpis.',
            number_format($notice->amount, 2, ',', ' '),
            $noticeCurrency,
            $account,
            $account,
        ));
    }

    /** Částka se znaménkem pohybu (výdej záporně), i když ji avízo uvádí bez znaménka. */
    private static function signed(float $value, float $movement): float
    {
        return $movement < 0 ? -abs($value) : abs($value);
    }
}

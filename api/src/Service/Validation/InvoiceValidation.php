<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Oss\OssItemDecision;
use MyInvoice\Service\Oss\OssPeriod;
use MyInvoice\Support\PaymentMethods;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\InvoiceRounding;

final class InvoiceValidation
{
    /**
     * Poslední záchrana, když volající zemi dodavatele nepředal. Není to definice
     * tuzemska — ta je JEN v `\MyInvoice\Service\Oss\OssItemDeriver::domesticCountry()`.
     * Hodnota je shodná s fallbackem, který tam používá `supplierSettings()`, když
     * dodavatel zemi vyplněnou nemá; jakákoli jiná by obě vrstvy rozešla přesně
     * u dodavatele identifikovaného mimo ČR.
     *
     * Obě produkční cesty (`CreateInvoiceAction`, `UpdateInvoiceAction`) zemi dodavatele
     * PŘEDÁVAJÍ. Fallback tu tedy nezůstal jako pohodlí volajícího — je to jen chování
     * pro volání bez kontextu dodavatele (testy, jednorázové skripty), aby se z chybějícího
     * argumentu nestala tichá výjimka z kontroly.
     */
    private const FALLBACK_DOMESTIC_COUNTRY = 'CZ';

    /**
     * @param array<int, float>|null  $vatRates
     * @param array<int, string>|null $vatRateCountries mapa `vat_rates.id` → `vat_rates.country`
     * @param ?string                 $domesticCountry  země dodavatele; volající ji bere
     *                                z `\MyInvoice\Service\Oss\OssItemDeriver::domesticCountry($supplierId)`,
     *                                aby validace a derivace OSS mluvily o témž tuzemsku.
     *                                K čemu slouží, viz komentář u kontroly cizí sazby níž
     * @param bool                    $allowRefundInvoice `supplier.allow_refund_invoices`
     * @param ?string                 $currencyCode kód měny dokladu (zaokrouhlení částky k vyplacení)
     * @return array<string, string[]>
     */
    public static function invoice(
        array $data,
        ?array $vatRates = null,
        ?array $vatRateCountries = null,
        ?string $domesticCountry = null,
        bool $allowRefundInvoice = false,
        ?string $currencyCode = null,
    ): array {
        $err = [];
        $domestic = self::normalizedCountry($domesticCountry) ?? self::FALLBACK_DOMESTIC_COUNTRY;

        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation', 'tax_document', 'penalty', 'payment_calendar', 'rent_calendar', 'advance_calendar'], true)) {
            $err['invoice_type'][] = 'Neplatný typ dokladu';
        }

        // Doména hodnot je sdílená s přijatými fakturami (migrace 1128) — jeden zdroj
        // pravdy v PaymentMethods, ať se ENUM v DB a whitelisty nerozejdou.
        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== null && $data['payment_method'] !== '') {
            if (!PaymentMethods::isValid($data['payment_method'])) {
                $err['payment_method'][] = 'Neplatný způsob úhrady';
            }
        }

        if (array_key_exists('rounding_mode', $data)
            && (!is_string($data['rounding_mode']) || !in_array($data['rounding_mode'], InvoiceRounding::MODES, true))) {
            $err['rounding_mode'][] = 'Neplatný způsob zaokrouhlení faktury';
        }

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }

        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }

        if (!empty($data['issue_date']) && !self::isValidDate((string) $data['issue_date'])) {
            $err['issue_date'][] = 'Neplatné datum vystavení';
        }
        if (!empty($data['due_date']) && !self::isValidDate((string) $data['due_date'])) {
            $err['due_date'][] = 'Neplatné datum splatnosti';
        }
        if ($type !== 'proforma' && !empty($data['tax_date']) && !self::isValidDate((string) $data['tax_date'])) {
            $err['tax_date'][] = 'Neplatné DUZP';
        }
        if (isset($data['payment_schedule']) && is_array($data['payment_schedule'])) {
            foreach (array_values($data['payment_schedule']) as $i => $row) {
                if (!is_array($row)) {
                    $err["payment_schedule.{$i}"][] = 'Neplatná splátka';
                    continue;
                }
                if (!empty($row['due_on']) && !self::isValidDate((string) $row['due_on'])) {
                    $err["payment_schedule.{$i}.due_on"][] = 'Neplatné datum splatnosti';
                }
                if (!empty($row['tax_date']) && !self::isValidDate((string) $row['tax_date'])) {
                    $err["payment_schedule.{$i}.tax_date"][] = 'Neplatné DUZP';
                }
            }
        }

        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $err['items'][] = 'items musí být pole';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $err["items.{$i}"][] = 'Neplatná položka';
                    continue;
                }
                $err = array_merge($err, InvoiceAmountPolicy::validateItem($item, $i, allowText: true));
                // Textový řádek nic nevykazuje: sazba, OSS ani časové rozlišení se na něm
                // nekontrolují, zápis je vynuluje ({@see InvoiceItemKind::normalize()}).
                if (InvoiceItemKind::isText($item)) {
                    continue;
                }

                // Časové rozlišení výnosu (384): obě data, nebo žádné; od <= do.
                $accrualFrom = trim((string) ($item['accrual_from'] ?? ''));
                $accrualTo = trim((string) ($item['accrual_to'] ?? ''));
                if ($accrualFrom !== '' || $accrualTo !== '') {
                    if (!self::isValidDate($accrualFrom) || !self::isValidDate($accrualTo)) {
                        $err["items.{$i}.accrual_from"][] = 'Období časového rozlišení potřebuje obě data ve formátu RRRR-MM-DD';
                    } elseif ($accrualFrom > $accrualTo) {
                        $err["items.{$i}.accrual_to"][] = 'Konec období časového rozlišení nesmí předcházet jeho začátku';
                    }
                }

                // POZOR, co tenhle test JE a co NENÍ.
                //
                // Je to kontrola KONZISTENCE ZADÁNÍ: uživatel vybral sazbu, kterou si sám
                // označil jako cizí, a přitom řádek nezařadil do OSS. Nic víc.
                //
                // NENÍ to pojistka proti tomu, aby cizí daň spadla do českého přiznání.
                // `vat_rates.country` je uživatelem editovatelný ŠTÍTEK s předvyplněnou CZ,
                // ne fakt o místě plnění: zákazník z analýzy OSS má sazbu „PL-23" založenou
                // se zemí CZ, takže na jeho konfiguraci tenhle test nezakáže vůbec nic.
                // Kdo místo plnění rozhoduje, je {@see OssItemDeriver} proti číselníku sazeb
                // ČLENSKÝCH STÁTŮ, který uživatel needituje — a ten na nepotvrzenou sazbu
                // položku odmítne, místo aby ji nechal projít jako tuzemskou.
                //
                // „Tuzemsko" se proto bere ze země DODAVATELE, ne z natvrdo zapsané 'CZ':
                // dvě různé definice téhož pojmu jsou přesně ta třída chyby, kvůli které
                // se pravidlo implementuje na jedné větvi a na druhé ne.
                if ($vatRateCountries !== null && empty($item['oss_applicable']) && !empty($item['vat_rate_id'])) {
                    $rateCountry = self::normalizedCountry($vatRateCountries[(int) $item['vat_rate_id']] ?? null);
                    if ($rateCountry !== null && $rateCountry !== $domestic) {
                        $err["items.{$i}.vat_rate_id"][] = 'Zahraniční sazbu DPH lze použít jen na řádku v režimu OSS';
                    }
                }

                if (!empty($item['oss_applicable'])) {
                    $country = strtoupper(trim((string) ($item['oss_consumer_country'] ?? '')));
                    if (!preg_match('/^[A-Z]{2}$/', $country)) {
                        $err["items.{$i}.oss_consumer_country"][] = 'Země spotřeby musí být dvoupísmenný ISO kód';
                    }

                    // Prázdný typ sazby je legitimní stav „zatím nezjištěno" — import ho neumí
                    // odvodit, když číselník členských států sazbu ve státě spotřeby nezná.
                    // Blokovat uložení by znamenalo, že takový řádek nejde ani zaevidovat, ani
                    // pak opravit. Do podání se stejně nedostane: OssLedgerService na něj varuje
                    // a OssXmlExporter::rateTypeCode(null) ho do XML nepustí. Neprázdná hodnota
                    // mimo whitelist je pořád chyba — to není nevědomost, to je překlep.
                    $rateType = trim((string) ($item['oss_rate_type'] ?? ''));
                    if ($rateType !== '' && !in_array($rateType, OssItemDecision::RATE_TYPES, true)) {
                        $err["items.{$i}.oss_rate_type"][] = 'Neplatný typ OSS sazby';
                    }
                    // Typ plnění zůstává povinný — uvolnění se týká JEN typu sazby. Bez
                    // goods/services nemá řádek v podání kam patřit a odvodit se nedá.
                    $supplyType = (string) ($item['oss_supply_type'] ?? '');
                    if (!in_array($supplyType, OssItemDecision::SUPPLY_TYPES, true)) {
                        $err["items.{$i}.oss_supply_type"][] = 'Typ OSS plnění musí být zboží nebo služba';
                    }

                    $rateValue = $item['oss_exchange_rate'] ?? null;
                    if ($rateValue !== null && $rateValue !== '') {
                        if (!is_numeric($rateValue) || !is_finite((float) $rateValue)
                            || (float) $rateValue <= 0 || (float) $rateValue > 1000000
                        ) {
                            $err["items.{$i}.oss_exchange_rate"][] = 'OSS kurz musí být kladné číslo v podporovaném rozsahu';
                        }
                    }
                    $rateDate = (string) ($item['oss_exchange_rate_date'] ?? '');
                    if ($rateDate !== '' && !self::isValidDate($rateDate)) {
                        $err["items.{$i}.oss_exchange_rate_date"][] = 'Neplatné datum OSS kurzu';
                    }

                    $manualAmounts = [];
                    foreach (['oss_taxable_amount_return', 'oss_vat_amount_return'] as $field) {
                        $value = $item[$field] ?? null;
                        $manualAmounts[$field] = $value !== null && $value !== '';
                        if ($manualAmounts[$field]
                            && (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 999999999999.99)
                        ) {
                            $err["items.{$i}.{$field}"][] = 'OSS částka je mimo podporovaný rozsah';
                        }
                    }
                    if ($manualAmounts['oss_taxable_amount_return'] !== $manualAmounts['oss_vat_amount_return']) {
                        $err["items.{$i}.oss_taxable_amount_return"][] = 'Ruční OSS základ a DPH musí být vyplněny společně';
                    }

                    $originalPeriod = strtoupper(trim((string) ($item['oss_original_period'] ?? '')));
                    if ($originalPeriod !== '') {
                        if (!preg_match('/^[0-9]{4}Q[1-4]$/', $originalPeriod) || $originalPeriod < '2021Q3') {
                            $err["items.{$i}.oss_original_period"][] = 'Původní OSS období musí být ve formátu RRRRQn a nejdříve Q3 2021';
                        } else {
                            $taxDate = (string) ($data['tax_date'] ?? $data['issue_date'] ?? '');
                            $currentPeriod = OssPeriod::quarterCode($taxDate);
                            if ($currentPeriod !== null && $originalPeriod >= $currentPeriod) {
                                $err["items.{$i}.oss_original_period"][] = 'Původní OSS období musí předcházet období dokladu';
                            }
                        }
                    }
                }
            }
        }

        $advance = (float) ($data['advance_paid_amount'] ?? 0);
        if ($advance < 0) {
            $err['advance_paid_amount'][] = 'Záloha nesmí být záporná';
        }

        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $data['discount_percent'] !== '') {
            if (!is_numeric($data['discount_percent'])) {
                $err['discount_percent'][] = 'Sleva musí být číslo';
            } else {
                $d = (float) $data['discount_percent'];
                if ($d < 0 || $d > 100) {
                    $err['discount_percent'][] = 'Sleva musí být mezi 0 a 100 %';
                }
            }
        }

        if ($vatRates !== null) {
            $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay($data, $vatRates, $allowRefundInvoice, $currencyCode);
            if ($amountError !== null) {
                $err['amount_to_pay'][] = $amountError;
            }
        }

        // Volitelný manuální varsymbol u draftu (override automatického číslování).
        // Prázdný / chybějící = generuje se při issue. Max 20 znaků (DB limit).
        if (array_key_exists('varsymbol', $data) && $data['varsymbol'] !== null && $data['varsymbol'] !== '') {
            $vs = (string) $data['varsymbol'];
            if (strlen($vs) > 20) {
                $err['varsymbol'][] = 'Číslo faktury má max 20 znaků';
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $vs)) {
                $err['varsymbol'][] = 'Číslo faktury obsahuje neplatné znaky';
            }
        }

        // Platební VS (#249) — volitelný, jen číslice, max 10. Nemusí být unikátní.
        if (array_key_exists('payment_variable_symbol', $data)) {
            try {
                InvoiceRepository::normalizePaymentVariableSymbol($data['payment_variable_symbol']);
            } catch (\InvalidArgumentException $e) {
                $err['payment_variable_symbol'][] = $e->getMessage();
            }
        }

        return $err;
    }

    /**
     * NEBLOKUJÍCÍ varování k uloženému dokladu — zrcadlo
     * {@see PurchaseInvoiceValidation::warnings()} na vydané větvi.
     *
     * @param array<string,mixed> $invoice uložený doklad po přepočtu (nese totály)
     * @return list<string> kódy varování pro i18n `invoice.warning.*`
     */
    public static function warnings(array $invoice): array
    {
        $warn = [];

        // Dobropis (opravný daňový doklad) má dle metodiky záporné částky. Kladný
        // součet znamená, že se znaménko neotočilo ani jednou — base = qty × price
        // vyjde kladně a ve výkazech DPH/KH se plnění PŘIČTE místo odečtení
        // (DPHDP3 ř. 1/2, KH A.4), zatímco deník ho obrátí správně
        // (PostingService::buildFromInvoice podle invoice_type) → deník a přiznání
        // si protiřečí. Zrcadlo issue #35 z přijaté větve.
        //
        // Pozor: dvojí negaci (záporné množství I cena) blokuje už
        // InvoiceAmountPolicy::validateItem na obou větvích. Tady jde o opačný případ —
        // žádná negace. UI sice u dobropisu předvyplňuje záporné množství, ale je to
        // jen klientská pomůcka, kterou lze přepsat (a API volání ji obejde úplně).
        if ((string) ($invoice['invoice_type'] ?? 'invoice') === 'credit_note') {
            $totalBase = (float) ($invoice['total_without_vat'] ?? 0);
            if ($totalBase > 0.005) {
                $warn[] = 'credit_note_positive_total';
            }
        }

        return $warn;
    }

    /**
     * ISO2 kód země, nebo `null` u prázdné či nesmyslné hodnoty. Porovnávat neořezané
     * řetězce by kontrolu cizí sazby uspalo na hodnotě `'cz '` z ručně editovaného
     * číselníku — a mlčky uspaná kontrola je horší než žádná.
     */
    private static function normalizedCountry(mixed $value): ?string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}

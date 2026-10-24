<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\BankSymbols;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;

/** Jediný čitelný zdrojový plán pro zápis daňové evidence a zkoušku nanečisto. */
final class StereoNxSourcePlan
{
    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    public static function partners(array $rows, bool $blankCountryIsCz, array $partnerCountries = []): array
    {
        $partnerCountries = StereoNxPartnerCountries::configuration($partnerCountries);
        $clients = [];
        foreach ($rows as $row) {
            $key = self::nonempty($row, 'Firma');
            if (isset($clients[$key])) throw new StereoNxException('client_duplicate', 'Duplicitní identita firmy v adresáři.');
            $countrySource = self::first($row, ['FakStat', 'Stat']);
            $country = self::country($countrySource, (string) ($row['DIC'] ?? ''), isset($partnerCountries[$key]) ? false : $blankCountryIsCz);
            $mainCountry = trim((string) ($row['Stat'] ?? ''));
            $billingCountry = trim((string) ($row['FakStat'] ?? ''));
            if ($mainCountry !== '' && $billingCountry !== ''
                && self::country($mainCountry, '', false)['code'] !== self::country($billingCountry, '', false)['code']) {
                $country['unresolved'] = true;
            }
            $country = StereoNxPartnerCountries::resolve($country, $key, $partnerCountries);
            $clients[$key] = ['source_key' => $key, 'row' => $row,
                'name' => self::first($row, ['FakNazev', 'Nazev']),
                'ico' => trim((string) ($row['ICO'] ?? '')), 'dic' => trim((string) ($row['DIC'] ?? '')),
                'street' => self::first($row, ['FakUlice', 'Ulice']),
                'city' => self::first($row, ['FakMisto', 'Misto']),
                'zip' => self::first($row, ['FakPSC', 'PSC']),
                'country' => self::first($row, ['FakStat', 'Stat']),
                'country_code' => $country['code'], 'country_unresolved' => $country['unresolved'],
                'email' => trim((string) ($row['Email'] ?? '')),
                'phone' => trim((string) ($row['Telefon'] ?? '')),
                'is_customer' => ($row['Odberatel'] ?? false) === true,
                'is_vendor' => ($row['Dodavatel'] ?? false) === true];
        }
        return $clients;
    }

    /** @return array<string,mixed> */
    public static function build(StereoNxBackup $backup, bool $blankCountryIsCz = false, array $partnerCountries = []): array
    {
        $backup->assertBankAccountSeriesReady();
        $inventory = $backup->inventory();
        $supportedPopulated = [
            'CBanka', 'CBankap', 'Cdenik', 'CPokl', 'Cpz', 'CPZZ', 'SPFH', 'Spfp', 'Svfh', 'Svfp', 'ZAZPVDPH',
            'DVERSION', 'JParamMaj', 'Ktskp', 'KTypyPos', 'KZasilky', 'LAdresy', 'LAdruct',
            'Lagendy', 'LAlgorit', 'Ldoklady', 'Ldruhy', 'LFirma', 'LFirmaUc', 'LKlice',
            'LKodyCPA', 'Lmeny', 'Lrozvrh', 'Lsdph', 'LSkZazPv', 'LSkZPv11', 'LSkZPv15',
            'LSKZPV17', 'LSloupce', 'Ltexty', 'Ltridy', 'MDruhD', 'Modvody', 'MOdvPar',
            'MPARUCT', 'MPARZPR', 'Mplatidl', 'MSkladby', 'Mstav', 'Mtridy', 'Mvzdel',
            'SParamSkl', 'SSklady', 'STypPoh',
        ];
        foreach ($inventory as $name => $entry) {
            if ($entry['status'] !== 'ok') throw new StereoNxException('source_decode', 'Některou tabulku NX1 nelze úplně načíst.');
            if ($entry['decoded_rows'] > 0 && !in_array($name, $supportedPopulated, true)) {
                throw new StereoNxException('source_agenda_unsupported', 'Záloha obsahuje naplněnou dosud nemapovanou agendu.');
            }
        }
        $required = ['LAdresy', 'LFirmaUc', 'Lsdph', 'LSloupce', 'Svfh', 'Svfp', 'SPFH',
            'Spfp', 'Cpz', 'CPZZ', 'ZAZPVDPH', 'CBanka', 'CBankap', 'CPokl', 'Cdenik'];
        $tables = [];
        foreach ($required as $name) $tables[$name] = iterator_to_array($backup->rows($name), false);
        $plan = self::fromTables($tables, $backup->companyIdentity(), $blankCountryIsCz, partnerCountries: $partnerCountries);
        $plan['source_company_index'] = $backup->companyIndex();
        return $plan;
    }

    /**
     * Čistá cesta pro syntetické testy; chybějící agendy nesmí potichu znamenat nulu.
     *
     * Rok v identitě dokladu, výpisu a pohybu je účetní období firmy: Stereo čísluje
     * v rámci účetního roku. Daňová evidence (FO) je vždy kalendářní, proto bez
     * kalendáře platí kalendářní rok; účetní převod předává kalendář firmy.
     * @param array<string,list<array<string,mixed>>> $tables
     * @param array{ico:string,dic:string,name:string,vat_payer:bool} $identity
     * @return array<string,mixed>
     */
    public static function fromTables(array $tables, array $identity, bool $blankCountryIsCz = false, bool $documentsOnly = false,
        ?FiscalCalendar $calendar = null, array $partnerCountries = []): array
    {
        $partnerCountries = StereoNxPartnerCountries::configuration($partnerCountries);
        $calendar ??= FiscalCalendar::calendar();
        foreach (['LAdresy', 'LFirmaUc', 'Lsdph', 'LSloupce', 'Svfh', 'Svfp', 'SPFH',
            'Spfp', 'Cpz', 'CPZZ', 'ZAZPVDPH', 'CBanka', 'CBankap', 'CPokl', 'Cdenik'] as $name) {
            if (!array_key_exists($name, $tables)) throw new StereoNxException('source_table_missing', 'Chybí zdrojová agenda pro úplný převod.');
        }
        if (!preg_match('/^[0-9]{8}$/D', $identity['ico'] ?? '') || ($identity['vat_payer'] ?? null) !== true) {
            throw new StereoNxException('company_identity', 'Import vyžaduje ověřené IČO a režim plátce DPH.');
        }
        $vat = new StereoNxVat($tables['Lsdph']);
        $purchaseRecap = new StereoNxPurchaseRecap($vat);
        $issuedMapper = new StereoNxIssuedDocuments($vat);
        $reconciliation = StereoNxPaymentReconciliation::check($tables['Cpz'], $tables['CBankap'], $tables['CPokl']);
        if (!$documentsOnly && !$reconciliation['ok']) throw new StereoNxException('payment_reconciliation', 'Zdrojové vazby a součty úhrad nesouhlasí.');

        $clients = self::partners($tables['LAdresy'], $blankCountryIsCz, $partnerCountries);
        $lines = [];
        $lineKeys = [];
        foreach ($tables['Svfp'] as $row) {
            $document = self::docKey($row);
            $line = $row['Klic'] ?? null;
            if (!is_int($line) || isset($lineKeys[$document][$line])) {
                throw new StereoNxException('issued_line_identity', 'Položka vydaného dokladu nemá jednoznačné pořadí.');
            }
            $lineKeys[$document][$line] = true;
            $lines[$document][$line] = $row;
        }
        foreach ($lines as &$documentLines) {
            ksort($documentLines, SORT_NUMERIC);
            $documentLines = array_values($documentLines);
        }
        unset($documentLines);
        $purchaseLines = [];
        $purchaseLineKeys = [];
        foreach ($tables['Spfp'] as $row) {
            $document = self::docKey($row);
            $line = $row['Klic'] ?? null;
            if (!is_int($line) || isset($purchaseLineKeys[$document][$line])) {
                throw new StereoNxException('purchase_line_identity', 'Položka přijatého dokladu nemá jednoznačné pořadí.');
            }
            $purchaseLineKeys[$document][$line] = true;
            $purchaseLines[$document][$line] = $row;
        }
        foreach ($purchaseLines as &$documentLines) {
            ksort($documentLines, SORT_NUMERIC);
            $documentLines = array_values($documentLines);
        }
        unset($documentLines);
        if (!$documentsOnly && $tables['Spfp'] !== []) {
            throw new StereoNxException('purchase_lines_unsupported', 'Položky přijatých dokladů vyžadují samostatné mapování.');
        }

        $documents = [];
        $documentsByBareKey = [];
        $issued = [];
        $purchases = [];
        foreach ([['Svfh', 'issued'], ['SPFH', 'purchase']] as [$table, $kind]) {
            foreach ($tables[$table] as $row) {
                $bareKey = self::docKey($row);
                $key = self::annualKey($row, 'DoklSRada', 'DoklSCislo', 'KdyVyhotUD', $calendar);
                if (isset($documents[$key]) || isset($documentsByBareKey[$bareKey])) {
                    // Platby Stereo NX rok nenesou. Stejná řada a číslo napříč roky by byly nejednoznačné.
                    throw new StereoNxException('document_duplicate', 'Nejednoznačná identita dokladu napříč obdobími.');
                }
                $partner = trim((string) ($row['Firma'] ?? ''));
                if ($partner === '') {
                    // Starší doklady mohou mít pouze historický snapshot bez živé vazby do adresáře.
                    $partner = 'snapshot:' . $kind . ':' . $key;
                    if (strlen($partner) > 190) throw new StereoNxException('source_key_too_long', 'Identita historické protistrany přesahuje limit importní mapy.');
                    $snapshotName = self::first($row, ['FirmaNazev', 'FirmaJmeno']);
                    $country = StereoNxPartnerCountries::resolve(self::country((string) ($row['FirmaStat'] ?? ''),
                        (string) ($row['FirmaDIC'] ?? ''), isset($partnerCountries[$partner]) ? false : $blankCountryIsCz), $partner, $partnerCountries);
                    $clients[$partner] = ['source_key' => $partner, 'row' => [],
                        'name' => $snapshotName !== '' ? $snapshotName : 'Neurčená protistrana',
                        'ico' => trim((string) ($row['FirmaICO'] ?? '')),
                        'dic' => trim((string) ($row['FirmaDIC'] ?? '')),
                        'street' => trim((string) ($row['FirmaUlice'] ?? '')),
                        'city' => trim((string) ($row['FirmaMisto'] ?? '')),
                        'zip' => trim((string) ($row['FirmaPSC'] ?? '')),
                        'country' => trim((string) ($row['FirmaStat'] ?? '')),
                        'country_code' => $country['code'], 'country_unresolved' => $country['unresolved'],
                        'email' => '', 'phone' => '',
                        'is_customer' => $kind === 'issued', 'is_vendor' => $kind === 'purchase'];
                } elseif (!isset($clients[$partner])) {
                    if (!$documentsOnly) throw new StereoNxException('document_partner_missing', 'Doklad odkazuje na chybějící firmu.');
                    $partner = 'snapshot:' . $kind . ':' . $key;
                    $snapshotName = self::first($row, ['FirmaNazev', 'FirmaJmeno']);
                    $country = StereoNxPartnerCountries::resolve(self::country((string) ($row['FirmaStat'] ?? ''),
                        (string) ($row['FirmaDIC'] ?? ''), isset($partnerCountries[$partner]) ? false : $blankCountryIsCz), $partner, $partnerCountries);
                    $clients[$partner] = ['source_key' => $partner, 'row' => [],
                        'name' => $snapshotName !== '' ? $snapshotName : 'Neurčená protistrana',
                        'ico' => trim((string) ($row['FirmaICO'] ?? '')), 'dic' => trim((string) ($row['FirmaDIC'] ?? '')),
                        'street' => trim((string) ($row['FirmaUlice'] ?? '')), 'city' => trim((string) ($row['FirmaMisto'] ?? '')),
                        'zip' => trim((string) ($row['FirmaPSC'] ?? '')), 'country' => trim((string) ($row['FirmaStat'] ?? '')),
                        'country_code' => $country['code'], 'country_unresolved' => $country['unresolved'],
                        'email' => '', 'phone' => '', 'is_customer' => $kind === 'issued', 'is_vendor' => $kind === 'purchase'];
                }
                $currency = self::currencyCode($row['Mena'] ?? null);
                if (!$documentsOnly) {
                    // Původní převod daňové evidence zůstává fail-closed; rozšířené
                    // měny, storna a neúplné položky zpracovává jen účetní modul.
                    $mapped = $kind === 'issued'
                        ? $issuedMapper->plan($row, $lines[$bareKey] ?? [])
                        : $purchaseRecap->plan($row);
                    $mapped['exchange_rate'] = null;
                } elseif ($currency === 'CZK') {
                    $sourceItems = $kind === 'issued' ? ($lines[$bareKey] ?? []) : ($purchaseLines[$bareKey] ?? []);
                    if (self::untaxedAdvanceWithoutItems($row, $sourceItems)) {
                        $mapped = self::advanceTotal($row);
                    } elseif ($kind === 'issued') {
                        try {
                            $mapped = $issuedMapper->plan($row, $lines[$bareKey] ?? [], true);
                        } catch (StereoNxException $e) {
                            if ($e->errorCode === 'issued_kind_unsupported') {
                                $mapped = self::unclassifiedDocument($row);
                                if (($row['Stornovano'] ?? null) === true) {
                                    $mapped['review_codes'][] = 'cancelled_document_review';
                                }
                                $mapped['requires_draft'] = true;
                            } elseif ($e->errorCode !== 'issued_line_unsupported') {
                                throw $e;
                            } else {
                            try {
                                $mapped = $issuedMapper->plan($row, [], true);
                            } catch (StereoNxException $fallback) {
                                if ($fallback->errorCode !== 'issued_empty') throw $fallback;
                                $mapped = self::unclassifiedDocument($row);
                            }
                            $mapped['review_codes'][] = 'issued_lines_aggregated';
                            $mapped['requires_draft'] = true;
                            }
                        }
                    } else {
                        try {
                            $mapped = $purchaseRecap->plan($row, true);
                            if (($purchaseLines[$bareKey] ?? []) !== []) {
                                try {
                                    $mapped = $purchaseRecap->planWithLines($row, $purchaseLines[$bareKey]);
                                } catch (StereoNxException $lineError) {
                                    if ($lineError->errorCode !== 'purchase_line_unsupported') throw $lineError;
                                    $mapped['review_codes'][] = 'purchase_lines_aggregated';
                                    $mapped['requires_draft'] = true;
                                }
                            }
                        } catch (StereoNxException $e) {
                            if (!in_array($e->errorCode, ['purchase_empty_recap', 'purchase_cancelled', 'purchase_kind_unsupported'], true)) throw $e;
                            $mapped = self::unclassifiedDocument($row);
                            $mapped['review_codes'][] = $e->errorCode === 'purchase_cancelled'
                                ? 'cancelled_document_review' : 'document_tax_mapping_unverified';
                            $mapped['requires_draft'] = true;
                        }
                    }
                    $mapped['exchange_rate'] = null;
                } else {
                    $mapped = self::foreignDocument($row,
                        $kind === 'issued' ? ($lines[$bareKey] ?? []) : ($purchaseLines[$bareKey] ?? []), $kind, $currency, $vat);
                }
                $mapped['currency_code'] = $currency;
                $mapped['source_key'] = $key;
                $mapped['header'] = $row;
                $mapped['partner_key'] = $partner;
                $snapshotCountry = self::country((string) ($row['FirmaStat'] ?? ''), (string) ($row['FirmaDIC'] ?? ''),
                    isset($partnerCountries[$partner]) ? false : $blankCountryIsCz);
                $historicalIco = PartnerIdentityMatcher::ico((string) ($row['FirmaICO'] ?? ''));
                $partnerIco = PartnerIdentityMatcher::ico((string) ($clients[$partner]['ico'] ?? ''));
                $historicalDic = PartnerIdentityMatcher::vatId((string) ($row['FirmaDIC'] ?? ''));
                $partnerDic = PartnerIdentityMatcher::vatId((string) ($clients[$partner]['dic'] ?? ''));
                // Potvrzení adresáře nesmí vyřešit zemi historicky jiného subjektu.
                // Stejnou normalizaci a konflikt DIČ používá importClient při párování karty.
                $differentHistoricalPartner = ($historicalIco !== '' && $partnerIco !== '' && $historicalIco !== $partnerIco)
                    || ($historicalDic !== '' && $partnerDic !== '' && $historicalDic !== $partnerDic);
                if (!$differentHistoricalPartner) {
                    $snapshotCountry = StereoNxPartnerCountries::resolve($snapshotCountry, $partner, $partnerCountries);
                }
                $mapped['partner_snapshot'] = [
                    'name' => self::first($row, ['FirmaNazev', 'FirmaJmeno']),
                    'ico' => trim((string) ($row['FirmaICO'] ?? '')),
                    'dic' => trim((string) ($row['FirmaDIC'] ?? '')),
                    'street' => trim((string) ($row['FirmaUlice'] ?? '')),
                    'city' => trim((string) ($row['FirmaMisto'] ?? '')),
                    'zip' => trim((string) ($row['FirmaPSC'] ?? '')),
                    'country' => trim((string) ($row['FirmaStat'] ?? '')),
                    'country_code' => $snapshotCountry['code'],
                    'country_unresolved' => $snapshotCountry['unresolved'],
                ];
                $mapped['document_no'] = self::nonempty($row, 'DokladS');
                $mapped['vendor_number'] = trim((string) ($row['EvidCislo'] ?? ''));
                $mapped['issue_date'] = self::date($row, 'KdyVyhotUD');
                $sourceTaxDate = self::optionalDate($row, 'DatumDPH');
                $sourceSupplyDate = self::optionalDate($row, 'KdyUskutUP');
                $mapped['supply_date'] = $sourceSupplyDate ?? $mapped['issue_date'];
                $mapped['tax_date'] = $sourceTaxDate ?? $mapped['supply_date'];
                $sourceType = strtoupper(trim((string) ($row['TypDokladu'] ?? '')));
                $untaxedAdvance = $documentsOnly && $sourceType === 'Z'
                    && ($row['ZpracovatDPH'] ?? null) === false;
                if ($documentsOnly && !$untaxedAdvance && ($sourceTaxDate === null || $sourceSupplyDate === null)) {
                    $mapped['review_codes'][] = 'document_tax_date_missing';
                    $mapped['requires_draft'] = true;
                }
                $mapped['due_date'] = self::optionalDate($row, 'DatumSpl');
                $mapped['variable_symbol'] = trim((string) ($row['VarSym'] ?? ''));
                $mapped['note'] = trim((string) ($row['Text'] ?? ''));
                $mapped['document_kind'] = $kind;
                $mapped['target_document_kind'] = $sourceType === 'D' ? 'credit_note'
                    : ($kind === 'issued' && in_array($sourceType, ['P', 'Z'], true) ? 'proforma'
                        : ($kind === 'purchase' && $sourceType === 'Z' ? 'advance' : 'invoice'));
                if (!in_array($sourceType, $kind === 'issued' ? ['F', 'D', 'P', 'Z'] : ['F', 'D', 'Z'], true)) {
                    $mapped['review_codes'][] = 'document_kind_unverified';
                    $mapped['requires_draft'] = true;
                }
                if ($mapped['tax_date'] !== $mapped['supply_date']) {
                    $mapped['review_codes'][] = 'tax_date_supply_mismatch';
                    $mapped['requires_draft'] = true;
                }
                if (str_starts_with($partner, 'snapshot:') && $clients[$partner]['name'] === 'Neurčená protistrana') {
                    $mapped['review_codes'][] = 'partner_identity_missing';
                    $mapped['requires_draft'] = true;
                }
                if ($clients[$partner]['country_unresolved']
                    || (isset($partnerCountries[$partner]) && $differentHistoricalPartner && $snapshotCountry['unresolved'])) {
                    $mapped['review_codes'][] = strtoupper(trim((string) ($clients[$partner]['country'] ?? ''))) === 'EU'
                        ? 'partner_country_eu_unspecified' : 'partner_country_unresolved';
                    $mapped['requires_draft'] = true;
                }
                $documents[$key] = $kind;
                $documentsByBareKey[$bareKey] = $key;
                if ($kind === 'issued') $issued[$key] = $mapped;
                else $purchases[$key] = $mapped;
            }
        }
        foreach (array_keys($lines) as $key) if (!isset($documentsByBareKey[$key]) || !isset($issued[$documentsByBareKey[$key]])) throw new StereoNxException('issued_orphan_line', 'Položka vydaného dokladu nemá hlavičku.');
        foreach (array_keys($purchaseLines) as $key) if (!isset($documentsByBareKey[$key]) || !isset($purchases[$documentsByBareKey[$key]])) throw new StereoNxException('purchase_orphan_line', 'Položka přijatého dokladu nemá hlavičku.');
        StereoNxPartnerCountries::assertKnown($partnerCountries, $clients);
        $countryCandidates = StereoNxPartnerCountries::candidates($clients, $issued, $purchases, $partnerCountries);
        $countryWarnings = StereoNxPartnerCountries::directoryWarnings($clients, $countryCandidates);
        if ($documentsOnly) {
            return ['partner_country_candidates' => $countryCandidates, 'partner_country_warnings' => $countryWarnings,
                'identity' => $identity, 'clients' => array_values($clients),
                'issued' => array_values($issued), 'purchases' => array_values($purchases),
                'counts' => ['clients' => count($clients), 'issued' => count($issued),
                    'purchases' => count($purchases),
                    'requires_draft' => count(array_filter([...$issued, ...$purchases],
                        static fn (array $d): bool => $d['requires_draft']))],
                'blockers' => []];
        }
        $cpz = [];
        foreach ($tables['Cpz'] as $row) {
            $bareKey = self::docKey($row);
            $key = $documentsByBareKey[$bareKey] ?? null;
            if ($key === null || !isset($documents[$key]) || isset($cpz[$key])
                || $calendar->fiscalYearOfDate(self::date($row, 'KdyVystaveno'))
                    !== $calendar->fiscalYearOfDate(self::date($documents[$key] === 'issued' ? $issued[$key]['header'] : $purchases[$key]['header'], 'KdyVyhotUD'))) {
                throw new StereoNxException('receivable_identity', 'Saldokonto má neznámou nebo duplicitní identitu.');
            }
            $cpz[$key] = $row;
            $docTotal = $documents[$key] === 'issued' ? $issued[$key]['source_total_with_vat'] : $purchases[$key]['source_total_with_vat'];
            if (self::cents($row['Celkem'] ?? null) !== self::cents($docTotal)) {
                throw new StereoNxException('receivable_total_mismatch', 'Saldokonto a doklad mají rozdílný celkový závazek nebo pohledávku.');
            }
        }
        if (count($cpz) !== count($documents)) throw new StereoNxException('receivable_missing', 'Pro některý doklad chybí zdrojový záznam saldokonta.');
        $controlReceivables = [];
        foreach ($tables['CPZZ'] as $row) {
            $bareKey = self::docKey($row);
            if (!isset($documentsByBareKey[$bareKey]) || isset($controlReceivables[$bareKey])
                || ($row['SmerPlatby'] ?? null) !== ($cpz[$documentsByBareKey[$bareKey]]['SmerPlatby'] ?? null)) {
                throw new StereoNxException('receivable_control', 'Kontrolní evidence pohledávek a závazků nesouhlasí.');
            }
            $controlReceivables[$bareKey] = true;
        }
        if (count($controlReceivables) !== count($documents)) throw new StereoNxException('receivable_control', 'Kontrolní evidence pohledávek a závazků není úplná.');
        $documentByNumber = [];
        foreach ([...$issued, ...$purchases] as $doc) {
            $number = $doc['document_no'];
            if (array_key_exists($number, $documentByNumber)) $documentByNumber[$number] = null;
            else $documentByNumber[$number] = $doc['source_key'];
        }
        $registeredVat = [];
        foreach ($tables['ZAZPVDPH'] as $row) {
            $documentNo = trim((string) ($row['Doklad'] ?? ''));
            if ($documentNo === '' || !isset($documentByNumber[$documentNo]) || $documentByNumber[$documentNo] === null) {
                throw new StereoNxException('vat_record_orphan', 'Kontrolní evidence DPH obsahuje nejednoznačný nebo osiřelý záznam.');
            }
            $kind = $documents[$documentByNumber[$documentNo]];
            if (($row['Agenda'] ?? null) !== ($kind === 'issued' ? 'VF' : 'PF')) {
                throw new StereoNxException('vat_record_direction', 'Kontrolní evidence DPH nesouhlasí se směrem dokladu.');
            }
            $key = $documentByNumber[$documentNo];
            $registeredVat[$key]['base'] = ($registeredVat[$key]['base'] ?? 0)
                + self::cents($row['Zaklad'] ?? null);
            $registeredVat[$key]['tax'] = ($registeredVat[$key]['tax'] ?? 0)
                + self::cents($row['DPH'] ?? null);
            $doc = $kind === 'issued' ? $issued[$key] : $purchases[$key];
            if (self::date($row, 'DatumDPH') !== $doc['tax_date']) {
                $registeredVat[$key]['date_mismatch'] = true;
            }
        }
        foreach ($registeredVat as $key => $registered) {
            $kind = $documents[$key];
            if ($kind === 'issued') $doc =& $issued[$key];
            else $doc =& $purchases[$key];
            $header = $doc['header'];
            $base = 0;
            $tax = 0;
            foreach (['z', 's', 't'] as $slot) {
                if (StereoNxRecapAmounts::isUnusedBucket($header, $slot)) continue;
                $base += self::cents($header['ZaklDPH' . $slot] ?? null);
                $tax += self::cents($header['DPH' . $slot] ?? null);
            }
            if (!StereoNxRecapAmounts::isUnusedBucket($header, '0')) $base += self::cents($header['BezDane'] ?? null);
            if ($base !== $registered['base'] || $tax !== $registered['tax']
                || ($registered['date_mismatch'] ?? false)) {
                $doc['review_codes'][] = 'vat_register_mismatch';
                $doc['requires_draft'] = true;
            }
            unset($doc);
        }

        $accounts = [];
        $accountBySeries = [];
        foreach ($tables['LFirmaUc'] as $row) {
            // Účet bez řady a bez navázaných výpisů nevytváří peněžní pohyby.
            if (trim((string) ($row['DoklRada'] ?? '')) === '') continue;
            $key = self::nonempty($row, 'DoklRada');
            if (isset($accounts[$key])) throw new StereoNxException('bank_account_duplicate', 'Bankovní řada nemá jednoznačný účet.');
            $accounts[$key] = ['source_key' => $key, 'row' => $row,
                'account_number' => trim((string) ($row['BaUcet'] ?? '')),
                'bank_code' => trim((string) ($row['KodBanky'] ?? '')),
                'iban' => trim((string) ($row['IBAN'] ?? '')),
                'label' => trim((string) ($row['NazevUctu'] ?? '')), 'currency' => 'CZK'];
            $accountBySeries[$key] = $key;
        }
        $statements = [];
        $statementsByBareKey = [];
        foreach ($tables['CBanka'] as $row) {
            $bareKey = self::physicalKey($row, false);
            $key = self::annualKey($row, 'DoklRada', 'DoklCislo', 'KdyVystaveno', $calendar);
            $series = self::nonempty($row, 'DoklRada');
            if (isset($statements[$key]) || isset($statementsByBareKey[$bareKey]) || !isset($accountBySeries[$series])) throw new StereoNxException('bank_statement_identity', 'Bankovní výpis nemá jednoznačnou řadu a účet.');
            $statements[$key] = ['source_key' => $key, 'row' => $row,
                'document_no' => self::nonempty($row, 'Doklad'),
                'date' => self::date($row, 'KdyVystaveno'), 'account_key' => $series];
            $statementsByBareKey[$bareKey] = $key;
        }

        $columns = [];
        foreach ($tables['LSloupce'] as $row) {
            $key = self::nonempty($row, 'Sloupec');
            if (isset($columns[$key])) throw new StereoNxException('cashbook_column_duplicate', 'Duplicitní sloupec peněžního deníku.');
            $columns[$key] = match ($row['Typ'] ?? null) {
                'P' => 'income_taxable', 'V' => 'expense_taxable',
                'OP' => 'income_nontax', 'OV' => 'expense_nontax',
                'X' => 'transfer',
                default => throw new StereoNxException('cashbook_column_unsupported', 'Neznámý typ sloupce peněžního deníku.'),
            };
        }
        $journal = [];
        foreach ($tables['Cdenik'] as $row) {
            $type = match ($row['Agenda'] ?? null) {
                'B' => 'bank', 'P' => 'cash',
                default => throw new StereoNxException('cashbook_agenda', 'Peněžní deník obsahuje nepodporovanou agendu.'),
            };
            $key = self::annualPhysicalKey($row, $type === 'bank', $calendar);
            $entryKey = $type . ':' . $key;
            if (isset($journal[$entryKey])) throw new StereoNxException('cashbook_duplicate', 'Duplicitní pohyb peněžního deníku.');
            $column = self::nonempty($row, 'Sloupec');
            if (!isset($columns[$column])) throw new StereoNxException('cashbook_column_missing', 'Pohybu chybí definice sloupce peněžního deníku.');
            $journal[$entryKey] = ['row' => $row, 'bucket' => $columns[$column], 'source_column' => $column];
        }
        $bank = [];
        $cash = [];
        $payments = [];
        $classifications = [];
        foreach ([['CBankap', 'bank'], ['CPokl', 'cash']] as [$table, $type]) {
            foreach ($tables[$table] as $row) {
                $key = self::annualPhysicalKey($row, $type === 'bank', $calendar);
                $entryKey = $type . ':' . $key;
                if (($type === 'bank' && isset($bank[$key])) || ($type === 'cash' && isset($cash[$key]))) {
                    throw new StereoNxException('movement_duplicate', 'Duplicitní identita bankovního nebo pokladního pohybu.');
                }
                if (!isset($journal[$entryKey])) throw new StereoNxException('cashbook_projection_missing', 'Fyzický pohyb nemá záznam peněžního deníku.');
                $j = $journal[$entryKey];
                if (self::cents($row['Castka'] ?? null) !== self::cents($j['row']['Celkem'] ?? null)
                    || self::date($row, 'KdyUcPripad') !== self::date($j['row'], 'KdyUcPripad')
                    || ($row['SmerPlatby'] ?? null) !== ($j['row']['SmerPlatby'] ?? null)) {
                    throw new StereoNxException('cashbook_projection_mismatch', 'Peněžní deník a fyzický pohyb nesouhlasí.');
                }
                $sourceTax = round((float) ($row['DPHz'] ?? 0) + (float) ($row['DPHs'] ?? 0) + (float) ($row['DPHt'] ?? 0), 2);
                if (self::cents($j['row']['DPH'] ?? 0.0) !== self::cents($sourceTax)) {
                    throw new StereoNxException('cashbook_tax_mismatch', 'Daň fyzického pohybu nesouhlasí s peněžním deníkem.');
                }
                $direction = $row['SmerPlatby'] ?? null;
                if (!in_array($direction, ['P', 'V'], true)) throw new StereoNxException('movement_direction', 'Neznámý směr pohybu.');
                // Daňová evidence odvozuje daňové sloupce z pohybů; záporné částky
                // zde vyžadují vlastní ověření. Účetní převod zachovává původní kontace.
                if (self::cents($row['Castka'] ?? null) < 0) throw new StereoNxException('movement_negative_amount', 'Směr a částka peněžního pohybu nejsou jednoznačné.');
                if ($type === 'bank' && trim((string) ($row['MenaCizi'] ?? '')) !== '') {
                    throw new StereoNxException('bank_foreign_currency', 'Cizoměnový bankovní pohyb vyžaduje samostatné mapování.');
                }
                if (isset($row['Kurz']) && !in_array((float) $row['Kurz'], [0.0, 1.0], true)) {
                    throw new StereoNxException('movement_foreign_rate', 'Peněžní pohyb obsahuje nepodporovaný kurz.');
                }
                $amount = self::money($row, 'Castka') * ($direction === 'P' ? 1 : -1);
                $bareDocumentKey = self::optionalDocKey($row);
                $documentKey = $bareDocumentKey === null ? null : ($documentsByBareKey[$bareDocumentKey] ?? null);
                if ($bareDocumentKey !== null && $documentKey === null) throw new StereoNxException('payment_orphan', 'Úhrada odkazuje na neznámý doklad.');
                if ($documentKey === null && self::cents($sourceTax) !== 0) {
                    throw new StereoNxException('unlinked_movement_vat', 'Nezařazený peněžní pohyb s DPH vyžaduje samostatný daňový doklad.');
                }
                if ($documentKey !== null && !isset($documents[$documentKey])) throw new StereoNxException('payment_orphan', 'Úhrada odkazuje na neznámý doklad.');
                $movement = ['source_key' => $key, 'row' => $row,
                    'amount' => $amount, 'date' => self::date($row, 'KdyUcPripad'),
                    'description' => trim((string) ($row['Text'] ?? '')),
                    'variable_symbol' => trim((string) ($row['VarSym'] ?? '')),
                    'document_key' => $documentKey,
                    'document_kind' => $documentKey === null ? null : $documents[$documentKey],
                    'bucket' => $j['bucket'], 'source_column' => $j['source_column']];
                if ($type === 'bank') {
                    [$movement['variable_symbol'], $movement['description']] = BankSymbols::variableSymbolAndDescription(
                        $movement['variable_symbol'], $movement['description'], false,
                    );
                    $statementKey = $statementsByBareKey[self::physicalKey($row, false)] ?? null;
                    if ($statementKey === null || !isset($statements[$statementKey])) throw new StereoNxException('bank_statement_missing', 'Bankovní pohyb nemá výpis.');
                    $accountKey = $statements[$statementKey]['account_key'];
                    $movement['statement_key'] = $statementKey;
                    $movement['account_key'] = $accountKey;
                    $movement['account'] = trim((string) ($row['BaUcet'] ?? ''));
                    $movement['bank_code'] = trim((string) ($row['KodBanky'] ?? ''));
                    $bank[$key] = $movement;
                } else {
                    $movement['document_no'] = self::nonempty($row, 'Doklad');
                    $cash[$key] = $movement;
                }
                $classifications[] = ['movement_key' => $key, 'movement_type' => $type,
                    'bucket' => $j['bucket'], 'source_column' => $j['source_column'],
                    'document_key' => $documentKey];
                if ($documentKey !== null) {
                    $payments[] = ['movement_key' => $key, 'movement_type' => $type,
                        'document_key' => $documentKey, 'document_kind' => $documents[$documentKey],
                        'amount' => abs($amount)];
                }
                unset($journal[$entryKey]);
            }
        }
        if ($journal !== []) throw new StereoNxException('cashbook_orphan', 'Peněžní deník obsahuje pohyb bez banky nebo pokladny.');
        return ['partner_country_candidates' => $countryCandidates, 'partner_country_warnings' => $countryWarnings,
            'identity' => $identity, 'clients' => array_values($clients),
            'issued' => array_values($issued), 'purchases' => array_values($purchases),
            'bank_accounts' => array_values($accounts), 'bank_statements' => array_values($statements),
            'bank_transactions' => array_values($bank), 'cash_transactions' => array_values($cash),
            'payments' => $payments, 'movement_classifications' => $classifications,
            'counts' => ['clients' => count($clients), 'issued' => count($issued),
                'purchases' => count($purchases), 'bank_statements' => count($statements),
                'bank_transactions' => count($bank), 'cash_transactions' => count($cash),
                'payments' => count($payments), 'requires_draft' => count(array_filter([...$issued, ...$purchases], static fn (array $d): bool => $d['requires_draft']))],
            'blockers' => []];
    }

    private static function currencyCode(mixed $value): string
    {
        $code = mb_strtoupper(trim((string) $value), 'UTF-8');
        if (in_array($code, ['KČ', 'CZK'], true)) return 'CZK';
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw new StereoNxException('document_currency_invalid', 'Doklad má neplatný kód měny.');
        }
        return $code;
    }

    /** @param array<string,mixed> $header @param list<array<string,mixed>> $items */
    private static function untaxedAdvanceWithoutItems(array $header, array $items): bool
    {
        if ($items !== [] || ($header['TypDokladu'] ?? null) !== 'Z'
            || !in_array($header['Agenda'] ?? null, ['VF', 'PF'], true)
            || ($header['Stornovano'] ?? null) !== false
            || ($header['ZpracovatDPH'] ?? null) !== false
            || !is_bool($header['CenySDPH'] ?? null)
            || trim((string) ($header['Text'] ?? '')) === '') return false;
        foreach (['Kurz', 'KurzMn'] as $field) {
            $value = $header[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || (float) $value !== 1.0) return false;
        }
        foreach (['z', 's', 't', '0'] as $slot) {
            if (!StereoNxRecapAmounts::isUnusedBucket($header, $slot)) return false;
        }
        // Bez položek nelze NULL zaokrouhlení nezávisle doložit součtem; zůstává povinné.
        foreach (['Zalohy', 'Zaokrouhleni'] as $field) {
            $value = $header[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || (float) $value !== 0.0) return false;
        }
        $total = $header['Celkem'] ?? null;
        return (is_int($total) || is_float($total)) && is_finite((float) $total)
            && $total >= 0.01 && $total <= 1.0e12;
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function advanceTotal(array $header): array
    {
        $total = round((float) $header['Celkem'], 2);
        $item = ['description' => trim((string) $header['Text']), 'quantity' => 1.0,
            'unit_price' => $total, 'unit_price_without_vat' => $total,
            'vat_rate_snapshot' => 0.0, 'total_without_vat' => $total,
            'total_vat' => 0.0, 'total_with_vat' => $total,
            'vat_classification_code' => null, 'vat_deduction' => 'none'];
        return ['origin' => 'advance_total', 'items' => [$item],
            'prices_include_vat' => $header['CenySDPH'], 'reverse_charge' => false,
            'source_vat_participation' => false,
            'review_codes' => [], 'requires_draft' => false,
            'total_without_vat' => $total, 'total_vat' => 0.0,
            'total_with_vat' => $total, 'rounding' => 0.0,
            'source_total_with_vat' => $total];
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function unclassifiedDocument(array $header): array
    {
        $total = $header['Celkem'] ?? null;
        if ((!is_int($total) && !is_float($total)) || !is_finite((float) $total)) {
            throw new StereoNxException('document_total_invalid', 'Doklad nemá platný celkový součet.');
        }
        $total = round((float) $total, 2);
        return ['origin' => 'unclassified_total', 'items' => [[
            'description' => trim((string) ($header['Text'] ?? '')) ?: 'Doklad Stereo NX',
            'quantity' => 1.0, 'unit_price' => $total, 'vat_rate_snapshot' => 0.0,
            'total_without_vat' => $total, 'total_vat' => 0.0, 'total_with_vat' => $total,
            'vat_classification_code' => null, 'vat_deduction' => 'none']],
            'prices_include_vat' => false, 'reverse_charge' => false,
            'review_codes' => ['document_tax_mapping_unverified'], 'requires_draft' => true,
            'total_without_vat' => $total, 'total_vat' => 0.0, 'total_with_vat' => $total,
            'rounding' => 0.0, 'source_total_with_vat' => $total];
    }

    /**
     * Cizoměnový doklad se přenese jako koncept s doloženým celkem v cizí měně.
     * Daňové částky Stereo vede v domácí měně a bez ověřené klasifikace je do položky
     * nepřepočítáváme; uživatel je zkontroluje proti zdroji.
     * @param array<string,mixed> $header @param list<array<string,mixed>> $sourceLines
     */
    private static function foreignDocument(array $header, array $sourceLines, string $kind, string $currency,
        StereoNxVat $vat): array
    {
        $rate = $header['Kurz'] ?? null;
        $units = $header['KurzMn'] ?? null;
        $total = $header['Celkem'] ?? null;
        if ((!is_int($rate) && !is_float($rate)) || (!is_int($units) && !is_float($units))
            || (!is_int($total) && !is_float($total)) || $rate <= 0 || $units <= 0 || !is_finite((float) $total)) {
            throw new StereoNxException('document_exchange_rate_invalid', 'Cizoměnový doklad nemá platný kurz nebo celkovou částku.');
        }
        $total = round((float) $total, 2);
        $lineTotal = 0.0;
        $takeoverItems = [];
        $blocked = null;
        foreach ($sourceLines as $line) {
            $quantity = $line['Mnozstvi'] ?? null;
            $unit = $line['JednCenaC'] ?? null;
            if ((!is_int($quantity) && !is_float($quantity)) || (!is_int($unit) && !is_float($unit))) {
                throw new StereoNxException('document_foreign_line_invalid', 'Cizoměnová položka nemá platné množství nebo cenu.');
            }
            $lineTotal += (float) $quantity * (float) $unit;
            $base = $line['ZakladDPH'] ?? null;
            $lineVat = $line['CelkemDPH'] ?? null;
            if ((!is_int($base) && !is_float($base)) || (!is_int($lineVat) && !is_float($lineVat))
                || !is_finite((float) $base) || !is_finite((float) $lineVat) || abs((float) $lineVat) >= 0.005) {
                $blocked = 'zdroj nemá ověřené korunové položky bez DPH';
            } else {
                $takeoverItems[] = ['base' => (float) $base, 'vat' => 0.0,
                    'foreign_base' => round((float) $quantity * (float) $unit, 2), 'foreign_vat' => 0.0];
            }
        }
        if ($sourceLines === [] || abs(round($lineTotal, 2) - $total) > 0.011) {
            throw new StereoNxException('document_foreign_total_mismatch', 'Cizoměnové položky nesouhlasí s celkem dokladu.');
        }
        $homeTotal = $header['CelkemVlastni'] ?? null;
        if ((!is_int($homeTotal) && !is_float($homeTotal)) || !is_finite((float) $homeTotal)) {
            $blocked = 'zdroj neobsahuje ověřený korunový celkem dokladu';
        }
        $normalizedRate = ForeignCurrencyTakeover::rate((float) $rate, (float) $units);
        if ($normalizedRate === null) $blocked = 'zdroj nemá platný kurz';
        if ($blocked === null) {
            $blocked = ForeignCurrencyTakeover::check($normalizedRate, $takeoverItems, (float) $homeTotal);
        }
        $description = trim((string) ($header['Text'] ?? '')) ?: 'Cizoměnový doklad Stereo NX';
        return [
            'origin' => 'foreign_total',
            'items' => [[
                'description' => $description, 'quantity' => 1.0, 'unit_price' => $total,
                'vat_rate_snapshot' => 0.0, 'total_without_vat' => $total, 'total_vat' => 0.0,
                'total_with_vat' => $total, 'vat_classification_code' => null, 'vat_deduction' => 'none',
            ]],
            'prices_include_vat' => is_bool($header['CenySDPH'] ?? null) ? $header['CenySDPH'] : false,
            'reverse_charge' => false,
            'exchange_rate' => $normalizedRate,
            'foreign_takeover_items' => $takeoverItems,
            'foreign_home_total' => is_int($homeTotal) || is_float($homeTotal) ? (float) $homeTotal : null,
            'foreign_takeover_blocked' => $blocked,
            'review_codes' => array_merge(['foreign_currency_vat_unverified'],
                StereoNxForeignCurrencyCheck::reviewCodes($header, $sourceLines, $vat)),
            'requires_draft' => true,
            'total_without_vat' => $total, 'total_vat' => 0.0, 'total_with_vat' => $total,
            'rounding' => 0.0, 'source_total_with_vat' => $total,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function docKey(array $row): string
    {
        return self::boundedKey([self::nonempty($row, 'DoklSRada'), self::nonempty($row, 'DoklSCislo')]);
    }

    /** @param array<string,mixed> $row */
    private static function annualKey(array $row, string $series, string $number, string $date, FiscalCalendar $calendar): string
    {
        return self::boundedKey([(string) $calendar->fiscalYearOfDate(self::date($row, $date)),
            self::nonempty($row, $series), self::nonempty($row, $number)]);
    }

    /** @param array<string,mixed> $row */
    private static function optionalDocKey(array $row): ?string
    {
        $series = trim((string) ($row['DoklSRada'] ?? ''));
        $number = trim((string) ($row['DoklSCislo'] ?? ''));
        if ($series === '' && ($number === '' || $number === '0')) return null;
        if ($series === '' || $number === '' || $number === '0') throw new StereoNxException('payment_link_incomplete', 'Neúplná vazba platby.');
        return self::boundedKey([$series, $number]);
    }

    /** @param array<string,mixed> $row */
    private static function physicalKey(array $row, bool $withLine): string
    {
        $parts = [self::nonempty($row, 'DoklRada'), self::nonempty($row, 'DoklCislo')];
        if ($withLine) $parts[] = self::nonempty($row, 'Klic');
        return self::boundedKey($parts);
    }

    /** @param array<string,mixed> $row */
    private static function annualPhysicalKey(array $row, bool $withLine, FiscalCalendar $calendar): string
    {
        $parts = [(string) $calendar->fiscalYearOfDate(self::date($row, 'KdyUcPripad')),
            self::nonempty($row, 'DoklRada'), self::nonempty($row, 'DoklCislo')];
        if ($withLine) $parts[] = self::nonempty($row, 'Klic');
        return self::boundedKey($parts);
    }

    /** @param list<string> $parts */
    private static function boundedKey(array $parts): string
    {
        $key = json_encode($parts, JSON_THROW_ON_ERROR);
        if (strlen($key) > 190) throw new StereoNxException('source_key_too_long', 'Identita zdrojového záznamu přesahuje limit importní mapy.');
        return $key;
    }

    /** @param array<string,mixed> $row */
    private static function nonempty(array $row, string $key): string
    {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '' || strlen($value) > 180) throw new StereoNxException('source_identity_invalid', 'Chybí nebo je neplatné pole identity: ' . $key);
        return $value;
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    /** @return array{code:string,unresolved:bool} */
    private static function country(string $source, string $dic, bool $blankCountryIsCz): array
    {
        $source = strtoupper(trim($source));
        if ($source === 'ČR' || $source === 'CZ' || $source === 'CZE') return ['code' => 'CZ', 'unresolved' => false];
        if ($source === 'D') return ['code' => 'DE', 'unresolved' => false];
        if (preg_match('/^[A-Z]{2}$/D', $source) && $source !== 'EU') return ['code' => $source, 'unresolved' => false];
        if ($source === '' && $blankCountryIsCz) return ['code' => 'CZ', 'unresolved' => false];
        $prefix = strtoupper(substr(trim($dic), 0, 2));
        if ($source === '' && in_array($prefix, ['CZ', 'PL'], true)) {
            return ['code' => $prefix, 'unresolved' => false];
        }
        // Země je v cílovém adresáři povinná; CZ je technická hodnota konceptu,
        // nikoli tvrzení o sídle neidentifikované nebo obecně evropské firmy.
        return ['code' => 'CZ', 'unresolved' => true];
    }

    /** @param array<string,mixed> $row */
    private static function date(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) throw new StereoNxException('source_date_invalid', 'Chybí nebo je neplatné datum: ' . $key);
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function optionalDate(array $row, string $key): ?string
    {
        if (($row[$key] ?? null) === null || $row[$key] === '') return null;
        return self::date($row, $key);
    }

    /** @param array<string,mixed> $row */
    private static function money(array $row, string $key): float
    {
        return self::cents($row[$key] ?? null) / 100;
    }

    private static function cents(mixed $value): int
    {
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || abs($value) > 1.0e12) {
            throw new StereoNxException('source_amount_invalid', 'Neplatná částka zdrojového pohybu.');
        }
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }
}

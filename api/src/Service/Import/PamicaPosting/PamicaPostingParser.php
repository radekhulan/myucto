<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import\PamicaPosting;

use MyInvoice\Http\IsoDate;

/**
 * Čtení zaúčtování mezd z PAMICY (STORMWARE dataPack verze 2.0, kódování podle hlavičky
 * XML, typicky Windows-1250).
 *
 * Výstup je čistý popis dokladů, nic se nezapisuje ani nedohledává:
 *  - `internal_documents`: interní doklady (`int:intDoc`), každý řádek s účtem MD a Dal,
 *  - `commitments`: ostatní závazky (`inv:invoice` typu `commitment`) s protistranou,
 *    symboly, splatností a účtem pro platební příkaz. Předkontace `BEZ…` (např. BEZ331000)
 *    znamená „bez zaúčtování": závazek už vznikl interním dokladem (331 Dal) a eviduje se
 *    jen k úhradě; účet za předponou je účet, na kterém závazek leží.
 *
 * Předkontace je v exportu PAMICY buď přímo dvojice účtů „MD/Dal" ve tvaru STORMWARE
 * (šest číslic, např. 524000/336001), nebo zkratka předkontace POHODY (7Int, 4Oz), když
 * je PAMICA nastavená na zkratky. Zkratku přeloží na dvojici účtů mapování firmy
 * (`$codeMap`, {@see PamicaPostingCodeMap}); zkratky bez mapování parser posbírá i se
 * vzorovými řádky a vrátí chybou `unmapped_accounting_codes`, aby je účetní mohla
 * přiřadit. Jiný tvar předkontace parser odmítne, protože by ji nebylo podle čeho zaúčtovat.
 */
final class PamicaPostingParser
{
    public const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    public const NS_INT = 'http://www.stormware.cz/schema/version_2/intDoc.xsd';
    public const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    public const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private const MAX_ITEMS = 2000;
    private const MAX_LINES = 50;
    private const MAX_SAMPLES = 3;

    /** @var array<string,array{code:string,usage:array<string,true>,count:int,samples:list<array{document:string,text:string,amount:float}>}> */
    private array $unmapped = [];
    /** @var array<string,array{debit:string,credit:string}> */
    private array $codeMap = [];

    /**
     * @param array<string,array{debit:string,credit:string}> $codeMap zkratka (velkými písmeny) => účty MD a Dal
     * @return array{
     *   application:string, ico:string, pack_id:?string, sym_par:string, period:string,
     *   accounting_date:string, document_date:string,
     *   internal_documents:list<array<string,mixed>>, commitments:list<array<string,mixed>>
     * }
     */
    public function parse(string $bytes, array $codeMap = []): array
    {
        $this->unmapped = [];
        $this->codeMap = [];
        foreach ($codeMap as $code => $pair) {
            $this->codeMap[PamicaPostingCodeMap::key((string) $code)] = $pair;
        }
        if ($bytes === '' || strlen($bytes) > PamicaPostingFormat::MAX_BYTES) {
            throw new PamicaPostingException('invalid_file', 'Soubor je prázdný nebo větší než 20 MiB.');
        }
        if (!PamicaPostingFormat::isPayrollPosting($bytes)) {
            throw new PamicaPostingException('not_payroll_posting',
                'Soubor není export zaúčtování mezd z PAMICY (dataPack s interním dokladem a ostatními závazky).');
        }
        if (preg_match('/<!DOCTYPE/i', $bytes) === 1) {
            throw new PamicaPostingException('invalid_xml', 'XML obsahuje DOCTYPE, což není povoleno.');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null || $dom->doctype !== null) {
            throw new PamicaPostingException('invalid_xml', 'XML se nepodařilo načíst. Nahrajte soubor tak, jak ho PAMICA vyvezla.');
        }
        $root = $dom->documentElement;
        if ($root->namespaceURI !== self::NS_DAT || $root->localName !== 'dataPack') {
            throw new PamicaPostingException('not_payroll_posting', 'Kořen souboru není dataPack STORMWARE verze 2.');
        }
        $ico = preg_replace('/\D+/', '', $root->getAttribute('ico')) ?? '';
        if ($ico === '') {
            throw new PamicaPostingException('missing_ico', 'Soubor neuvádí IČO firmy (atribut ico v dataPack).');
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('dat', self::NS_DAT);
        $xp->registerNamespace('int', self::NS_INT);
        $xp->registerNamespace('inv', self::NS_INV);
        $xp->registerNamespace('typ', self::NS_TYP);

        $items = $xp->query('/dat:dataPack/dat:dataPackItem');
        if ($items === false || $items->length === 0) {
            throw new PamicaPostingException('empty_pack', 'Soubor neobsahuje žádné doklady.');
        }
        if ($items->length > self::MAX_ITEMS) {
            throw new PamicaPostingException('too_many_items', 'Soubor obsahuje víc než ' . self::MAX_ITEMS . ' dokladů.');
        }

        $internal = [];
        $commitments = [];
        $symPars = [];
        $accountingDates = [];
        $documentDates = [];
        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) continue;
            $itemId = trim($item->getAttribute('id'));
            $doc = null;
            foreach ($item->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $doc = $child;
                    break;
                }
            }
            if ($doc === null) continue;
            if ($doc->namespaceURI === self::NS_INT && $doc->localName === 'intDoc') {
                $parsed = $this->internalDocument($xp, $doc, $itemId);
                $internal[] = $parsed;
            } elseif ($doc->namespaceURI === self::NS_INV && $doc->localName === 'invoice') {
                $parsed = $this->commitment($xp, $doc, $itemId);
                $commitments[] = $parsed;
            } else {
                throw new PamicaPostingException('unsupported_document',
                    'Položka ' . $itemId . ' obsahuje doklad „' . $doc->localName . '", který zaúčtování mezd nenese.');
            }
            $symPars[$parsed['sym_par']] = true;
            $accountingDates[] = $parsed['accounting_date'];
            $documentDates[] = $parsed['document_date'];
        }
        if ($internal === [] && $commitments === []) {
            throw new PamicaPostingException('empty_pack', 'Soubor neobsahuje žádné doklady.');
        }
        if (count($symPars) !== 1 || array_key_first($symPars) === '') {
            throw new PamicaPostingException('sym_par_mismatch',
                'Doklady v souboru nemají jeden společný párovací symbol měsíce (např. MZ202603). Vyvezte z PAMICY zaúčtování jednoho měsíce.');
        }
        $symPar = (string) array_key_first($symPars);
        $accountingDate = max($accountingDates);
        if (substr(min($accountingDates), 0, 7) !== substr($accountingDate, 0, 7)) {
            throw new PamicaPostingException('period_mismatch', 'Doklady v souboru mají datum účetního případu v různých měsících.');
        }
        if ($this->unmapped !== []) {
            $unmapped = array_values(array_map(static fn (array $u): array => [
                'code' => $u['code'],
                'usage' => array_keys($u['usage']),
                'count' => $u['count'],
                'samples' => $u['samples'],
            ], $this->unmapped));
            throw new PamicaPostingException('unmapped_accounting_codes',
                'Soubor používá zkratky předkontací ' . implode(', ', array_column($unmapped, 'code'))
                . ', ke kterým firma nemá přiřazené účty MD a Dal. Přiřaďte je a načtení zopakujte.',
                422, ['unmapped_codes' => $unmapped]);
        }

        return [
            'application' => 'PAMICA',
            'ico' => str_pad($ico, 8, '0', STR_PAD_LEFT),
            'pack_id' => ($id = trim($root->getAttribute('id'))) !== '' ? mb_substr($id, 0, 64) : null,
            'sym_par' => $symPar,
            'period' => substr($accountingDate, 0, 7),
            'accounting_date' => $accountingDate,
            'document_date' => max($documentDates),
            'internal_documents' => $internal,
            'commitments' => $commitments,
        ];
    }

    /** @return array<string,mixed> */
    private function internalDocument(\DOMXPath $xp, \DOMElement $doc, string $itemId): array
    {
        $header = $this->first($xp, 'int:intDocHeader', $doc)
            ?? throw new PamicaPostingException('invalid_document', "Interní doklad {$itemId} nemá hlavičku.");
        [$symPar, $documentDate, $accountingDate] = $this->headerBasics($xp, $header, 'int', "Interní doklad {$itemId}");
        $headerAccounting = $this->text($xp, 'int:accounting/typ:ids', $header);
        $title = $this->text($xp, 'int:text', $header);
        $lines = [];
        foreach ($xp->query('int:intDocDetail/int:intDocItem', $doc) ?: [] as $row) {
            if (!$row instanceof \DOMElement) continue;
            $lines[] = $this->line($xp, $row, 'int', $headerAccounting, "Interní doklad {$itemId}");
        }
        if ($lines === []) {
            $amount = $this->summaryAmount($xp, $doc, 'int:intDocSummary');
            if ($amount !== null) {
                $lines[] = $this->accountLine($headerAccounting, $amount, $title, null, "Interní doklad {$itemId}", 'internal');
            }
        }
        $lines = array_values(array_filter($lines, static fn (array $l): bool => $l['amount'] !== 0.0));
        foreach ($lines as $i => $line) {
            if ($line['without_posting']) {
                throw new PamicaPostingException('invalid_accounting',
                    "Interní doklad {$itemId}, řádek " . ($i + 1) . ': předkontace bez zaúčtování u interního dokladu nedává smysl.');
            }
            // Záporná částka = tentýž zápis s prohozenými stranami.
            if ($line['amount'] < 0) {
                $lines[$i] = ['debit' => $line['credit'], 'credit' => $line['debit'], 'amount' => -$line['amount']] + $line;
            }
        }
        if ($lines === []) {
            throw new PamicaPostingException('empty_document', "Interní doklad {$itemId} nemá žádný řádek s částkou.");
        }
        if (count($lines) > self::MAX_LINES * 2) {
            throw new PamicaPostingException('too_many_lines', "Interní doklad {$itemId} má víc než " . (self::MAX_LINES * 2) . ' řádků.');
        }
        return [
            'item_id' => $itemId,
            'sym_par' => $symPar,
            'title' => self::clip($title !== '' ? $title : 'Zaúčtování mezd ' . $symPar, 255),
            'document_date' => $documentDate,
            'accounting_date' => $accountingDate,
            'lines' => $lines,
            'amount' => self::sum($lines),
        ];
    }

    /** @return array<string,mixed> */
    private function commitment(\DOMXPath $xp, \DOMElement $doc, string $itemId): array
    {
        $label = "Závazek {$itemId}";
        $header = $this->first($xp, 'inv:invoiceHeader', $doc)
            ?? throw new PamicaPostingException('invalid_document', "{$label} nemá hlavičku.");
        $type = $this->text($xp, 'inv:invoiceType', $header);
        if ($type !== 'commitment') {
            throw new PamicaPostingException('unsupported_document',
                "Doklad {$itemId} je typu „{$type}\". Zaúčtování mezd nese jen ostatní závazky (commitment).");
        }
        [$symPar, $documentDate, $accountingDate] = $this->headerBasics($xp, $header, 'inv', $label);
        $dueOn = $this->text($xp, 'inv:dateDue', $header);
        if ($dueOn === '') {
            $dueOn = $documentDate;
        } elseif (!IsoDate::isValid($dueOn)) {
            throw new PamicaPostingException('invalid_date', "{$label} má neplatné datum splatnosti.");
        }
        $headerAccounting = $this->text($xp, 'inv:accounting/typ:ids', $header);
        $title = $this->text($xp, 'inv:text', $header);

        $address = $this->first($xp, 'inv:partnerIdentity/typ:address', $header);
        $company = $address ? $this->text($xp, 'typ:company', $address) : '';
        $name = $address ? $this->text($xp, 'typ:name', $address) : '';
        $partner = null;
        if ($company !== '' || $name !== '') {
            $isPerson = $company === '';
            $partner = [
                'name' => self::clip($company !== '' ? $company : $name, 190),
                'is_person' => $isPerson,
                // Zaměstnanec: na kartu jde jen jméno, adresu bydliště import nepřebírá.
                'street' => $isPerson || !$address ? null : self::nullable($this->text($xp, 'typ:street', $address)),
                'city' => $isPerson || !$address ? null : self::nullable($this->text($xp, 'typ:city', $address)),
                'zip' => $isPerson || !$address ? null : self::nullable($this->text($xp, 'typ:zip', $address)),
                'ic' => $isPerson || !$address ? null : self::nullable(preg_replace('/\D+/', '', $this->text($xp, 'typ:ico', $address)) ?? ''),
            ];
        }

        $lines = [];
        foreach ($xp->query('inv:invoiceDetail/inv:invoiceItem', $doc) ?: [] as $row) {
            if (!$row instanceof \DOMElement) continue;
            $lines[] = $this->line($xp, $row, 'inv', $headerAccounting, $label);
        }
        if ($lines === []) {
            $amount = $this->summaryAmount($xp, $doc, 'inv:invoiceSummary')
                ?? throw new PamicaPostingException('empty_document', "{$label} nemá položky ani částku.");
            $lines[] = $this->accountLine($headerAccounting, $amount, $title, null, $label, 'commitment');
        }
        $lines = array_values(array_filter($lines, static fn (array $l): bool => $l['amount'] !== 0.0));
        if ($lines === []) {
            throw new PamicaPostingException('empty_document', "{$label} nemá žádnou částku.");
        }
        if (count($lines) > self::MAX_LINES) {
            throw new PamicaPostingException('too_many_lines', "{$label} má víc než " . self::MAX_LINES . ' položek.');
        }
        foreach ($lines as $i => $line) {
            if ($line['amount'] < 0) {
                throw new PamicaPostingException('negative_amount', "{$label}, položka " . ($i + 1) . ': záporná částka ostatního závazku není podporovaná.');
            }
        }
        // Řádky s nemapovanou zkratkou účty nemají; kontroly účtů proběhnou až s mapováním
        // (parse po posbírání všech zkratek skončí chybou unmapped_accounting_codes).
        $unmapped = array_filter($lines, static fn (array $l): bool => $l['credit'] === null);
        $withoutPosting = array_values(array_unique(array_map(static fn (array $l): bool => $l['without_posting'], $lines)));
        if ($unmapped !== []) {
            $withoutPosting = [false];
        } elseif (count($withoutPosting) !== 1) {
            throw new PamicaPostingException('invalid_accounting', "{$label} míchá položky se zaúčtováním a bez zaúčtování.");
        }
        $credits = array_values(array_unique(array_map(static fn (array $l): string => (string) $l['credit'], $lines)));
        if ($unmapped !== []) {
            $credits = [''];
        } elseif (count($credits) !== 1) {
            throw new PamicaPostingException('invalid_accounting',
                "{$label} má položky na různých účtech Dal (" . implode(', ', $credits) . '). Ostatní závazek leží na jednom účtu.');
        }

        $vs = $this->text($xp, 'inv:symVar', $header);
        $ks = $this->text($xp, 'inv:symConst', $header);
        $paymentType = $this->text($xp, 'inv:paymentType/typ:paymentType', $header);
        $accountNo = $this->text($xp, 'inv:paymentAccount/typ:accountNo', $header);
        $bankCode = $this->text($xp, 'inv:paymentAccount/typ:bankCode', $header);

        return [
            'item_id' => $itemId,
            'sym_par' => $symPar,
            'title' => self::clip($title !== '' ? $title : 'Závazek z mezd ' . $symPar, 255),
            'document_date' => $documentDate,
            'accounting_date' => $accountingDate,
            'due_on' => $dueOn,
            'partner' => $partner,
            'variable_symbol' => $vs !== '' ? $vs : null,
            'constant_symbol' => $ks !== '' ? $ks : null,
            'payment_type' => $paymentType !== '' ? $paymentType : null,
            'account_number' => $accountNo !== '' ? $accountNo : null,
            'bank_code' => $bankCode !== '' ? $bankCode : null,
            'without_posting' => $withoutPosting[0],
            'credit' => $credits[0],
            'lines' => $lines,
            'amount' => self::sum($lines),
        ];
    }

    /** @return array{0:string,1:string,2:string} symPar, datum dokladu, datum účetního případu */
    private function headerBasics(\DOMXPath $xp, \DOMElement $header, string $ns, string $label): array
    {
        $symPar = $this->text($xp, $ns . ':symPar', $header);
        if ($symPar === '' || mb_strlen($symPar) > 40) {
            throw new PamicaPostingException('missing_sym_par', "{$label} nemá párovací symbol měsíce (symPar).");
        }
        $date = $this->text($xp, $ns . ':date', $header);
        $accounting = $this->text($xp, $ns . ':dateAccounting', $header);
        if ($accounting === '') $accounting = $date;
        if ($date === '') $date = $accounting;
        if (!IsoDate::isValid($date) || !IsoDate::isValid($accounting)) {
            throw new PamicaPostingException('invalid_date', "{$label} nemá platné datum dokladu nebo účetního případu.");
        }
        return [$symPar, $date, $accounting];
    }

    /** @return array<string,mixed> */
    private function line(\DOMXPath $xp, \DOMElement $row, string $ns, string $headerAccounting, string $label): array
    {
        $quantity = $this->text($xp, $ns . ':quantity', $row);
        $qty = $quantity === '' ? 1.0 : self::number($quantity, $label);
        $price = $this->text($xp, $ns . ':homeCurrency/typ:priceSum', $row);
        if ($price === '') $price = $this->text($xp, $ns . ':homeCurrency/typ:price', $row);
        $amount = $price !== ''
            ? self::number($price, $label)
            : round(self::number($this->text($xp, $ns . ':homeCurrency/typ:unitPrice', $row) ?: '0', $label) * $qty, 2);
        $accounting = $this->text($xp, $ns . ':accounting/typ:ids', $row);
        return $this->accountLine($accounting !== '' ? $accounting : $headerAccounting, $amount,
            $this->text($xp, $ns . ':text', $row), $this->text($xp, $ns . ':note', $row), $label,
            $ns === 'int' ? 'internal' : 'commitment');
    }

    /**
     * Řádek s účty podle předkontace. Zkratka bez mapování vrátí řádek bez účtů
     * (`credit` null) a zapíše se do seznamu nemapovaných zkratek.
     *
     * @return array<string,mixed>
     */
    private function accountLine(string $accounting, float $amount, string $text, ?string $note, string $label, string $usage): array
    {
        $base = ['text' => self::clip($text, 255), 'note' => self::nullable(self::clip((string) $note, 255)), 'code' => null];
        $ids = strtoupper(str_replace(' ', '', $accounting));
        if (preg_match('/^BEZ(\d{3,9})$/', $ids, $m) === 1) {
            return $base + ['debit' => null, 'credit' => $m[1], 'without_posting' => true, 'amount' => round($amount, 2)];
        }
        if (preg_match('/^(\d{3,9})\/(\d{3,9})$/', $ids, $m) === 1) {
            if ($m[1] === $m[2]) {
                throw new PamicaPostingException('invalid_accounting', "{$label}: předkontace {$accounting} má stejný účet na obou stranách.");
            }
            return $base + ['debit' => $m[1], 'credit' => $m[2], 'without_posting' => false, 'amount' => round($amount, 2)];
        }
        $code = trim($accounting);
        if (!PamicaPostingCodeMap::isCode($code)) {
            throw new PamicaPostingException('invalid_accounting',
                "{$label}: předkontace „{$accounting}\" není dvojice účtů MD/Dal ani zkratka předkontace.");
        }
        $key = PamicaPostingCodeMap::key($code);
        $base['code'] = $code;
        $pair = $this->codeMap[$key] ?? null;
        if ($pair !== null) {
            return $base + ['debit' => $pair['debit'], 'credit' => $pair['credit'], 'without_posting' => false, 'amount' => round($amount, 2)];
        }
        $entry = $this->unmapped[$key] ?? ['code' => $code, 'usage' => [], 'count' => 0, 'samples' => []];
        $entry['usage'][$usage] = true;
        $entry['count']++;
        if (count($entry['samples']) < self::MAX_SAMPLES) {
            $entry['samples'][] = ['document' => $label, 'text' => $base['text'], 'amount' => round($amount, 2)];
        }
        $this->unmapped[$key] = $entry;
        return $base + ['debit' => null, 'credit' => null, 'without_posting' => false, 'amount' => round($amount, 2)];
    }

    private function summaryAmount(\DOMXPath $xp, \DOMElement $doc, string $summaryPath): ?float
    {
        $value = $this->text($xp, $summaryPath . '/' . explode(':', $summaryPath)[0] . ':homeCurrency/typ:priceNone', $doc);
        return $value === '' ? null : self::number($value, 'Souhrn dokladu');
    }

    private function first(\DOMXPath $xp, string $path, \DOMNode $context): ?\DOMElement
    {
        $node = ($xp->query($path, $context) ?: null)?->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    private function text(\DOMXPath $xp, string $path, \DOMNode $context): string
    {
        $node = $this->first($xp, $path, $context);
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private static function number(string $value, string $label): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if (preg_match('/^-?\d{1,12}(\.\d+)?$/', $normalized) !== 1) {
            throw new PamicaPostingException('invalid_amount', "{$label}: neplatná částka „{$value}\".");
        }
        return round((float) $normalized, 2);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function sum(array $lines): float
    {
        $cents = 0;
        foreach ($lines as $line) {
            $cents += (int) round((float) $line['amount'] * 100);
        }
        return round($cents / 100, 2);
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

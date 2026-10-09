<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Generátor XML „Přehledu o příjmech a výdajích OSVČ" pro ČSSZ (sociální pojištění) —
 * Epic DP v2 (issue #19), Fáze 3. Vlastní schéma ČSSZ (ns http://schemas.cssz.cz/OSVC2025,
 * `api/xsd/osvc25.xsd` + import `baseTypes2.xsd`) — jiný kanál i formát než EPO MFČR.
 *
 * Vstup = výstup {@see InsuranceSummaryService::build()} (VZ, pojistné, zálohy, doplatek)
 * + supplier (identifikace, vsdp/dep, DIČ→RČ). Výstup = validovaná datová věta k nahrání
 * na ePortál ČSSZ / do datové schránky. **Přímé odeslání NEřešíme — necháváme na uživateli.**
 *
 * Struktura (dle DV_OSVC25): OSVC > VENDOR + prehledosvc(for,dep,vsdp,rok,typ) >
 *   client(name,birth,adr,idds,druc,hlavc/vedc) + pvv(pri=daňový základ; VZ/pojistné/zálohy)
 *   + pre(přeplatek) + spo(spolupracující). Řádný = typ 'N', opravný = 'O' (+ opr/dat).
 *
 * Zaokrouhlení dle DV: VVZ/DVZ/MVZ/UVZ/POJ/SLEV/POJPOSLEV nahoru na celé Kč;
 * VZZA/VZSU/VZSVC na celé Kč; MESP na 2 desetinná místa.
 */
final class CsszPrehledXmlBuilder
{
    private const NS = 'http://schemas.cssz.cz/OSVC2025';

    /**
     * @param array<string,mixed> $supplier row ze supplier (loadSupplierCssz v service)
     * @param array<string,mixed> $summary  výstup InsuranceSummaryService::build()
     * @param array<string,mixed> $meta      productVersion, typ ('N'|'O'), fill_date (Y-m-d),
     *                                        opr_date (Y-m-d), opr_reason (opravný)
     * @return array{xml:string,warnings:list<string>}
     */
    public function build(array $supplier, int $year, array $summary, array $meta = []): array
    {
        $warnings = [];
        $social = (array) ($summary['social'] ?? []);
        $monthsInfo = $this->monthsInfo($summary);
        $isSecondary = $monthsInfo !== null ? $monthsInfo['is_secondary'] : !empty($summary['is_secondary']);
        $typ = ($meta['typ'] ?? 'N') === 'O' ? 'O' : 'N';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $osvc = $dom->createElementNS(self::NS, 'OSVC');
        $osvc->setAttribute('version', '1.0');
        $dom->appendChild($osvc);

        $vendor = $dom->createElement('VENDOR');
        $vendor->setAttribute('productName', 'MyÚčto.cz');
        $vendor->setAttribute('productVersion', (string) ($meta['productVersion'] ?? '0'));
        $osvc->appendChild($vendor);

        $prehled = $dom->createElement('prehledosvc');
        $prehled->setAttribute('for', 'prehledosvc'); // konstanta (11 znaků)
        $dep = preg_replace('/\D/', '', (string) ($supplier['cssz_ossz_code'] ?? '')) ?? '';
        if (strlen($dep) === 3) {
            $prehled->setAttribute('dep', $dep);
        } else {
            $prehled->setAttribute('dep', '000');
            $warnings[] = 'Chybí/neplatný kód OSSZ (dep) — doplňte 3místný kód místně příslušné OSSZ v Nastavení firmy.';
        }
        $vsdp = preg_replace('/\D/', '', (string) ($supplier['cssz_vsdp'] ?? '')) ?? '';
        if ($vsdp !== '') {
            $prehled->setAttribute('vsdp', $vsdp);
        } else {
            $warnings[] = 'Chybí variabilní symbol OSVČ (VS ČSSZ) — doplňte ho v Nastavení firmy.';
        }
        $prehled->setAttribute('rok', (string) $year);
        $prehled->setAttribute('typ', $typ);
        $osvc->appendChild($prehled);

        // Všechny částky pvv se odvozují z JEDNOHO zdroje (celé Kč), aby platily
        // cross-kontroly ČSSZ (uvz=max(vvz,mvz), poj=ceil(uvz×sazba), ned=poj−zal).
        $ints = $this->socialInts($summary, $social);

        $prehled->appendChild($this->buildClient($dom, $supplier, $isSecondary, $monthsInfo, $warnings));
        $prehled->appendChild($this->buildPvv($dom, $summary, $isSecondary, $ints, $monthsInfo));
        $prehled->appendChild($this->buildPre($dom, $ints));
        if ($typ === 'O') {
            $prehled->appendChild($this->buildOpr($dom, $meta));
        }
        $prehled->appendChild($this->buildSpo($dom));
        $prehled->appendChild($this->buildDat($dom, $meta));

        if ($ints['ned'] > 0) {
            $warnings[] = 'Přehled ČSSZ počítá nedoplatek pojistného ' . number_format($ints['ned'], 0, ',', ' ')
                . ' Kč — doplatek je splatný do 8 dnů po podání přehledu.';
        }
        if ($monthsInfo === null) {
            $warnings[] = 'Přehled předpokládá JEDEN druh činnosti (hlavní/vedlejší) po CELÝ rok. '
                . 'Při souběhu hlavní+vedlejší, souběhu se zaměstnáním nebo zahájení/ukončení/'
                . 'přerušení v průběhu roku upravte měsíce výkonu, rozdělení základu a VZ ze zaměstnání ručně.';
        } elseif ($monthsInfo['main'] > 0 && $monthsInfo['secondary'] > 0) {
            $warnings[] = 'Souběh hlavní a vedlejší činnosti — měsíce výkonu jsou rozdělené dle skutečnosti, '
                . 'ale vyměřovací základ je uvedený v jednom sloupci. Rozdělení základu mezi hlavní/vedlejší '
                . 'a případný VZ ze zaměstnání ověřte a upravte ručně.';
        }

        return ['xml' => $dom->saveXML() ?: '', 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array{main_flags:array<int,bool>,secondary_flags:array<int,bool>,main:int,secondary:int,main_paid:int,secondary_paid:int,is_secondary:bool}|null $monthsInfo
     * @param list<string> $warnings
     */
    private function buildClient(\DOMDocument $dom, array $supplier, bool $isSecondary, ?array $monthsInfo, array &$warnings): \DOMElement
    {
        $client = $dom->createElement('client');

        // Jméno OSVČ (company_name = jméno fyzické osoby): "Jméno Příjmení" nebo víc slov.
        $name = $dom->createElement('name');
        $full = trim((string) ($supplier['company_name'] ?? ''));
        [$fir, $sur] = EpoSupplierBlockBuilder::splitPersonName($full);
        $title = '';
        if (preg_match('/^((?:(?:prof|doc|MUDr|MDDr|MVDr|JUDr|PhDr|RNDr|PharmDr|ThDr|ThLic|Ing|BcA?|MgrA?)\.)\s*)+/iu', $full, $m)) {
            $title = trim($m[1]);
        }
        if ($sur === '') {
            $sur = $fir; // jen jedno slovo → dej ho do příjmení (povinné), jméno zůstane
            $fir = $fir !== '' ? $fir : '-';
        }
        $name->setAttribute('sur', mb_substr($sur, 0, 50));
        $name->setAttribute('fir', mb_substr($fir !== '' ? $fir : '-', 0, 50));
        $name->setAttribute('tit', mb_substr($title, 0, 30));
        $client->appendChild($name);

        // Rodné číslo (bno) z DIČ FO + datum narození (den) z RČ.
        $rc = preg_replace('/\D/', '', preg_replace('/^CZ/i', '', (string) ($supplier['dic'] ?? '')) ?? '');
        $birth = $dom->createElement('birth');
        if (preg_match('/^\d{9,10}$/', $rc)) {
            $birth->setAttribute('bno', $rc);
            $den = $this->rcToBirthDate($rc);
            if ($den !== '') {
                $birth->setAttribute('den', $den);
            } else {
                $birth->setAttribute('den', '');
                $warnings[] = 'Nepodařilo se odvodit datum narození z rodného čísla — doplňte ho ručně před podáním.';
            }
            // 10místné RČ (od 1954): kontrolní číslice = (prvních 9) mod 11 (zbytek 10 → 0).
            // ČSSZ kontroluje — upozorni na překlep v DIČ/RČ.
            if (strlen($rc) === 10) {
                $mod = (int) substr($rc, 0, 9) % 11;
                $expected = $mod === 10 ? 0 : $mod;
                if ($expected !== (int) substr($rc, 9, 1)) {
                    $warnings[] = 'Rodné číslo neprošlo kontrolou dělitelnosti 11 — ověřte DIČ/RČ (ČSSZ ho odmítne).';
                }
            }
        } else {
            $birth->setAttribute('bno', '');
            $birth->setAttribute('den', '');
            $warnings[] = 'Chybí rodné číslo OSVČ (odvozuje se z DIČ) — ČSSZ ho vyžaduje, doplňte před podáním.';
        }
        $client->appendChild($birth);

        // Adresa.
        [$ulice, $cislo] = $this->parseStreet($supplier);
        $adr = $dom->createElement('adr');
        if ($ulice !== '') {
            $adr->setAttribute('str', mb_substr($ulice, 0, 50));
        }
        $adr->setAttribute('num', mb_substr($cislo !== '' ? $cislo : '0', 0, 12));
        $adr->setAttribute('pnu', (preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?: '00000'));
        $adr->setAttribute('cit', mb_substr((string) ($supplier['city'] ?? ''), 0, 50));
        $adr->setAttribute('cnt', (string) ($supplier['country_iso2'] ?? 'CZ'));
        $client->appendChild($adr);

        // Datová schránka + kontakty.
        $idds = trim((string) ($supplier['data_box_id'] ?? ''));
        if (strlen($idds) === 7) {
            $client->appendChild($dom->createElement('idds', $idds));
        }
        if (!empty($supplier['email'])) {
            $client->appendChild($dom->createElement('email', (string) $supplier['email']));
        }
        if (!empty($supplier['phone'])) {
            $client->appendChild($dom->createElement('tel', EpoSupplierBlockBuilder::normalizePhone((string) $supplier['phone'])));
        }

        // Druh činnosti + měsíce. Bez per-month dat = celoroční předvyplnění (viz warning
        // v build()); s per-month daty = skutečný měsíční stav (hlavní i vedlejší blok).
        $druc = $isSecondary ? 'V' : 'H';
        $client->appendChild($dom->createElement('druc', $druc));
        if ($monthsInfo !== null) {
            $client->appendChild($this->monthsBlock($dom, 'hlavc', $monthsInfo['main_flags']));
            $client->appendChild($this->monthsBlock($dom, 'vedc', $monthsInfo['secondary_flags']));
        } else {
            $client->appendChild($this->monthsBlock($dom, $isSecondary ? 'vedc' : 'hlavc'));
        }

        return $client;
    }

    /**
     * Blok měsíců (m1..m13). Bez $flags = celoroční výkon 'A'; s $flags = 'A' jen v měsících,
     * kde daný druh činnosti (hlavní/vedlejší) probíhal, jinak prázdno.
     *
     * @param array<int,bool>|null $flags
     */
    private function monthsBlock(\DOMDocument $dom, string $tag, ?array $flags = null): \DOMElement
    {
        $el = $dom->createElement($tag);
        for ($m = 1; $m <= 13; $m++) {
            $active = $flags === null ? true : !empty($flags[$m]);
            $el->appendChild($dom->createElement('m' . $m, $active ? 'A' : ''));
        }
        return $el;
    }

    /**
     * Odvodí per-month rozdělení činnosti z $summary['months'] (osvc_months). Vrací null,
     * když per-month data chybí (starý profil) → volající spadne na celoroční chování.
     * mesc = počet měsíců výkonu, mesv = počet měsíců účasti na pojištění (social_participates);
     * odděleně pro hlavní (h) a vedlejší (v) sloupec.
     *
     * @param array<string,mixed> $summary
     * @return array{main_flags:array<int,bool>,secondary_flags:array<int,bool>,main:int,secondary:int,main_paid:int,secondary_paid:int,is_secondary:bool}|null
     */
    private function monthsInfo(array $summary): ?array
    {
        $months = $summary['months'] ?? null;
        if (!is_array($months) || $months === []) {
            return null;
        }
        $mainFlags = array_fill(1, 13, false);
        $secFlags = array_fill(1, 13, false);
        $main = 0;
        $secondary = 0;
        $mainPaid = 0;
        $secondaryPaid = 0;
        foreach ($months as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mon = (int) ($m['month'] ?? 0);
            if ($mon < 1 || $mon > 12) {
                continue;
            }
            $status = (string) ($m['activity_status'] ?? 'inactive');
            $paid = !empty($m['social_participates']);
            if ($status === 'main') {
                $mainFlags[$mon] = true;
                $main++;
                if ($paid) {
                    $mainPaid++;
                }
            } elseif ($status === 'secondary') {
                $secFlags[$mon] = true;
                $secondary++;
                if ($paid) {
                    $secondaryPaid++;
                }
            }
        }
        return [
            'main_flags' => $mainFlags,
            'secondary_flags' => $secFlags,
            'main' => $main,
            'secondary' => $secondary,
            'main_paid' => $mainPaid,
            'secondary_paid' => $secondaryPaid,
            'is_secondary' => $main === 0 && $secondary > 0,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $social
     */
    /**
     * Odvodí všechny celočíselné hodnoty pvv z jednoho zdroje (daňový základ, sazby)
     * tak, aby platily cross-kontroly ČSSZ. Neúčast (vedlejší pod rozhodnou částkou)
     * → VZ i minimum 0 (jinak by uvz=0 < mvz porušilo uvz=max(vvz,mvz)).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $social
     * @return array{pri:int,vvz:int,mvz:int,uvz:int,poj:int,advances:int,ned:int}
     */
    private function socialInts(array $summary, array $social): array
    {
        $pri = (int) round((float) ($summary['tax_base_7'] ?? 0));
        $participates = !array_key_exists('participates', $social) || !empty($social['participates']);
        $rates = (array) ($summary['rates'] ?? []);
        $pct = (float) ($rates['social_assessment_pct'] ?? 0.55);
        $rate = (float) ($rates['social_rate'] ?? 0.292);
        $advances = (int) round((float) ($social['advances_paid'] ?? 0));

        if ($participates) {
            $vvz = (int) TaxFormAmount::ceilKc($pri * $pct);                        // vypočtený VZ (před minimem)
            $mvz = (int) TaxFormAmount::ceilKc((float) ($social['min_base'] ?? 0)); // minimální VZ
            $maxBase = isset($social['max_base']) ? (int) TaxFormAmount::ceilKc((float) $social['max_base']) : PHP_INT_MAX;
            $uvz = min(max($vvz, $mvz), $maxBase);                  // určený VZ včetně stropu
        } else {
            $vvz = 0;
            $mvz = 0;
            $uvz = 0;
        }
        $poj = (int) TaxFormAmount::ceilKc($uvz * $rate); // pojistné = ceil(VZ × sazba)

        return [
            'pri' => $pri,
            'vvz' => $vvz,
            'mvz' => $mvz,
            'uvz' => $uvz,
            'poj' => $poj,
            'advances' => $advances,
            'ned' => $poj - $advances, // nedoplatek(+) / přeplatek(−)
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array{pri:int,vvz:int,mvz:int,uvz:int,poj:int,advances:int,ned:int} $i
     * @param array{main_flags:array<int,bool>,secondary_flags:array<int,bool>,main:int,secondary:int,main_paid:int,secondary_paid:int,is_secondary:bool}|null $monthsInfo
     */
    private function buildPvv(\DOMDocument $dom, array $summary, bool $isSecondary, array $i, ?array $monthsInfo): \DOMElement
    {
        $pvv = $dom->createElement('pvv');
        $pvv->setAttribute('pri', (string) $i['pri']); // daňový základ (celé Kč)
        $col = $isSecondary ? 'v' : 'h'; // sloupec hlavní/vedlejší
        $mesp = round($i['pri'] / 12, 2);

        // Měsíce činnosti (mesc) a účasti na pojištění (mesv). S per-month daty se odvozují
        // ze skutečného měsíčního stavu rozděleného do sloupců h/v, jinak celoroční = 12.
        if ($monthsInfo !== null) {
            $this->hvColsNode($dom, $pvv, 'mesc', $monthsInfo['main'], $monthsInfo['secondary']);
            $this->hvColsNode($dom, $pvv, 'mesv', $monthsInfo['main_paid'], $monthsInfo['secondary_paid']);
        } else {
            $this->hvNode($dom, $pvv, 'mesc', $col, '12');
            $this->hvNode($dom, $pvv, 'mesv', $col, '12');
        }
        $pvv->appendChild($dom->createElement('mesp', $this->dec($mesp)));
        $this->hvNode($dom, $pvv, 'rdza', $col, (string) $i['pri']);
        $this->hvNode($dom, $pvv, 'vvz', $col, (string) $i['vvz']);
        $this->hvNode($dom, $pvv, 'dvz', $col, (string) $i['vvz']);
        $pvv->appendChild($dom->createElement('mvz', (string) $i['mvz']));
        $pvv->appendChild($dom->createElement('uvz', (string) $i['uvz']));
        $pvv->appendChild($dom->createElement('vzza', '0'));
        $pvv->appendChild($dom->createElement('vzsu', (string) $i['uvz'])); // uvz + vzza (vzza=0)
        $pvv->appendChild($dom->createElement('vzsvc', (string) $i['uvz']));
        $pvv->appendChild($dom->createElement('poj', (string) $i['poj']));
        $pvv->appendChild($dom->createElement('slev', '0'));
        $pvv->appendChild($dom->createElement('pojposlev', (string) $i['poj'])); // poj − slev (slev=0)
        $pvv->appendChild($dom->createElement('zal', (string) $i['advances']));
        $pvv->appendChild($dom->createElement('ned', (string) $i['ned'])); // = poj − zal

        return $pvv;
    }

    /** Uzel s atributy h/v — hodnota do zvoleného sloupce, druhý prázdný. */
    private function hvNode(\DOMDocument $dom, \DOMElement $parent, string $tag, string $col, string $value): void
    {
        $el = $dom->createElement($tag);
        $el->setAttribute('h', $col === 'h' ? $value : '');
        $el->setAttribute('v', $col === 'v' ? $value : '');
        $parent->appendChild($el);
    }

    /** Uzel s atributy h/v naplněnými nezávisle (souběh); nula = prázdný sloupec (dle DV ČSSZ). */
    private function hvColsNode(\DOMDocument $dom, \DOMElement $parent, string $tag, int $h, int $v): void
    {
        $el = $dom->createElement($tag);
        $el->setAttribute('h', $h > 0 ? (string) $h : '');
        $el->setAttribute('v', $v > 0 ? (string) $v : '');
        $parent->appendChild($el);
    }

    /** @param array{ned:int} $i */
    private function buildPre(\DOMDocument $dom, array $i): \DOMElement
    {
        $pre = $dom->createElement('pre');
        // Přeplatek = záporný doplatek (ned < 0), konzistentní s pvv. Vrácení (kam/účet)
        // řeší uživatel na ePortálu.
        $pre->setAttribute('vra', (string) max(0, -$i['ned']));
        $pre->appendChild($dom->createElement('bs'));
        $pre->appendChild($dom->createElement('adr'));
        return $pre;
    }

    /** @param array<string,mixed> $meta */
    private function buildOpr(\DOMDocument $dom, array $meta): \DOMElement
    {
        $opr = $dom->createElement('opr');
        $opr->setAttribute('datopr', $this->isoDate($meta['opr_date'] ?? ''));
        $opr->setAttribute('duvod', mb_substr(trim((string) ($meta['opr_reason'] ?? '')), 0, 60));
        return $opr;
    }

    private function buildSpo(\DOMDocument $dom): \DOMElement
    {
        // Spolupracující osoba — povinný element, ale prázdný (bez spolupracující osoby).
        $spo = $dom->createElement('spo');
        $spo->appendChild($dom->createElement('name'));
        $spo->appendChild($dom->createElement('adr'));
        return $spo;
    }

    /** @param array<string,mixed> $meta */
    private function buildDat(\DOMDocument $dom, array $meta): \DOMElement
    {
        $dat = $dom->createElement('dat');
        $dat->setAttribute('dre', $this->isoDate($meta['fill_date'] ?? ''));
        return $dat;
    }

    /**
     * Odvodí datum narození (YYYY-MM-DD) z rodného čísla. Podpora 9místných (do 1954)
     * i 10místných RČ; ženy měsíc +50, speciální +20/+70 (vyčerpaná denní řada po 2004).
     * Vrací '' když RČ neodpovídá platnému datu.
     */
    private function rcToBirthDate(string $rc): string
    {
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})(\d{3,4})$/', $rc, $m)) {
            return '';
        }
        $yy = (int) $m[1];
        $mm = (int) $m[2];
        $dd = (int) $m[3];
        $len = strlen($rc);

        // Měsíc: ženy +50; speciál +20/+70 (jen 10místná RČ od 2004).
        if ($mm > 70 && $len === 10) {
            $mm -= 70;
        } elseif ($mm > 50) {
            $mm -= 50;
        } elseif ($mm > 20 && $len === 10) {
            $mm -= 20;
        }

        // Století: 9místné = do 1953 → 19YY; 10místné 54–99 → 19YY, 00–53 → 20YY.
        if ($len === 9) {
            $year = 1900 + $yy;
        } else {
            $year = $yy <= 53 ? 2000 + $yy : 1900 + $yy;
        }

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31 || !checkdate($mm, $dd, $year)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }

    /**
     * Rozdělí adresu na [ulice, číslo]. ČSSZ chce číslo popisné a orientační v JEDNOM
     * poli (`č.p./č.o.`), na rozdíl od EPO, kde jsou to dva samostatné atributy — proto
     * se sdílený parser {@see EpoSupplierBlockBuilder::parseStreet} skládá přes
     * {@see EpoSupplierBlockBuilder::houseNumber}.
     *
     * @param array<string,mixed> $supplier
     * @return array{0:string,1:string}
     */
    private function parseStreet(array $supplier): array
    {
        [$ulice, $cpop, $corient] = EpoSupplierBlockBuilder::parseStreet($supplier);

        return [$ulice, EpoSupplierBlockBuilder::houseNumber($cpop, $corient)];
    }

    private function dec(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private function isoDate(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        return $d === false ? '' : $d->format('Y-m-d');
    }
}

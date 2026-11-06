<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use MyInvoice\Repository\ClientRepository;

/**
 * Zjistí, zda je dodavatel plátce DPH, z autoritativních registrů a uloží příznak
 * na klienta (`clients.is_vat_payer`). Sdílí ho AI import, online refresh endpoint
 * i backfill skript — jediné místo s pravidlem.
 *
 * Rozhodné DIČ: když doklad uvádí NAVÍC „DIČ k DPH" (`$vatDic`, typicky skupinová
 * registrace odštěpného závodu), použije se pro registry ONO — `dic` zůstává DIČ
 * subjektu kvůli párování karty dodavatele.
 *
 * Zdroj podle typu dodavatele (precedence):
 *  1. CZ (IČO 8 číslic) → ARES `is_vat_payer` (aktivní vlastní nebo skupinová registrace).
 *     - Pozitivní výsledek je vždy konečný.
 *     - Negativní výsledek (vč. ZANIKLY) je konečný JEN pokud DIČ na dokladu
 *       odpovídá vlastní registraci subjektu (číselná část DIČ = IČO). Člen
 *       skupinové registrace DPH (§5a ZDPH) má vlastní registraci zaniklou
 *       vstupem do skupiny. AresClient zahrnuje aktivní skupinu do plátcovství
 *       i bez DIČ z dokladu; pokud ale ARES skupinu neuvádí, doověříme skupinové
 *       DIČ z dokladu krokem 2 (číselná část DIČ skupiny ≠ IČO).
 *  2. VIES `valid` podle DIČ (zahraniční EU dodavatel, NEBO doověření skupinové
 *     registrace z kroku 1 — `ViesClient::lookup()` skupinové DIČ interně routuje
 *     na CRPDPH, protože VIES DPH skupiny vůbec neeviduje). Nález v CRPDPH = plátce.
 *  3. Nezjištěno (registry nedostupné / 404 / mimo EU) → null (necháme dosavadní
 *     příznak beze změny), NEBO — u skupinové DIČ z kroku 1, kterou se nepodařilo
 *     doověřit — ponecháme ARES negativum (konzervativní fallback, nic nezhoršuje
 *     oproti dřívějšímu chování).
 *
 * Cache: ARES (ares_cache 24 h), VIES (vies_cache) i CRPDPH (crpdph_cache) řeší TTL
 * na úrovni klientů → volání při každém vytvoření faktury je fakticky „1× denně"
 * bez zátěže registrů.
 */
final class VendorVatPayerResolver
{
    public function __construct(
        private readonly AresClient $ares,
        private readonly ViesClient $vies,
        private readonly ClientRepository $clients,
    ) {}

    /**
     * Zjistí plátcovství a (pokud je výsledek jednoznačný) uloží ho na klienta.
     *
     * Plátcovství doložené aktivní skupinovou registrací převezme na kartu i DIČ skupiny,
     * pokud karta nese vlastní DIČ člena (číslice = IČO) nebo žádné. Vlastní registrace
     * člena vstupem do skupiny zaniká; karta plátce s ní by poslala do KH DIČ, které
     * registr plátců nezná. Jiné ručně zadané DIČ zůstává.
     *
     * @param ?string $vatDic DIČ k DPH z dokladu (skupinová registrace), viz {@see resolve()}
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown', group_dic?:string, dic_updated?:string}
     */
    public function resolveAndPersist(int $clientId, ?string $ic, ?string $dic, ?string $vatDic = null): array
    {
        $res = $this->resolve($ic, $dic, $vatDic);
        if ($res['is_vat_payer'] !== null) {
            $this->clients->setVatPayer($clientId, $res['is_vat_payer']);
        }
        if ($res['is_vat_payer'] === true && isset($res['group_dic'])
            && $this->clients->adoptGroupVatDic($clientId, (string) $ic, $res['group_dic'])) {
            $res['dic_updated'] = $res['group_dic'];
        }
        return $res;
    }

    /**
     * Pure lookup bez zápisu — vrací is_vat_payer (true/false) nebo null (nezjištěno).
     *
     * @param ?string $vatDic „DIČ k DPH" z dokladu, když ho doklad uvádí NAVÍC vedle `dic`
     *        (odštěpný závod fakturující pod skupinovou registrací — issue #8: hlavička nese
     *        „DIČ: CZ27597075, DIČ k DPH: CZ699000139"). Pro dotaz do registru plátců je
     *        rozhodující právě tohle DIČ; `dic` zůstává DIČ subjektu, protože na něm stojí
     *        dohledání karty dodavatele ({@see \MyInvoice\Service\Import\ClientResolver}).
     *        Bez něj by krok 1 níže viděl „DIČ = CZ + IČO", vzal ARES negativum jako konečné
     *        a doklad by se vytěžil jako od neplátce (nulová daň, žádný odpočet).
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown', group_dic?:string}
     */
    public function resolve(?string $ic, ?string $dic, ?string $vatDic = null): array
    {
        $icDigits = preg_replace('/\D/', '', (string) $ic) ?? '';
        // DIČ k DPH má přednost před DIČ subjektu — právě pod ním je subjekt registrovaný.
        $dicTrim = trim((string) $vatDic) !== '' ? trim((string) $vatDic) : trim((string) $dic);
        $dicDigits = preg_replace('/\D/', '', $dicTrim) ?? '';

        // 1. CZ subjekt dle IČO → ARES (vlastní i skupinová registrace, normalizuje AresClient).
        $aresResult = null;
        if (strlen($icDigits) === 8) {
            $resp = $this->ares->lookup($icDigits);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                $aresResult = ['is_vat_payer' => (bool) ($resp['data']['is_vat_payer'] ?? false), 'source' => 'ares'];
                $aresDic = preg_replace('/\D/', '', (string) ($resp['data']['dic'] ?? '')) ?? '';
                if ($aresResult['is_vat_payer'] && CrpDphClient::isGroupRegistration($aresDic)) {
                    $aresResult['group_dic'] = 'CZ' . $aresDic;
                }

                // Pozitiv je vždy konečný. Negativ je konečný jen když DIČ na dokladu
                // odpovídá vlastní registraci (číselná část = IČO) — jinak jde o DIČ
                // skupinové registrace, kterou ARES nemusí uvádět, doověříme krokem 2.
                $isDicMismatch = $dicDigits !== '' && $dicDigits !== $icDigits;
                if ($aresResult['is_vat_payer'] || !$isDicMismatch) {
                    return $aresResult;
                }
            }
        }

        // 2. Zahraniční EU dle DIČ → VIES (valid = registrovaný plátce), nebo doověření
        //    skupinové DPH registrace z kroku 1 — ViesClient skupinové DIČ (CZ699xxxxxx)
        //    interně routuje na CRPDPH. Jen když ARES nerozhodl nebo rozhodl negativně
        //    u nesouhlasícího DIČ výše. source='error' = registr nedostupný → nevíme.
        if ($dicTrim !== '') {
            try {
                $v = $this->vies->lookup($dicTrim);
                if (($v['source'] ?? '') !== 'error') {
                    return ['is_vat_payer' => !empty($v['valid']), 'source' => 'vies'];
                }
            } catch (\Throwable) {
                // VIES/CRPDPH timeout / chyba — necháme nezjištěno (níže: fallback na ARES).
            }
        }

        // ARES rozhodl negativně u DIČ skupinové registrace, ale doověření selhalo nebo
        // nebylo k dispozici (chybí DIČ) → radši konzervativně ARES negativum než
        // "nezjištěno" (nezhoršuje dnešní stav).
        if ($aresResult !== null) {
            return $aresResult;
        }

        return ['is_vat_payer' => null, 'source' => 'unknown'];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Vypočtený stav licence instance — snapshot pro middleware, Actions i /auth/me.
 *
 * Stavy:
 *   trial         — bez klíče, < 60 dní od trial_started_at (plný provoz, bez limitů)
 *   trial_expired — bez klíče, po 60 dnech (MIT základ zůstává plně funkční)
 *   active        — platný token, status ok, now <= valid_until
 *   overage       — token status overage (plný provoz + výzva, ale blok tvorby uživatelů/firem)
 *   degraded      — token expirovaný / neplatný podpis / chybí (bez komerčních funkcí)
 */
final class LicenseState
{
    public const TRIAL         = 'trial';
    public const TRIAL_EXPIRED = 'trial_expired';
    public const ACTIVE        = 'active';
    public const OVERAGE       = 'overage';
    public const DEGRADED      = 'degraded';

    /** Důvody, proč nejde přidat uživatele na licencované místo. */
    public const BLOCK_NO_LICENSE = 'no_license';
    public const BLOCK_SEAT_LIMIT = 'seat_limit';

    /**
     * Kolik uživatelů s právem zápisu unese SPRAVOVANÁ instalace BEZ licence.
     *
     * Jeden — živnostník nebo firma, která si vede fakturaci sama. Druhý
     * uživatel už znamená víc lidí nad jedněmi daty a to je placená funkce.
     *
     * ⚠️ Platí JEN ve spravovaném provozu ({@see $managed}). Instalace, kterou
     * si zákazník provozuje sám a nemá licenci, běží bez stropu — to je slib
     * bezplatné verze a čl. 1.12 licenčního ujednání, který říká, že bezplatné
     * funkce zůstanou plně funkční včetně vytváření a změn dat. Jakmile si
     * licenci koupí, platí počet míst z ní.
     *
     * ⚠️ Nepočítají se sem účty bez business WRITE oprávnění. Typ role není
     * výjimka: zapisující klientská role zabírá místo stejně jako staff role.
     */
    public const FREE_SEATS = 1;

    public function __construct(
        public readonly string $state,
        public readonly string $instanceId,
        public readonly ?string $tier,
        /** null = neomezeno; 0+ = limit počtu firem */
        public readonly ?int $maxCompanies,
        /** licencovaný počet aktivních uživatelů; 0 = neurčeno (trial → bez limitu) */
        public readonly int $usersLicensed,
        public readonly int $usersActive,
        public readonly int $companiesActive,
        public readonly ?int $validUntil,
        public readonly ?int $trialEndsAt,
        public readonly ?int $overageDeadline,
        public readonly ?string $licenseKey,
        public readonly ?string $lastCheckAt,
        public readonly bool $lastCheckOk,
        /** Doživotní (perpetual) licence — neomezená platnost; valid_until je jen 14denní TTL tokenu. */
        public readonly bool $perpetual = false,
        /**
         * Poslední známý stav předplatného z licenčního serveru — automatické
         * prodlužování a datum dalšího stržení. `null` = server ho nehlásil
         * (doživotní licence, trial, starší server).
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $subscription = null,
        /**
         * Odemyká TARIF placené moduly?
         *
         * ⚠️ Není to totéž co „licence platí". Klíč se vydává i na bezplatný
         * tarif — je to jediný kanál, kterým se instance dozví o zaplacené
         * kvótě, stavu předplatného a počtu uživatelů. Platná licence proto
         * sama o sobě účetnictví neodemyká.
         *
         * Výchozí `true` je nutný pro zpětnou kompatibilitu: token vydaný před
         * zavedením příznaku ho nenese a všechny takové licence jsou placené.
         * Kdyby se defaultovalo na false, zavřelo by to účetnictví každému
         * platícímu zákazníkovi až do příští obnovy tokenu.
         */
        public readonly bool $commercial = true,
        /**
         * Provozuje instalaci někdo jiný než zákazník (`app.managed`)?
         *
         * Rozhoduje o tom, jestli platí strop {@see FREE_SEATS}. Aplikace se
         * NESMÍ ptát, KDO ji hostuje — jen jestli je hostovaná.
         */
        public readonly bool $managed = false,
    ) {}

    /** Prodlužuje se licence automaticky (chystá se další stržení)? */
    public function autoRenews(): bool
    {
        return (bool) ($this->subscription['auto_renew'] ?? false);
    }

    /**
     * Je dostupná komerční účetní nadstavba MyÚčto?
     *
     * Dvě nezávislé podmínky, a musí platit obě:
     *  1. licence platí (není propadlá ani chybějící),
     *  2. tarif placené moduly vůbec odemyká.
     *
     * ⚠️ Druhou podmínku sem přidal bezplatný tarif „Fakturace a DPH". Ten má
     * platnou licenci — potřebuje ji, aby se instance dozvěděla o kvótě
     * a platbách — ale účetnictví, mzdy, sklad ani přiznání si nezaplatil.
     */
    public function hasCommercialFeatures(): bool
    {
        return $this->commercial && $this->licenseLive();
    }

    /**
     * Platí licence? Odpověď na otázku „známe rozsah, který si zákazník
     * zaplatil", ne „na co má nárok".
     *
     * Na tomhle stojí LIMITY (uživatelé, firmy): dokud licence neplatí, běží
     * instalace na bezplatném základu bez stropů — to je slib MIT verze
     * a nesmí ho zrušit tarif, který zrovna neodemyká placené moduly.
     */
    private function licenseLive(): bool
    {
        return $this->state !== self::DEGRADED && $this->state !== self::TRIAL_EXPIRED;
    }

    /**
     * Smí vzniknout nový (nebo aktivovaný) uživatel na licencovaném místě?
     *
     * ⚠️ Ptá se na PLATNOST licence, ne na přístup k placeným modulům. Kdyby
     * se ptal na `hasCommercialFeatures()`, bezplatný tarif by měl uživatele
     * bez omezení — a přitom je to tarif, kde se za ně platí.
     *
     * ⚠️ Týká se JEN rolí, které zabírají licenční místo. Uživatel bez business
     * WRITE oprávnění jde založit vždy; samotný typ client žádnou výjimkou není.
     */
    public function allowsNewUser(): bool
    {
        return $this->newUserBlockReason() === null;
    }

    /**
     * Proč nejde založit další uživatele na licencovaném místě, nebo `null`.
     *
     * Rozlišení má smysl kvůli tomu, co se řekne adminovi: „dosáhli jste počtu
     * podle licence" a „nemáte licenci" vedou k úplně jiné akci a splynout
     * do jedné hlášky nesmí.
     */
    public function newUserBlockReason(): ?string
    {
        return $this->seatCountBlockReason($this->usersActive + 1);
    }

    /**
     * Ověří konkrétní cílový počet míst. Používá atomická kapacitní brána po
     * mutaci, která může jedním krokem přidat více míst změnou sdílené role.
     */
    public function seatCountBlockReason(int $targetSeats): ?string
    {
        if ($targetSeats <= $this->usersActive) {
            return null;
        }
        // Zkušební období běží v plném rozsahu, včetně počtu uživatelů.
        if ($this->state === self::TRIAL) {
            return null;
        }
        // ⚠️ Bez platné licence platí strop JEN ve spravovaném provozu: jeden
        // uživatel s právem zápisu, dál už jen účty pro čtení.
        //
        // Instalace, kterou si zákazník provozuje sám, běží bez stropu. Nic
        // jsme mu neprodali, nic mu neplatí a bezplatná verze slibuje plnou
        // funkčnost včetně vytváření dat; zavřít mu zakládání uživatelů by
        // znamenalo vzít funkci, kterou měl. Jakmile si licenci koupí, platí
        // počet míst z ní (větev níž).
        //
        // STÁVAJÍCÍ uživatelé pracují dál i nad tímhle počtem — po vypršení
        // licence se nikomu nezamyká přístup do jeho vlastních dat, jen se
        // neprodá další místo.
        if (!$this->licenseLive()) {
            if (!$this->managed) {
                return null;
            }
            return $targetSeats <= self::FREE_SEATS ? null : self::BLOCK_NO_LICENSE;
        }
        if ($this->state === self::ACTIVE) {
            return ($this->usersLicensed <= 0 || $targetSeats <= $this->usersLicensed)
                ? null
                : self::BLOCK_SEAT_LIMIT;
        }

        // Overage přes limit → blok do navýšení nebo srovnání počtů.
        return self::BLOCK_SEAT_LIMIT;
    }

    /**
     * Kopie stavu s ČERSTVÝM počtem obsazených míst.
     *
     * Počet se jinak čte z cache, což při souběhu nestačí: dva požadavky
     * zároveň by oba viděly volno. Zakládání uživatele si proto uvnitř
     * transakce spočítá místa znovu a dosadí je sem.
     */
    public function withActiveUsers(int $usersActive): self
    {
        return new self(
            $this->state, $this->instanceId, $this->tier, $this->maxCompanies,
            $this->usersLicensed, $usersActive, $this->companiesActive,
            $this->validUntil, $this->trialEndsAt, $this->overageDeadline,
            $this->licenseKey, $this->lastCheckAt, $this->lastCheckOk,
            $this->perpetual, $this->subscription, $this->commercial, $this->managed,
        );
    }

    /** Kopie stavu s čerstvým COUNT(*) firem pro atomickou kapacitní bránu. */
    public function withActiveCompanies(int $companiesActive): self
    {
        return new self(
            $this->state, $this->instanceId, $this->tier, $this->maxCompanies,
            $this->usersLicensed, $this->usersActive, $companiesActive,
            $this->validUntil, $this->trialEndsAt, $this->overageDeadline,
            $this->licenseKey, $this->lastCheckAt, $this->lastCheckOk,
            $this->perpetual, $this->subscription, $this->commercial, $this->managed,
        );
    }

    /** Smí vzniknout nová firma (supplier)? Viz {@see allowsNewUser()}. */
    public function allowsNewCompany(): bool
    {
        if ($this->state === self::TRIAL || !$this->licenseLive()) {
            return true;
        }
        if ($this->state === self::ACTIVE) {
            return $this->maxCompanies === null || $this->companiesActive < $this->maxCompanies;
        }
        return false;
    }

    /** Maskovaný klíč pro UI: MYU-XXXX-…-poslední 4 znaky. */
    public function maskedKey(): ?string
    {
        if ($this->licenseKey === null || $this->licenseKey === '') {
            return null;
        }
        $key = $this->licenseKey;
        $last4 = substr($key, -4);
        $prefix = str_contains($key, '-') ? explode('-', $key, 2)[0] : substr($key, 0, 3);
        return $prefix . '-XXXX-…-' . $last4;
    }

    /** Serializace pro /api/license/status. */
    public function toArray(string $buyUrl): array
    {
        return [
            'state'           => $this->state,
            'instance_id'     => $this->instanceId,
            'tier'            => $this->tier,
            'max_companies'   => $this->maxCompanies,
            'users_licensed'  => $this->usersLicensed,
            'users_active'    => $this->usersActive,
            'companies_active' => $this->companiesActive,
            'valid_until'     => $this->validUntil,
            'trial_ends_at'   => $this->trialEndsAt,
            'overage_deadline' => $this->overageDeadline,
            'perpetual'       => $this->perpetual,
            'license_key_masked' => $this->maskedKey(),
            'last_check_at'   => $this->lastCheckAt,
            'last_check_ok'   => $this->lastCheckOk,
            'commercial_features' => $this->hasCommercialFeatures(),
            // ⚠️ Rozdíl proti `commercial_features`: tohle říká, jestli
            // placené moduly odemyká TARIF. Bez toho by obrazovka nedokázala
            // rozlišit „licence propadla, zaplaťte" od „tenhle tarif to nikdy
            // neměl" — a nabízela by zaplacení něčeho, co je zaplacené.
            'tier_commercial' => $this->commercial,
            // ⚠️ Stav předplatného, ne jen stav licence.
            //
            // Licence může být pořád platná, a přitom má zákazník po splatnosti:
            // token doběhne až na konci zaplaceného období. Bez tohohle pole by
            // se výzva k úhradě objevila teprve ve chvíli, kdy se komerční
            // moduly zavřou — tedy až když je pozdě.
            //
            // Nese se jen stav, žádná částka ani číslo dokladu: aplikace o nich
            // nic neví a vědět nepotřebuje.
            'subscription_state'  => isset($this->subscription['state'])
                ? (string) $this->subscription['state']
                : null,
            'buy_url'         => $buyUrl,
            // Stav předplatného ze serveru: {state, period, auto_renew, next_charge_at,
            // cancelled_at, valid_until}. null = licence se automaticky neprodlužuje
            // (doživotní, trial) nebo to server nehlásí.
            'subscription'    => $this->subscription,
        ];
    }

    /** Kompaktní shrnutí pro /auth/me (FE bannery). */
    public function toMeSummary(): array
    {
        return [
            'state'            => $this->state,
            'tier'             => $this->tier,
            'trial_ends_at'    => $this->trialEndsAt,
            'valid_until'      => $this->validUntil,
            'overage_deadline' => $this->overageDeadline,
            'perpetual'        => $this->perpetual,
            'commercial_features' => $this->hasCommercialFeatures(),
            'tier_commercial'  => $this->commercial,
            'subscription_state' => isset($this->subscription['state'])
                ? (string) $this->subscription['state']
                : null,
            // Proč nejde přidat dalšího uživatele na licencované místo:
            // `no_license` / `seat_limit` / null. Obrazovka správy uživatelů
            // podle toho varuje dřív, než admin vyplní celý formulář.
            'new_user_blocked' => $this->newUserBlockReason(),
            // Počty pro přehledný overage banner (aktivní vs. licencováno).
            'users_active'     => $this->usersActive,
            'users_licensed'   => $this->usersLicensed,
            'companies_active' => $this->companiesActive,
            'max_companies'    => $this->maxCompanies,
        ];
    }
}

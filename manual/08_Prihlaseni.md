# 8. Přihlášení a uživatelský profil

## 8.1 Přihlášení

![Přihlašovací obrazovka](img/04_login.webp)

Pokud správce povolil přihlášení bez hesla a máš zaregistrovanou passkey,
klikni na **Přihlásit přístupovým klíčem**. V systémovém dialogu vyber účet a
potvrď otiskem, obličejem, PINem nebo jinou metodou zařízení. E-mail ani heslo
nezadáváš.

Jako fallback zadej e-mail + heslo a klikni **Přihlásit**. Pokud má účet
zapnuté silné vícefaktorové ověření, následuje potvrzení passkey nebo zadání
TOTP kódu. Po úspěšném ověření tě systém pustí na
[Přehled (dashboard)](10_Prehled.md).

| Pole | Význam |
|---|---|
| Přihlásit přístupovým klíčem | Přihlášení bez e-mailu a hesla; zobrazí se jen při povolení správcem |
| E-mail | Login zadaný při registraci |
| Heslo | Heslo zadané při registraci |
| Zapomenuté heslo? | Odkaz na obnovu — viz § 8.4 |

## 8.2 Brute-force ochrana

Po **5 neúspěšných pokusech** během 5 minut z jedné IP se objeví **CAPTCHA**
(Cloudflare Turnstile). Po **10 selháních** během 15 minut se IP zablokuje na
15 minut. Po **30 selháních za hodinu** je lockout 24 hodin a uživateli na
e-mail přijde upozornění.

> 🛈 Pokud heslo zapomeneš a omylem se 5× spletl, CAPTCHA se objeví — vyřeš ji
> a pokračuj. Pokud se zablokuješ, počkej 15 minut nebo požádej admina, aby ti
> heslo resetoval z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 8.3 Vícefaktorové ověření (passkey / TOTP)

Passkey lze použít přímo k přihlášení bez e-mailu a hesla, pokud tuto možnost
povolil správce, nebo jako silný druhý krok po zadání e-mailu a hesla.
Systémový dialog zařízení může použít otisk prstu, obličej, PIN, gesto, heslo
zařízení nebo externí bezpečnostní klíč. MyÚčto konkrétní metodu nevybírá a
biometrická data nikdy nedostane.

Druhý krok nabízí pouze metody, které účet skutečně má — vybírá je server až po
ověření hesla. Účtu bez zaregistrované passkey se tedy passkey nenabídne a při
jediné dostupné metodě se rovnou zobrazí pole pro kód, bez mezikroku s výběrem.
Tlačítko **Přihlásit přístupovým klíčem** patří k prvnímu kroku (nahrazuje
e-mail a heslo), takže po odeslání hesla zmizí.

Má-li účet současně aktivní TOTP, můžeš místo passkey zvolit
**Použít kód z autentikátoru** po standardním přihlášení e-mailem a heslem a
zadat aktuální šestimístný kód. Zrušení systémového dialogu passkey TOTP samo
nespustí.

MyÚčto nepoužívá záložní jednorázové recovery kódy. Obnova přístupu probíhá
jinou passkey, TOTP nebo administrátorským CLI rescue. Podrobnosti jsou v
[97. Bezpečnost](97_Bezpecnost.md).

## 8.4 Zapomenuté heslo

Klikni na **Zapomenuté heslo?** pod přihlášením. Zadej e-mail, na který přijde
odkaz pro nastavení nového hesla (platnost 1 hodina).

![Reset hesla](img/04_reset.webp)

Pokud e-mail nepřišel:

- Zkontroluj spam.
- Ověř s adminem, že systém má nakonfigurované SMTP (`cfg.php` → `smtp.*`).
- V krajním případě admin nastaví heslo z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 8.5 Můj profil

V pravém horním rohu klikni na své jméno → **Můj profil**.

![Můj profil](img/04_profil.webp)

Můžeš si změnit:

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI + activity log |
| Jazyk | `cs` (čeština) nebo `en` (angličtina) — UI + e-mailové šablony |
| Heslo | Změna stávajícího hesla (vyžaduje původní) |
| TOTP | Zobrazit stav a aktivovat pomocí QR + ověřovacího kódu |
| Passkeys | Přidat, pojmenovat, přejmenovat nebo odvolat přístupový klíč |
| Zámek aplikace | Převzít interval správce nebo zvolit vlastní přísnější interval |

Pro běžný účet je vhodné mít dvě passkeys, případně jednu passkey a aktivní
TOTP. Přidání nebo odvolání passkey vyžaduje čerstvé ověření existujícím silným
faktorem, a to podle toho, co účet zrovna má:

| Stav účtu | Co registrace vyžádá |
|---|---|
| Žádný silný faktor | Aktuální heslo |
| Aktivní TOTP, zatím žádná passkey | **Povinně kód z autentikátoru** — jiný silný faktor k ověření neexistuje |
| Alespoň jedna passkey | Potvrzení existující passkey; kód z autentikátoru je volitelná alternativa |

## 8.6 Zamknutí a odemčení aplikace

V uživatelském menu můžeš zvolit **Zamknout**. V profilu na záložce
**Zámek aplikace** lze také nastavit automatické zamknutí po nečinnosti.
Volba **Použít nastavení správce** převezme společnou politiku instalace.
Vlastní kladný interval může být pouze kratší nebo stejný jako limit správce.
Pokud správce automatický zámek nevynucuje (`0`), můžeš jej pro svůj účet
dobrovolně zapnout v rozsahu 1 až 1440 minut.

Zámek je uložený na serveru: nejde jen o překryv obrazovky a zamčená session
nemůže číst ani měnit business data přes API.

Pro odemčení stiskni **Odemknout pomocí passkey** a potvrď systémový dialog.
Odemčení nevyžaduje znovu zadat e-mail a heslo. Když passkey není dostupná,
zvol **Přihlásit se znovu**; aplikace nejprve bezpečně ukončí zamčenou session
a provede celý login včetně MFA.

Zámek zachová rozepsaný formulář pouze po dobu, kdy prohlížeč drží stránku
v paměti. Pokud Android stránku ukončí, neuložené změny se ztratí. Webová PWA
také nedokáže garantovat zákaz screenshotu ani skrytí náhledu v Android Recents.

## 8.7 Odhlášení

V pravém horním rohu klikni **Odhlásit**. Session se zruší okamžitě i na
serveru. Pokud nezmáčkneš odhlásit a jen zavřeš okno, session má absolutní
platnost nejvýše **30 dní**; během ní se může dříve zamknout po nečinnosti.

# Národní klasifikace postavení v zaměstnání (NKPZ)

Připnutý číselník ČSÚ 80145 (`NKPZ_KL`, sdělení ČSÚ č. 401/2024 Sb.) k postavení
v zaměstnání v registraci zaměstnance (REGZEC `job/@relat`, ID 10249).

**Podání tento číselník nepoužívá, je jen reference.** REGZEC 2025 i REGZEC 2027
berou postavení v zaměstnání z výčtu vloženého ve slovníku 1.4.1.6
(`PayrollRegistrationEmploymentStatusCodebook`), který je řádek po řádku shodný
s listem „CIS Klasif. postavení v zaměst" v EDV REGZEC27 1.5.0.3 (závazná
definice věty ČSSZ/MPSV) i s číselníkem MPSV
`DS_CIS_Klasifikace_postaveni_v_zamestnani`. Export ČSÚ zůstává jako hlídač
příchodu nového sdělení ČSÚ.

| Zdroj | Soubor | Původ | SHA-256 |
|---|---|---|---|
| NKPZ, export iSMS k 1. 1. 2027 | `csu-80145-2026-11-03/CIS80145_CS_2026-11-03.csv` | [ČSÚ iSMS, číselník 80145](https://apl2.czso.cz/iSMS/klasstru.jsp?kodcis=80145), export CSV s `datpohl=01.01.2027` (bajtově shodný s exportem k 3. 11. 2026) | `9a82d70e812b520b348f2e638f7cd506b5915c2f63c79e6b43f9416b8ecabf4c` |

Manifest `csu-80145-2026-11-03/manifest.json` má `manifest_sha256`
`35e2d0839d3290b1aba6ad0d401f6f439a83d9043e3b188fd43b8223dd794134`, připnutý
v `PayrollRegistrationNkpzClassification::MANIFEST_SHA256`. Číselník má 65
položek (úrovně 1 až 4: 6, 10, 15, 34) a všechny platí od 1. 1. 2025 bez konce
platnosti. Nabídka i kontrola pracují jen se 34 čtyřmístnými listy.

## Rozdíl proti vložené verzi 1.4.1.6

Listy se liší jediným kódem. Slovník 1.4.1.6 má
2119 „Zaměstnavatelé – fyzické osoby jinde neuvedené", sdělení č. 401/2024 Sb.
i iSMS ČSÚ uvádějí tutéž položku pod kódem 2219 (iSMS ji věší pod 221).
Kromě toho se liší název nelistové položky 134 („… č. 361/2003 Sb., …").
Kód 2219 je tisková chyba přílohy sdělení č. 401/2024 Sb. (řádek stojí uvnitř
bloku 211 fyzických osob, vzor xx19 „jinde neuvedené" je i u 3119 a 5119),
kterou převzala iSMS ČSÚ a podle prvních číslic ji zařadila pod 221. Systematická
část NKPZ ČSÚ (`nkpz_systematicka_cast.xlsx`), EDV REGZEC25 1.4.0.6 i REGZEC27
1.5.0.3 a číselník MPSV mají 2119. Podání proto posílá 2119 v obou ročnících
a uložený kód 2219 při čtení převádí na 2119.

## Obnova

```
php tools/NkpzClassificationPackageBuilder.php
```

Builder má připnutý otisk, velikost i počet položek exportu a nad jiným
souborem vědomě selže. Při nové verzi číselníku: stáhnout export
(`https://apl2.czso.cz/iSMS/do_cis_export?kodcis=80145&typdat=0&datpohl=DD.MM.RRRR&cisjaz=203&format=2`),
porovnat s připnutým, přepnout konstanty v builderu, postavit manifest a
aktualizovat `MANIFEST_SHA256`, `SHA256SUMS` a tuto tabulku.

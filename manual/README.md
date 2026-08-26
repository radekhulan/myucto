# Manuál — workflow

Adresář `manual/` obsahuje uživatelskou dokumentaci v MD souborech. Z nich:

- `tools/generateManualHtml.php` vytvoří HTML verzi servírovanou na URL `/manual`,
- `tools/exportManualToPdf.php` vytvoří `manual/manual.pdf` (button **„Stáhnout PDF"** v sidebaru).

## Struktura

```
manual/
├── INDEX.md                  # navigace + grupy (zdroj pro sidebar TOC)
├── 01_Uvod.md                # kapitoly NN_Nazev.md (řazené dle menu aplikace)
├── 02_Instalace_Quickstart.md
├── ...
├── 99_Reseni_problemu.md
├── img/                      # screenshoty (WEBP po konverzi z PNG)
│   └── SCREENSHOTS.md        # seznam, co vyfotit
├── index.php                 # PHP route handler — servíruje HTML manuál
├── README.md                 # tento soubor
├── generated/                # auto-generated, NEKOMITUJ — `git update-index`
│   ├── 01_Uvod.html          # body fragment per kapitola
│   ├── INDEX.html            # landing
│   ├── _toc.php              # PHP pole s grupy → kapitoly
│   ├── search-index.json     # JSON pro klientský fulltext search
│   └── img/                  # kopie WEBP obrázků
└── manual.pdf                # auto-generated PDF, NEKOMITUJ
```

## Workflow

### Po editaci kapitoly nebo přidání screenshotu

```bash
# 1. Konverze nových PNG screenshotů na WEBP (smaže PNG po úspěchu)
php tools/convertImagesToWebp.php

# 2. Regenerace HTML pro lokální kontrolu
php tools/generateManualHtml.php

# 3. Otevři v prohlížeči pro kontrolu
# https://tvoje-domena.cz/manual
```

PDF se při běžné editaci manuálu negeneruje. Vytváří se při sestavení vydání,
případně ručně na výslovnou žádost:

```powershell
php tools/exportManualToPdf.php
```

### Přidání nové kapitoly

1. Vytvoř `manual/NN_Nazev.md` (NN = pořadové dvojcifré číslo).
2. Přidej řádek do `manual/INDEX.md` ve správné grupě (### Instalace a start /
   ### Grafy / ### Prodej / ### Nákup / ### Peníze / ### Dokumenty /
   ### Sklad / ### Daně / ### Účetnictví / ### Mzdy / ### Nástroje /
   ### Daňová evidence / ### Firma / ### Systém).
3. Spusť `php tools/generateManualHtml.php`. PDF vytvoř jen při sestavení vydání
   nebo na výslovnou žádost.

### Reorganizace pořadí

Změň prefix v názvu souborů a aktualizuj odkazy v `INDEX.md`. Generátor sortuje
abecedně podle filename — `01_*` přijde před `02_*` atd.

Pro vsuvku mezi `15_*` a `16_*` můžeš použít `15a_*` (glob `[0-9][0-9]_*.md`
zachytí.)

## Konvence pro psaní

- **H1** `# NN. Název kapitoly` (max 1 per soubor)
- **H2** `## NN.X` (chapter-prefixed) — generátor je dá do TOC
- **H3** `### NN.X.Y`
- **Screenshot** na začátku sekce: `![Popisek](img/NN_screen.webp)`
- **Tabulky polí** formuláře: `| Pole | Význam |`
- **Box poznámky**:
  - `> 💡 Tip: ...` — užitečný tip
  - `> 🛈 Pozn: ...` — poznámka (info)
  - `> ⚠️ Pozor: ...` — varování / důležité
- **Cross-reference**: `[Detail klienta](18_Klienti.md)` — generátor přepíše na
  `?ch=14_Klienti`

## Servírování

`/manual` URL je nakonfigurované v:

- `.htaccess` (Apache / Docker) — RewriteRule `^manual/?$ → manual/index.php`
- `web.config` (IIS) — `<rule name="Manual route">`

`manual/index.php` čte `manual/generated/_toc.php` (sidebar) a
`manual/generated/<NN>.html` (obsah). Layout: levý sidebar TOC + content panel,
fulltext search přes `search-index.json` (klient-side).

## Bezpečnost

`.md` soubory jsou blokované přímým přístupem (i přes URL `/manual/01_Uvod.md`)
— uživatel je dostane jen v HTML rendru přes PHP. Týká se to .htaccess i web.config.

`/manual/img/*.webp` a `/manual/generated/img/*.webp` jsou veřejně dostupné jako
statické soubory (cacheované 1 rok).

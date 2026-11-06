# Dávkový regresní koordinátor

Soubor batch.py spouští úplný manifest přes čtyři pevné ordinal lanes. Každý PHP bridge má vnější limit 150 sekund. Bridge, aplikační Runner i worker.php jsou pevné soubory v tools/regression. CLI nemá přepínač pro callback, worker ani jinou spustitelnou cestu.

Capture přijímá soukromý JSON s přesně těmito poli:

    {
      "code_root": "absolutní cesta úplného kontrolovaného checkoutu",
      "database": "jedinecny_01234567_test",
      "as_of": "2026-12-31",
      "execution_date": "2026-10-19",
      "dataset_id": "64 malých hex znaků",
      "source_hashes": {
        "api/composer.lock": "64 malých hex znaků"
      }
    }

code_root musí být explicitní, absolutní a musí obsahovat vlastní api/vendor/autoload.php. source_hashes používají pouze kanonické relativní cesty uvnitř tohoto rootu. Koordinátor každou uvedenou položku ověří před capture a znovu před dokončením. Úplnost source manifestu je odpovědnost schváleného generátoru. Nástroj ji sám neumí dokázat.

Pro izolovanou testovací databázi v Dockeru lze přidat jediné volitelné pole `docker_host`, například `db`. Vyžaduje současně výslovné `MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST=1`. Hodnota musí být samotné jméno hostitele bez portu, URL nebo cesty. Koordinátor ji předá existující bezpečnostní kontrole workeru a do trvalé identity uloží pouze její hash. Bez tohoto pole platí původní omezení na lokální hostitele; produkční ani běžná pracovní databáze se tím nepovoluje.

Na čistém Windows nejdřív nainstalujte pevně připnutá IANA časová data:

    python -m pip install -r tools\regression\requirements.txt

Bez nich koordinátor skončí před prvním capture s chybou `regression_timezone_data_required` a instalačním pokynem. Linux může použít systémovou IANA databázi, pin ale zachovává reprodukovatelné Windows prostředí.

PHP CLI lze nastavit přes MYINVOICE_REGRESSION_PHP_BINARY. Bez proměnné se hledá php.exe nebo php na PATH. Kandidát se bez shellu ověří jako PHP CLI 8.5 nebo novější.

    $env:MYINVOICE_REGRESSION_PHP_BINARY = (Get-Command php).Source
    python tools\regression\batch.py capture <config.json> <novy-capture-adresar>
    python tools\regression\batch.py compare <baseline-capture> <candidate-capture> <novy-compare-adresar>

Vstupní config a výstupní adresáře musí být mimo checkout a mimo každý Git strom. Config zůstává soukromý. Trvalá identity ukládá hash názvu databáze, relativní source manifest, jeho generation hash, dataset, datum a přesné hashe harnessu. Neukládá absolutní code_root, TEMP cestu ani název databáze. Transientní requesty a runtime adresáře se po každém dokončeném či chybném bridge volání odstraní.

Každý case má immutable receipt s ordinalem, lane, case ID, hashem specifikace, hashem manifestu, výsledku a numerických metrik. Finální index je v pořadí manifestu. Resume znovu nespouští case s platným receiptem. Chybějící receipt, cizí adresář, duplicitní JSON klíč, změněný hash, source generation, harness, plán, index, metrika, den nebo error case ukončí ověření chybou.

Na Windows koordinátor vytvoří pro každý bridge nový nepojmenovaný Job Object s KILL_ON_JOB_CLOSE. Job handle výslovně zakáže dědění. Pomocný proces čeká na grant přes stdin a spustí bridge až po úspěšném přiřazení do jobu. Timeout nebo přerušení nastaví sdílený cancellation event, ukončí jen tento job a teprve potom koordinátor čeká na worker thread. Na POSIX drží interní supervisor vlastní session a její process group až do převzetí exit kódu bridge. Potom koordinátor ukončí celou vlastněnou skupinu i při úspěšném konci leaderu. Stejný bounded polling a cleanup se použije při timeoutu a přerušení. Živý group leader brání recyklaci identifikátoru skupiny před cleanupem. Nástroj nikdy nehledá ani neukončuje globální PIDy.

Comparator musí zůstat byte-for-byte na SHA-256 d687f0ca0a4b65a4ff78b581aef1165f8a3eaa8081b31161579387c2980da487. Srovnání čte vždy jeden pár výsledků a ukládá samostatný výsledek case, nevytváří pole všech odpovědí. Shodná chyba ani nulové numerické pokrytí nemohou projít.

Syntetické testy:

    python tools\regression\tests\test_runner_exit.py
    python tools\regression\tests\test_owned_process.py
    python tools\regression\tests\test_batch.py
    php tools\regression\tests\test_function_case.php

Tyto testy neotevírají databázi ani síť a nečtou dump, skutečný request, result nebo finanční data. Dokládají pouze koordinaci, procesní vlastnictví, integritu receipts a přesné volání existujícího Comparatoru. Neprokazují rychlost ani finanční paritu reálného frameworku 500. Jeden case může běžet nejvýše 150 sekund, celý manifest nemá společný 150sekundový limit. Aktivní jsou nejvýše čtyři bridge procesní stromy.

/**
 * Vite plugin: rozseká `src/i18n/{locale}.json` na kousky po jmenných prostorech
 * do `src/i18n/chunks/{locale}/`.
 *
 * Why: cs.json má 488 kB a načítal se celý při startu, i když stránka sáhne na
 * zlomek. Zdrojem pravdy ale musí zůstat JEDEN soubor na jazyk — na tom stojí
 * `check:i18n`, překladatelé i všechny nástroje. Rozdělení proto patří do
 * buildu, ne do repozitáře; `chunks/` je gitignored a generuje se sem.
 *
 * Kousky jsou obyčejné .json soubory (ne virtuální moduly), aby je Vite uměl
 * načíst přes `import.meta.glob` a udělal z nich samostatné chunky bez další
 * konfigurace.
 */
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const LOCALES = ['cs', 'en']
const CHANGE_DELAY_MS = 50
const RETRY_DELAY_MS = 100
const MAX_RETRIES = 3

function writeIfChanged(file, content) {
  try {
    if (readFileSync(file, 'utf8') === content) return
  } catch { /* soubor zatím není */ }
  writeFileSync(file, content)
}

export default function i18nSplit({ srcDir }) {
  const i18nDir = join(srcDir, 'i18n')
  const chunksDir = join(i18nDir, 'chunks')

  function generate() {
    const map = JSON.parse(readFileSync(join(i18nDir, 'namespaces.generated.json'), 'utf8'))
    const core = new Set(map.core)

    // Nejprve načíst oba jazyky: neúplný zápis nesmí smazat poslední platné chunky.
    const locales = LOCALES.map(locale => ({
      locale,
      messages: JSON.parse(readFileSync(join(i18nDir, `${locale}.json`), 'utf8')),
    }))
    mkdirSync(chunksDir, { recursive: true })
    for (const { locale, messages } of locales) {
      const dir = join(chunksDir, locale)
      // Smazat a vytvořit znovu: po odstranění prostoru z cs.json by tu jinak
      // zůstal osiřelý kousek a `import.meta.glob` by ho pořád nabízel.
      rmSync(dir, { recursive: true, force: true })
      mkdirSync(dir, { recursive: true })

      const coreMessages = {}
      for (const ns of Object.keys(messages)) {
        if (core.has(ns)) {
          coreMessages[ns] = messages[ns]
        } else {
          writeIfChanged(join(dir, `${ns}.json`), JSON.stringify({ [ns]: messages[ns] }))
        }
      }
      writeIfChanged(join(dir, '__core.json'), JSON.stringify(coreMessages))
    }
  }

  return {
    name: 'myucto-i18n-split',
    enforce: 'pre',
    buildStart() {
      generate()
    },
    configureServer(server) {
      // V dev režimu se kousky musí přegenerovat, jakmile někdo sáhne na zdroj —
      // jinak by se do prohlížeče dostal starý překlad a vypadalo by to jako
      // zaseknutá cache.
      const watched = [
        ...LOCALES.map(l => join(i18nDir, `${l}.json`)),
        join(i18nDir, 'namespaces.generated.json'),
      ]
      server.watcher.add(watched)
      let timer
      let retries = 0
      function regenerate() {
        try {
          generate()
          // Již načtené prostory zůstávají v paměti i18n; nové chunky je samy nenahradí.
          server.ws.send({ type: 'full-reload' })
        } catch (error) {
          // Watcher může dorazit mezi vyprázdněním souboru a dokončením zápisu.
          // Opakování je omezené, trvalou chybu musí vývojář vidět v logu.
          if ((error instanceof SyntaxError || error.code === 'ENOENT') && retries < MAX_RETRIES) {
            retries += 1
            timer = setTimeout(regenerate, RETRY_DELAY_MS)
            return
          }
          server.config.logger.error(`[myucto-i18n-split] ${error.message}`, { error })
        }
      }
      server.watcher.on('change', (file) => {
        if (!watched.includes(file)) return
        clearTimeout(timer)
        retries = 0
        timer = setTimeout(regenerate, CHANGE_DELAY_MS)
      })
      server.httpServer?.once('close', () => clearTimeout(timer))
    },
  }
}

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Lepivý první sloupec (`table-sticky-first`) musí být neprůhledný, jinak pod ním
 * při vodorovném posunu prosvítají odjeté buňky. Barva řádku bývá poloprůhledná
 * (zebra, hover, aktivní řádek), proto main.css skládá neprůhledný podklad
 * (background-image) a přes něj ::after se zděděnou barvou řádku.
 *
 * Kontrola hlídá tři věci, které to rozbijí potichu:
 * - vrstva zmizí nebo přestane dědit barvu a lištu aktivního řádku,
 * - jiné pravidlo v main.css použije na buňce tabulky ::after (a vrstvu přebije,
 *   jako to dělají popisky sloupců s ::before),
 * - stránka si první buňce dá vlastní pozadí ve scoped stylu. Ten stojí mimo
 *   vrstvu, přebije podklad i tint a sdílené řešení tím okopíruje.
 */

const SRC = resolve(__dirname, '../..')
const mainCss = readFileSync(join(SRC, 'styles/main.css'), 'utf8')
const printCss = readFileSync(join(SRC, 'styles/print.css'), 'utf8')

/** Těla všech pravidel, jejichž seznam selektorů je právě `selector` (bez skupin s jinými selektory). */
function rule(css: string, selector: string): string | null {
  const bodies = [...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
    .filter(m => m[1].trim() === selector)
    .map(m => m[2])
  return bodies.length ? bodies.join('\n') : null
}

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return vueFiles(path)
    return name.endsWith('.vue') ? [path] : []
  })
}

describe('lepivý první sloupec tabulek', () => {
  it('první buňka má neprůhledný podklad a přes něj barvu řádku', () => {
    const cell = rule(mainCss, '.table-sticky-first tbody td:first-child')
    expect(cell).not.toBeNull()
    expect(cell).toMatch(/background-color:\s*inherit/)
    expect(cell).toMatch(/background-image:\s*linear-gradient\(var\(--color-surface\),\s*var\(--color-surface\)\)/)

    const layer = rule(mainCss, '.table-sticky-first tbody td:first-child::after')
    expect(layer).not.toBeNull()
    expect(layer).toMatch(/position:\s*absolute/)
    expect(layer).toMatch(/z-index:\s*-1/)
    expect(layer).toMatch(/background-color:\s*inherit/)
    expect(layer).toMatch(/box-shadow:\s*inherit/)
    expect(layer).toMatch(/pointer-events:\s*none/)
  })

  it('::after na buňce tabulky nepoužívá v main.css nic jiného', () => {
    const selectors = [...mainCss.matchAll(/([^{}]*)\{/g)]
      .map(m => m[1].replace(/\/\*[\s\S]*?\*\//g, '').trim())
      .flatMap(s => s.split(','))
      .map(s => s.trim())
      .filter(s => /\btd\b[^\s>+~]*::after\b/.test(s) || /\btd\)?::after\b/.test(s))
    expect(selectors).toEqual(['.table-sticky-first tbody td:first-child::after'])
  })

  it('v tisku se vrstvy vypínají', () => {
    expect(rule(printCss, '.table-sticky-first tbody td:first-child')).toMatch(/background-image:\s*none/)
    expect(rule(printCss, '.table-sticky-first tbody td:first-child::after')).toMatch(/display:\s*none/)
  })

  it('stránky s lepivým sloupcem nemají vlastní neprůhledné pozadí buňky', () => {
    const offenders: string[] = []
    for (const path of vueFiles(SRC)) {
      const source = readFileSync(path, 'utf8')
      if (!source.includes('table-sticky-first')) continue
      const file = relative(SRC, path).replaceAll('\\', '/')
      for (const style of source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)) {
        if (/background(-color)?:[^;]*var\(--color-surface\)/.test(style[1])) offenders.push(file)
      }
    }
    expect(offenders).toEqual([])
  })
})

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = resolve(__dirname, '../../..')

/** Horní dialogy nad celou plochou: mají vlastní výškový strop (dvh) a seznam uvnitř. */
const CAPPED_TOP_DIALOGS = new Set([
  'components/layout/CommandPalette.vue',
  'components/workspace/WorkspaceFavorites.vue',
  'components/workspace/WorkspaceWindowSwitcher.vue',
])

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return vueFiles(path)
    return name.endsWith('.vue') ? [path] : []
  })
}

interface Overlay { where: string, file: string, classes: string[] }

function overlays(): Overlay[] {
  const found: Overlay[] = []
  for (const path of vueFiles(SRC)) {
    const source = readFileSync(path, 'utf8')
    const file = relative(SRC, path).replaceAll('\\', '/')
    for (const match of source.matchAll(/class="([^"]*)"/g)) {
      const classes = match[1].split(/\s+/)
      if (!classes.includes('fixed') || !classes.includes('inset-0')) continue
      if (!classes.includes('flex') || classes.includes('pointer-events-none')) continue
      const line = source.slice(0, match.index).split('\n').length
      found.push({ where: `${file}:${line}`, file, classes })
    }
  }
  return found
}

describe('dialogy na nízkém displeji', () => {
  it('main.css nechává vystředěný dialog rolovat od hlavičky', () => {
    const css = readFileSync(join(SRC, 'styles/main.css'), 'utf8').replace(/\s+/g, ' ')
    const selector = '.fixed.inset-0.flex.items-center.justify-center:not(.pointer-events-none)'
    expect(css).toContain(`${selector} { align-items: flex-start; overflow-y: auto; overscroll-behavior: contain; }`)
    expect(css).toContain(`${selector} > * { margin-block: auto; }`)
  })

  it('každý flexový overlay spadá pod společné pravidlo nebo roluje sám', () => {
    const all = overlays()
    expect(all.length).toBeGreaterThan(50)

    const uncovered = all.filter(({ file, classes }) => {
      const centered = classes.includes('items-center') && classes.includes('justify-center')
      return !centered && !classes.includes('overflow-y-auto') && !CAPPED_TOP_DIALOGS.has(file)
    })
    expect(uncovered.map((overlay) => overlay.where)).toEqual([])
  })

  it('formulář bankovního účtu používá Modal se stále viditelnou patičkou', () => {
    const source = readFileSync(join(SRC, 'pages/admin/BankAccounts.vue'), 'utf8')
    const form = source.match(/<Modal v-if="canManageAccounts && currencyFormOpen"[\s\S]*?<\/Modal>/)?.[0] ?? ''
    expect(form).toContain('<template #footer>')
    expect(form).toContain('@click="saveCurrency"')
  })
})

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Mobilní rám v `AppLayout`: spodní lišta s agendami místo hamburgeru nahoře.
 * Čte se zdroj (jako `quickCreateEmployee.spec.ts`), montovat celý layout by
 * znamenalo táhnout všechny story, router i API.
 */
const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const cs = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/cs.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/en.json'), 'utf8'))

function snippet(marker: string, length = 260): string {
  const at = appLayout.indexOf(marker)
  expect(at, marker).toBeGreaterThan(-1)
  return appLayout.slice(at, at + length)
}

describe('mobilní rám aplikace', () => {
  it('spodní lišta je jen na telefonu na výšku, lišta ikon na šířku ji nemá', () => {
    expect(appLayout).toContain('const phoneNavigation = computed(() => !isDesktop.value && !tabletNavigationRail.value)')
    expect(appLayout).toContain('<MobileTabBar v-if="phoneNavigation"')
    // Tablet / telefon na šířku si nechává původní patičku i hamburger.
    expect(appLayout).toContain('<AppMenuToggle v-else-if="!tabletNavigationRail"')
  })

  it('dlaždice zakládání respektují stejná práva jako „+" v hlavičce', () => {
    expect(snippet("if (can('/invoices/new'))")).toContain("to: '/invoices/new'")
    expect(snippet("if (can('/purchase-invoices/new'))")).toContain("to: '/purchase-invoices/new'")
    expect(snippet("if (can('/clients/new?role=vendor'))")).toContain("to: '/clients/new?role=vendor'")
    expect(snippet("auth.canWrite('projects.create')")).toContain("to: '/projects/new'")
    // Fotoaparát jen tomu, kdo smí vytěžovat, stejně jako AI import v menu.
    expect(appLayout).toMatch(/if \(canExtractPurchase\.value\) \{\s*actions\.push\(\{ key: 'capture', capture: true/)
  })

  it('účtenka je jen pro plátce DPH, zjednodušený doklad jinde nedává smysl', () => {
    expect(snippet("supplierStore.currentSupplier?.is_vat_payer !== false", 200)).toContain("to: '/invoices/new?simplified=1'")
  })

  it('pokladní dlaždice jen s pokladnou v menu a právem psát doklady', () => {
    expect(appLayout).toContain("items.some(item => item.to === '/accounting/cash') && can('/accounting/cash/new')")
  })

  it('přepínač oken je v hlavičce telefonu, ne v menu', () => {
    const header = snippet('<template v-if="phoneNavigation">', 400)
    expect(header).toContain('<WorkspaceWindowButton')
    expect(header).toContain('data-testid="mobile-windows"')
    const drawerRow = snippet(`<div :class="phoneNavigation ? 'flex items-center gap-1.5'`, 400)
    expect(drawerRow).not.toContain('WorkspaceWindowButton')
    expect(drawerRow).toContain('<WorkspaceFavoritesButton')
  })

  it('zásuvka i nabídky nechávají hlavičku a spodní lištu volné', () => {
    expect(appLayout).toContain("const phoneOverlayStyle = { top: 'var(--app-header-height)', bottom: 'var(--app-footer-height)', height: 'auto' }")
    expect(appLayout).toContain(':style="phoneNavigation ? phoneOverlayStyle : undefined"')
  })

  it('Peníze nesou i Dokumenty: odkazy, zápis jízdy a zvýraznění záložky', () => {
    expect(appLayout).toContain("bank_cash: ['bank_cash', 'documents']")
    expect(snippet("const mobileSheetMoreLinks = computed", 220)).toContain("mobileSection('documents')")
    expect(snippet("const logbook = mobileSection('documents')", 200)).toContain('canCreate(logbook)')
  })

  it('max-md a max-sm se řídí šířkou okna prohlížeče, ne panelu', () => {
    const css = readFileSync(resolve(process.cwd(), 'src/styles/main.css'), 'utf8')
    expect(css).toContain('@custom-variant max-md (&:where([data-phone], [data-phone] *));')
    expect(css).toContain('@custom-variant max-sm (&:where([data-phone-sm], [data-phone-sm] *));')
    const main = readFileSync(resolve(process.cwd(), 'src/main.ts'), 'utf8')
    expect(main.indexOf('installPhoneViewport()')).toBeLessThan(main.indexOf('parseWindowFrameBootstrap(window.location.search'))
  })

  it('každá dlaždice nabídky má popisek i podtitulek, ať mají jednotný vzhled', () => {
    const tiles = [...appLayout.matchAll(/actions\.push\(\{ key: '(\w+)'[^\n]*/g)]
    expect(tiles.length).toBeGreaterThan(10)
    for (const tile of tiles) expect(tile[0], tile[1]).toContain('hint:')
  })

  it('všechny texty mobilního rámu jsou v obou jazycích', () => {
    const keys = Object.keys(cs.nav.mobile)
    expect(keys.length).toBeGreaterThan(10)
    expect(Object.keys(en.nav.mobile).sort()).toEqual([...keys].sort())
    for (const match of appLayout.matchAll(/t\('nav\.mobile\.(\w+)'/g)) {
      expect(cs.nav.mobile[match[1]], match[1]).toBeTruthy()
    }
  })
})

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

  it('zásuvka i nabídky nechávají hlavičku a spodní lištu volné', () => {
    expect(appLayout).toContain("const phoneOverlayStyle = { top: 'var(--app-header-height)', bottom: 'var(--app-footer-height)', height: 'auto' }")
    expect(appLayout).toContain(':style="phoneNavigation ? phoneOverlayStyle : undefined"')
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

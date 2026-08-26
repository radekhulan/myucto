import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Mzdové menu radí uživateli pořadí práce. Do teď začínalo Běhy a Platbami —
 * tedy prostředkem měsíce — zatímco in-page návod `PayrollGuide.vue` na přehledu
 * mezd učí opačný sled (nepřítomnosti → docházka → vstupy → běh → …). Dvě různá
 * doporučení na dvou obrazovkách jsou horší než jedno špatné, proto to hlídá test.
 *
 * Čte se zdroj, ne vykreslená komponenta: `AppLayout` má za sebou celý řetěz
 * storů, routeru a API volání a smontovat ho jen kvůli pořadí položek by bylo
 * dražší i křehčí než přečíst pole, které je v souboru doslova napsané.
 */

// vitest běží s cwd = web/, proto cesty od něj.
const appLayout = readFileSync(
  resolve(process.cwd(), 'src/components/layout/AppLayout.vue'),
  'utf8',
)
const payrollGuide = readFileSync(
  resolve(process.cwd(), 'src/pages/payroll/PayrollGuide.vue'),
  'utf8',
)

/** Položky mzdové sekce menu v pořadí, v jakém je uživatel uvidí. */
function payrollNavItems(): Array<{ to: string; dividerBefore: boolean }> {
  const start = appLayout.indexOf("key: 'payroll',")
  expect(start).toBeGreaterThan(-1)
  const itemsStart = appLayout.indexOf('items: [', start)
  const itemsEnd = appLayout.indexOf('\n      ],', itemsStart)
  expect(itemsEnd).toBeGreaterThan(itemsStart)
  const block = appLayout.slice(itemsStart, itemsEnd)

  return [...block.matchAll(/\{\s*to: '(\/payroll[^']*)'([^}]*)\}/g)].map(match => ({
    to: match[1],
    dividerBefore: match[2].includes('dividerBefore: true'),
  }))
}

/** Kroky měsíce podle in-page návodu, přeložené na cesty v menu. */
function guideSteps(): string[] {
  const routeToPath: Record<string, string> = {
    'payroll-absences': '/payroll/absences',
    'payroll-time': '/payroll/time',
    'payroll-travel': '/payroll/travel',
    'payroll-quick-inputs': '/payroll/quick-inputs',
    'payroll-runs': '/payroll/runs',
    'payroll-payments': '/payroll/payments',
    'payroll-posting-reconciliation': '/payroll/posting-reconciliation',
    'payroll-documents': '/payroll/documents',
    'payroll-submissions': '/payroll/submissions',
  }
  return [...payrollGuide.matchAll(/\{ route: '([a-z-]+)',/g)].map(match => {
    const path = routeToPath[match[1]]
    expect(path, `návod odkazuje na neznámou routu ${match[1]}`).toBeDefined()
    return path
  })
}

describe('mzdové menu', () => {
  it('řadí měsíční kroky ve stejném pořadí jako návod na přehledu mezd', () => {
    const menu = payrollNavItems().map(item => item.to)
    const steps = guideSteps()
    expect(steps.length).toBeGreaterThan(0)

    const menuOrderOfSteps = menu.filter(path => steps.includes(path))
    expect(menuOrderOfSteps).toEqual(steps)
  })

  it('má přehled mezd jako první položku', () => {
    expect(payrollNavItems()[0]?.to).toBe('/payroll')
  })

  it('odděluje jednorázové nastavení od měsíční práce a řadí ho nakonec', () => {
    const items = payrollNavItems()
    const setup = [
      '/payroll/settings', '/payroll/components', '/payroll/rulesets',
      '/payroll/retention', '/payroll/erasure',
    ]

    expect(items.slice(-setup.length).map(item => item.to)).toEqual(setup)
    // Předěl patří na první položku nastavení, ne doprostřed skupiny.
    const settings = items.find(item => item.to === '/payroll/settings')
    expect(settings?.dividerBefore).toBe(true)
  })

  it('vede na legislativní pravidla mezd — stránka se do teď dala otevřít jen ručně psanou URL', () => {
    const rulesets = payrollNavItems().find(item => item.to === '/payroll/rulesets')
    expect(rulesets).toBeDefined()
    expect(appLayout).toContain("permission: 'payroll.rulesets' as PermissionKey")
    expect(appLayout).toContain("t('nav.payroll_rulesets')")
  })

  it('nezapomíná žádnou dosavadní mzdovou položku', () => {
    const menu = payrollNavItems().map(item => item.to)
    for (const path of [
      '/payroll', '/payroll/runs', '/payroll/payments', '/payroll/posting-reconciliation',
      '/payroll/people', '/payroll/quick-inputs', '/payroll/components', '/payroll/time',
      '/payroll/absences', '/payroll/travel', '/payroll/deduction-agreements',
      '/payroll/enforcement', '/payroll/documents', '/payroll/submissions', '/payroll/settings',
    ]) {
      expect(menu, `z menu zmizelo ${path}`).toContain(path)
    }
    expect(new Set(menu).size).toBe(menu.length)
  })
})

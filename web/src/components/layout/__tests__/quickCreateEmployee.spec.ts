import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Položka „Nový zaměstnanec" v globálním „+" v hlavičce.
 *
 * Hlídá čtyři věci, které se rozbijí potichu: že položka existuje právě jednou,
 * že je podmíněná zapnutými mzdami I právem (a bez práva MIZÍ, nešedne — jako
 * ostatní podmíněné položky), že míří na povel `?new=1` na seznamu osob (vlastní
 * routa pro zakládání neexistuje) a že se díky smyčce nad `quickActions` sama
 * registruje mezi klávesové zkratky skupiny `create`.
 *
 * Čte se zdroj, ne vykreslená komponenta: `AppLayout` táhne celý řetěz storů,
 * routeru a API volání a montovat ho jen kvůli jedné položce nabídky by bylo
 * dražší i křehčí — stejný důvod jako u `payrollNavOrder.spec.ts`.
 */

// vitest běží s cwd = web/, proto cesty od něj.
const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const cs = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/cs.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/en.json'), 'utf8'))

const ITEM = "to: '/payroll/people?new=1'"

describe('rychlé zakládání zaměstnance v „+"', () => {
  it('je v nabídce právě jednou a míří na povel ?new=1', () => {
    expect([...appLayout.matchAll(/to: '\/payroll\/people\?new=1'/g)]).toHaveLength(1)
    const item = appLayout.slice(appLayout.indexOf(ITEM), appLayout.indexOf(ITEM) + 160)
    expect(item).toContain("t('nav.quick_employee')")
    // Ikona lidí, ne dokladu — je to jiná agenda než fakturace.
    expect(item).toContain('ICONS.users')
  })

  it('je podmíněná licencí i firemním zapnutím mezd — vzor převzatý ze skladu', () => {
    const before = appLayout.slice(Math.max(0, appLayout.indexOf(ITEM) - 260), appLayout.indexOf(ITEM))
    expect(before).toContain('auth.hasCommercialFeatures')
    expect(before).toContain("supplierStore.currentSupplier?.payroll_enabled === true")
    // Klientský portál zakládat zaměstnance nesmí.
    expect(before).toContain('!clientExperience.value')
  })

  it('bez práva payroll.person.write položka zmizí — filtr canCreate ji vyhodí', () => {
    expect(appLayout).toContain("if (path.startsWith('/payroll/people')) return auth.canWrite('payroll.person.write')")
    // Nabídka se filtruje přes canCreate, takže položka bez práva nezůstane zašedlá.
    expect(appLayout).toContain('].filter(action => canCreate({')
  })

  it('v demu položka není — zápisy jsou tam blokované, formulář by nešel odeslat', () => {
    const demo = appLayout.slice(appLayout.indexOf('if (auth.isDemo && !clientExperience.value) return ['))
    const demoBlock = demo.slice(0, demo.indexOf('\n  ]'))
    expect(demoBlock).not.toContain('/payroll/people?new=1')
    // Ani výjimka v canCreate pro demo ji nepropustí.
    const canCreateDemo = appLayout.slice(appLayout.indexOf('if (auth.isDemo && ['), appLayout.indexOf('].includes(item.newTo)) return true'))
    expect(canCreateDemo).not.toContain('/payroll/people')
  })

  it('registruje se mezi zkratky skupiny create sama, bez další ruční položky', () => {
    // Smyčka nad quickActions je jediné místo, kde se položky „+" stanou zkratkami.
    const loop = appLayout.slice(appLayout.indexOf('for (const action of quickActions.value) {'))
    expect(loop.slice(0, 220)).toContain("group: 'create'")
    expect(loop.slice(0, 220)).toContain('to: action.to')
    // Žádná ručně dopsaná zkratka na zaměstnance — jinak by v nastavení byla dvakrát.
    expect([...appLayout.matchAll(/nav\.quick_employee/g)]).toHaveLength(1)
  })

  it('má překlad česky i anglicky', () => {
    expect(cs.nav.quick_employee).toBe('Nový zaměstnanec')
    expect(en.nav.quick_employee).toBe('New employee')
  })
})

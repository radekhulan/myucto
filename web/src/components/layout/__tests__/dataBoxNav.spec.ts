import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const workspaceRoutes = readFileSync(
  resolve(process.cwd(), 'src/router/workspaceRoutes.ts'),
  'utf8',
)

const appLayout = readFileSync(
  resolve(process.cwd(), 'src/components/layout/AppLayout.vue'),
  'utf8',
)

describe('navigace datové schránky', () => {
  // Položka je ve Mzdách (pokračování mzdových podání) a v Daních (daňová podání
  // datovkou potřebuje i firma bez mezd). V každé sekci nejvýš jednou a vždy s právem
  // `settings.signing`; dřív se zdvojené položky s různými právy rozešly.
  const ITEM = "{ to: '/admin/databox', label: t('nav.databox'), icon: ICONS.documents, permission: 'settings.signing' as PermissionKey }"

  it('nabízí schránku ve Mzdách i v Daních, pokaždé se stejným právem', () => {
    expect(appLayout.match(/to: '\/admin\/databox'/g)).toHaveLength(2)
    expect(appLayout.split(ITEM)).toHaveLength(3)
  })

  // Schránka je pokračování podání (odeslané zprávy, doručenky), ne nastavení —
  // stojí hned za „Podání a hlášení".
  it('stojí hned za Podáními a hlášeními', () => {
    const submissions = appLayout.indexOf("{ to: '/payroll/submissions'")
    const databox = appLayout.indexOf("{ to: '/admin/databox'", submissions)
    expect(submissions).toBeGreaterThan(-1)
    expect(databox).toBeGreaterThan(submissions)
    const between = appLayout.slice(submissions, databox)
    expect(between.match(/\{ to: '/g)).toHaveLength(1)
  })

  // Brána stojí vedle schránky pod Mzdami: přes ISDS chodí prakticky jen mzdová
  // podání, takže rozdělení mezi Firmu a Systém uživateli nedávalo smysl.
  it('vede odesílací bránu vedle schránky, jen pro superadmina', () => {
    expect(appLayout).toContain("to: '/admin/isds-gateway'")
    expect(appLayout).toContain("label: t('nav.isds_gateway')")
    expect(appLayout).toContain("...(isAdmin ? [{ to: '/admin/isds-gateway'")
    expect(appLayout.indexOf("to: '/admin/isds-gateway'")).toBeGreaterThan(appLayout.indexOf("to: '/admin/databox'"))
  })

  it('skrývá položku bez práva k zápisu stejně jako samotná routa', () => {
    expect(appLayout).toContain("if (path === '/admin/databox') return 'settings.signing'")
    expect(appLayout).toContain("if (item.to.startsWith('/admin/databox')) return auth.canWrite('settings.signing')")
  })

  it('v Daních stojí hned za EPO podáním a archívem, i pro firmu bez mezd', () => {
    const payrollStart = appLayout.indexOf('if (payrollEnabled) {')
    const taxDatabox = appLayout.indexOf(ITEM)
    const taxSubmissions = appLayout.lastIndexOf("{ to: '/reports/submissions'", taxDatabox)
    expect(taxSubmissions).toBeGreaterThan(-1)
    expect(taxDatabox).toBeGreaterThan(taxSubmissions)
    // Daňová položka leží mimo blok mezd, takže ji firma bez mezd uvidí.
    expect(taxDatabox).toBeLessThan(payrollStart)
    expect(appLayout.slice(taxSubmissions, taxDatabox).match(/\{ to: '/g)).toHaveLength(1)
    // Ve Mzdách zůstává jednou.
    const payrollSection = appLayout.slice(payrollStart)
    expect(payrollSection.split(ITEM)).toHaveLength(2)
  })

  it('routa nevyžaduje mzdy, právo settings.signing zůstává', () => {
    const route = workspaceRoutes.split(/\r?\n/).find(line => line.includes("name: 'admin-databox'")) ?? ''
    expect(route).not.toBe('')
    expect(route).not.toContain('requiresPayroll')
    const policy = readFileSync(resolve(process.cwd(), 'src/router/index.ts'), 'utf8')
    expect(policy).toContain("'admin-databox': ['settings.signing', 'write']")
  })
})

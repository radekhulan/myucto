import { describe, expect, it } from 'vitest'
import { computed, ref, type ComputedRef } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import ts from 'typescript'
import { canUsePurchaseAiExtraction } from '@/security/purchaseAiExtraction'
import { OPERATIONAL_SETTINGS_KEYS } from '@/security/permissions'
import { hasFrameworkScope } from '@/security/frameworkScope'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'

type NavSection = { key: string; items: { to: string }[] }
type Supplier = { accounting_framework?: 'cz_500' | 'cz_504'; entity_profile?: 'nonprofit' | 'svj' | 'unknown'; dimensions_enabled?: boolean }

const NONPROFIT_ITEMS = [
  '/accounting/nonprofit-dppo',
  '/accounting/securities/debt',
  '/accounting/framework-conversion',
  '/accounting/nonprofit-capital',
  '/accounting/nonprofit-funding',
  '/accounting/nonprofit-membership',
]
const SVJ_ITEMS = [
  '/property/registry',
  '/property/charges',
  '/property/charges/issues',
  '/property/service-settlements',
  '/property/service-lifecycles',
  '/property/funds',
  '/property/openings',
  '/property/payments',
]

const layout = readFileSync(resolve(__dirname, '../AppLayout.vue'), 'utf8')
const source = ts.createSourceFile('AppLayout.ts', layout.slice(layout.indexOf('>') + 1, layout.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const required = new Set(['navSections', 'accountantFirst', 'navPermission', 'filterNavigation', 'canExtractPurchase'])
const declarations = source.statements.filter(statement => {
  if (ts.isFunctionDeclaration(statement)) return required.has(statement.name?.text ?? '')
  return ts.isVariableStatement(statement) && statement.declarationList.declarations.some(declaration =>
    ts.isIdentifier(declaration.name) && required.has(declaration.name.text))
})
const navigationCode = ts.transpileModule(declarations.map(declaration => declaration.getText(source)).join('\n'), {
  compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.None },
}).outputText

function navigationPaths(supplier: Supplier): string[] {
  const can = () => true
  const auth = {
    isSuperadmin: false, isClientRole: false, isCompanyAdminRole: true, isAccountantRole: false,
    isAdminPlusRole: false, hasCommercialFeatures: true, hasPayrollFeatures: true,
    isManagedInstallation: false, isDemo: false, can, canRead: can, canWrite: can,
  }
  const evaluate = new Function('computed', 'auth', 'supplierStore', 'clientExperience', 't', 'ICONS',
    'approvalCount', 'accountantSigningProfilesEnabled', 'hostingAttention', 'automationStore', 'canUsePurchaseAiExtraction', 'OPERATIONAL_SETTINGS_KEYS', 'hasFrameworkScope', `${navigationCode}\nreturn navSections;`)
  const sections = evaluate(computed, auth,
    { currentSupplier: { accounting_mode: 'double_entry', accounting_enabled: true, ...supplier }, hasMultiple: false },
    ref(false), (key: string) => key, new Proxy({}, { get: (_, key) => String(key) }),
    { pending: ref(0) }, ref(false), ref(null), { actionable: 0 }, canUsePurchaseAiExtraction, OPERATIONAL_SETTINGS_KEYS, hasFrameworkScope) as ComputedRef<NavSection[]>
  return sections.value.flatMap(section => section.items.map(item => item.to))
}

describe('navigace agend NNO/504 a SVJ', () => {
  it('běžná s.r.o. na rámci 500 nevidí žádnou ze 14 položek', () => {
    const paths = navigationPaths({ accounting_framework: 'cz_500', entity_profile: 'unknown' })
    expect([...NONPROFIT_ITEMS, ...SVJ_ITEMS]).toHaveLength(14)
    for (const path of [...NONPROFIT_ITEMS, ...SVJ_ITEMS]) expect(paths).not.toContain(path)
    expect(paths).toContain('/accounting/journal')
    expect(paths).toContain('/accounting/statement-mapping')
  })

  it('nevýdělečná organizace na rámci 500 vidí agendy 504 včetně převodu, ne SVJ', () => {
    const paths = navigationPaths({ accounting_framework: 'cz_500', entity_profile: 'nonprofit' })
    for (const path of NONPROFIT_ITEMS) expect(paths).toContain(path)
    for (const path of SVJ_ITEMS) expect(paths).not.toContain(path)
  })

  it('firma s rámcem 504 vidí agendy 504 i bez rozpoznané právní formy', () => {
    const paths = navigationPaths({ accounting_framework: 'cz_504', entity_profile: 'unknown' })
    for (const path of NONPROFIT_ITEMS) expect(paths).toContain(path)
    for (const path of SVJ_ITEMS) expect(paths).not.toContain(path)
  })

  it('SVJ vidí agendu jednotek', () => {
    const paths = navigationPaths({ accounting_framework: 'cz_504', entity_profile: 'svj' })
    for (const path of [...NONPROFIT_ITEMS, ...SVJ_ITEMS]) expect(paths).toContain(path)
  })

  it('SVJ na rámci 500 vidí převod rámce (stejně jako doporučení v Nastavení), ne ostatní agendy 504', () => {
    const paths = navigationPaths({ accounting_framework: 'cz_500', entity_profile: 'svj' })
    expect(paths).toContain('/accounting/framework-conversion')
    for (const path of NONPROFIT_ITEMS.filter(item => item !== '/accounting/framework-conversion')) expect(paths).not.toContain(path)
    for (const path of SVJ_ITEMS) expect(paths).toContain(path)
  })

  it('společné náklady (alokace rámce 504) vidí jen firma s agendou 504 a dimenzemi', () => {
    const commercial = navigationPaths({ accounting_framework: 'cz_500', entity_profile: 'unknown', dimensions_enabled: true } as Supplier)
    expect(commercial).toContain('/accounting/dimension-profit')
    expect(commercial).not.toContain('/accounting/joint-allocations')
    const nonprofit = navigationPaths({ accounting_framework: 'cz_504', entity_profile: 'unknown', dimensions_enabled: true } as Supplier)
    expect(nonprofit).toContain('/accounting/joint-allocations')
    const scopes = new Map(createWorkspaceRoutes().map(route => [`/${route.path}`, route.meta?.frameworkScope]))
    expect(scopes.get('/accounting/joint-allocations')).toBe('nonprofit504')
  })

  it('router nese stejnou podmínku na každé z 14 položek', () => {
    const scopes = new Map(createWorkspaceRoutes().map(route => [`/${route.path.replace(/\/:id.*$/, '')}`, route.meta?.frameworkScope]))
    for (const path of NONPROFIT_ITEMS) {
      expect(scopes.get(path)).toBe(path === '/accounting/framework-conversion' ? 'conversion504' : 'nonprofit504')
    }
    for (const path of SVJ_ITEMS) expect(scopes.get(path)).toBe('svj')
  })
})

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { computed, defineComponent, h, ref, type ComputedRef } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import ts from 'typescript'
import { canUsePurchaseAiExtraction } from '@/security/purchaseAiExtraction'
import { OPERATIONAL_SETTINGS_KEYS } from '@/security/permissions'
import { hasFrameworkScope } from '@/security/frameworkScope'

type NavSection = { key: string; items: { to: string; label: string }[] }
type Role = 'superadmin' | 'admin' | 'accountant' | 'client'

const profiles = {
  superadmin: { isSuperadmin: true, isClientRole: false, isCompanyAdminRole: true, isAccountantRole: false },
  admin: { isSuperadmin: false, isClientRole: false, isCompanyAdminRole: true, isAccountantRole: false },
  accountant: { isSuperadmin: false, isClientRole: false, isCompanyAdminRole: false, isAccountantRole: true },
  client: { isSuperadmin: false, isClientRole: true, isCompanyAdminRole: false, isAccountantRole: false },
}

const layout = readFileSync(resolve(__dirname, '../AppLayout.vue'), 'utf8')
const source = ts.createSourceFile('AppLayout.ts', layout.slice(layout.indexOf('>') + 1, layout.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const required = new Set(['navSections', 'accountantFirst', 'navPermission', 'filterNavigation', 'canExtractPurchase'])
const declarations = source.statements.filter(statement => {
  if (ts.isFunctionDeclaration(statement)) return required.has(statement.name?.text ?? '')
  return ts.isVariableStatement(statement) && statement.declarationList.declarations.some(declaration =>
    ts.isIdentifier(declaration.name) && required.has(declaration.name.text))
})
if (declarations.length !== required.size) throw new Error('AppLayout navigation declarations missing')
const navigationCode = ts.transpileModule(declarations.map(declaration => declaration.getText(source)).join('\n'), {
  compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.None },
}).outputText

function renderNavigation(role: Role, permissionOverrides: Record<string, boolean> = {}) {
  const profile = profiles[role]
  const can = (permission: string) => profile.isSuperadmin
    || (permissionOverrides[permission] ?? (permission === 'documents.submit' ? profile.isClientRole : true))
  const auth = {
    ...profile,
    isAdminPlusRole: false, hasCommercialFeatures: true, hasPayrollFeatures: true,
    isManagedInstallation: false, isDemo: false, can, canRead: can, canWrite: can,
  }
  const evaluate = new Function('computed', 'auth', 'supplierStore', 'clientExperience', 't', 'ICONS',
    'approvalCount', 'accountantSigningProfilesEnabled', 'hostingAttention', 'automationStore', 'canUsePurchaseAiExtraction', 'OPERATIONAL_SETTINGS_KEYS', 'hasFrameworkScope', `${navigationCode}\nreturn navSections;`)
  const sections = evaluate(computed, auth, { currentSupplier: { accounting_mode: 'double_entry', accounting_enabled: true } },
    ref(auth.isClientRole), (key: string) => key, new Proxy({}, { get: (_, key) => String(key) }),
    { pending: ref(0) }, ref(false), ref(null), { actionable: 0 }, canUsePurchaseAiExtraction, OPERATIONAL_SETTINGS_KEYS, hasFrameworkScope) as ComputedRef<NavSection[]>
  return mount(defineComponent({
    setup: () => () => h('nav', sections.value.map(section => h('section', { 'data-section': section.key },
      section.items.map(item => h('a', { href: item.to }, item.label))))),
  }))
}

describe('AppLayout document submission navigation', () => {
  it.each<Role>(['superadmin', 'client'])('%s sees one document submission link', role => {
    const wrapper = renderNavigation(role)
    expect(wrapper.findAll('a[href="/portal/purchase-invoice-submissions"]')).toHaveLength(1)
    expect(wrapper.get('[data-section="documents"] a[href="/portal/purchase-invoice-submissions"]').text()).toBe('nav.submit_documents')
  })

  it.each<Role>(['admin', 'accountant'])('%s keeps the staff navigation without client document submission', role => {
    const wrapper = renderNavigation(role)
    expect(wrapper.findAll('a[href="/portal/purchase-invoice-submissions"]')).toHaveLength(0)
    expect(wrapper.find('a[href="/purchase-invoices/incoming"]').exists()).toBe(true)
  })
})

describe('AppLayout company settings navigation', () => {
  const portal = 'a[href="/portal/settings"]'

  it('neukazuje superadminovi druhé Nastavení firmy vedle plného nastavení', () => {
    const wrapper = renderNavigation('superadmin')
    expect(wrapper.find('a[href="/admin/settings"]').exists()).toBe(true)
    expect(wrapper.find(portal).exists()).toBe(false)
  })

  it('ukazuje internímu správci firmy delegované nastavení jen kvůli odesílacím profilům', () => {
    expect(renderNavigation('admin').find(portal).exists()).toBe(true)
    const withoutProfiles = renderNavigation('admin', { 'settings.email_profiles': false })
    expect(withoutProfiles.find('a[href="/admin/settings"]').exists()).toBe(true)
    expect(withoutProfiles.find('a[href="/admin/branding"]').exists()).toBe(true)
    expect(withoutProfiles.find(portal).exists()).toBe(false)
  })

  it('ukazuje interní roli bez zápisu do Nastavení provozní oblasti, které jinde nenajde', () => {
    const none = Object.fromEntries(OPERATIONAL_SETTINGS_KEYS.map(key => [key, false]))
    const base = { ...none, 'settings.company.write': false }
    expect(renderNavigation('accountant', base).find(portal).exists()).toBe(false)
    expect(renderNavigation('accountant', { ...base, 'settings.branding': true }).find(portal).exists()).toBe(false)
    expect(renderNavigation('accountant', { ...base, 'settings.payment_qr': true }).find(portal).exists()).toBe(true)
    expect(renderNavigation('accountant', { ...base, 'settings.email_templates': true }).find(portal).exists()).toBe(true)
  })

  it('klientovi nechává Nastavení firmy s kteroukoli delegovanou oblastí', () => {
    const none = Object.fromEntries(OPERATIONAL_SETTINGS_KEYS.map(key => [key, false]))
    expect(renderNavigation('client', none).find(portal).exists()).toBe(false)
    expect(renderNavigation('client', { ...none, 'settings.branding': true }).find(portal).exists()).toBe(true)
  })
})

describe('AppLayout price list navigation', () => {
  it.each<Role>(['admin', 'accountant', 'client'])('%s sees the price list only with its own permission', role => {
    for (const allowed of [false, true]) {
      const wrapper = renderNavigation(role, { price_list: allowed })
      expect(wrapper.find('a[href="/admin/price-list"]').exists()).toBe(allowed)
      wrapper.unmount()
    }
  })
})

describe('AppLayout client tax submissions', () => {
  it('shows prepared submissions only with the dedicated permission and keeps internal reports hidden', () => {
    const allowed = renderNavigation('client', { 'reports.submissions': true, reports: false })
    expect(allowed.find('a[href="/reports/submissions"]').exists()).toBe(true)
    expect(allowed.find('a[href="/reports/vat-return"]').exists()).toBe(false)
    expect(allowed.find('a[href="/admin/databox"]').exists()).toBe(false)
    expect(allowed.find('a[href="/admin/electronic-signatures"]').exists()).toBe(false)
    expect(renderNavigation('client', { 'reports.submissions': false, reports: true })
      .find('a[href="/reports/submissions"]').exists()).toBe(false)
  })
})

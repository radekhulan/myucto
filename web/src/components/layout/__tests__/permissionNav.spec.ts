import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const appLayout = readFileSync(
  resolve(process.cwd(), 'src/components/layout/AppLayout.vue'),
  'utf8',
)

const bankPage = readFileSync(
  resolve(process.cwd(), 'src/pages/bank/BankPage.vue'),
  'utf8',
)

const supportPage = readFileSync(
  resolve(process.cwd(), 'src/pages/admin/Support.vue'),
  'utf8',
)

describe('navigace podle RBAC oprávnění', () => {
  it('nabízí importy podle utilities.import, ne podle superadmin role', () => {
    expect(appLayout).not.toContain("...(isAdmin ? [{ to: '/invoices/import'")
    expect(appLayout).not.toContain("...(isAdmin ? [{ to: '/purchase-invoices/import'")
    expect(appLayout.match(/permission: 'utilities\.import' as PermissionKey, access: 'write'/g)).toHaveLength(2)
  })

  it('nabízí firemní sekci i staff roli a její položky pak filtruje podle práv', () => {
    const companyAt = appLayout.indexOf("key: 'company'")
    const adminSystemGateAt = appLayout.indexOf('if (isAdmin || auth.isDemo) {', companyAt)

    expect(companyAt).toBeGreaterThan(-1)
    expect(adminSystemGateAt).toBeGreaterThan(companyAt)
    expect(appLayout.match(/to: '\/profile\/api-tokens'/g)).toHaveLength(1)
    expect(appLayout.match(/to: '\/document-requests'/g)).toHaveLength(1)
  })

  it('má explicitní oprávnění pro odkazy, které nelze správně odvodit prefixem', () => {
    expect(appLayout).toContain("to: '/gopay', label: t('nav.gopay'), icon: ICONS.payment_orders, permission: 'bank' as PermissionKey")
    expect(appLayout).toContain("to: '/admin/accounting-activation', label: t('nav.accounting_activation'), icon: ICONS.updates, permission: 'accounting.periods.manage' as PermissionKey, access: 'write'")
    expect(appLayout).toContain("to: '/accounting/transition-report', label: t('nav.accounting_transition_report'), icon: ICONS.reports, permission: 'tax_evidence' as PermissionKey")
    expect(appLayout).toContain("if (path === '/crm') return 'dashboard.portfolio'")
  })

  it('zpřístupní bankovní nastavení podle settings.bank_accounts', () => {
    expect(bankPage).toContain("auth.canRead('settings.bank_accounts')")
    expect(bankPage).toContain("auth.canWrite('settings.bank_accounts')")
  })

  it('neslibuje neadministrátorovi diagnostiku ze stránky podpory', () => {
    expect(supportPage).toContain('<RouterLink v-if="isAdmin" to="/admin/diagnostics"')
  })

  it('omezuje šířku mobilního a tabletového menu', () => {
    expect(appLayout).toContain("'w-[calc(100vw-3rem)] max-w-80 lg:w-60 shrink-0'")
    expect(appLayout).not.toContain("'w-full lg:w-60 shrink-0'")
  })
})

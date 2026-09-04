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
    expect(appLayout).toContain("'w-[calc(100vw-3rem)] max-w-80 shrink-0'")
    expect(appLayout).toContain("'lg:w-60'")
    expect(appLayout).not.toContain("'w-full lg:w-60 shrink-0'")
  })

  it('otevírá mobilní menu zprava a desktopové levé menu přepne na kompaktní pruh', () => {
    expect(appLayout).toContain("'fixed right-0 z-30 lg:right-auto lg:sticky")
    expect(appLayout).toContain("'nav-inverted bg-surface border-l border-neutral-200 shadow-lg lg:border-l-0 lg:border-r lg:shadow-none'")
    expect(appLayout).toContain("mobileOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'")
    expect(appLayout).toContain("t('nav.menu_compact')")
    expect(appLayout).toContain('v-if="si === 0 && isDesktop"')
    expect(appLayout).toContain("@click.stop=\"setDesktopNavigationMode('rail')\"")
  })

  it('na užším desktopu přesouvá utility do patičky a skrývá metadata', () => {
    expect(appLayout.match(/<DesktopUtilityActions/g)).toHaveLength(2)
    expect(appLayout).toContain('class="hidden 2xl:flex"')
    expect(appLayout).toContain('class="flex 2xl:hidden"')
    expect(appLayout).toContain('class="hidden 2xl:inline whitespace-nowrap hover:text-primary-700')
    expect(appLayout).toContain('class="hidden 2xl:inline whitespace-nowrap hover:text-neutral-700"')
  })

  it('nabízí na tabletu volitelný levý pruh sekcí místo hamburgeru', () => {
    expect(appLayout).toContain('(isDesktop.value && forceNavigationRail.value)')
    expect(appLayout).toContain('(isTablet.value && tabletNavigationRailPreference.value)')
    expect(appLayout).toContain('<TabletNavigationRail')
    expect(appLayout).toContain('v-if="!tabletNavigationRail"')
    expect(appLayout).toContain("'nav.tablet_menu_drawer' : 'nav.tablet_menu_rail'")
  })

  it('nabízí na desktopu tři přímo volitelné režimy menu', () => {
    expect(appLayout).toContain("const desktopNavigationMode = computed<'top' | 'side' | 'rail'>")
    expect(appLayout).toContain("setDesktopNavigationMode('top')")
    expect(appLayout).toContain("setDesktopNavigationMode('side')")
    expect(appLayout).toContain("setDesktopNavigationMode('rail')")
    expect(appLayout).toContain("t('nav.menu_layout')")
  })

  it('používá v kompaktním pruhu významově odlišené ikony sekcí', () => {
    expect(appLayout).toContain('bank_cash: ICONS.coin')
    expect(appLayout).toContain('documents: ICONS.folderOpen')
    expect(appLayout).toContain('taxes: ICONS.percent')
    expect(appLayout).toContain('accounting_tools: ICONS.tools')
    expect(appLayout).toContain('company: ICONS.company')
    expect(appLayout).toContain('system_signing: ICONS.api_tokens')
    expect(appLayout).not.toContain('bank_cash: ICONS.bank')
  })

  it('řadí asistenta jako druhou položku Nástrojů a ne do Systému', () => {
    const toolsStart = appLayout.indexOf("key: 'accounting_tools'")
    const toolsEnd = appLayout.indexOf("key: 'tax_evidence'", toolsStart)
    const tools = appLayout.slice(toolsStart, toolsEnd)
    const templatesAt = tools.indexOf("to: '/templates'")
    const assistantAt = tools.indexOf("to: '/accounting/setup-assistant'")
    const accountsAt = tools.indexOf("to: '/accounting/accounts'")

    expect(templatesAt).toBeGreaterThan(-1)
    expect(assistantAt).toBeGreaterThan(templatesAt)
    expect(assistantAt).toBeLessThan(accountsAt)

    const systemStart = appLayout.indexOf("key: 'system_global'")
    const systemEnd = appLayout.indexOf("key: 'system_signing'", systemStart)
    expect(appLayout.slice(systemStart, systemEnd)).not.toContain("to: '/accounting/setup-assistant'")
  })
})

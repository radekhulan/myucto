import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  capabilities: vi.fn(),
  runs: vi.fn(),
  payrollSetupCheck: vi.fn(),
  isSuperadmin: { value: false },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    capabilities: m.capabilities,
    runs: m.runs,
    payrollSetupCheck: m.payrollSetupCheck,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: () => true,
    canRead: () => true,
    get isSuperadmin() { return m.isSuperadmin.value },
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
  }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

import PayrollDashboard from '@/pages/payroll/PayrollDashboard.vue'

const routerLinkStub = {
  props: ['to'],
  template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
}

const actionBarStub = {
  props: ['actions'],
  template: '<div data-test="action-bar"><span v-for="a in actions" :key="a.key" :data-action="a.key" v-show="a.show === undefined || a.show">{{ a.label }}</span></div>',
}

function mountDashboard() {
  return mount(PayrollDashboard, {
    global: {
      stubs: {
        RouterLink: routerLinkStub,
        ActionBar: actionBarStub,
        PayrollEmployeeCards: { props: ['period'], template: '<div data-test="employee-cards-stub" :data-period="period" />' },
        PayrollGuide: { template: '<div data-test="guide-stub" />' },
        PayrollSetupGuide: { template: '<div data-test="setup-guide-stub" />' },
        PayrollAnnualReportPanel: {
          props: ['initialYear'],
          template: '<div data-test="annual-report-panel-stub" :data-year="initialYear" />',
        },
        PayrollOperationalHealthPanel: {
          template: '<div data-test="operational-health-panel-stub" />',
        },
        PayrollDeadlinesPanel: {
          template: '<div data-test="deadlines-panel-stub" />',
        },
      },
    },
  })
}

describe('PayrollDashboard monthly workspace', () => {
  beforeEach(() => {
    m.isSuperadmin.value = false
    m.capabilities.mockResolvedValue({
      state: {
        supplier_id: 1,
        status: 'active',
        start_period: '2026-01',
        row_version: 1,
        activated_at: null,
        suspended_at: null,
        created_at: null,
        updated_at: null,
      },
      support_matrix: {
        version: '2026-08',
        supported_years: [2026],
        employment_types: [],
        features: [{
          key: 'monthly_payroll',
          status: 'supported',
          available: true,
          min_epic: 'MZ01',
        }],
      },
      company_capability: {
        production_ready: true,
        assessed_from: '2026-01-01',
        blockers: [],
      },
    })
    m.runs.mockResolvedValue([])
    m.payrollSetupCheck.mockResolvedValue({
      ready: true,
      effective_on: '2026-08-01',
      policy_id: 1,
      checks: [],
      blockers: [],
    })
  })

  it('dá běžné měsíční úkoly dopředu a diagnostiku běžnému uživateli vůbec neukáže', async () => {
    const wrapper = mountDashboard()
    await flushPromises()

    const workspace = wrapper.get('[data-test="monthly-workspace"]')
    const destinations = workspace.findAll('a').map(link => link.attributes('data-to'))

    expect(destinations).toContain('{"name":"payroll-quick-inputs"}')
    expect(destinations).toContain('{"name":"payroll-runs"}')
    expect(destinations).toContain('{"name":"payroll-people"}')
    expect(destinations).toContain('{"name":"payroll-payments"}')
    expect(destinations).toContain('{"name":"payroll-documents"}')
    // Matice podporovaných scénářů nese interní identifikátory epiců a verzi
    // support matrix. Zaměstnavateli neříká nic a budí dojem nehotového
    // produktu — proto ji vidí jen superadmin.
    expect(wrapper.find('[data-test="support-diagnostics"]').exists()).toBe(false)
  })

  it('zobrazuje v diagnostické matici jen skutečně dostupné funkce', async () => {
    m.isSuperadmin.value = true
    m.capabilities.mockResolvedValue({
      state: {
        supplier_id: 1,
        status: 'active',
        start_period: '2026-01',
        row_version: 1,
        activated_at: null,
        suspended_at: null,
        created_at: null,
        updated_at: null,
      },
      support_matrix: {
        version: '2026-08',
        supported_years: [2026],
        employment_types: [],
        features: [
          { key: 'ready_feature', status: 'supported', available: true, min_epic: 'MZ-01' },
          { key: 'planned_feature', status: 'not_supported', available: false, min_epic: 'MZ-99' },
        ],
      },
      company_capability: {
        production_ready: true,
        assessed_from: '2026-01-01',
        blockers: [],
      },
      production_release: {
        released: false,
      },
    })

    const wrapper = mountDashboard()
    await flushPromises()

    const diagnostics = wrapper.get('[data-test="support-diagnostics"]')
    expect(diagnostics.text()).toContain('payroll.features.ready_feature')
    expect(diagnostics.text()).not.toContain('payroll.features.planned_feature')
    expect(diagnostics.text()).not.toContain('payroll.capabilities.planned')
  })

  it('shows the guide and employee cards for the current period', async () => {
    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-test="guide-stub"]').exists()).toBe(true)
    const cards = wrapper.get('[data-test="employee-cards-stub"]')
    expect(cards.attributes('data-period')).toMatch(/^\d{4}-\d{2}$/)
  })

  /**
   * Průvodce prvním nastavením mezd patří na přehled jen do prvního schváleného
   * běhu. Rozhoduje `capabilities.onboarding` — chybějící klíč znamená
   * nezobrazit, ať ho nedostane firma, která mzdy dávno jede.
   */
  it('shows the first-time setup guide only until payroll has settled data', async () => {
    const base = await m.capabilities()

    const withoutFlag = mountDashboard()
    await flushPromises()
    expect(withoutFlag.find('[data-test="setup-guide-stub"]').exists()).toBe(false)

    m.capabilities.mockResolvedValue({ ...base, onboarding: { has_settled_payroll: true } })
    const withData = mountDashboard()
    await flushPromises()
    expect(withData.find('[data-test="setup-guide-stub"]').exists()).toBe(false)

    m.capabilities.mockResolvedValue({ ...base, onboarding: { has_settled_payroll: false } })
    const fresh = mountDashboard()
    await flushPromises()
    expect(fresh.find('[data-test="setup-guide-stub"]').exists()).toBe(true)
    // Měsíční návod „Jak to funguje" zůstává vedle — jsou to dva různé průvodci.
    expect(fresh.find('[data-test="guide-stub"]').exists()).toBe(true)
  })

  it('reports the payroll run state of the current month', async () => {
    m.runs.mockResolvedValue([
      { id: 1, status: 'draft' },
      { id: 2, status: 'calculated' },
    ])
    const wrapper = mountDashboard()
    await flushPromises()

    // Poslední běh období je ten aktuální — starší revize nesmí přebít stav.
    expect(wrapper.get('[data-test="run-status"]').text())
      .toBe('payroll.dashboard.month.run_status')
  })

  it('falls back to "no run" when the period has none', async () => {
    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.get('[data-test="run-status"]').text())
      .toBe('payroll.dashboard.month.run_missing')
  })

  it('surfaces setup blockers with a link to settings', async () => {
    m.payrollSetupCheck.mockResolvedValue({
      ready: false,
      effective_on: '2026-08-01',
      policy_id: null,
      checks: [
        { code: 'health_insurer_account', status: 'blocked', message: 'Chybí účet pojišťovny.' },
        { code: 'policy', status: 'ok', message: 'Politika je nastavena.' },
      ],
      blockers: ['health_insurer_account'],
    })
    const wrapper = mountDashboard()
    await flushPromises()

    const panel = wrapper.get('[data-test="setup-blockers"]')
    expect(panel.text()).toContain('Chybí účet pojišťovny.')
    expect(panel.text()).not.toContain('Politika je nastavena.')
    expect(panel.get('a').attributes('data-to')).toBe('{"name":"payroll-settings"}')
  })

  it('keeps the overview usable when the optional month calls fail', async () => {
    m.runs.mockRejectedValue(new Error('403'))
    m.payrollSetupCheck.mockRejectedValue(new Error('403'))
    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-workspace"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="setup-blockers"]').exists()).toBe(false)
  })

  it('explains the internal test operation without asking the customer for qualification', async () => {
    m.capabilities.mockResolvedValue({
      state: {
        supplier_id: 1,
        status: 'active',
        start_period: '2026-01',
        row_version: 2,
        activated_at: null,
        suspended_at: null,
        created_at: null,
        updated_at: null,
      },
      support_matrix: {
        version: '2026-08',
        supported_years: [2026],
        employment_types: [],
        features: [],
      },
      company_capability: {
        production_ready: true,
        assessed_from: '2026-01-01',
        blockers: [],
      },
      production_release: {
        released: false,
      },
    })

    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-test="production-release-notice"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('payroll.activation.qualification.title')
    expect(wrapper.find('[data-test="monthly-workspace"]').exists()).toBe(true)
  })

  it('staví hlídač zákonných termínů nad provozní přehled i nad dlaždice měsíce', async () => {
    const wrapper = mountDashboard()
    await flushPromises()

    const html = wrapper.html()
    const deadlines = html.indexOf('deadlines-panel-stub')
    const health = html.indexOf('operational-health-panel-stub')
    const workspace = html.indexOf('monthly-workspace')
    expect(deadlines).toBeGreaterThan(-1)
    // Zmeskana lhuta je jedina vec na strance, kterou uz nejde napravit pozdeji.
    expect(deadlines).toBeLessThan(health)
    expect(deadlines).toBeLessThan(workspace)
  })

})

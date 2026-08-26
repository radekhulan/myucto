import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  capabilities: vi.fn(),
  runs: vi.fn(),
  payrollSetupCheck: vi.fn(),
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
        PayrollProductionQualificationPanel: {
          props: ['state', 'matrixVersion'],
          template: '<div data-test="qualification-panel-stub" :data-version="matrixVersion" />',
        },
      },
    },
  })
}

describe('PayrollDashboard monthly workspace', () => {
  beforeEach(() => {
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

  it('shows the guide and employee cards for the current period', async () => {
    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-test="guide-stub"]').exists()).toBe(true)
    const cards = wrapper.get('[data-test="employee-cards-stub"]')
    expect(cards.attributes('data-period')).toMatch(/^\d{4}-\d{2}$/)
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

  it('explains test operation without hiding the monthly workflow', async () => {
    m.capabilities.mockResolvedValue({
      state: {
        supplier_id: 1,
        status: 'qualification_required',
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
    })

    const wrapper = mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-test="production-qualification-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="qualification-panel-stub"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="monthly-workspace"]').exists()).toBe(true)
  })
})

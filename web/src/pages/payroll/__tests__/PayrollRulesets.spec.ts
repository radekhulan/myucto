import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollRuleParameter,
  PayrollRulesetDetail,
  PayrollRulesetDomainGroup,
  PayrollRulesetOverview,
  PayrollRulesetSummary,
} from '@/api/payrollRulesets'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  detail: vi.fn(),
  diff: vi.fn(),
  isSuperadmin: { value: false },
}))

vi.mock('@/api/payrollRulesets', async () => {
  const actual = await vi.importActual<typeof import('@/api/payrollRulesets')>(
    '@/api/payrollRulesets',
  )
  return {
    ...actual,
    payrollRulesetsApi: {
      overview: m.overview,
      detail: m.detail,
      diff: m.diff,
      save: vi.fn(),
      reset: vi.fn(),
      command: vi.fn(),
    },
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ get isSuperadmin() { return m.isSuperadmin.value } }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params === undefined ? key : `${key}:${JSON.stringify(params)}`,
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollRulesets from '@/pages/payroll/PayrollRulesets.vue'

function summary(overrides: Partial<PayrollRulesetSummary> = {}): PayrollRulesetSummary {
  return {
    ruleset_id: 'cz-payroll-2026.income-tax.v1',
    domain: 'income_tax',
    version: '2026.1.0',
    effective_from: '2026-01-01',
    effective_to: '2026-12-31',
    lifecycle: 'reviewed',
    capability: 'supported',
    canonical_hash: 'a'.repeat(64),
    origin: 'vendor',
    sources: [{
      id: 'fs-dependent-activity-2026',
      title: 'Finanční správa: zaměstnanci a zaměstnavatelé 2026',
      url: 'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu',
      retrieved_on: '2026-08-03',
    }],
    is_override: false,
    has_default: true,
    checksum_valid: true,
    calculation_ready: false,
    reason: null,
    technical_review: null,
    approval: null,
    updated_by: null,
    updated_at: null,
    reviewed_by: null,
    approved_by: null,
    activated_by: null,
    row_version: 0,
    parameter_count: 19,
    manual_review_parameters: [],
    next_command: 'approve',
    blockers: [],
    warnings: [],
    ...overrides,
  }
}

function group(overrides: Partial<PayrollRulesetDomainGroup> = {}): PayrollRulesetDomainGroup {
  return {
    domain: 'income_tax',
    version_count: 1,
    active_count: 0,
    calculation_ready: false,
    status: 'awaiting_activation',
    manual_review_by_design: false,
    manual_review_explanation: null,
    manual_review_parameter_count: 0,
    parameter_count: 19,
    coverage_issues: [],
    versions: [summary()],
    ...overrides,
  }
}

function parameter(overrides: Partial<PayrollRuleParameter> = {}): PayrollRuleParameter {
  return {
    key: 'total.rate',
    label: 'Celková sazba pojistného',
    type: 'decimal_rate',
    value: '0.135',
    value_label: null,
    capability: 'supported',
    note: null,
    manual_review_why: null,
    manual_review_action: null,
    ...overrides,
  }
}

function detail(
  parameters: PayrollRuleParameter[],
  overrides: Partial<PayrollRulesetDetail> = {},
): PayrollRulesetDetail {
  return {
    ...summary({ ruleset_id: 'cz-payroll-2026.health-insurance.v1', domain: 'health_insurance' }),
    parameters,
    audit: [],
    default_diff: null,
    previous_ruleset_id: null,
    ...overrides,
  }
}

async function mountPage(
  groups: PayrollRulesetDomainGroup[],
  degradedReason: string | null = null,
) {
  const overview: PayrollRulesetOverview = {
    domains: groups,
    override_storage_available: true,
    degraded_reason: degradedReason,
    generated_at: '2026-08-15 10:00:00',
  }
  m.overview.mockResolvedValue(overview)
  const wrapper = mount(PayrollRulesets, { global: { stubs: { Modal: { template: '<div><slot /></div>' } } } })
  await flushPromises()
  return wrapper
}

describe('PayrollRulesets', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.isSuperadmin.value = false
    m.diff.mockResolvedValue(null)
  })

  it('tells a domain waiting for activation apart from one with nothing to approve', async () => {
    const wrapper = await mountPage([
      group(),
      group({
        domain: 'deadlines',
        status: 'manual_review',
        manual_review_by_design: true,
        manual_review_explanation: 'Lhůty hlídá stránka Podání.',
        manual_review_parameter_count: 1,
        parameter_count: 1,
        versions: [summary({ ruleset_id: 'cz-payroll-2026.deadlines.v1', domain: 'deadlines' })],
      }),
    ])

    expect(wrapper.get('[data-test="ruleset-status-income_tax"]').text())
      .toBe('payroll.rulesets.status.awaiting_activation')
    expect(wrapper.get('[data-test="ruleset-status-deadlines"]').text())
      .toBe('payroll.rulesets.status.manual_review')

    // „Čeká to na vás" a „tady není co schvalovat" nesmí mít stejnou barvu.
    expect(wrapper.get('[data-test="ruleset-status-income_tax"]').classes())
      .not.toEqual(wrapper.get('[data-test="ruleset-status-deadlines"]').classes())

    const hint = wrapper.get('[data-test="ruleset-status-hint-deadlines"]').text()
    expect(hint).toContain('payroll.rulesets.status_hint.manual_review')
    expect(hint).toContain('Lhůty hlídá stránka Podání.')
  })

  it('schová technickou výjimku degradace za bezpečnou českou zprávu', async () => {
    const technical = 'Ruleset synthetic canonical checksum mismatch.'
    const wrapper = await mountPage([group()], technical)

    const alert = wrapper.get('[data-test="ruleset-degraded"]')
    const message = alert.get('[data-test="ruleset-degraded-message"]')
    expect(message.text()).toBe('payroll.rulesets.degraded')
    expect(message.text()).not.toContain(technical)
    expect(alert.get('[data-test="ruleset-degraded-technical"]').text())
      .toContain(technical)
  })

  it('shows how many parameters manual judgement actually affects', async () => {
    const wrapper = await mountPage([
      group({
        domain: 'social_insurance',
        manual_review_parameter_count: 3,
        parameter_count: 10,
        versions: [summary({ ruleset_id: 'cz-payroll-2026.social-insurance.v1', domain: 'social_insurance' })],
      }),
      group({
        domain: 'codebooks',
        status: 'manual_review',
        manual_review_by_design: true,
        manual_review_parameter_count: 1,
        parameter_count: 1,
        versions: [summary({ ruleset_id: 'cz-payroll-2026.codebooks.v1', domain: 'codebooks' })],
      }),
    ])

    expect(wrapper.get('[data-test="ruleset-manual-share-social_insurance"]').text())
      .toBe('payroll.rulesets.manual_review_share:{"manual":3,"total":10}')
    expect(wrapper.get('[data-test="ruleset-manual-share-codebooks"]').text())
      .toBe('payroll.rulesets.manual_review_all:{"total":1}')
  })

  it('keeps the domain tables aligned by using identical fixed column widths', async () => {
    const wrapper = await mountPage([
      group(),
      group({
        domain: 'deadlines',
        versions: [summary({ ruleset_id: 'cz-payroll-2026.deadlines.v1', domain: 'deadlines' })],
      }),
    ])

    const tables = wrapper.findAll('section table')
    expect(tables.length).toBe(2)
    for (const table of tables) {
      expect(table.classes()).toContain('table-fixed')
      expect(table.findAll('col').map(col => col.classes().join(' '))).toEqual([
        'w-[24%]',
        'w-[26%]',
        'w-[21%]',
        'w-[16%]',
        'w-[13%]',
      ])
    }
  })

  it('leads with the Czech name and keeps the canonical key as a subtitle', async () => {
    m.detail.mockResolvedValue(detail([
      parameter(),
      parameter({
        key: 'rounding.total',
        label: 'Zaokrouhlení celkového pojistného',
        type: 'text',
        value: 'ceil-to-1-czk',
        value_label: 'zaokrouhlit nahoru na celé koruny',
      }),
    ]))
    const wrapper = await mountPage([group()])
    await wrapper.get('section table tbody button').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-test="parameter-rounding.total"]')
    expect(row.text()).toContain('Zaokrouhlení celkového pojistného')
    expect(row.text()).toContain('rounding.total')
    // Kód se ukazuje jako doplněk, ne jako hodnota.
    expect(row.text()).toContain('zaokrouhlit nahoru na celé koruny')
    expect(row.text()).not.toContain('ceil-to-1-czk')
  })

  it('explains a manual-review parameter instead of showing a blocking blob', async () => {
    m.detail.mockResolvedValue(detail([
      parameter({
        key: 'submission_calendar',
        label: 'Kalendář lhůt pro podání',
        type: 'manual_review',
        value: 'Lhůty závisí na agendě.',
        capability: 'manual_review',
        note: 'Lhůty závisí na agendě.',
        manual_review_why: 'Jedno univerzální datum neexistuje.',
        manual_review_action: 'Nic tu neschvalujete, termín ukazuje stránka Podání.',
      }),
    ]))
    const wrapper = await mountPage([group()])
    await wrapper.get('section table tbody button').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-test="parameter-submission_calendar"]')
    expect(row.text()).toContain('payroll.rulesets.manual_review_badge')
    expect(row.text()).toContain('payroll.rulesets.manual_review_why')
    expect(row.text()).toContain('Jedno univerzální datum neexistuje.')
    expect(row.text()).toContain('payroll.rulesets.manual_review_action')
    expect(row.text()).toContain('Nic tu neschvalujete, termín ukazuje stránka Podání.')
  })

  // Doložení zdrojem je náhrada za zrušené schvalovací klikání, takže musí být
  // vidět rovnou u domény — ne až po otevření verze.
  it('shows where a domain took its values from, with a link and a retrieval date', async () => {
    const wrapper = await mountPage([group()])

    const provenance = wrapper.get('[data-test="ruleset-provenance-income_tax"]')
    expect(provenance.text()).toContain('payroll.rulesets.provenance.title')
    expect(provenance.text())
      .toContain('Finanční správa: zaměstnanci a zaměstnavatelé 2026')
    expect(provenance.text())
      .toContain('payroll.rulesets.provenance.retrieved:{"date":"2026-08-03"}')

    const link = provenance.get('a')
    expect(link.attributes('href'))
      .toBe('https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
  })

  it('attributes a single-source version down to the parameter row', async () => {
    m.detail.mockResolvedValue(detail([parameter()]))
    const wrapper = await mountPage([group()])
    await wrapper.get('section table tbody button').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-test="parameter-total.rate"]')
    expect(row.text()).toContain('Finanční správa: zaměstnanci a zaměstnavatelé 2026')
    expect(row.text()).toContain('payroll.rulesets.provenance.retrieved:{"date":"2026-08-03"}')
  })

  it('does not invent per-parameter provenance when the version has several sources', async () => {
    m.detail.mockResolvedValue(detail([parameter()], {
      sources: [
        { id: 'a', title: 'VZP: metodika', url: 'https://www.vzp.cz/a', retrieved_on: '2026-08-03' },
        { id: 'b', title: 'VZP: platby 2026', url: 'https://www.vzp.cz/b', retrieved_on: '2026-08-03' },
      ],
    }))
    const wrapper = await mountPage([group()])
    await wrapper.get('section table tbody button').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-test="parameter-total.rate"]')
    expect(row.text()).toContain('payroll.rulesets.provenance.multiple:{"count":2}')
    expect(row.text()).not.toContain('VZP: metodika')

    // …ale za celou verzi zdroje uvedené jsou.
    await row.get('button').trigger('click')
    const tab = wrapper.get('[data-test="ruleset-sources-tab"]')
    expect(tab.text()).toContain('payroll.rulesets.provenance.vendor_note')
    expect(tab.text()).toContain('VZP: metodika')
    expect(tab.text()).toContain('VZP: platby 2026')
    expect(tab.text()).toContain('payroll.rulesets.provenance.no_approval')
  })
})

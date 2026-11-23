import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  PayrollQuickComponentCell,
  PayrollQuickComponentColumn,
  PayrollQuickInputRef,
  PayrollQuickInputRow,
  PayrollQuickSurchargeKind,
  PayrollQuickSurchargeState,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  load: vi.fn(),
  getPref: vi.fn(),
  putPref: vi.fn(),
  patchPrefs: vi.fn(),
  // Stav preferencí tabulky; nastaví ho mock `useUserPrefs` níže.
  pagePrefs: null as unknown as { value: Record<string, unknown> },
  save: vi.fn(),
  materialize: vi.fn(),
  approveBatch: vi.fn(),
  canWrite: vi.fn(),
  canRead: vi.fn(),
  hourlyEvidence: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    quickInputs: m.load,
    hourlyEvidence: m.hourlyEvidence,
    saveQuickInputs: m.save,
    materializeRecurringComponents: m.materialize,
    approveInputsBatch: m.approveBatch,
  },
}))
vi.mock('@/api/preferences', () => ({
  preferencesApi: {
    getPreferenceKey: m.getPref,
    putPreferenceKey: m.putPref,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite, canRead: m.canRead }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => {
  const original = await importOriginal<typeof import('vue-i18n')>()
  const { default: cs } = await import('@/i18n/cs.json')
  const { te } = original.createI18n({ legacy: false, locale: 'cs', messages: { cs } }).global
  return {
    ...original,
    useI18n: () => ({ t: (key: string) => key, te, locale: ref('cs-CZ') }),
  }
})

// Preference tabulek (přepínač příplatků, zvolené sloupce složek) drží stav,
// aby šlo ověřit, co stránka ukládá a jak čte uloženou volbu.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed, ref } = await import('vue')
  const state = ref<Record<string, unknown>>({})
  m.pagePrefs = state
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => state.value),
    patchPagePrefs: (key: string, patch: Record<string, unknown>) => {
      m.patchPrefs(key, patch)
      state.value = { ...state.value, ...patch }
    },
  }
})

import PayrollQuickInputs from '@/pages/payroll/PayrollQuickInputs.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'

function inputRef(
  status: PayrollQuickInputRef['status'],
  overrides: Partial<PayrollQuickInputRef> = {},
): PayrollQuickInputRef {
  return {
    id: 101,
    amount_minor: 4_200_000,
    quantity_milliunits: null,
    source_kind: 'manual',
    status,
    row_version: 3,
    source_snapshot: null,
    ...overrides,
  }
}

/**
 * Výchozí stav zákonných příplatků: druh je dostupný, ale nic zadaného nemá.
 * Server ho posílá u každého řádku, takže fixtura ho posílat musí taky —
 * jinak by testy běžely nad tvarem, který v odpovědi nikdy nenastane.
 */
function surchargeStates(
  overrides: Partial<Record<PayrollQuickSurchargeKind, Partial<PayrollQuickSurchargeState>>> = {},
): Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState> {
  const sections: Record<PayrollQuickSurchargeKind, string> = {
    night: '§ 116',
    weekend: '§ 118',
    holiday: '§ 115',
    difficult_environment: '§ 117',
  }
  const kinds: PayrollQuickSurchargeKind[] = [
    'night', 'weekend', 'holiday', 'difficult_environment',
  ]
  return Object.fromEntries(kinds.map(kind => [kind, {
    kind,
    label: kind,
    section: sections[kind],
    component_code: `PRIPLATEK_${kind.toUpperCase()}`,
    basis: kind === 'difficult_environment' ? 'minimum_wage_hourly' : 'average_earning',
    basis_hourly_minor: 20_000,
    average_hourly_minor: 20_000,
    average_snapshot_id: 41,
    average_snapshot_version: 1,
    rate_basis_points: kind === 'holiday' ? 10_000 : 1_000,
    rate_is_agreed: false,
    requires_factors: kind === 'difficult_environment',
    default_factors: null,
    hours_milli: null,
    factors: null,
    amount_minor: 0,
    managed_amount_minor: 0,
    row_version: null,
    status: null,
    managed_elsewhere: false,
    from_attendance: false,
    conflict: false,
    available: true,
    entry_available: true,
    clear_only: false,
    unavailable_reason: null,
    ...overrides[kind],
  }])) as Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState>
}

function fixture(overrides: Partial<PayrollQuickInputRow> = {}): PayrollQuickInputRow {
  return {
    employee_id: 8,
    employment_id: 12,
    employment_row_version: 7,
    full_name: 'Syntetická osoba',
    birth_number_masked: '******/**42',
    employment_code: 'SYN-HPP',
    relation_type: 'employment',
    effective_status: 'active',
    suspended_in_month: false,
    base_amount_minor: 4_200_000,
    base_managed_elsewhere: false,
    base_conflict: false,
    partial_month: false,
    base_requires_entry: false,
    overtime_mode: 'amount',
    overtime_hours_milli: null,
    overtime_amount_minor: 25_000,
    overtime_hourly_rate_minor: null,
    overtime_average_snapshot_id: null,
    overtime_average_snapshot_version: null,
    overtime_hours_available: false,
    overtime_hours_relation_supported: true,
    overtime_managed_elsewhere: false,
    overtime_conflict: false,
    bonus_amount_minor: 50_000,
    bonus_managed_elsewhere: false,
    bonus_conflict: false,
    other_amount_minor: 0,
    non_monetary_amount_minor: 0,
    excluded_from_gross_amount_minor: 0,
    gross_preview_minor: 4_275_000,
    inputs: { base: null, overtime: null, bonus: null },
    surcharges: surchargeStates(),
    surcharge_amount_minor: 0,
    blockers: [],
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollQuickInputs, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollQuickInputs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.pagePrefs.value = {}
    m.routeQuery = {}
    m.canWrite.mockReturnValue(true)
    m.canRead.mockReturnValue(true)
    m.hourlyEvidence.mockResolvedValue({ work_source: 'manual_summary', options: { nonworked_holidays: [], holiday_time_off_entitlements: [], overtime_time_off_entitlements: [], time_off_absences: [] }, period: '2026-11', employment_id: 12, can_write: true, row_version: 0, work_source_hash: 'a'.repeat(64), segments_suggested: [], evidence: null, history: [], history_offset: 0, history_has_more: false })
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture()],
    }))
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, items: [], total: 0 },
      failures: [],
    }))
  })

  it('uloží vybraným dohodám bez příjmu 0 Kč jednou akcí', async () => {
    const dpp = (id: number, overrides: Partial<PayrollQuickInputRow> = {}) => fixture({
      employment_id: id,
      employment_code: `DPP-${id}`,
      relation_type: 'dpp',
      base_amount_minor: 0,
      base_requires_entry: true,
      overtime_amount_minor: 0,
      bonus_amount_minor: 0,
      gross_preview_minor: 0,
      ...overrides,
    })
    m.load.mockImplementation(async period => ({
      period,
      total: 4,
      items: [
        fixture(),
        dpp(21),
        dpp(22),
        dpp(23, { inputs: { base: inputRef('approved'), overtime: null, bonus: null } }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-zero-income-open"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-zero-income-row-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-zero-income-row-23"]').exists()).toBe(false)
    await wrapper.get('[data-testid="quick-zero-income-row-22"]').setValue(false)
    await wrapper.get('[data-testid="quick-zero-income-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledTimes(1)
    const payload = m.save.mock.calls[0][0]
    expect(payload.rows.map((row: { employment_id: number }) => row.employment_id)).toEqual([21])
    expect(payload.rows[0].base_amount_minor).toBe(0)
    expect(m.success).toHaveBeenCalledWith('payroll.quick_inputs.zero_income.saved')
  })

  it('keeps two employments of one person separate and labels statutory income correctly', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture(),
        fixture({
          employment_id: 13,
          employment_row_version: 4,
          employment_code: 'SYN-JED',
          relation_type: 'statutory_body',
          base_amount_minor: 800_000,
          overtime_amount_minor: 0,
          bonus_amount_minor: 0,
          gross_preview_minor: 800_000,
          overtime_hours_relation_supported: false,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').findAll('tbody tr')).toHaveLength(2)
    // Běžný pracovní poměr je výchozí stav a štítek ani popisek u pole nenese.
    expect(wrapper.find('[data-testid="quick-relation-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-income-label-12"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="quick-relation-13"]').text())
      .toBe('payroll.people.relations.statutory_body')
    expect(wrapper.get('[data-testid="quick-income-label-13"]').text())
      .toBe('payroll.quick_inputs.income_labels.statutory_body')
    expect(wrapper.find('[data-testid="overtime-mode-hours-13"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.quick_inputs.amount_only_relation_hint')
  })

  it('marks base wages suggested from agreed terms as unsaved and saves them with one action', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture(), fixture({ employment_id: 13, inputs: { base: inputRef('approved'), overtime: null, bonus: null } })],
      total: 121,
      unsaved_suggestions: { employments: 120, amount_minor: 504_000_000 },
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-unsaved-suggestions"]').text())
      .toContain('payroll.quick_inputs.unsaved.title')
    expect(wrapper.find('[data-testid="quick-base-suggested-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-base-suggested-13"]').exists()).toBe(false)

    await wrapper.get('[data-testid="quick-unsaved-save"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalled()
    expect(m.save.mock.calls[0][0].rows.map((row: { employment_id: number }) => row.employment_id)).toContain(12)
  })

  it('creates and approves inputs from pending recurring components for the whole month', async () => {
    let materialized = false
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({ base_managed_elsewhere: true, recurring_pending_count: materialized ? 0 : 1 })],
      total: 1,
      recurring_pending: materialized
        ? { employments: 0, assignments: 0 }
        : { employments: 72, assignments: 72 },
    }))
    m.materialize.mockImplementation(async () => {
      materialized = true
      return {
        period: '2026-09',
        created_count: 2,
        replayed_count: 0,
        manual_review_count: 1,
        created: [
          { recurring_component_id: 1, input_id: 501, amount_minor: 1 },
          { recurring_component_id: 2, input_id: 502, amount_minor: 1 },
        ],
        replayed: [],
        manual_review: [{
          recurring_component_id: 3,
          employment_id: 30,
          component_id: 9,
          reason: 'Chybí kalendář.',
          employee_id: 31,
          full_name: 'Syntetická osoba B',
        }],
      }
    })
    m.approveBatch.mockResolvedValue({ approved: [501, 502], skipped: [], failed: [], remaining: 0, complete: true, next_after_id: 0 })

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-recurring-pending"]').text())
      .toContain('payroll.quick_inputs.recurring_pending.title')
    await wrapper.get('[data-testid="quick-recurring-materialize"]').trigger('click')
    await flushPromises()

    expect(m.materialize).toHaveBeenCalledTimes(1)
    expect(m.approveBatch).toHaveBeenCalledWith({ ids: [501, 502] })
    expect(wrapper.find('[data-testid="quick-recurring-pending"]').exists()).toBe(false)
    const manual = wrapper.get('[data-testid="quick-recurring-manual-review"]')
    expect(manual.text()).toContain('Syntetická osoba B')
    expect(manual.text()).toContain('Chybí kalendář.')
  })

  it('uloží měsíční podklady celého měsíce, ne jen zobrazenou stránku', async () => {
    const all = [
      fixture({ employment_id: 12 }),
      fixture({ employment_id: 13, employee_id: 9, full_name: 'Syntetická osoba 2' }),
      fixture({ employment_id: 14, employee_id: 10, full_name: 'Syntetická osoba 3' }),
    ]
    m.load.mockImplementation(async (period: string, page?: { limit?: number, offset?: number }) => {
      const limit = page?.limit ?? 25
      const offset = page?.offset ?? 0
      // Stránka obrazovky má jen jeden řádek, celý měsíc tři.
      const size = limit === 25 ? 1 : limit
      return { period, items: all.slice(offset, offset + size), total: all.length }
    })

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledTimes(1)
    expect(m.save.mock.calls[0][0].rows.map((row: { employment_id: number }) => row.employment_id))
      .toEqual([12, 13, 14])
  })

  it('keeps partner dependent income amount-only as well', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        relation_type: 'partner_dependent',
        overtime_hours_relation_supported: false,
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-income-label-12"]').text())
      .toBe('payroll.quick_inputs.income_labels.partner_dependent')
    expect(wrapper.find('[data-testid="overtime-mode-hours-12"]').exists()).toBe(false)
  })

  it('labels a suspension that occurred during an otherwise active month', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'active',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
  })

  it('renders the mobile form, masked identifier and lg sticky action bar', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-base-mobile-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-relation-mobile-12"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('******/**42')
    expect(wrapper.text()).not.toContain('123456/7842')
    const actionBar = wrapper.get('[data-testid="quick-payroll-save"]').element.parentElement
    expect(actionBar?.classList.contains('lg:sticky')).toBe(true)
    expect(actionBar?.classList.contains('md:sticky')).toBe(false)
    expect(wrapper.get('[data-testid="quick-payroll-save"]').classes()).toContain('w-full')
    expect(wrapper.get('[data-testid="quick-payroll-runs"]').text())
      .toContain('payroll.quick_inputs.continue_to_runs')
  })

  it('importovaný měsíc měsíčně odměňovaného vztahu nenabízí podklady hodin ani výměnu zdroje', async () => {
    m.routeQuery = { period: '2026-11', employment: '12', hours: '1' }
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({ hourly_remuneration_in_period: false, worked_time: {
      month: { id: 1, row_version: 2, revision_no: 1, status: 'open', work_source: 'import_summary' },
      manual_summary: null, suggested_segments: [{ date_from: '2026-11-01', date_to: '2026-11-30' }],
      worked_duration_units: 22500, overtime_duration_units: null, worked_days: null, jmhz_confirmed: false,
      available_sources: ['entries', 'import_summary'],
    } })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="quick-worked-hours-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-worked-hours-mobile-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="worked-hours-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="worked-hours-restore-panel"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeUndefined()
  })

  it('přeloží blokaci hodinové mzdy podle kódu a serverový text použije jen jako zálohu', async () => {
    m.load.mockImplementation(async period => ({ period, items: [fixture({
      hourly_remuneration_in_period: true,
      blockers: ['hourly_time_missing'],
      hourly_wage_preview: { complete: false, blockers: [{ code: 'hourly_time_missing', message: 'Doložte skutečně odpracovanou dobu.', context: {} }], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: null, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('payroll.hourly_preview.blockers.hourly_time_missing')
    expect(wrapper.text()).not.toContain('Doložte skutečně odpracovanou dobu.')
  })

  it('opens historical hourly settlement evidence while keeping current monthly base editable', async () => {
    m.load.mockImplementation(async period => ({ period, items: [fixture({ hourly_remuneration_in_period: false,
      hourly_wage_preview: { settlement_only: true, complete: true, blockers: [], rounding: 'ceil', evidence_hash: 'c'.repeat(64), payable_total_minor: 0, claims: [], entitlements: [], included_allocations: [] },
      worked_time: { month: { id: 1, row_version: 2, revision_no: 1, status: 'approved', work_source: 'entries' }, manual_summary: null, suggested_segments: [], worked_duration_units: 22500, overtime_duration_units: 0, worked_days: 1, jmhz_confirmed: true },
    })], total: 1 }))
    const wrapper = mountPage(); await flushPromises()
    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="quick-base-mobile-12"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="quick-worked-hours-12"] [data-test="worked-hours-open"]').trigger('click'); await flushPromises()
    expect(wrapper.find('[data-test="hourly-evidence-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="worked-hours-current"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="worked-hours-save"]').exists()).toBe(false)
    expect(m.hourlyEvidence).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeUndefined()
  })

  it('hodinové podklady načte dávkově a panel nepovolí zápisem peněžních vstupů', async () => {
    m.canWrite.mockImplementation(permission => permission === 'payroll.inputs.write')
    m.load.mockImplementation(async period => ({ period, items: [fixture({ hourly_remuneration_in_period: true, worked_time: {
      month: { id: 1, row_version: 2, revision_no: 1, status: 'approved', work_source: 'manual_summary' },
      manual_summary: { id: 2, time_month_revision_no: 1, content_sha256: 'a'.repeat(64), reason: 'Syntetický výkaz', segments: [] },
      suggested_segments: [{ date_from: '2026-11-01', date_to: '2026-11-30' }],
      worked_duration_units: 22500, overtime_duration_units: null, worked_days: null, jmhz_confirmed: false,
    } })], total: 1 }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-worked-hours-12"]').text()).toContain('7:30')
    expect(wrapper.get('[data-testid="quick-worked-hours-mobile-12"]').text()).toContain('jmhz_pending')
    expect(m.hourlyEvidence).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="quick-worked-hours-12"] [data-test="worked-hours-open"]').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-test="worked-hours-panel"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="worked-hours-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="worked-hours-reopen"]').exists()).toBe(false)
    expect(m.hourlyEvidence).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="hourly-evidence-save"]').exists()).toBe(false)
    expect(m.load).toHaveBeenCalledTimes(1)
  })

  it('v hodinovém měsíci pouze ukáže existující základ a zablokuje jeho změnu', async () => {
    m.load.mockImplementation(async period => ({ period, items: [fixture({
      hourly_remuneration_in_period: true,
      blockers: ['hourly_remuneration_calculation_unsupported'],
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-base-mobile-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '42000,00')
    expect(wrapper.find('[data-testid="quick-base-suggested-12"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.hourly_remuneration_calculation_unsupported')
  })

  it('hodiny a náhled hodinové mzdy dá do samostatného řádku přes celou šířku, ne do sloupce se jménem', async () => {
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({
      hourly_remuneration_in_period: true,
      worked_time: {
        month: { id: 1, row_version: 2, revision_no: 1, status: 'open', work_source: 'manual_summary' },
        manual_summary: null, suggested_segments: [{ date_from: '2026-11-01', date_to: '2026-11-30' }],
        worked_duration_units: 22500, overtime_duration_units: null, worked_days: null, jmhz_confirmed: false,
      },
      hourly_wage_preview: { complete: true, blockers: [], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: 450000, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    const hourlyRow = wrapper.get('[data-testid="quick-hourly-row-12"]')
    expect(hourlyRow.element.tagName).toBe('TR')
    const cell = hourlyRow.get('td')
    expect(Number(cell.attributes('colspan'))).toBe(wrapper.findAll('thead th').length)
    expect(hourlyRow.find('[data-testid="quick-worked-hours-12"]').exists()).toBe(true)
    expect(hourlyRow.find('[data-test="hourly-wage-preview"]').exists()).toBe(true)
    const personCell = hourlyRow.element.previousElementSibling!.querySelector('td')!
    expect(personCell.querySelector('[data-test="hourly-wage-preview"]')).toBeNull()
    expect(personCell.querySelector('[data-test="worked-hours-summary"]')).toBeNull()
    expect(wrapper.get('[data-testid="quick-hourly-card-12"]').find('[data-test="hourly-wage-preview"]').exists()).toBe(true)
  })

  it('tlačítko na mzdový běh zachová zvolené období', async () => {
    m.routeQuery = { period: '2026-02' }
    const wrapper = mountPage()
    await flushPromises()
    expect(JSON.parse(wrapper.get('[data-testid="quick-payroll-runs"]').attributes('data-to')!))
      .toEqual({ name: 'payroll-runs', query: { period: '2026-02' } })
  })

  it.each([
    [false, '4725000'],
    [true, '4275000'],
  ])('náhled hrubého příjmu přičte úplný hodinový náhled, jen když hodinová mzda není ve vstupech (%s)', async (inInputs, digits) => {
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({
      hourly_remuneration_in_period: true,
      hourly_claims_in_inputs: inInputs,
      hourly_wage_preview: { complete: true, blockers: [], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: 450000, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-gross-12"]').text().replace(/\D/g, '')).toBe(digits)
    expect(wrapper.find('[data-testid="quick-gross-hourly-12"]').exists()).toBe(!inInputs)
  })

  it('neúplný hodinový náhled k hrubému příjmu nepřičte', async () => {
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({
      hourly_remuneration_in_period: true,
      hourly_wage_preview: { complete: false, blockers: [], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: null, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-gross-12"]').text().replace(/\D/g, '')).toBe('4275000')
  })

  it('měsíční řádek žádný hodinový řádek nemá', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="quick-hourly-row-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-hourly-card-12"]').exists()).toBe(false)
  })

  it('blokaci, kterou už říká náhled hodinové mzdy, neopakuje u osoby a stejnou větu ukáže jednou', async () => {
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({
      hourly_remuneration_in_period: true,
      blockers: ['hourly_premium_evidence_missing', 'hourly_time_missing'],
      hourly_wage_preview: { complete: false, blockers: [
        { code: 'hourly_premium_evidence_missing', message: 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.', context: {} },
        { code: 'hourly_premium_evidence_missing', message: 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.', context: { segment: 2 } },
        { code: 'hourly_premium_evidence_missing', message: 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.', context: { segment: 3 } },
        { code: 'hourly_time_missing', message: 'Doložte skutečně odpracovanou dobu.', context: {} },
      ], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: null, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    const hourlyRow = wrapper.get('[data-testid="quick-hourly-row-12"]')
    const items = hourlyRow.findAll('[data-test="hourly-preview-blockers"] li').map(item => item.text())
    expect(items).toEqual(['payroll.hourly_preview.blockers.hourly_premium_evidence_missing', 'payroll.hourly_preview.blockers.hourly_time_missing'])
    const personCell = hourlyRow.element.previousElementSibling!.querySelector('td')!
    expect(personCell.textContent).not.toContain('hourly_premium_evidence_missing')
    expect(personCell.textContent).not.toContain('hourly_time_missing')
  })

  it('zobrazí konkrétní serverovou příčinu hodinové blokace místo kódu překladu', async () => {
    m.load.mockImplementation(async period => ({ period, items: [fixture({
      hourly_remuneration_in_period: true,
      blockers: ['hourly_specific_evidence_missing'],
      hourly_wage_preview: { complete: false, blockers: [{ code: 'hourly_specific_evidence_missing', message: 'Doložte přesný podklad nároku.', context: {} }], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: null, claims: [], entitlements: [], included_allocations: [] },
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Doložte přesný podklad nároku.')
    expect(wrapper.text()).not.toContain('payroll.quick_inputs.blockers.hourly_specific_evidence_missing')
  })

  it('keeps hour mode unavailable without an average and sends employment concurrency version', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="overtime-mode-hours-12"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledTimes(1)
    expect(m.save.mock.calls[0][0].rows[0]).toMatchObject({
      employment_id: 12,
      employment_row_version: 7,
      base_amount_minor: 4_200_000,
      overtime_mode: 'amount',
      overtime_amount_minor: 25_000,
      bonus_amount_minor: 50_000,
    })
  })

  it('explains and protects locked, approved, draft and externally managed fields', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture({
          inputs: {
            base: inputRef('locked'),
            overtime: inputRef('approved', { id: 102, amount_minor: 25_000 }),
            bonus: inputRef('draft', { id: 103, amount_minor: 50_000 }),
          },
        }),
        fixture({
          employment_id: 13,
          employment_code: 'SYN-DPC',
          relation_type: 'dpc',
          bonus_managed_elsewhere: true,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-base-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.locked')
    expect(wrapper.get('[data-testid="quick-overtime-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.approved')
    expect(wrapper.get('[data-testid="quick-bonus-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.draft')
    // Spravované pole: hodnota jen ke čtení a ikona se zdrojem; plná věta je
    // jen v `title` a `sr-only`, ne jako odstavec pod polem.
    const managedState = wrapper.get('[data-testid="quick-bonus-state-13"]')
    expect(managedState.text()).toContain('payroll.quick_inputs.field_state.managed')
    expect(managedState.get('.sr-only').text()).toBe(managedState.text())
    expect(managedState.attributes('title')).toContain('payroll.quick_inputs.field_state.managed')
    expect(wrapper.find('[data-testid="quick-bonus-13"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="quick-bonus-readonly-13"]').text()).toBe('500,00')
  })

  it('offers the way to the wage component on both layouts, not just the table', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({ base_managed_elsewhere: true, blockers: ['base_managed_elsewhere'] })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    for (const testId of ['quick-base-managed-link-12', 'quick-base-managed-link-mobile-12']) {
      const link = wrapper.get(`[data-test="${testId}"]`)
      expect(JSON.parse(link.attributes('data-to') ?? '{}')).toEqual({
        name: 'payroll-components',
        query: { employment: '12' },
      })
    }
  })

  it('explains a base wage reduced by recorded absence', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        away_in_month: true,
        base_amount_minor: 4_009_100,
        base_proration: {
          fund_minutes: 10_560,
          replaced_minutes: 480,
          replaced_minutes_by_title: { vacation: 480 },
          amount_minor: 4_009_100,
        },
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').element)
      .toHaveProperty('value', '40091,00')
    expect(wrapper.get('[data-testid="quick-base-proration-12"]').text())
      .toContain('payroll.quick_inputs.base_proration')
  })

  it('keeps the base explanation away when the month has no absence', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-base-proration-12"]').exists()).toBe(false)
  })

  it('shows effective suspended status and requires an explicit base', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'suspended',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '')
    // Prázdný základ ukládání neblokuje: znamená „základ neřeším". Východiskem
    // z blokátoru je zadat nulu — viz test níž, který ji pošle jako 0.
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeUndefined()
  })

  it('tells an entered zero apart from an unfilled base', async () => {
    const partialMonthRow = () => fixture({
      base_requires_entry: true,
      base_amount_minor: 0,
      blockers: ['partial_month_base_required'],
    })
    m.load.mockImplementation(async period => ({
      period,
      items: [partialMonthRow()],
    }))
    // Uložení vrací řádek zpátky, aby druhá polovina testu měla do čeho psát.
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, items: [partialMonthRow()], total: 1 },
      failures: [],
    }))

    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '')

    // Nevyplněné pole → null, na serveru „základ neřeším".
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].base_amount_minor).toBeNull()

    // Zadaná nula → 0, na serveru plnohodnotný nulový základ.
    await wrapper.get('[data-testid="quick-base-12"]').setValue('0')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[1][0].rows[0].base_amount_minor).toBe(0)
  })

  it('uses read-only mode while keeping the payroll-run navigation available', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-payroll-runs"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.quick_inputs.readonly_hint')
  })

  // Prázdno je legitimní jen u základní mzdy. U odměny nese nulu jedině zadaná
  // nula, takže prázdné pole tam zůstává chybou a ukládání dál blokuje.
  it('blocks saving when an editable amount is empty or invalid', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('')

    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_required')
    expect(wrapper.find('[data-testid="quick-payroll-validation-summary"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="quick-bonus-12"]').attributes('aria-invalid')).toBe('true')
  })

  it('rejects a negative amount locally and keeps the gross preview fail-safe', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-base-12"]').setValue('-1')

    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_non_negative')
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('keeps the exact API failure visible and offers reload after employment conflict', async () => {
    m.save.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'employment_row_version_conflict',
            message: 'Syntetický vztah mezitím změnil jiný uživatel.',
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-payroll-save-error"]').text())
      .toContain('Syntetický vztah mezitím změnil jiný uživatel.')
    expect(wrapper.find('[data-testid="quick-payroll-conflict-refresh"]').exists()).toBe(true)
    expect(m.error).toHaveBeenCalledWith('Syntetický vztah mezitím změnil jiný uživatel.')

    await wrapper.get('[data-testid="quick-payroll-conflict-refresh"]').trigger('click')
    await flushPromises()
    expect(m.load).toHaveBeenCalledTimes(2)
  })

  it('does not apply a stale response after the payroll period changes', async () => {
    let resolveFirst: ((value: unknown) => void) | undefined
    m.load.mockReset()
    m.load
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce({ period: '2026-07', items: [] })

    const wrapper = mountPage()
    await flushPromises()
    const periodInput = wrapper.get('[data-testid="quick-payroll-period"]')
    expect(periodInput.attributes('disabled')).toBeDefined()
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    void vm.load()
    resolveFirst?.({ period: '2026-06', items: [fixture({ full_name: 'Starý měsíc' })] })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Starý měsíc')
    expect(m.load).toHaveBeenLastCalledWith('2026-07', { limit: 25, offset: 0 }, undefined, undefined)
  })

  /**
   * Zúžení z karty zaměstnance zužuje SERVER, ne prohlížeč nad načtenou stránkou.
   * Vztah z druhé strany by se jinak tiše neprojevil: seznam by zůstal celý,
   * nebo by vyšel prázdný, a obojí vypadá jako legitimní výsledek.
   */
  it('sends the narrowing to the server instead of filtering the loaded page', async () => {
    m.routeQuery = { employment: '9999' }
    mountPage()
    await flushPromises()

    // Období závisí na dnešku, na kontraktu záleží zbytek: stránka zůstává
    // normální a vztah jde na server jako parametr.
    expect(m.load).toHaveBeenLastCalledWith(expect.any(String), { limit: 25, offset: 0 }, 9999, undefined)
  })

  /**
   * Prázdné zúžení musí být pojmenované. Tichá prázdná tabulka vypadá stejně
   * jako měsíc bez lidí a uživatel nemá jak poznat, že se dívá na zúžený
   * seznam — ani jak se ze zúžení dostat ven.
   */
  it('names an empty narrowing instead of showing a silent empty table', async () => {
    m.routeQuery = { employment: '9999' }
    m.load.mockImplementation(async period => ({ period, items: [], total: 0 }))
    const wrapper = mountPage()
    await flushPromises()

    const notice = wrapper.find('[data-test="payroll-focus-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('payroll.agendas.focus.missing')
    expect(wrapper.find('[data-test="payroll-focus-clear"]').exists()).toBe(true)
  })

  /*
   * Id vztahu zná adresní řádek, ne uživatel. Hláška o slepém zúžení proto
   * mluví o zaměstnanci, ne o čísle z databáze.
   */
  it('keeps the raw employment id out of the empty narrowing notice', async () => {
    m.routeQuery = { employment: '9999' }
    m.load.mockImplementation(async period => ({ period, items: [], total: 0 }))
    const wrapper = mountPage()
    await flushPromises()

    const notice = wrapper.get('[data-test="payroll-focus-notice"]')
    expect(notice.text()).toContain('payroll.agendas.focus.missing_named')
    expect(notice.text()).not.toContain('9999')
    // `t` v testu zahazuje parametry, takže jméno se ověřuje na propu.
    expect(wrapper.getComponent(PayrollFocusNotice).props('name'))
      .toBe('payroll.agendas.focus.unknown_person')
  })

  it('invalidates old rows when loading a new payroll period fails', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Syntetická osoba')

    m.load.mockRejectedValueOnce(new Error('synthetic load failure'))
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    await vm.load()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(m.save).not.toHaveBeenCalled()
  })
  /*
   * X-04: jeden vadný řádek nesmí uzamknout uložení celé stránky. Konflikt
   * s jiným vstupem je věc jednoho zaměstnance, ne dalších čtyřiadvaceti.
   */
  it('saves the healthy rows and pins the backend reason to the broken field', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 2,
      items: [
        fixture({ base_conflict: true }),
        fixture({ employment_id: 13, employment_code: 'SYN-DRUHY', full_name: 'Druhá osoba' }),
      ],
    }))
    m.save.mockImplementation(async payload => ({
      month: {
        period: payload.period,
        total: 2,
        items: [
          fixture({ base_conflict: true }),
          fixture({ employment_id: 13, employment_code: 'SYN-DRUHY', full_name: 'Druhá osoba' }),
        ],
      },
      failures: [{
        employment_id: 12,
        field: 'base',
        code: 'input_state_conflict',
        message: 'Základní mzda je v měsíci evidována rychlým i jiným vstupem.',
        current_row_version: null,
      }],
    }))

    const wrapper = mountPage()
    await flushPromises()

    const button = wrapper.get('[data-testid="quick-payroll-save"]')
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    await flushPromises()

    // Odeslaly se OBA řádky — o tom, co uložit nejde, rozhoduje server.
    expect(m.save.mock.calls[0][0].rows).toHaveLength(2)
    expect(wrapper.get('[data-testid="quick-base-server-error-12"]').text())
      .toContain('Základní mzda je v měsíci evidována')
    // Chyba se nesmí smrsknout na obecné „nepodařilo se".
    expect(wrapper.get('[data-testid="quick-payroll-save-error"]').text())
      .toContain('payroll.quick_inputs.saved_partially')
  })

  /*
   * X-05: rozepsaný řádek přežije přelistování. Dřív se ukládala jen zobrazená
   * stránka, takže 40 lidí znamenalo dvě uložení a dvě šance na konflikt verzí.
   */
  it('carries edits from other pages into a single save', async () => {
    m.load.mockImplementation(async (period, page) => ({
      period,
      total: 2,
      items: page.offset === 0
        ? [fixture()]
        : [fixture({ employment_id: 13, employment_code: 'SYN-DRUHA', full_name: 'Druhá osoba' })],
    }))

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('900')

    const vm = wrapper.vm as unknown as { goToPage: (page: number) => void }
    vm.goToPage(2)
    await flushPromises()
    expect(wrapper.find('[data-testid="quick-bonus-12"]').exists()).toBe(false)
    await wrapper.get('[data-testid="quick-bonus-13"]').setValue('700')

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    const sent = m.save.mock.calls[0][0].rows
    expect(sent.map((row: { employment_id: number }) => row.employment_id).sort())
      .toEqual([12, 13])
    expect(sent.find((row: { employment_id: number }) => row.employment_id === 12)
      .bonus_amount_minor).toBe(90_000)
  })

  /*
   * X-01: kdo smí schvalovat, ukládá rovnou schválené vstupy — a musí je jít
   * ještě opravit, dokud je nepohltil mzdový běh. Bez toho práva zůstává
   * schválený vstup zamčený jako dřív.
   */
  it('keeps an approved field editable only for someone who may approve', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        inputs: {
          base: inputRef('approved'),
          overtime: null,
          bonus: inputRef('locked', { id: 103 }),
        },
      })],
    }))

    const withApproval = mountPage()
    await flushPromises()
    expect(withApproval.get('[data-testid="quick-base-12"]').attributes('disabled'))
      .toBeUndefined()
    // Uzamčený vstup patří mzdovému běhu a nesmí jít přepsat ani schvalovateli.
    expect(withApproval.get('[data-testid="quick-bonus-12"]').attributes('disabled'))
      .toBeDefined()

    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.approve')
    const withoutApproval = mountPage()
    await flushPromises()
    expect(withoutApproval.get('[data-testid="quick-base-12"]').attributes('disabled'))
      .toBeDefined()
  })

  /** Přepnutí měsíce rozepsané řádky zahazuje — patřily jinému období. */
  it('drops pending edits when the payroll period changes', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('900')

    await wrapper.get('[data-testid="quick-payroll-period"]').setValue('2026-07')
    await wrapper.get('[data-testid="quick-payroll-period"]').trigger('change')
    await flushPromises()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows.map((row: { bonus_amount_minor: number }) =>
      row.bonus_amount_minor)).toEqual([50_000])
  })
/*
   * Příplatkové sloupce odkrývá JEDEN přepínač nad tabulkou.
   *
   * Kdyby se rozbalovaly u řádku, bylo by to při 500 zaměstnancích 500
   * kliknutí — přesně ten vzorec, který rychlý měsíční vstup odstraňuje.
   * Tenhle test hlídá, že se sloupce objeví u VŠECH řádků naráz.
   */
  it('reveals surcharge columns for every row from a single toggle', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 2,
      items: [fixture(), fixture({ employment_id: 13, employee_id: 9 })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(false)

    await wrapper.get('[data-testid="quick-surcharges-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-surcharge-night-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-surcharge-weekend-13"]').exists()).toBe(true)
    // Stav přepínače si směnný provoz nesmí nastavovat každý měsíc znovu.
    // Ukládá se do preferencí tabulky — samostatný klíč server nepřijímal.
    expect(m.patchPrefs).toHaveBeenCalledWith(
      'payroll-quick-inputs',
      { flags: { surcharges: true } },
    )
    expect(m.putPref).not.toHaveBeenCalled()
  })

  it('vysvětlí příplatky z hodinové docházky a nenabízí nový ruční příplatek', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({ hourly_remuneration_in_period: true })] }))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-surcharge-night-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-surcharge-mobile-night-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.quick_inputs.surcharges.unavailable.hourly_attendance')
  })

  it('dovolí vymazat dřívější ruční příplatek hodinového zaměstnance', async () => {
    m.load.mockImplementation(async period => ({ period, total: 1, items: [fixture({
      hourly_remuneration_in_period: true,
      surcharges: surchargeStates({ night: { hours_milli: 4000, amount_minor: 10_000, row_version: 2, status: 'draft' } }),
    })] }))
    const wrapper = mountPage()
    await flushPromises()
    const hours = wrapper.get('[data-testid="quick-surcharge-night-12"]')
    expect(hours.attributes('disabled')).toBeUndefined()
    await hours.setValue('')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].surcharges.night).toEqual({ hours_milli: null, factors: null })
    expect(m.save.mock.calls[0][0].rows[0].surcharges.weekend).toBeUndefined()
  })

  /** Uložená preference přepínač zapne ještě před prvním kliknutím. */
  it('restores the remembered toggle state', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
  })

  /*
   * Řádek s příplatkem sekci otevře sám. Skrytá data by se jinak uživateli
   * ztratila z očí jen proto, že přepínač zůstal z minula vypnutý — a to je
   * u zákonného nároku horší než sloupec navíc.
   */
  it('opens the section on its own when a row already carries a surcharge', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        surcharges: surchargeStates({
          night: { hours_milli: 10_000, amount_minor: 20_000, row_version: 2, status: 'draft' },
        }),
        surcharge_amount_minor: 20_000,
      })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
    expect((wrapper.get('[data-testid="quick-surcharge-night-12"]')
      .element as HTMLInputElement).value).toBe('10')
  })

  /** Zadané hodiny odcházejí na server po druzích, i s verzí řádku. */
  it('sends entered hours per surcharge kind', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-night-12"]').setValue('12,5')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    const row = m.save.mock.calls[0][0].rows[0]
    expect(row.surcharges.night).toEqual({ hours_milli: 12_500, factors: null })
    // Druh, který uživatel nevyplnil, se posílá jako `null` — smí ho měnit,
    // takže mlčet o něm by znamenalo nemoci ho vyprázdnit.
    expect(row.surcharges.weekend).toEqual({ hours_milli: null, factors: null })
    expect(row.versions.surcharges.night).toBeNull()
  })

  /*
   * Druh, který uživatel měnit NESMÍ (drží ho docházka), se do požadavku
   * nesmí dostat vůbec. Kdyby se posílal jako prázdný, uložení by zrušilo
   * zákonný nárok, o kterém uživatel ani nevěděl.
   */
  it('never sends kinds the user is not allowed to change', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        surcharges: surchargeStates({
          night: {
            from_attendance: true,
            entry_available: false,
            unavailable_reason: 'claimed_by_attendance',
            amount_minor: 20_000,
            managed_amount_minor: 20_000,
            managed_elsewhere: true,
          },
        }),
      })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-night-12"]').attributes('disabled'))
      .toBeDefined()
    expect(wrapper.get('[data-testid="quick-surcharge-blocked-night-12"]').text())
      .toContain('claimed_by_attendance')

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].surcharges.night).toBeUndefined()
  })

  /*
   * Právní vysvětlení se nesmí opakovat v každé buňce.
   *
   * Odstavec o § 115 nebo o chybějícím průměrném výdělku byl u každého
   * zaměstnance stejný a nafoukl řádek přes 200 px. Platí-li pro celý sloupec,
   * vypíše se JEDNOU nad tabulkou a v buňce zůstane značka — text ale zůstává
   * dostupný v `title` i pro odečítače, informačně se nic neztrácí.
   */
  it('hoists a surcharge reason shared by the whole column above the table', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    const blocked = {
      holiday: {
        entry_available: false,
        unavailable_reason: 'holiday_arrangement_missing',
      },
    } as const
    m.load.mockImplementation(async period => ({
      period,
      total: 2,
      items: [
        fixture({ surcharges: surchargeStates(blocked) }),
        fixture({
          employment_id: 13,
          employee_id: 9,
          surcharges: surchargeStates(blocked),
        }),
      ],
    }))
    const wrapper = mountPage()
    await flushPromises()

    const note = wrapper.get('[data-testid="quick-surcharge-column-note-holiday"]')
    expect(note.text()).toContain('holiday_arrangement_missing')
    // Kde to řešit: dotčení lidé s odkazem na kartu vztahu.
    const people = wrapper.get('[data-testid="quick-surcharge-column-note-people-holiday"]')
    expect(people.text()).toContain('payroll.quick_inputs.surcharge_fix.holiday_arrangement_missing')
    const links = people.findAll('a')
    expect(links).toHaveLength(2)
    expect(links[1]!.attributes('data-to')).toContain('employment=13')
    // V buňce žádná značka pod polem: neaktivní pole s placeholderem,
    // důvod v `title` pole a pro odečítače v `sr-only`.
    for (const id of [12, 13]) {
      const input = wrapper.get(`[data-testid="quick-surcharge-holiday-${id}"]`)
      expect(input.attributes('disabled')).toBeDefined()
      expect(input.attributes('placeholder'))
        .toBe('payroll.quick_inputs.surcharges.unavailable_placeholder')
      expect(input.attributes('title')).toContain('holiday_arrangement_missing')
      const reason = wrapper.get(`[data-testid="quick-surcharge-blocked-holiday-${id}"]`)
      expect(reason.classes()).toContain('sr-only')
      expect(reason.text()).toContain('holiday_arrangement_missing')
    }
  })

  /*
   * Řádek, který se od sloupce liší, si plnou větu nechává u sebe — jinak by
   * se výjimka schovala pod společné vysvětlení, které pro ni neplatí.
   */
  it('keeps the full reason in a row that differs from the column', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    m.load.mockImplementation(async period => ({
      period,
      total: 3,
      items: [
        fixture({
          surcharges: surchargeStates({
            holiday: { entry_available: false, unavailable_reason: 'basis_missing' },
          }),
        }),
        fixture({
          employment_id: 13,
          employee_id: 9,
          surcharges: surchargeStates({
            holiday: { entry_available: false, unavailable_reason: 'basis_missing' },
          }),
        }),
        fixture({
          employment_id: 14,
          employee_id: 10,
          surcharges: surchargeStates({
            holiday: {
              entry_available: false,
              unavailable_reason: 'holiday_compensatory_time_off',
            },
          }),
        }),
      ],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-column-note-holiday"]').text())
      .toContain('basis_missing')
    // Výjimka nese vlastní důvod v `title` svého pole, ne společný důvod sloupce.
    expect(wrapper.get('[data-testid="quick-surcharge-holiday-14"]').attributes('title'))
      .toContain('holiday_compensatory_time_off')
    const odd = wrapper.get('[data-testid="quick-surcharge-blocked-holiday-14"]')
    expect(odd.classes()).toContain('sr-only')
    expect(odd.text()).toContain('holiday_compensatory_time_off')
  })

  /*
   * § 117 náleží ZA KAŽDÝ ztěžující vliv. Bez jejich počtu se nedá počítat —
   * odhadnout jedničku by byl tichý nedoplatek, tak se řádek radši neuloží.
   */
  it('requires the aggravating factor count for Section 117', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-difficult_environment-12"]').setValue('8')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled'))
      .toBeDefined()

    await wrapper.get('[data-testid="quick-surcharge-factors-difficult_environment-12"]')
      .setValue('3')
    await flushPromises()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].surcharges.difficult_environment)
      .toEqual({ hours_milli: 8_000, factors: 3 })
  })

  /*
   * Dopočtená částka u pole. Účetní musí vidět, co z hodin vyjde, ještě než
   * uloží — stejně jako u náhledu hrubé mzdy.
   */
  it('previews the amount next to the entered hours', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    const wrapper = mountPage()
    await flushPromises()

    // 200,00 Kč/h × 10 % × 10 h = 200,00 Kč.
    await wrapper.get('[data-testid="quick-surcharge-night-12"]').setValue('10')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-preview-night-12"]').text())
      .toContain('200')
  })

  /** Chyba serveru se ukáže u KONKRÉTNÍHO příplatkového pole, ne jen v toastu. */
  it('shows a server failure at the surcharge field it belongs to', async () => {
    m.pagePrefs.value = { flags: { surcharges: true } }
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, total: 1, items: [fixture()] },
      failures: [{
        employment_id: 12,
        field: 'surcharge_holiday',
        code: 'input_state_conflict',
        message: 'Zasada svatku neni sjednana.',
        current_row_version: null,
      }],
    }))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-holiday-12"]').setValue('8')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-server-error-holiday-12"]').text())
      .toContain('Zasada svatku neni sjednana.')
  })


  it('dokládá rozpad přesčasu na dosaženou mzdu a příplatek (§ 142 odst. 5 ZP)', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        overtime_amount_minor: 31_250,
        overtime_wage_minor: 25_000,
        overtime_premium_minor: 6_250,
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    // Obě poloviny nároku musí být doložitelné na desktopu i na mobilu.
    expect(wrapper.get('[data-testid="quick-overtime-split-12"]').text())
      .toBe('payroll.quick_inputs.overtime_split')
    expect(wrapper.find('[data-testid="quick-overtime-split-mobile-12"]').exists()).toBe(true)
  })

  it('ukáže rozpad i u náhradního volna, kde je příplatek nulový', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        overtime_amount_minor: 25_000,
        overtime_wage_minor: 25_000,
        overtime_premium_minor: 0,
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-overtime-split-12"]').exists()).toBe(true)
  })

  /*
   * Po importu docházky spravuje přesčas jiný vstup skoro u každého řádku.
   * Věta o tom se dřív opakovala dvakrát na řádek (blokátor u osoby a odstavec
   * pod polem). Teď musí stát JEDNOU nad tabulkou; v řádku zbude jen ikona,
   * jejíž plné vysvětlení je v `title` a `sr-only`, tedy mimo viditelný text.
   */
  it('says "managed by another input" once for N locked rows, not N times', async () => {
    const managed = (id: number, overrides: Partial<PayrollQuickInputRow> = {}) => fixture({
      employment_id: id,
      employee_id: id,
      full_name: `Osoba ${id}`,
      overtime_managed_elsewhere: true,
      blockers: ['overtime_managed_elsewhere'],
      ...overrides,
    })
    m.load.mockImplementation(async period => ({
      period,
      total: 4,
      items: [
        managed(12),
        managed(13, { birth_number_masked: null }),
        managed(14),
        fixture({ employment_id: 15, employee_id: 15, full_name: 'Osoba 15', birth_number_masked: null }),
      ],
    }))
    const wrapper = mountPage()
    await flushPromises()

    const visible = wrapper.element.cloneNode(true) as HTMLElement
    visible.querySelectorAll('.sr-only').forEach(node => node.remove())
    const text = visible.textContent ?? ''
    const occurrences = (pattern: RegExp) => (text.match(pattern) ?? []).length

    expect(wrapper.findAll('[data-testid="quick-managed-summary"]')).toHaveLength(1)
    expect(occurrences(/quick_inputs\.managed_summary(?!_)/g)).toBe(1)
    expect(occurrences(/blockers\.overtime_managed_elsewhere/g)).toBe(0)
    expect(occurrences(/field_state\.managed/g)).toBe(0)
    for (const id of [12, 13, 14]) {
      expect(wrapper.get(`[data-testid="quick-overtime-state-${id}"]`).attributes('title'))
        .toContain('payroll.quick_inputs.field_state.managed')
    }

    const link = wrapper.get('[data-test="quick-managed-summary-link"]')
    expect(JSON.parse(link.attributes('data-to') ?? '{}')).toMatchObject({
      name: 'payroll-components',
      query: { tab: 'inputs', status: 'draft' },
    })

    // Chybějící rodné číslo: jeden souhrn se jmény, v řádcích nic.
    expect(wrapper.findAll('[data-testid="quick-identifier-missing-summary"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="quick-identifier-missing-link-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="quick-identifier-missing-link-15"]').exists()).toBe(true)
    expect(occurrences(/quick_inputs\.identifier_missing(?!_)/g)).toBe(0)

    // Hodiny bez průměru: jedna věta v hlavičce (a jedna pro mobil), ne u řádku.
    expect(wrapper.findAll('[data-testid="quick-hours-unavailable-note"]')).toHaveLength(1)
    expect(occurrences(/quick_inputs\.hours_unavailable(?!_)/g)).toBe(1)
  })

  /*
   * Stránka je stránkovaná serverem, takže hledat musí server. Hledání jde
   * s prodlevou (ne na každé písmeno), drží se v adrese a NESMÍ zahodit
   * rozepsané řádky: uložení je pošle, i když je hledání zrovna nevrací.
   */
  it('searches on the server after a debounce and keeps pending edits', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    try {
      m.load.mockImplementation(async (period, _page, _employment, q) => ({
        period,
        total: 1,
        items: q
          ? [fixture({ employment_id: 13, employee_id: 9, full_name: 'Hledaná osoba' })]
          : [fixture()],
      }))
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.get('[data-testid="quick-bonus-12"]').setValue('900')
      m.load.mockClear()

      await wrapper.get('[data-testid="quick-payroll-search"]').setValue('hledaná')
      expect(m.load).not.toHaveBeenCalled()
      vi.advanceTimersByTime(300)
      await flushPromises()

      expect(m.load).toHaveBeenLastCalledWith(
        expect.any(String), { limit: 25, offset: 0 }, undefined, 'hledaná',
      )
      expect(m.routerReplace).toHaveBeenCalledWith({ query: { q: 'hledaná' } })
      expect(wrapper.find('[data-testid="quick-bonus-12"]').exists()).toBe(false)

      await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
      await flushPromises()
      const [payload, , , q] = m.save.mock.calls[0]
      expect(q).toBe('hledaná')
      const sent = payload.rows as { employment_id: number; bonus_amount_minor: number }[]
      expect(sent.map(row => row.employment_id).sort()).toEqual([12, 13])
      expect(sent.find(row => row.employment_id === 12)?.bonus_amount_minor).toBe(90_000)
    } finally {
      vi.useRealTimers()
    }
  })

  it('names an empty search result and offers the way back', async () => {
    m.routeQuery = { q: 'nikdo' }
    m.load.mockImplementation(async (period, _page, _employment, q) => ({
      period,
      total: q ? 0 : 1,
      items: q ? [] : [fixture()],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(m.load).toHaveBeenLastCalledWith(expect.any(String), { limit: 25, offset: 0 }, undefined, 'nikdo')
    expect(wrapper.get('[data-testid="quick-payroll-search-empty"]').text())
      .toContain('payroll.quick_inputs.search_empty')
    expect(wrapper.text()).not.toContain('payroll.quick_inputs.empty_hint')

    await wrapper.get('[data-testid="quick-payroll-search-clear"]').trigger('click')
    await flushPromises()
    expect(m.load).toHaveBeenLastCalledWith(expect.any(String), { limit: 25, offset: 0 }, undefined, undefined)
    expect(m.routerReplace).toHaveBeenLastCalledWith({ query: {} })
    expect(wrapper.text()).toContain('Syntetická osoba')
  })

  it('u řádku bez přesčasu a u starší odpovědi bez rozpadu mlčí', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture({
          employment_id: 12,
          overtime_amount_minor: 0,
          overtime_wage_minor: 0,
          overtime_premium_minor: 0,
        }),
        fixture({ employment_id: 13, employment_row_version: 4, employment_code: 'SYN-B' }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-overtime-split-12"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-overtime-split-13"]').exists()).toBe(false)
  })

  describe('sloupce mzdových složek', () => {
    const BOZP_COLUMN: PayrollQuickComponentColumn = {
      code: 'PRIPLATEK_BOZP',
      name: 'Příplatek BOZP',
      kind: 'premium',
      unit: 'money',
      editable_in_quick: true,
      rows_with_value: 1,
      counts_in_gross: true,
    }

    function cell(overrides: Partial<PayrollQuickComponentCell> = {}): PayrollQuickComponentCell {
      return {
        amount_minor: 50_000,
        quantity_milliunits: null,
        status: 'draft',
        source: 'import',
        mode: 'import',
        input_id: 501,
        component_id: 77,
        row_version: 3,
        external_id: 'attendance:2026-06:12:PRIPLATEK_BOZP',
        override_of: null,
        input_count: 1,
        has_recurring: false,
        entry_available: true,
        ...overrides,
      }
    }

    function monthWith(components: Record<string, PayrollQuickComponentCell>) {
      m.load.mockImplementation(async (period: string) => ({
        period,
        total: 1,
        items: [fixture({ components })],
        columns: [BOZP_COLUMN],
        totals: {
          rows: 3,
          base_amount_minor: 12_600_000,
          overtime_amount_minor: 0,
          bonus_amount_minor: 0,
          surcharge_amount_minor: 0,
          other_amount_minor: 70_000,
          excluded_from_gross_amount_minor: 0,
          gross_preview_minor: 12_670_000,
          surcharges: {},
          components: { PRIPLATEK_BOZP: { amount_minor: 70_000, quantity_milliunits: null, rows_with_value: 1 } },
        },
      }))
    }

    /*
     * „Zadat i příplatky" ukáže výchozí sloupce (zákonné příplatky, jako
     * dřív) a zároveň nabídne další složky zaškrtávátky. Volba se ukládá do
     * preferencí tabulky pod klíčem `component:<KÓD>`.
     */
    it('offers payroll components from the toggle and remembers the choice', async () => {
      monthWith({})
      const wrapper = mountPage()
      await flushPromises()

      await wrapper.get('[data-testid="quick-surcharges-toggle"]').trigger('click')
      await flushPromises()

      const menu = wrapper.get('[data-testid="quick-columns-menu"]')
      const surcharge = menu.get('[data-testid="quick-columns-option-surcharge:night"] input')
      const component = menu.get('[data-testid="quick-columns-option-component:PRIPLATEK_BOZP"] input')
      expect((surcharge.element as HTMLInputElement).checked).toBe(true)
      expect((component.element as HTMLInputElement).checked).toBe(false)
      expect(wrapper.find('[data-testid="quick-component-head-PRIPLATEK_BOZP"]').exists()).toBe(false)
      expect(menu.text()).toContain('payroll.quick_inputs.component_kinds.premium')

      await component.setValue(true)
      await flushPromises()

      expect(m.patchPrefs).toHaveBeenLastCalledWith('payroll-quick-inputs', {
        hidden: [],
        shown: ['component:PRIPLATEK_BOZP'],
      })
      expect(wrapper.find('[data-testid="quick-component-head-PRIPLATEK_BOZP"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="quick-component-PRIPLATEK_BOZP-12"]').exists()).toBe(true)
    })

    it('full overview preset adds every component with a value', async () => {
      monthWith({})
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.get('[data-testid="quick-columns-menu-toggle"]').trigger('click')
      await flushPromises()

      await wrapper.get('[data-testid="quick-columns-preset-full"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid="quick-component-head-PRIPLATEK_BOZP"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="quick-surcharge-head-night"]').exists()).toBe(true)
    })

    it('edits an imported value as a manual override and sends only the touched cell', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({ PRIPLATEK_BOZP: cell() })
      const wrapper = mountPage()
      await flushPromises()

      const input = wrapper.get('[data-testid="quick-component-PRIPLATEK_BOZP-12"]')
      expect((input.element as HTMLInputElement).value).toBe('500,00')
      const source = wrapper.get('[data-testid="quick-component-source-PRIPLATEK_BOZP-12"]')
      expect(source.attributes('data-source')).toBe('import')

      await input.setValue('700')
      await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
      await flushPromises()

      const row = m.save.mock.calls[0][0].rows[0]
      expect(row.components).toEqual({ PRIPLATEK_BOZP: { amount_minor: 70_000, row_version: 3 } })
    })

    it('does not send component cells nobody touched', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({ PRIPLATEK_BOZP: cell() })
      const wrapper = mountPage()
      await flushPromises()

      await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
      await flushPromises()

      expect(m.save.mock.calls[0][0].rows[0].components).toBeUndefined()
    })

    it('refuses to clear an imported value with an empty field', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({ PRIPLATEK_BOZP: cell() })
      const wrapper = mountPage()
      await flushPromises()

      await wrapper.get('[data-testid="quick-component-PRIPLATEK_BOZP-12"]').setValue('')
      await flushPromises()

      expect(wrapper.text()).toContain('payroll.quick_inputs.component_validation.amount_required')
    })

    it('marks a manual override and reverts it to the imported value on save', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({
        PRIPLATEK_BOZP: cell({
          amount_minor: 70_000,
          mode: 'override',
          source: 'manual',
          external_id: 'override:501',
          input_id: 601,
          row_version: 5,
          override_of: {
            input_id: 501,
            amount_minor: 50_000,
            quantity_milliunits: null,
            external_id: 'attendance:2026-06:12:PRIPLATEK_BOZP',
          },
        }),
      })
      const wrapper = mountPage()
      await flushPromises()

      const source = wrapper.get('[data-testid="quick-component-source-PRIPLATEK_BOZP-12"]')
      expect(source.attributes('data-source')).toBe('override')
      expect(source.attributes('title')).toBe('payroll.quick_inputs.component_cell.override_title')

      await wrapper.get('[data-testid="quick-component-revert-PRIPLATEK_BOZP-12"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-testid="quick-component-revert-pending-PRIPLATEK_BOZP-12"]').exists()).toBe(true)

      await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
      await flushPromises()

      expect(m.save.mock.calls[0][0].rows[0].components).toEqual({
        PRIPLATEK_BOZP: { revert: true, row_version: 5 },
      })
    })

    it('keeps a cell managed elsewhere read-only', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({
        PRIPLATEK_BOZP: cell({ mode: 'managed', source: 'recurring', entry_available: false, input_id: null }),
      })
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.find('[data-testid="quick-component-PRIPLATEK_BOZP-12"]').exists()).toBe(false)
      expect(wrapper.get('[data-testid="quick-component-readonly-PRIPLATEK_BOZP-12"]').attributes('title'))
        .toBe('payroll.quick_inputs.component_cell.managed')
    })

    /** Součtový řádek je za celé období, ne za stránku, a počítá rozepsané změny. */
    it('shows period totals and shifts them by pending edits', async () => {
      m.pagePrefs.value = { flags: { surcharges: true }, shown: ['component:PRIPLATEK_BOZP'] }
      monthWith({ PRIPLATEK_BOZP: cell() })
      const wrapper = mountPage()
      await flushPromises()

      const digits = (selector: string): string =>
        wrapper.get(selector).text().replace(/\D/g, '')
      expect(digits('[data-testid="quick-total-gross"]')).toBe('12670000')
      expect(digits('[data-testid="quick-total-component-PRIPLATEK_BOZP"]')).toBe('70000')

      await wrapper.get('[data-testid="quick-component-PRIPLATEK_BOZP-12"]').setValue('700')
      await flushPromises()

      // 700 Kč − 500 Kč uložených = +200 Kč proti součtu za období.
      expect(digits('[data-testid="quick-total-component-PRIPLATEK_BOZP"]')).toBe('90000')
      expect(digits('[data-testid="quick-total-gross"]')).toBe('12690000')
    })
  })

  describe('uzavřená mzda za měsíc', () => {
    const closedRun = { run_id: 77, status: 'closed' as const, period: '2026-09' }

    it('zamkne uzavřený řádek, ukáže upozornění a neposílá ho k uložení', async () => {
      m.load.mockImplementation(async period => ({
        period,
        items: [
          fixture({ employment_id: 12, closed_run: closedRun }),
          fixture({ employment_id: 13, employee_id: 9, full_name: 'Syntetická osoba 2', closed_run: null }),
        ],
        total: 2,
      }))

      const wrapper = mountPage()
      await flushPromises()

      const notice = wrapper.get('[data-testid="quick-closed-run-notice"]')
      expect(notice.text()).toContain('payroll.quick_inputs.closed_run.title_some')
      expect(wrapper.get('[data-testid="quick-closed-run-open"]').attributes('data-to'))
        .toContain('"name":"payroll-runs"')
      expect(wrapper.find('[data-testid="quick-closed-12"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="quick-closed-13"]').exists()).toBe(false)

      const [closedRow, openRow] = wrapper.get('[data-layout="desktop"]').findAll('tbody tr')
      const closedInputs = closedRow.findAll('input')
      expect(closedInputs.length).toBeGreaterThan(0)
      expect(closedInputs.every(input => input.attributes('disabled') !== undefined)).toBe(true)
      expect(openRow.get('[data-testid="quick-base-13"]').attributes('disabled')).toBeUndefined()

      await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
      await flushPromises()
      expect(m.save).toHaveBeenCalledTimes(1)
      expect(m.save.mock.calls[0][0].rows.map((row: { employment_id: number }) => row.employment_id))
        .toEqual([13])
    })

    it('u celého uzavřeného měsíce zablokuje uložení a řekne proč', async () => {
      m.load.mockImplementation(async period => ({
        period,
        items: [fixture({ closed_run: closedRun })],
        total: 1,
      }))

      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.get('[data-testid="quick-closed-run-notice"]').text())
        .toContain('payroll.quick_inputs.closed_run.title_all')
      expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid="quick-payroll-save-blocked"]').text())
        .toBe('payroll.quick_inputs.closed_run.save_blocked')
    })

    it('bez uzavřeného běhu upozornění neukazuje', async () => {
      const wrapper = mountPage()
      await flushPromises()

      expect(wrapper.find('[data-testid="quick-closed-run-notice"]').exists()).toBe(false)
    })
  })
})

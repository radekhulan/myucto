import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  apply: vi.fn(),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    compensationRecalculationPreview: m.preview,
    applyCompensationRecalculation: m.apply,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      (params ? `${key} ${Object.values(params).join(' ')}` : key),
    locale: ref('cs-CZ'),
  }),
}))

import PayrollCompensationRecalculationDialog from '@/components/payroll/PayrollCompensationRecalculationDialog.vue'

function item(overrides: Record<string, unknown>) {
  return {
    input_id: 101,
    input_status: 'locked',
    period: '2026-01',
    employee_id: 5,
    employment_id: 11,
    full_name: 'Syntetická Brigádnice',
    component_code: 'NAHRADA_MZDY_DOVOLENA',
    component_name: 'Náhrada mzdy za dovolenou',
    kind: 'leave',
    quantity_milliunits: 5000,
    minutes: 300,
    amount_minor: 67_200,
    old_average_hourly_minor: 13_440,
    new_average_hourly_minor: 18_000,
    new_amount_minor: 90_000,
    action: 'recalculate',
    reason: null,
    next_step: null,
    run_id: 7,
    run_status: 'calculated',
    ...overrides,
  }
}

function period(overrides: Record<string, unknown>) {
  return {
    period: '2026-01',
    availability: 'ready',
    reason: null,
    runs: [{ id: 7, status: 'calculated', row_version: 3, availability: 'ready', refresh: true }],
    counts: { recalculate: 2, manual: 1, blocked: 0 },
    difference_minor: 45_600,
    items: [
      item({}),
      item({ input_id: 102, kind: 'import', action: 'replace' }),
      item({ input_id: 103, kind: 'obstacle', action: 'manual', new_amount_minor: null, reason: 'Upravte ručně.' }),
    ],
    ...overrides,
  }
}

const MOUNT = { global: { stubs: { teleport: true, RouterLink: { template: '<a><slot /></a>' } } } }

describe('PayrollCompensationRecalculationDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('ukáže starou a novou částku a přepočítá jen náhrady z náhledu', async () => {
    m.preview.mockResolvedValueOnce({
      periods: [period({}), period({ period: '2026-02', counts: { recalculate: 0, manual: 0, blocked: 0 }, items: [] })],
      can_apply: true,
      can_approve: true,
      can_refresh: true,
    }).mockResolvedValueOnce({ periods: [period({ items: [item({ input_id: 103, action: 'manual', new_amount_minor: null })], counts: { recalculate: 0, manual: 1, blocked: 0 } })], can_apply: true, can_approve: true, can_refresh: true })
    m.apply.mockResolvedValue({
      period: '2026-01',
      recalculated: [{ input_id: 101, new_input_id: 201, new_status: 'approved', employment_id: 11, full_name: 'x', component_name: 'y', amount_minor: 67_200, new_amount_minor: 90_000 }],
      manual: [],
      refreshed_runs: [{ id: 7, status: 'inputs_locked', row_version: 4 }],
    })
    const wrapper = mount(PayrollCompensationRecalculationDialog, { ...MOUNT, props: { periods: ['2026-01', '2026-02'], employmentIds: [11] } })
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(['2026-01', '2026-02'], [11])
    expect(wrapper.findAll('[data-test="compensation-recalculation-item"]')).toHaveLength(3)
    expect(wrapper.find('[data-test="compensation-recalculation-period-2026-02"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Upravte ručně.')
    await wrapper.find('[data-test="compensation-recalculation-confirm"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledTimes(1)
    expect(m.apply.mock.calls[0][0]).toEqual({ period: '2026-01', employment_ids: [11], input_ids: [101, 102] })
    expect(typeof m.apply.mock.calls[0][1]).toBe('string')
    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(wrapper.find('[data-test="compensation-recalculation-result"]').text()).toContain('done_refreshed')
  })

  it('schválený běh nepřepočítá a navede na opravu', async () => {
    m.preview.mockResolvedValue({
      periods: [period({
        availability: 'request_correction',
        reason: 'Mzdový běh je schválený. Zvolte Vyžádat opravu.',
        counts: { recalculate: 0, manual: 0, blocked: 2 },
        items: [item({ action: 'blocked', next_step: 'request_correction' }), item({ input_id: 102, action: 'blocked' })],
      })],
      can_apply: true,
      can_approve: true,
      can_refresh: true,
    })
    const wrapper = mount(PayrollCompensationRecalculationDialog, { ...MOUNT, props: { periods: ['2026-01'] } })
    await flushPromises()

    expect(wrapper.find('[data-test="compensation-recalculation-blocked"]').text()).toContain('Vyžádat opravu')
    expect(wrapper.find('[data-test="compensation-recalculation-confirm"]').exists()).toBe(false)
  })

  it('bez práva obnovit podklady otevřeného běhu přepočet nespustí', async () => {
    m.preview.mockResolvedValue({ periods: [period({})], can_apply: true, can_approve: false, can_refresh: false })
    const wrapper = mount(PayrollCompensationRecalculationDialog, { ...MOUNT, props: { periods: ['2026-01'] } })
    await flushPromises()

    const confirm = wrapper.find('[data-test="compensation-recalculation-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="compensation-recalculation-forbidden"]').text()).toContain('refresh_forbidden')
    expect(wrapper.find('[data-test="compensation-recalculation-drafts"]').exists()).toBe(true)
    await confirm.trigger('click')
    expect(m.apply).not.toHaveBeenCalled()
  })
})

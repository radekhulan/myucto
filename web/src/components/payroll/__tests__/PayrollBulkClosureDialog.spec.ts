import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  bulkClosurePreview: vi.fn(),
  bulkClosureApply: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    bulkClosurePreview: m.bulkClosurePreview,
    bulkClosureApply: m.bulkClosureApply,
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

import PayrollBulkClosureDialog from '@/components/payroll/PayrollBulkClosureDialog.vue'

function item(overrides: Record<string, unknown>) {
  return {
    employee_id: 5,
    full_name: 'Syntetická brigádnice',
    employment_code: 'DPP-1',
    relation_type: 'dpp',
    status: 'active',
    row_version: 1,
    group: 'expired',
    action: 'end',
    start_on: '2026-03-01',
    agreed_end_on: '2026-06-30',
    last_activity_period: null,
    last_settled_period: null,
    proposed_on: '2026-06-30',
    blocked_reason: null,
    blocked_period: null,
    ...overrides,
  }
}

function preview() {
  return {
    as_of: '2026-10-15',
    inactivity_months: 3,
    archive_after_months: 12,
    activity_since: '2026-08-01',
    archive_before: '2025-10-15',
    max_items: 500,
    summary: { expired: 2, inactive: 1, archive: 1, blocked: 1 },
    items: [
      item({ employment_id: 11 }),
      item({ employment_id: 12, full_name: 'Pokračující brigádník', blocked_reason: 'activity_after_end', blocked_period: '2026-07' }),
      item({ employment_id: 13, full_name: 'Nečinná dohoda', relation_type: 'dpc', group: 'inactive', agreed_end_on: null, last_activity_period: '2026-05', proposed_on: '2026-05-31' }),
      item({ employment_id: 14, full_name: 'Dávno skončený', status: 'ended', group: 'archive', action: 'archive', agreed_end_on: '2024-06-30', proposed_on: '2026-10-15', row_version: 3 }),
    ],
  }
}

const MOUNT = { global: { stubs: { teleport: true } } }

describe('PayrollBulkClosureDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('předvybere jen položky bez konfliktu a po potvrzení pošle přechody s daty z náhledu', async () => {
    m.bulkClosurePreview.mockResolvedValue(preview())
    m.bulkClosureApply.mockResolvedValue({
      counts: { applied: 2, failed: 1 },
      applied: [
        { employment_id: 11, status: 'ended', effective_on: '2026-06-30' },
        { employment_id: 14, status: 'archived', effective_on: '2026-10-15' },
      ],
      failed: [{ employment_id: 13, code: 'row_version_conflict', message: 'Vztah mezitím změnil někdo jiný.' }],
    })

    const wrapper = mount(PayrollBulkClosureDialog, MOUNT)
    await flushPromises()

    expect(m.bulkClosurePreview).toHaveBeenCalledWith({ inactivity_months: 3, archive_after_months: 12 })
    expect((wrapper.get('[data-test="bulk-closure-select-11"]').element as HTMLInputElement).checked).toBe(true)
    expect((wrapper.get('[data-test="bulk-closure-select-12"]').element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.get('[data-test="bulk-closure-blocked-12"]').text()).toContain('payroll.bulk_closure.blocked.activity_after_end')

    // Akce se provede až po potvrzení.
    await wrapper.get('[data-test="bulk-closure-apply"]').trigger('click')
    expect(m.bulkClosureApply).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="bulk-closure-confirm"]').text()).toContain('payroll.bulk_closure.confirm_text 2 1')

    await wrapper.get('[data-test="bulk-closure-confirm-apply"]').trigger('click')
    await flushPromises()

    expect(m.bulkClosureApply).toHaveBeenCalledWith([
      { employment_id: 11, action: 'end', effective_on: '2026-06-30', row_version: 1 },
      { employment_id: 13, action: 'end', effective_on: '2026-05-31', row_version: 1 },
      { employment_id: 14, action: 'archive', effective_on: '2026-10-15', row_version: 3 },
    ], { inactivity_months: 3, archive_after_months: 12 })
    expect(wrapper.get('[data-test="bulk-closure-applied"]').text()).toBe('2')
    expect(wrapper.get('[data-test="bulk-closure-failed"]').text()).toContain('Nečinná dohoda')
    expect(wrapper.get('[data-test="bulk-closure-failed"]').text()).toContain('Vztah mezitím změnil někdo jiný.')
    expect(wrapper.emitted('applied')).toHaveLength(1)
  })

  it('bez výběru nedovolí pokračovat', async () => {
    m.bulkClosurePreview.mockResolvedValue(preview())

    const wrapper = mount(PayrollBulkClosureDialog, MOUNT)
    await flushPromises()
    // Skupina s nepředvybraným konfliktem se nejdřív vybere celá, pak odznačí.
    await wrapper.get('[data-test="bulk-closure-toggle-expired"]').setValue(true)
    expect((wrapper.get('[data-test="bulk-closure-select-12"]').element as HTMLInputElement).checked).toBe(true)
    for (const group of ['expired', 'inactive', 'archive']) {
      await wrapper.get(`[data-test="bulk-closure-toggle-${group}"]`).setValue(false)
    }

    expect(wrapper.get('[data-test="bulk-closure-apply"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.bulk_closure.nothing_selected')
  })

  it('změněná délka nečinnosti se pošle do nového náhledu', async () => {
    m.bulkClosurePreview.mockResolvedValue(preview())

    const wrapper = mount(PayrollBulkClosureDialog, MOUNT)
    await flushPromises()
    await wrapper.get('[data-test="bulk-closure-inactivity"]').setValue('6')
    await wrapper.get('[data-test="bulk-closure-reload"]').trigger('click')
    await flushPromises()

    expect(m.bulkClosurePreview).toHaveBeenLastCalledWith({ inactivity_months: 6, archive_after_months: 12 })
  })
})

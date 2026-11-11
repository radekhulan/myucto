import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollDeclarationSignPreview } from '@/api/payroll'

const m = vi.hoisted(() => ({
  declarationSignBulkPreview: vi.fn(),
  declarationSignBulkApply: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    declarationSignBulkPreview: m.declarationSignBulkPreview,
    declarationSignBulkApply: m.declarationSignBulkApply,
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

import DateInput from '@/components/ui/DateInput.vue'
import PayrollDeclarationSignBulkDialog from '@/components/payroll/PayrollDeclarationSignBulkDialog.vue'

const MOUNT = { global: { stubs: { teleport: true } } }

function preview(overrides: Partial<PayrollDeclarationSignPreview> = {}): PayrollDeclarationSignPreview {
  return {
    year: 2026,
    signed_on: '2026-04-10',
    effective_from: '2026-05-01',
    effective_from_basis: 'following_month',
    annual_settlement: false,
    frozen_through: null,
    summary: { people: 4, ready: 2, already_signed: 1, period_frozen: 0, after_year_end: 0, timeline_gap: 1, employee_not_found: 0 },
    ready_employee_ids: [5, 6],
    runs_to_recalculate: [],
    people: [
      { employee_id: 5, full_name: 'Syntetický brigádník', state: 'ready', change: 'add', current_status: null, effective_from: '2026-05-01', effective_from_basis: 'following_month', annual_settlement: false, effective_to: null },
      { employee_id: 6, full_name: 'Druhý brigádník', state: 'ready', change: 'close_previous', current_status: 'not-signed', effective_from: '2026-05-01', effective_from_basis: 'following_month', annual_settlement: false, effective_to: null },
      { employee_id: 7, full_name: 'Podepsaný brigádník', state: 'already_signed', change: null, current_status: 'signed', effective_from: null, effective_from_basis: null, annual_settlement: false, effective_to: null },
      { employee_id: 8, full_name: 'Brigádník s mezerou', state: 'timeline_gap', change: null, current_status: null, effective_from: null, effective_from_basis: null, annual_settlement: false, effective_to: null },
    ],
    ...overrides,
  }
}

describe('PayrollDeclarationSignBulkDialog', () => {
  beforeEach(() => {
    m.declarationSignBulkPreview.mockReset()
    m.declarationSignBulkApply.mockReset()
  })

  it('ukáže upozornění, náhled po osobách a zapíše jen vybrané připravené osoby po potvrzení', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview())
    m.declarationSignBulkApply.mockResolvedValue({
      year: 2026,
      signed_on: '2026-04-10',
      counts: { applied: 1, skipped: 0, failed: 1 },
      applied: [{ employee_id: 5, change: 'add', effective_from: '2026-05-01', effective_from_basis: 'following_month', annual_settlement: false, effective_to: null }],
      skipped: [],
      failed: [{ employee_id: 6, message: 'Syntetická chyba' }],
    })
    const wrapper = mount(PayrollDeclarationSignBulkDialog, MOUNT)
    await flushPromises()

    const warnings = wrapper.get('[data-test="declaration-sign-bulk-warnings"]').text()
    expect(warnings).toContain('payroll.declaration_sign_bulk.warning_one_payer')
    expect(warnings).toContain('payroll.declaration_sign_bulk.warning_responsibility')

    // Výchozí datum podpisu je dnešek a rok je letošní.
    const call = m.declarationSignBulkPreview.mock.calls[0]![0]
    const today = new Date()
    expect(call.year).toBe(today.getFullYear())
    expect(call.signed_on).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(call.employee_ids).toBeNull()

    expect(wrapper.get('[data-test="declaration-sign-bulk-state-7"]').text())
      .toContain('payroll.declaration_sign_bulk.state.already_signed')
    expect(wrapper.get('[data-test="declaration-sign-bulk-pick-7"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="declaration-sign-bulk-pick-8"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="declaration-sign-bulk-state-6"]').text())
      .toContain('payroll.declaration_sign_bulk.change.close_previous payroll.declaration_sign_bulk.status.not-signed')

    const apply = wrapper.get('[data-test="declaration-sign-bulk-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.declaration_sign_bulk.confirm_required')

    await wrapper.get('[data-test="declaration-sign-bulk-pick-6"]').setValue(false)
    await wrapper.get('[data-test="declaration-sign-bulk-confirm"]').setValue(true)
    expect(wrapper.get('[data-test="declaration-sign-bulk-apply"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="declaration-sign-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.declarationSignBulkApply).toHaveBeenCalledWith({
      year: 2026,
      signed_on: '2026-04-10',
      employee_ids: [5],
    })
    expect(wrapper.emitted('applied')).toHaveLength(1)
    expect(wrapper.get('[data-test="declaration-sign-bulk-applied"]').text()).toBe('1')
    expect(wrapper.get('[data-test="declaration-sign-bulk-result-5"]').text()).toContain('Syntetický brigádník')
    expect(wrapper.get('[data-test="declaration-sign-bulk-result-6"]').text()).toContain('Syntetická chyba')
  })

  it('bez zúžení na osoby nepředvybere přepis výslovně nepodepsaného prohlášení', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview())
    const wrapper = mount(PayrollDeclarationSignBulkDialog, MOUNT)
    await flushPromises()

    expect((wrapper.get('[data-test="declaration-sign-bulk-pick-5"]').element as HTMLInputElement).checked).toBe(true)
    expect((wrapper.get('[data-test="declaration-sign-bulk-pick-6"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('zúžení z nálezu kontroly běhu předvybere všechny vyjmenované osoby', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview())
    const wrapper = mount(PayrollDeclarationSignBulkDialog, { ...MOUNT, props: { employeeIds: [5, 6] } })
    await flushPromises()

    expect(m.declarationSignBulkPreview.mock.calls[0]![0].employee_ids).toEqual([5, 6])
    expect((wrapper.get('[data-test="declaration-sign-bulk-pick-6"]').element as HTMLInputElement).checked).toBe(true)
  })

  it('změna roku a data podpisu načte náhled znovu', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview())
    const wrapper = mount(PayrollDeclarationSignBulkDialog, { ...MOUNT, props: { employeeIds: [5, 6] } })
    await flushPromises()

    await wrapper.get('[data-test="declaration-sign-bulk-year"]').setValue('2025')
    await wrapper.get('[data-test="declaration-sign-bulk-year"]').trigger('change')
    await flushPromises()
    await wrapper.get('[data-test="declaration-sign-bulk-signed-on"]').setValue('2025-02-01')
    wrapper.findComponent(DateInput).vm.$emit('change', '2025-02-01')
    await flushPromises()

    expect(m.declarationSignBulkPreview).toHaveBeenLastCalledWith({
      year: 2025,
      signed_on: '2025-02-01',
      employee_ids: [5, 6],
    })
  })

  it('celý rok uzavřený schválenou mzdou nic nenabídne k zápisu', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview({
      effective_from: null,
      effective_from_basis: 'after_frozen_period',
      frozen_through: '2026-12-31',
      summary: { people: 1, ready: 0, already_signed: 0, period_frozen: 1, after_year_end: 0, timeline_gap: 0, employee_not_found: 0 },
      ready_employee_ids: [],
      runs_to_recalculate: [],
      people: [
        { employee_id: 5, full_name: 'Syntetický brigádník', state: 'period_frozen', change: null, current_status: null, effective_from: null, effective_from_basis: null, annual_settlement: false, effective_to: null },
      ],
    }))
    const wrapper = mount(PayrollDeclarationSignBulkDialog, MOUNT)
    await flushPromises()

    expect(wrapper.find('[data-test="declaration-sign-bulk-year-frozen"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="declaration-sign-bulk-apply"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.declaration_sign_bulk.nothing_selected')
    expect(wrapper.find('[data-test="declaration-sign-bulk-confirm"]').exists()).toBe(false)
  })

  it('u osoby ukáže její první měsíc a dorovnání v ročním zúčtování', async () => {
    const base = preview()
    m.declarationSignBulkPreview.mockResolvedValue(preview({
      people: [
        { ...base.people[0]!, effective_from: '2026-06-01', effective_from_basis: 'employment_start' },
        { ...base.people[1]!, effective_from: '2026-02-01', effective_from_basis: 'after_frozen_period', annual_settlement: true },
      ],
    }))
    const wrapper = mount(PayrollDeclarationSignBulkDialog, MOUNT)
    await flushPromises()

    expect(wrapper.get('[data-test="declaration-sign-bulk-state-5"]').text())
      .toContain('payroll.declaration_sign_bulk.person_effective')
    expect(wrapper.get('[data-test="declaration-sign-bulk-state-5"]').text())
      .not.toContain('payroll.declaration_sign_bulk.annual_settlement')
    expect(wrapper.get('[data-test="declaration-sign-bulk-state-6"]').text())
      .toContain('payroll.declaration_sign_bulk.annual_settlement')
  })

  it('vyjmenuje rozpracované mzdy k přepočtu', async () => {
    m.declarationSignBulkPreview.mockResolvedValue(preview({
      runs_to_recalculate: [{ id: 11, period_start: '2026-04-01', status: 'calculated' }],
    }))
    const wrapper = mount(PayrollDeclarationSignBulkDialog, MOUNT)
    await flushPromises()

    expect(wrapper.find('[data-test="declaration-sign-bulk-recalculate"]').exists()).toBe(true)
  })
})

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  healthInsurerBulkPreview: vi.fn(),
  healthInsurerBulkApply: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, warning: m.warning, error: vi.fn() }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    healthInsurerBulkPreview: m.healthInsurerBulkPreview,
    healthInsurerBulkApply: m.healthInsurerBulkApply,
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

import PayrollHealthInsurerBulkDialog from '@/components/payroll/PayrollHealthInsurerBulkDialog.vue'

const MOUNT = { global: { stubs: { teleport: true } } }

describe('PayrollHealthInsurerBulkDialog', () => {
  /*
   * Agentura s DPP: brigádník má pojišťovnu zapsanou s pozdějším vztahem od
   * dubna, únorová dohoda ji nemá. Dialog ji dřív nenabídl a výběr byl prázdný.
   */
  it('předvyplní pojišťovnu, kterou má osoba zapsanou až od pozdějšího měsíce', async () => {
    m.healthInsurerBulkPreview.mockResolvedValue({
      period_start: '2026-02-01',
      people: [
        { employee_id: 17, full_name: 'Syntetický brigádník', suggested_from: '2026-02-01', known_insurer_code: '111', known_from: '2026-04-01' },
        { employee_id: 18, full_name: 'Bez známé pojišťovny', suggested_from: '2026-02-01', known_insurer_code: null, known_from: null },
      ],
    })
    m.healthInsurerBulkApply.mockResolvedValue({ counts: { applied: 1, failed: 0 }, applied: [17], failed: [] })
    const wrapper = mount(PayrollHealthInsurerBulkDialog, { ...MOUNT, props: { periodStart: '2026-02-01' } })
    await flushPromises()

    expect((wrapper.get('[data-test="health-insurer-bulk-code-17"]').element as HTMLSelectElement).value).toBe('111')
    expect((wrapper.get('[data-test="health-insurer-bulk-code-18"]').element as HTMLSelectElement).value).toBe('')
    expect(wrapper.get('[data-test="health-insurer-bulk-known-17"]').text()).toContain('payroll.health_insurer_bulk.known_later 04/2026')
    expect(wrapper.find('[data-test="health-insurer-bulk-known-18"]').exists()).toBe(false)

    await wrapper.get('[data-test="health-insurer-bulk-apply"]').trigger('click')
    await flushPromises()
    expect(m.healthInsurerBulkApply).toHaveBeenCalledWith([
      { employee_id: 17, insurer_code: '111', effective_from: '2026-02-01' },
    ])
  })

  it('vypíše osoby bez pojišťovny, doplní prázdné a uloží jen vybrané od měsíce nástupu', async () => {
    m.healthInsurerBulkPreview.mockResolvedValue({
      period_start: '2026-09-01',
      people: [
        { employee_id: 5, full_name: 'Syntetická osoba', suggested_from: '2026-03-01' },
        { employee_id: 6, full_name: 'Druhá syntetická osoba', suggested_from: '2026-05-01' },
      ],
    })
    m.healthInsurerBulkApply.mockResolvedValue({ counts: { applied: 2, failed: 0 }, applied: [5, 6], failed: [] })
    const wrapper = mount(PayrollHealthInsurerBulkDialog, { ...MOUNT, props: { periodStart: '2026-09-01' } })
    await flushPromises()

    expect(m.healthInsurerBulkPreview).toHaveBeenCalledWith('2026-09-01')
    const apply = wrapper.get('[data-test="health-insurer-bulk-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.health_insurer_bulk.nothing_selected')

    await wrapper.get('[data-test="health-insurer-bulk-code-6"]').setValue('207')
    await wrapper.get('[data-test="health-insurer-bulk-fill-code"]').setValue('111')
    await wrapper.get('[data-test="health-insurer-bulk-fill"]').trigger('click')
    await wrapper.get('[data-test="health-insurer-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.healthInsurerBulkApply).toHaveBeenCalledWith([
      { employee_id: 5, insurer_code: '111', effective_from: '2026-03-01' },
      { employee_id: 6, insurer_code: '207', effective_from: '2026-05-01' },
    ])
    expect(wrapper.emitted('applied')).toHaveLength(1)
    expect(wrapper.find('[data-test="health-insurer-bulk-result"]').exists()).toBe(true)
  })

  it('bez chybějících pojišťoven řekne, že je vše v pořádku', async () => {
    m.healthInsurerBulkPreview.mockResolvedValue({ period_start: '2026-09-01', people: [] })
    const wrapper = mount(PayrollHealthInsurerBulkDialog, { ...MOUNT, props: { periodStart: '2026-09-01' } })
    await flushPromises()

    expect(wrapper.find('[data-test="health-insurer-bulk-empty"]').exists()).toBe(true)
  })

  it('když se nic neuloží, nehlásí úspěch „uložena u 0 osob"', async () => {
    m.success.mockClear()
    m.warning.mockClear()
    m.healthInsurerBulkPreview.mockResolvedValue({
      period_start: '2026-02-01',
      people: [{ employee_id: 5, full_name: 'Syntetická osoba', suggested_from: '2026-02-01', known_insurer_code: '111', known_from: '2026-02-01' }],
    })
    m.healthInsurerBulkApply.mockResolvedValue({
      counts: { applied: 0, failed: 1 }, applied: [], failed: [{ employee_id: 5, message: 'Pojišťovnu se nepodařilo uložit kvůli chybě serveru.' }],
    })
    const wrapper = mount(PayrollHealthInsurerBulkDialog, { ...MOUNT, props: { periodStart: '2026-02-01' } })
    await flushPromises()
    await wrapper.get('[data-test="health-insurer-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.success).not.toHaveBeenCalled()
    expect(m.warning).toHaveBeenCalledWith('payroll.health_insurer_bulk.failed_title 1')
    expect(wrapper.get('[data-test="health-insurer-bulk-result"]').text()).not.toContain('applied_toast')
  })
})

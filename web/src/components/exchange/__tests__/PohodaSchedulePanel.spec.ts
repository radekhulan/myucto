import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key) }),
}))

const m = vi.hoisted(() => ({ get: vi.fn(), run: vi.fn(), save: vi.fn(), canExport: true }))
vi.mock('@/api/reports', () => ({ pohodaScheduleApi: { get: m.get, run: m.run, save: m.save } }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isDemo: false, canWrite: (p: string) => (p === 'utilities.export' ? m.canExport : true) }),
}))

import PohodaSchedulePanel from '@/components/exchange/PohodaSchedulePanel.vue'

describe('PohodaSchedulePanel', () => {
  beforeEach(() => {
    m.canExport = true
    m.get.mockReset().mockResolvedValue({ config: { enabled: true, run_day: 5, include_credit_notes: true, recipient_email: 'u@example.com' }, runs: [], problem: null })
    m.run.mockReset()
  })

  it('tlačítko Spustit teď ukáže jen s právem zápisu do exportů', async () => {
    m.canExport = false
    const wrapper = mount(PohodaSchedulePanel)
    await flushPromises()
    expect(wrapper.find('[data-test="pohoda-run-now"]').exists()).toBe(false)
  })

  it('při chybě běhu ukáže text chyby ze serveru', async () => {
    m.run.mockResolvedValue({ status: 'error', doc_count: 0, error: 'Není vyplněný e-mail účetní.' })
    const wrapper = mount(PohodaSchedulePanel)
    await flushPromises()
    await wrapper.find('[data-test="pohoda-run-now"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Není vyplněný e-mail účetní.')
  })

  it('opakování potvrdí s údajem, kdy a komu se měsíc posílal', async () => {
    const last = { created_at: '2026-11-05 06:30:00', recipient_email: 'u@example.com' }
    m.run.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'already_exported', message: 'x', last } } } })
      .mockResolvedValueOnce({ status: 'ok', doc_count: 3 })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(PohodaSchedulePanel)
    await flushPromises()
    await wrapper.find('[data-test="pohoda-run-now"]').trigger('click')
    await flushPromises()
    expect(confirm.mock.calls[0]![0]).toContain('2026-11-05 06:30:00')
    expect(confirm.mock.calls[0]![0]).toContain('u@example.com')
    expect(m.run).toHaveBeenLastCalledWith(expect.any(String), true)
    expect(wrapper.text()).toContain('pohodaSchedule.run_done')
    confirm.mockRestore()
  })
})

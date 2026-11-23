import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getEntryForm: vi.fn(),
  scheduleEntryForm: vi.fn(),
  listPeriods: vi.fn(),
  success: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  // Chybové kódy vrací „přeložené" (jiné než klíč), ostatní klíč, jako skutečný překladač.
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => key.startsWith('accounting.error.') ? `přeloženo ${key}` : params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatDateTime: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/utils/date', () => ({ appIsoDate: () => '2027-03-15' }))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getEntryForm: m.getEntryForm,
    scheduleEntryForm: m.scheduleEntryForm,
    listPeriods: m.listPeriods,
    previewPairConversion: vi.fn(),
    getPairConversion: vi.fn(),
    cancelPairConversion: vi.fn(),
  },
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /><slot name="footer" /></div>' } }))
vi.mock('@/components/ui/DateInput.vue', () => ({
  default: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-test="date" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
}))
vi.mock('@/components/accounting/PairConversionPreview.vue', () => ({
  default: { props: ['dateFrom', 'dateTo'], emits: ['ready', 'reset'], template: '<div data-test="preview" :data-from="dateFrom" />' },
}))

import EntryFormSettings from '../EntryFormSettings.vue'

const period = { id: 1, fiscal_year: 2027, starts_on: '2027-01-01', ends_on: '2027-12-31', status: 'open' }

beforeEach(() => {
  vi.clearAllMocks()
  m.getEntryForm.mockResolvedValue({ entry_form: 'composite', history: [] })
  m.listPeriods.mockResolvedValue([period])
  m.scheduleEntryForm.mockResolvedValue({ ok: true })
})

describe('EntryFormSettings', () => {
  it('firma bez historie ukáže složenou formu a prázdnou historii', async () => {
    const w = mount(EntryFormSettings, { props: { canChange: true } })
    await flushPromises()
    expect(w.get('[data-test="entry-form-current"]').text()).toBe('accounting.pairs.settings.form_composite')
    expect(w.text()).toContain('accounting.pairs.settings.no_history')
    w.unmount()
  })

  it('ne-admin nevidí tlačítko změny', async () => {
    const w = mount(EntryFormSettings, { props: { canChange: false } })
    await flushPromises()
    expect(w.find('[data-test="entry-form-change"]').exists()).toBe(false)
    w.unmount()
  })

  it('dialog předvyplní první den období a odešle změnu na jednoduchý zápis', async () => {
    const w = mount(EntryFormSettings, { props: { canChange: true } })
    await flushPromises()
    await w.get('[data-test="entry-form-change"]').trigger('click')
    await flushPromises()
    expect((w.get('[data-test="date"]').element as HTMLInputElement).value).toBe('2027-01-01')
    expect((w.get('[data-test="entry-form-select"]').element as HTMLSelectElement).value).toBe('single')
    expect(w.get('[data-test="preview"]').attributes('data-from')).toBe('2027-01-01')
    await w.get('[data-test="entry-form-save"]').trigger('click')
    await flushPromises()
    expect(m.scheduleEntryForm).toHaveBeenCalledWith({ valid_from: '2027-01-01', entry_form: 'single' })
    expect(m.success).toHaveBeenCalled()
    w.unmount()
  })

  it('serverová chyba se ukáže srozumitelně a dialog zůstane otevřený', async () => {
    m.scheduleEntryForm.mockRejectedValue({ response: { data: { error: { code: 'entry_form_date_locked', message: 'x' } } } })
    const w = mount(EntryFormSettings, { props: { canChange: true } })
    await flushPromises()
    await w.get('[data-test="entry-form-change"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="entry-form-save"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="entry-form-error"]').text()).toBe('přeloženo accounting.error.entry_form_date_locked')
    expect(w.find('[data-test="entry-form-save"]').exists()).toBe(true)
    w.unmount()
  })

  it('firma s jednoduchou formou nabídne návrat na složený a ukáže historii', async () => {
    m.getEntryForm.mockResolvedValue({
      entry_form: 'single',
      history: [{ valid_from: '2027-01-01', entry_form: 'single', created_by: 1, created_at: '2026-12-01 10:00:00', note: 'start' }],
    })
    const w = mount(EntryFormSettings, { props: { canChange: true } })
    await flushPromises()
    expect(w.get('[data-test="entry-form-current"]').text()).toBe('accounting.pairs.settings.form_single')
    expect(w.text()).toContain('start')
    await w.get('[data-test="entry-form-change"]').trigger('click')
    await flushPromises()
    expect((w.get('[data-test="entry-form-select"]').element as HTMLSelectElement).value).toBe('composite')
    expect(w.find('[data-test="preview"]').exists()).toBe(false)
    w.unmount()
  })
})

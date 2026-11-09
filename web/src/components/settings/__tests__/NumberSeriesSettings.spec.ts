import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ list: vi.fn(), update: vi.fn(), setDefault: vi.fn() }))

vi.mock('@/api/numberSeries', () => ({
  SELECTABLE_KINDS: ['invoice'],
  numberSeriesApi: { list: m.list, update: m.update, setDefault: m.setDefault, completeness: vi.fn() },
}))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: () => Promise.resolve([]) } }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: unknown) => (p && typeof p === 'object' ? `${key}${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => `CZ(${v})` }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))

import NumberSeriesSettings from '../NumberSeriesSettings.vue'

function series(patch: Record<string, unknown> = {}) {
  return {
    id: 5, doc_kind: 'invoice', group: 'issued', scope_type: 'company', scope_id: 0, scope_name: null,
    storage: 'native', legacy_key: null, name: 'Faktury B', label: '', prefix: 'FB', template: '{PREFIX}{YY}{CCCC}',
    period: 'year', next_preview: 'FB260001', next_number: 1, is_default: false, is_user_default: false, valid_to: null,
    sort_order: 1, selectable: true, can_edit_template: true, can_set_next: true, can_end: true, can_delete: false,
    ...patch,
  }
}

async function mountSettings(rows: unknown[]) {
  m.list.mockResolvedValue(rows)
  const wrapper = mount(NumberSeriesSettings)
  await flushPromises()
  return wrapper
}

describe('NumberSeriesSettings', () => {
  beforeEach(() => vi.clearAllMocks())

  it('má mobilní karty vedle tabulky a datum ukončení česky, ne ISO', async () => {
    const wrapper = await mountSettings([series({ valid_to: '2026-11-08' })])
    expect(wrapper.find('[data-test="series-cards"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="series-table"]').classes()).toContain('hidden')
    const text = wrapper.text()
    expect(text).toContain('CZ(2026-11-08)')
    expect(text).not.toContain('"date":"2026-11-08"')
  })

  it('Ukončit řadu otevře dialog s vysvětlením a ukončí ji až po uložení', async () => {
    m.update.mockResolvedValue(series())
    const wrapper = await mountSettings([series()])
    ;(wrapper.vm as any).openEnd(series())
    await flushPromises()

    expect(document.body.textContent).toContain('number_series.end_hint')
    expect(m.update).not.toHaveBeenCalled()
    const date = document.body.querySelector('[data-test="end-date"]') as HTMLInputElement
    expect(date.value).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    ;(document.body.querySelector('[data-test="end-save"]') as HTMLButtonElement).click()
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(5, { valid_to: date.value })
  })
})

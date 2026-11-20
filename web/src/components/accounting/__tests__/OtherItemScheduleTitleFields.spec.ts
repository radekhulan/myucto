import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ titlePreview: vi.fn() }))

vi.mock('@/api/otherItemPlans', () => ({ otherItemPlansApi: { titlePreview: m.titlePreview } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
    tm: () => [{ c: 'c1', d: 'd1' }],
    rt: (value: string) => `rt:${value}`,
    te: (key: string) => key === 'other_items.plans.title_errors.unknown_title_placeholder'
      || key === 'other_items.plans.title_errors.schedule_finished',
  }),
}))

import OtherItemScheduleTitleFields from '../OtherItemScheduleTitleFields.vue'

type Settings = { title_template: string | null; title_sync_period: boolean }

function mountFields(model: Settings, props: Record<string, unknown> = {}) {
  const wrapper = mount(OtherItemScheduleTitleFields, {
    props: {
      itemId: 9, frequency: 'monthly' as const, modelValue: model,
      'onUpdate:modelValue': (value: Settings) => wrapper.setProps({ modelValue: value }),
      ...props,
    },
  })
  return wrapper
}

describe('OtherItemScheduleTitleFields', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
  })
  afterEach(() => vi.useRealTimers())

  it('náhled počítá server až po pauze v psaní a ukáže název s datem', async () => {
    m.titlePreview.mockResolvedValue({ title: 'Nájem 02/2099', issued_on: '2099-02-28' })
    const wrapper = mountFields({ title_template: null, title_sync_period: false })
    await wrapper.get('[data-test="schedule-title-template"]').setValue('Nájem {MM}')
    await wrapper.get('[data-test="schedule-title-template"]').setValue('Nájem {MM}/{YYYY}')
    expect(m.titlePreview).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(m.titlePreview).toHaveBeenCalledTimes(1)
    expect(m.titlePreview).toHaveBeenCalledWith(9, { title_template: 'Nájem {MM}/{YYYY}', title_sync_period: false, frequency: 'monthly' })
    expect(wrapper.get('[data-test="schedule-title-preview"]').text()).toContain('Nájem 02/2099')
    expect(wrapper.get('[data-test="schedule-title-preview"]').text()).toContain('"date":"2099-02-28"')
  })

  it('prázdné pole uloží null a zaškrtnutí srovnání se propíše do modelu', async () => {
    m.titlePreview.mockResolvedValue({ title: 'Nájem', issued_on: '2099-02-28' })
    const wrapper = mountFields({ title_template: 'X', title_sync_period: false })
    await wrapper.get('[data-test="schedule-title-template"]').setValue('')
    await wrapper.get('[data-test="schedule-title-sync"]').setValue(true)
    expect(wrapper.props('modelValue')).toEqual({ title_template: null, title_sync_period: true })
  })

  it('chyba náhledu se ukáže u pole a pozdní odpověď starší šablony ji nepřepíše', async () => {
    let resolveOld: (v: unknown) => void = () => {}
    m.titlePreview
      .mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.unknown_title_placeholder',
        field: 'title_template', message: 'Česká hláška serveru', tokens: ['{FOO}'] } } } })
    const wrapper = mountFields({ title_template: 'Nájem', title_sync_period: false })
    await vi.advanceTimersByTimeAsync(300)
    await wrapper.get('[data-test="schedule-title-template"]').setValue('Nájem {FOO}')
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    resolveOld({ title: 'Nájem', issued_on: '2099-02-28' })
    await flushPromises()
    // Hláška podle kódu v jazyce UI, s neznámým znakem jako parametrem.
    expect(wrapper.get('[data-test="schedule-title-error"]').text())
      .toBe('other_items.plans.title_errors.unknown_title_placeholder:{"tokens":"{FOO}"}')
    const input = wrapper.get('[data-test="schedule-title-template"]')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toContain(wrapper.get('[data-test="schedule-title-error"]').attributes('id'))
    expect(wrapper.get('[data-test="schedule-title-preview"]').text()).toBe('—')
  })

  it('chyba z uložení se ukáže u pole i bez chyby náhledu', async () => {
    m.titlePreview.mockResolvedValue({ title: 'Nájem', issued_on: '2099-02-28' })
    const wrapper = mountFields({ title_template: null, title_sync_period: true },
      { submitError: { field: 'title_sync_period', message: 'Chyba volby.' } })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-title-sync-error"]').text()).toBe('Chyba volby.')
    expect(wrapper.get('[data-test="schedule-title-sync"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[data-test="schedule-title-error"]').exists()).toBe(false)
  })

  it('chyba náhledu bez pole (síť, skončený rozvrh) je neutrální text v náhledu, ne chyba šablony', async () => {
    m.titlePreview
      .mockRejectedValueOnce({ response: { status: 500 } })
      .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.schedule_finished', message: 'x' } } } })
    const wrapper = mountFields({ title_template: 'Nájem {MM}', title_sync_period: false })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-title-template"]').attributes('aria-invalid')).toBeUndefined()
    expect(wrapper.find('[data-test="schedule-title-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="schedule-title-preview-problem"]').text()).toBe('common.error')
    await wrapper.setProps({ frequency: 'quarterly' })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-title-preview-problem"]').text()).toBe('other_items.plans.title_errors.schedule_finished:{"tokens":""}')
  })

  it('změna četnosti náhled spočítá znovu s novou četností', async () => {
    m.titlePreview.mockResolvedValue({ title: 'Nájem', issued_on: '2099-02-28' })
    const wrapper = mountFields({ title_template: 'Nájem {Q}', title_sync_period: false })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    await wrapper.setProps({ frequency: 'quarterly' })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(m.titlePreview).toHaveBeenCalledTimes(2)
    expect(m.titlePreview).toHaveBeenLastCalledWith(9, expect.objectContaining({ frequency: 'quarterly' }))
    // Konec rozepsaného rozvrhu jde do náhledu (délka se kontroluje jen do něj).
    await wrapper.setProps({ endsOn: '2100-06-30' })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(m.titlePreview).toHaveBeenLastCalledWith(9, expect.objectContaining({ ends_on: '2100-06-30' }))
  })

  it('nápověda je zabalená a rozbalí seznam znaků přes tm/rt', async () => {
    m.titlePreview.mockResolvedValue({ title: 'Nájem', issued_on: '2099-02-28' })
    const wrapper = mountFields({ title_template: null, title_sync_period: false })
    expect(wrapper.find('[data-test="schedule-title-help"]').exists()).toBe(false)
    await wrapper.get('[data-test="schedule-title-help-toggle"]').trigger('click')
    expect(wrapper.get('[data-test="schedule-title-help"]').text()).toContain('rt:c1')
    expect(wrapper.get('[data-test="schedule-title-help"]').text()).toContain('rt:d1')
  })
})

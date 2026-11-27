import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'

const m = vi.hoisted(() => ({
  pages: {} as Record<string, { flags?: Record<string, boolean>; options?: Record<string, unknown> }>,
  patchPagePrefs: vi.fn(),
  patchDisplayPrefs: vi.fn(),
  resetDisplayPrefs: vi.fn(() => Promise.resolve()),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: (key: string) => computed(() => m.pages[key] ?? {}),
  patchPagePrefs: m.patchPagePrefs,
  patchDisplayPrefs: m.patchDisplayPrefs,
  resetDisplayPrefs: m.resetDisplayPrefs,
}))

import DisplayPreferences from '@/pages/DisplayPreferences.vue'
import { setLoadedDisplayPrefs } from '@/composables/useDisplayPrefs'

async function mountPage() {
  const wrapper = mount(DisplayPreferences)
  await flushPromises()
  await new Promise(resolve => setTimeout(resolve, 0))
  await flushPromises()
  return wrapper
}

describe('Předvolby zobrazení', () => {
  beforeEach(() => {
    setLoadedDisplayPrefs({})
    m.pages = {}
    m.patchPagePrefs.mockReset()
    m.patchDisplayPrefs.mockReset()
    m.resetDisplayPrefs.mockClear()
  })

  it('ukáže výchozí stav: Zaúčtování i Dimenze sbalené, náhled vedle formuláře, výkaz v Kč', async () => {
    const wrapper = await mountPage()
    expect(wrapper.get('[data-test="display-prefs-posting_open"]').attributes('aria-checked')).toBe('false')
    expect(wrapper.get('[data-test="display-prefs-dimensions_open"]').attributes('aria-checked')).toBe('false')
    expect(wrapper.get('[data-test="display-prefs-statement_hide_zero"]').attributes('aria-checked')).toBe('true')
    expect(wrapper.get('[data-test="display-prefs-statement_analytics"]').attributes('aria-checked')).toBe('true')
    const placement = wrapper.get('[data-test="display-prefs-preview_placement"]')
    expect(placement.find('[aria-checked="true"]').text()).toBe('display_prefs.preview_placement_side')
    const unit = wrapper.get('[data-test="display-prefs-statement_unit"]')
    expect(unit.find('[aria-checked="true"]').text()).toBe('reports.unit_czk')
    expect(wrapper.get('[data-test="display-prefs-reset"]').attributes('disabled')).toBeDefined()
  })

  it('přepínač rozbalení Zaúčtování a Dimenzí uloží předvolbu', async () => {
    const wrapper = await mountPage()
    await wrapper.get('[data-test="display-prefs-posting_open"]').trigger('click')
    await wrapper.get('[data-test="display-prefs-dimensions_open"]').trigger('click')
    expect(m.patchDisplayPrefs).toHaveBeenNthCalledWith(1, { posting_open: true })
    expect(m.patchDisplayPrefs).toHaveBeenNthCalledWith(2, { dimensions_open: true })
    // Volby detailu se zapamatovaného stavu náhledu v agendách nedotknou.
    expect(m.patchPagePrefs).not.toHaveBeenCalled()
  })

  it('jednotky výkazu se volí záložkami', async () => {
    const wrapper = await mountPage()
    const thousands = wrapper.get('[data-test="display-prefs-statement_unit"]').findAll('button')[1]
    await thousands.trigger('click')
    expect(m.patchDisplayPrefs).toHaveBeenCalledWith({ statement_unit: 'thousands' })
  })

  it('změna náhledu zahodí stav, který si zapamatovaly agendy, ostatní volby stránek nechá', async () => {
    m.pages = {
      purchase_invoices: { flags: { preview_open: false, inbox_preview_open: true }, options: { preview_placement: 'below', preview_width: 40 } },
      invoices: { flags: { preview_open: true } },
    }
    const wrapper = await mountPage()
    await wrapper.get('[data-test="display-prefs-preview_open"]').trigger('click')
    expect(m.patchDisplayPrefs).toHaveBeenCalledWith({ preview_open: true })
    expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { flags: { inbox_preview_open: true } })
    expect(m.patchPagePrefs).toHaveBeenCalledWith('invoices', { flags: {} })
    expect(m.patchPagePrefs).toHaveBeenCalledTimes(2)

    m.patchPagePrefs.mockReset()
    await wrapper.get('[data-test="display-prefs-preview_placement"]').findAll('button')[1].trigger('click')
    expect(m.patchDisplayPrefs).toHaveBeenLastCalledWith({ preview_placement: 'below' })
    expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { options: { preview_width: 40 } })
    expect(m.patchPagePrefs).toHaveBeenCalledTimes(1)
  })

  it('uložená předvolba je vidět a jde vrátit na výchozí', async () => {
    setLoadedDisplayPrefs({ posting_open: true, statement_unit: 'thousands' })
    const wrapper = await mountPage()
    expect(wrapper.get('[data-test="display-prefs-posting_open"]').attributes('aria-checked')).toBe('true')
    const reset = wrapper.get('[data-test="display-prefs-reset"]')
    expect(reset.attributes('disabled')).toBeUndefined()
    await reset.trigger('click')
    expect(m.resetDisplayPrefs).toHaveBeenCalled()
  })
})

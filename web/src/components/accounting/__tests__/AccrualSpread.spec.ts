import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccrualSpreadNote from '../AccrualSpreadNote.vue'
import AccrualSpreadToggle from '../AccrualSpreadToggle.vue'
import type { StatementAccrualSpread } from '@/api/accounting'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { setLoadedDisplayPrefs } from '@/composables/useDisplayPrefs'
import { useAccrualSpread } from '@/composables/useAccrualSpread'

const stored = vi.hoisted(() => ({ flags: {} as Record<string, boolean> }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => ({
    flag: (key: string, fallback = false) => stored.flags[key] ?? fallback,
    setFlag: (key: string, value: boolean) => { stored.flags[key] = value },
  }),
}))

const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })
const format = (value: number) => `${value.toFixed(2)} Kč`
const spread: StatementAccrualSpread = {
  applied: true, skipped_reason: null, prepaid_expense: 6100, deferred_revenue: 24300, documents: 2, depreciation: 0, depreciation_assets: 0, prev: null,
}

describe('průběžné časové rozlišení ve výkazech', () => {
  it('poznámka uvádí částky 381/384 a že úprava není v deníku', () => {
    const wrapper = mount(AccrualSpreadNote, { props: { spread, format }, global: { plugins: [i18n] } })
    const text = wrapper.get('[data-test="accrual-spread-note"]').text()
    expect(text).toContain('6100.00 Kč')
    expect(text).toContain('24300.00 Kč')
    expect(text).toContain('není zaúčtovaná v deníku')
  })

  it('poznámka uvede uplynulé části zápisů s pozdějším datem a dopad do výsledku hospodaření', () => {
    const wrapper = mount(AccrualSpreadNote, {
      props: { spread: { ...spread, vh_effect: 18200, later_dated: { deferred_revenue: 24300, prepaid_expense: 0, depreciation: 1500, entries: 3 } }, format },
      global: { plugins: [i18n] },
    })
    const later = wrapper.get('[data-test="accrual-spread-later-dated"]').text()
    expect(later).toContain('zaúčtovaných s datem po dni výkazu')
    expect(later).toContain('zápisů: 3')
    expect(later).toContain('1500.00 Kč')
    expect(wrapper.get('[data-test="accrual-spread-vh-effect"]').text()).toContain('mění o 18200.00 Kč')
  })

  it('bez zápisů s pozdějším datem větu o nich vynechá', () => {
    const wrapper = mount(AccrualSpreadNote, {
      props: { spread: { ...spread, vh_effect: 0, later_dated: { deferred_revenue: 0, prepaid_expense: 0, depreciation: 0, entries: 0 } }, format },
      global: { plugins: [i18n] },
    })
    expect(wrapper.find('[data-test="accrual-spread-later-dated"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="accrual-spread-vh-effect"]').exists()).toBe(true)
  })

  it('výkaz za hodnotu dimenze hlásí, že volbu neuplatnil', () => {
    const wrapper = mount(AccrualSpreadNote, {
      props: { spread: { ...spread, applied: false, skipped_reason: 'dimension' }, format },
      global: { plugins: [i18n] },
    })
    expect(wrapper.find('[data-test="accrual-spread-note"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="accrual-spread-skipped"]').text()).toContain('dimenze')
  })

  it('přepínač emituje nový stav', async () => {
    const wrapper = mount(AccrualSpreadToggle, { props: { modelValue: false }, global: { plugins: [i18n] } })
    await wrapper.get('input').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })
})

describe('useAccrualSpread.restore', () => {
  beforeEach(() => {
    stored.flags = {}
    setLoadedDisplayPrefs({})
  })

  it('bez předvolby i bez zapamatovaného přepínače zůstane vypnuté', async () => {
    const spreadPref = useAccrualSpread()
    await spreadPref.restore()
    expect(spreadPref.enabled.value).toBe(false)
    expect(spreadPref.param()).toEqual({})
  })

  it('dokud uživatel přepínač nepřepnul, vezme výchozí stav z Předvoleb zobrazení', async () => {
    setLoadedDisplayPrefs({ statement_accrual_spread: true })
    const spreadPref = useAccrualSpread()
    await spreadPref.restore()
    expect(spreadPref.enabled.value).toBe(true)
    expect(spreadPref.param()).toEqual({ accrual_spread: 1 })
  })

  it('zapamatovaný přepínač má přednost před předvolbou', async () => {
    setLoadedDisplayPrefs({ statement_accrual_spread: true })
    stored.flags = { accrual_spread: false }
    const spreadPref = useAccrualSpread()
    await spreadPref.restore()
    expect(spreadPref.enabled.value).toBe(false)
    spreadPref.set(true)
    expect(stored.flags.accrual_spread).toBe(true)
  })
})

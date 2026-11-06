import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AccountingFrameworkState, AccountingProfileRecommendation } from '@/api/settings'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const m = vi.hoisted(() => ({ save: vi.fn(), refresh: vi.fn(), blockDemoMutation: vi.fn(), can: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ refresh: m.refresh, can: m.can }) }))
vi.mock('@/api/settings', () => ({ settingsApi: { updateAccountingFramework: m.save } }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: m.blockDemoMutation }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (date: string) => date }))

import AccountingFrameworkSettings from '../AccountingFrameworkSettings.vue'

const state: AccountingFrameworkState = {
  id: 42,
  accounting_framework: 'cz_500',
  tax_accounting_decree_effective: '500',
  tax_accounting_decree_is_derived: true,
  accounting_framework_history: [{
    id: 7, effective_from: '1900-01-01', accounting_framework: 'cz_500',
    created_at: '2026-01-01 00:00:00', created_by: null,
  }],
}

const routerLinkStub = { props: ['to'], template: '<a data-stub="router-link"><slot /></a>' }

const nonprofit: AccountingProfileRecommendation = { entity_profile: 'nonprofit', accounting_framework: 'cz_504', public_benefit_taxpayer: null }
const svj: AccountingProfileRecommendation = { entity_profile: 'svj', accounting_framework: 'cz_504', public_benefit_taxpayer: null }

function withRecommendation(recommendation: AccountingProfileRecommendation | undefined, id = 42): AccountingFrameworkState {
  return { ...structuredClone(state), id, accounting_profile_recommendation: recommendation }
}

function render(canChange = true, realDateInput = false, supplier: AccountingFrameworkState = structuredClone(state)) {
  return mount(AccountingFrameworkSettings, {
    props: { supplier, canChange },
    global: { stubs: realDateInput ? { RouterLink: routerLinkStub } : { RouterLink: routerLinkStub, DateInput: {
      props: ['modelValue', 'disabled'],
      emits: ['update:modelValue'],
      template: '<input type="date" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  m.can.mockReturnValue(true)
  m.blockDemoMutation.mockReturnValue(false)
  m.refresh.mockResolvedValue(true)
  m.save.mockResolvedValue(structuredClone(state))
})

describe('AccountingFrameworkSettings', () => {
  it('shows history and offers framework 504 for testing', () => {
    const wrapper = render()
    expect(wrapper.text()).toContain('settings.accounting_framework.baseline')
    expect(wrapper.get('option[value="cz_504"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="save-framework"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('saves only the explicit framework and period date for the displayed company', async () => {
    const wrapper = render()
    await wrapper.get('input').setValue('2027-07-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_500', '2027-07-01')
    expect(m.refresh).toHaveBeenCalledOnce()
    expect(wrapper.emitted('updated')).toEqual([[state]])
  })

  it('saves 504 without a testing warning', async () => {
    const wrapper = render()
    await wrapper.get('select').setValue('cz_504')
    expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(false)
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_504', '2027-01-01')
    await wrapper.setProps({ supplier: { ...state, accounting_framework: 'cz_504' }, canChange: false })
    expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(false)
  })

  it('shows the backend refusal without claiming the change was saved', async () => {
    m.save.mockRejectedValue({ response: { data: { error: { message: 'Posted entries prevent the change.' } } } })
    const wrapper = render()
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Posted entries prevent the change.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.emitted('updated')).toBeUndefined()
  })

  it('offers the controlled conversion when the backend refuses a company with history', async () => {
    m.save.mockRejectedValue({ response: { data: { error: {
      code: 'accounting_framework_conversion_required',
      message: 'History requires conversion.',
      conversion_url: '/accounting/framework-conversion',
    } } } })
    const wrapper = render()
    expect(wrapper.find('[data-test="framework-conversion-required-link"]').exists()).toBe(false)
    await wrapper.get('select').setValue('cz_504')
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('History requires conversion.')
    const link = wrapper.get('[data-test="framework-conversion-required-link"]')
    expect(link.text()).toContain('settings.accounting_framework.conversion_required_link')
    expect(wrapper.findAllComponents(routerLinkStub).at(-1)?.props('to')).toEqual({ name: 'accounting-framework-conversion' })
    expect(cs.settings.accounting_framework.conversion_required_link).toBeTruthy()
    expect(en.settings.accounting_framework.conversion_required_link).toBeTruthy()

    m.save.mockRejectedValue({ response: { data: { error: { code: 'accounting_framework_period_start_required', message: 'Period start.' } } } })
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="framework-conversion-required-link"]').exists()).toBe(false)
  })

  it('does not apply a delayed response after switching company', async () => {
    let resolve!: (value: AccountingFrameworkState) => void
    m.save.mockReturnValue(new Promise<AccountingFrameworkState>(r => { resolve = r }))
    const wrapper = render()
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await wrapper.setProps({ supplier: { ...state, id: 43 } })
    resolve(state)
    await flushPromises()
    expect(wrapper.emitted('updated')).toBeUndefined()
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('')
  })

  it('prevents writes when unavailable or in demo mode', async () => {
    const wrapper = render(false)
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    await wrapper.setProps({ canChange: true })
    m.blockDemoMutation.mockReturnValue(true)
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
  })

  it('passes the standard field size and styling to the actual DateInput text input', async () => {
    const wrapper = render(true, true)
    const input = wrapper.get('input[type="text"]')
    const select = wrapper.get('select')
    for (const token of ['w-full', 'h-9', 'px-3', 'border', 'border-neutral-300', 'rounded-md', 'bg-surface', 'text-sm']) {
      expect(input.classes()).toContain(token)
      expect(select.classes()).toContain(token)
    }
    expect(select.classes()).toContain('focus:ring-2')
    expect(select.classes()).toContain('focus:border-primary-500')
    expect(input.classes()).toContain('focus:ring-2')
    expect(input.classes()).toContain('focus:border-primary-500')
    expect(wrapper.get('[data-test="save-framework"]').element.parentElement?.classList.contains('flex-wrap')).toBe(true)
    await wrapper.setProps({ canChange: false })
    expect(input.attributes('disabled')).toBeDefined()
    expect(select.attributes('disabled')).toBeDefined()
  })

  describe('recommendation', () => {
    const recommendation = '[data-test="framework-recommendation"]'
    const apply = '[data-test="apply-recommended-framework"]'

    it('does not recommend without a server recommendation for cz_504', () => {
      const cases: Array<AccountingProfileRecommendation | undefined> = [
        undefined,
        { entity_profile: 'unknown', accounting_framework: null, public_benefit_taxpayer: null },
        { entity_profile: 'unknown', accounting_framework: 'cz_504', public_benefit_taxpayer: null },
        { entity_profile: 'nonprofit', accounting_framework: null, public_benefit_taxpayer: null },
        { entity_profile: 'svj', accounting_framework: null, public_benefit_taxpayer: true },
      ]
      for (const item of cases) {
        const wrapper = render(true, false, withRecommendation(item))
        expect(wrapper.find(recommendation).exists()).toBe(false)
        expect(wrapper.find(apply).exists()).toBe(false)
        expect(wrapper.find('[data-test="framework-conversion-link"]').exists()).toBe(false)
      }
    })

    it('shows distinct text for nonprofit and svj', () => {
      const np = render(true, false, withRecommendation(nonprofit))
      const sv = render(true, false, withRecommendation(svj))
      expect(np.find('[data-test="framework-recommendation-nonprofit"]').text()).toBe('settings.accounting_framework.recommendation.nonprofit')
      expect(sv.find('[data-test="framework-recommendation-svj"]').text()).toBe('settings.accounting_framework.recommendation.svj')
      expect(np.find('[data-test="framework-recommendation-svj"]').exists()).toBe(false)
      expect(sv.find('[data-test="framework-recommendation-nonprofit"]').exists()).toBe(false)
      for (const wrapper of [np, sv]) {
        expect(wrapper.get('[data-test="framework-recommendation-conversion"]').text()).toBe('settings.accounting_framework.recommendation.conversion')
      }
    })

    it('ships distinct, complete cs and en texts in the narrow namespace', () => {
      const csText = cs.settings.accounting_framework.recommendation
      const enText = en.settings.accounting_framework.recommendation
      expect(Object.keys(csText).sort()).toEqual(Object.keys(enText).sort())
      expect(Object.keys(csText).sort()).toEqual(['apply', 'conversion', 'conversion_link', 'nonprofit', 'svj', 'title'])
      for (const texts of [csText, enText]) {
        expect(texts.nonprofit).not.toBe(texts.svj)
        for (const value of Object.values(texts)) expect(value.trim()).not.toBe('')
      }
      expect(csText.nonprofit).toContain('veřejnou prospěšnost')
      expect(enText.nonprofit).toContain('public benefit')
    })

    it('neither selects nor saves the recommended framework on its own', async () => {
      const wrapper = render(true, false, withRecommendation(undefined))
      await wrapper.setProps({ supplier: withRecommendation(nonprofit) })
      await flushPromises()
      expect(wrapper.find(recommendation).exists()).toBe(true)
      expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('cz_500')
      expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="save-framework"]').attributes('disabled')).toBeDefined()
      expect(m.save).not.toHaveBeenCalled()
      expect(m.refresh).not.toHaveBeenCalled()
      expect(wrapper.emitted('updated')).toBeUndefined()
    })

    it('applies the recommendation only locally and still needs a date and a separate save', async () => {
      const wrapper = render(true, false, withRecommendation(svj))
      await wrapper.get(apply).trigger('click')
      expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('cz_504')
      expect(wrapper.find(apply).exists()).toBe(false)
      expect(m.save).not.toHaveBeenCalled()
      expect(wrapper.get('[data-test="save-framework"]').attributes('disabled')).toBeDefined()
      await wrapper.get('[data-test="save-framework"]').trigger('click')
      expect(m.save).not.toHaveBeenCalled()
      await wrapper.get('input').setValue('2027-01-01')
      await wrapper.get('[data-test="save-framework"]').trigger('click')
      await flushPromises()
      expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_504', '2027-01-01')
    })

    it('hides the apply action when read-only, while saving or once 504 is selected', async () => {
      const readOnly = render(false, false, withRecommendation(nonprofit))
      expect(readOnly.find(recommendation).exists()).toBe(true)
      expect(readOnly.find(apply).exists()).toBe(false)

      const wrapper = render(true, false, withRecommendation(nonprofit))
      await wrapper.get('select').setValue('cz_504')
      expect(wrapper.find(apply).exists()).toBe(false)
      await wrapper.get('select').setValue('cz_500')
      expect(wrapper.get(apply).attributes('disabled')).toBeUndefined()

      let resolve!: (value: AccountingFrameworkState) => void
      m.save.mockReturnValue(new Promise<AccountingFrameworkState>(r => { resolve = r }))
      await wrapper.get('input').setValue('2027-01-01')
      await wrapper.get('[data-test="save-framework"]').trigger('click')
      expect(wrapper.get(apply).attributes('disabled')).toBeDefined()
      resolve(state)
      await flushPromises()
    })

    it('does not carry the previous company recommendation or local choice over a company switch', async () => {
      const wrapper = render(true, false, withRecommendation(nonprofit, 42))
      await wrapper.get(apply).trigger('click')
      await wrapper.setProps({ supplier: withRecommendation(undefined, 43) })
      expect(wrapper.find(recommendation).exists()).toBe(false)
      expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('cz_500')
      await wrapper.setProps({ supplier: withRecommendation(svj, 44) })
      expect(wrapper.find('[data-test="framework-recommendation-svj"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="framework-recommendation-nonprofit"]').exists()).toBe(false)
      expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('cz_500')
      expect(m.save).not.toHaveBeenCalled()
    })

    it('links to the framework conversion only with the accounting permission', () => {
      const allowed = render(true, false, withRecommendation(nonprofit))
      expect(m.can).toHaveBeenCalledWith('accounting')
      expect(allowed.findComponent(routerLinkStub).props('to')).toEqual({ name: 'accounting-framework-conversion' })
      expect(allowed.find('[data-test="framework-conversion-link"]').exists()).toBe(true)
      m.can.mockReturnValue(false)
      const denied = render(true, false, withRecommendation(nonprofit))
      expect(denied.find('[data-test="framework-conversion-link"]').exists()).toBe(false)
      expect(denied.find(recommendation).exists()).toBe(true)
    })
  })
})

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import { useModalDraft } from '../useModalDraft'

function host() {
  let draft!: ReturnType<typeof useModalDraft>
  const C = defineComponent({
    setup() {
      draft = useModalDraft()
      return () => h('div', { onInput: draft.mark, onChange: draft.mark }, [
        h('input', { type: 'search', 'data-test': 'search' }),
        h('div', { 'data-draft-ignore': '' }, [h('input', { role: 'combobox', 'data-test': 'rate' })]),
        h('input', { role: 'combobox', 'data-test': 'contact' }),
      ])
    },
  })
  const w = mount(C, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })] } })
  return { w, draft: () => draft }
}

describe('useModalDraft', () => {
  it('nepočítá hledání (pole search, výběr sazby) jako rozepsaný formulář, hledání kontaktu ano', async () => {
    const confirm = vi.spyOn(window, 'confirm')
    const { w, draft } = host()
    await w.get('[data-test="search"]').trigger('input')
    await w.get('[data-test="rate"]').trigger('input')
    expect(draft().confirmClose()).toBe(true)
    expect(confirm).not.toHaveBeenCalled()
    await w.get('[data-test="contact"]').trigger('input')
    confirm.mockReturnValueOnce(false)
    expect(draft().confirmClose()).toBe(false)
    expect(confirm).toHaveBeenCalledWith(cs.nonprofitMembership.closeUnsavedConfirm)
    w.unmount()
  })
})

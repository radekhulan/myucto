import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { defineComponent, h, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

afterEach(() => { document.body.innerHTML = '' })

/** Okno se vnořeným oknem (nový kontakt z okna nového člena) a výběrem uvnitř. */
const Host = defineComponent({
  setup() {
    const outer = ref(true), inner = ref(false)
    return () => h('div', [
      outer.value ? h(Modal, { title: 'Outer', onClose: () => { outer.value = false } }, () => [
        h(SearchableSelect, { modelValue: null, options: [{ value: 'a', label: 'Synthetic A' }] }),
        h('button', { 'data-test': 'open-inner', onClick: () => { inner.value = true } }),
        inner.value ? h(Modal, { title: 'Inner', onClose: () => { inner.value = false } }, () => h('p', 'inner')) : null,
      ]) : null,
    ])
  },
})
const escape = (target: EventTarget = document) => target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
const titles = () => Array.from(document.querySelectorAll('[role="dialog"]')).map(el => el.textContent ?? '')

describe('Modal a Escape', () => {
  it('zavře Escapem jen nejvrchnější okno, rodič s rozepsaným formulářem zůstane', async () => {
    const w = mount(Host, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    ;(document.querySelector('[data-test="open-inner"]') as HTMLButtonElement).click(); await flushPromises()
    expect(titles().some(text => text.includes('Inner'))).toBe(true)
    escape(); await flushPromises()
    expect(titles().some(text => text.includes('Inner'))).toBe(false)
    expect(titles().some(text => text.includes('Outer'))).toBe(true)
    escape(); await flushPromises()
    expect(titles()).toHaveLength(0)
    w.unmount()
  })

  it('Escape v rozbaleném výběru zavře jen výběr, ne okno', async () => {
    const w = mount(Host, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    const input = document.querySelector<HTMLInputElement>('input[role="combobox"]')!
    input.dispatchEvent(new FocusEvent('focus')); await flushPromises()
    expect(input.getAttribute('aria-expanded')).toBe('true')
    escape(input); await flushPromises()
    expect(input.getAttribute('aria-expanded')).toBe('false')
    expect(titles().some(text => text.includes('Outer'))).toBe(true)
    // Zavřený výběr Escape nespotřebuje: další Escape okno zavře.
    escape(input); await flushPromises()
    expect(titles()).toHaveLength(0)
    w.unmount()
  })
})

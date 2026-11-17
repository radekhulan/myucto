import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { defineComponent, h, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogActions from '@/components/ui/DialogActions.vue'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

afterEach(() => { document.body.innerHTML = '' })

const run = vi.fn()
const actions: ActionItem[] = [
  { key: 'confirm', label: 'Synthetic confirm', icon: 'check', tier: 'primary', variant: 'success', disabled: true, disabledReason: 'Synthetic reason', run },
  { key: 'cancel', label: 'Synthetic cancel', icon: 'x', run },
  { key: 'hidden', label: 'Synthetic hidden', show: false, run },
]

describe('DialogActions', () => {
  it('v okně vykreslí tlačítka do patičky: vedlejší akce obrysem, hlavní poslední a plná, důvod zašednutí viditelně', async () => {
    const Host = defineComponent({ setup: () => () => h(Modal, { title: 'Synthetic dialog' }, () => [h('p', 'body'), h(DialogActions, { actions })]) })
    const w = mount(Host, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    const footer = document.querySelector('[role="dialog"] footer') as HTMLElement
    expect(footer.style.display).not.toBe('none')
    const buttons = Array.from(footer.querySelectorAll('button')).map(b => b.getAttribute('data-action'))
    expect(buttons).toEqual(['cancel', 'confirm'])
    expect(footer.querySelector('[data-action="confirm"]')!.className).toContain('bg-success')
    expect((footer.querySelector('[data-action="confirm"]') as HTMLButtonElement).disabled).toBe(true)
    expect(footer.querySelector('[data-test="action-disabled-reason"]')!.textContent).toBe('Synthetic reason')
    ;(footer.querySelector('[data-action="cancel"]') as HTMLButtonElement).click()
    expect(run).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('mimo okno a s inline zůstane na místě; okno bez tlačítek patičku nezobrazí', async () => {
    const inline = mount(DialogActions, { props: { actions } })
    expect(inline.find('[data-action="cancel"]').exists()).toBe(true)
    inline.unmount()
    const show = ref(true)
    const Host = defineComponent({ setup: () => () => h(Modal, { title: 'Synthetic dialog' }, () => [show.value ? h(DialogActions, { actions, inline: true }) : null]) })
    const w = mount(Host, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    const footer = document.querySelector('[role="dialog"] footer') as HTMLElement
    expect(footer.querySelector('button')).toBeNull()
    expect(document.querySelector('[role="dialog"] [data-action="cancel"]')).not.toBeNull()
    show.value = false; await flushPromises()
    expect(footer.style.display).toBe('none')
    w.unmount()
  })
})

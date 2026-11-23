import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { computed, defineComponent, h, provide, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import ActionBar from '@/components/ui/ActionBar.vue'
import { windowContextKey } from '@/workspace/windowContext'
import cs from '@/i18n/cs.json'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import Drawer from '@/components/ui/Drawer.vue'

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

describe('Modal a Escape v pracovní ploše a s nabídkou akcí', () => {
  it('Escape v rozbalené nabídce „…“ zavře jen nabídku, ne okno', async () => {
    const MenuHost = defineComponent({
      setup() {
        const outer = ref(true)
        return () => h('div', outer.value ? [h(Modal, { title: 'Outer', onClose: () => { outer.value = false } }, () =>
          h(ActionBar, { actions: [{ key: 'a', label: 'Synthetic action', tier: 'overflow', run: () => {} }] }))] : [])
      },
    })
    const w = mount(MenuHost, { attachTo: document.body, global: { plugins: [createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs } })] } })
    await flushPromises()
    const trigger = document.querySelector<HTMLButtonElement>('[role="dialog"] button[aria-expanded]')!
    trigger.click(); await flushPromises()
    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    escape(trigger); await flushPromises()
    expect(trigger.getAttribute('aria-expanded')).toBe('false')
    expect(titles().some(text => text.includes('Outer'))).toBe(true)
    w.unmount()
  })

  it('odložené okno pracovní plochy se při Escape nepočítá jako nejvrchnější', async () => {
    // Dvě okna ve stejném panelu; zobrazené je jen jedno (druhé je odložené a zůstává připojené).
    const showingA = ref(true)
    const portal = document.createElement('div'); document.body.appendChild(portal)
    const context = (mine: boolean) => ({
      paneId: computed(() => (showingA.value === mine ? 'pane-1' : null)),
      active: computed(() => showingA.value === mine),
      root: document.body, portal,
    })
    const closed: string[] = []
    const Win = (name: string, mine: boolean) => defineComponent({
      setup() {
        provide(windowContextKey, context(mine) as never)
        return () => h(Modal, { title: name, onClose: () => { closed.push(name) } }, () => h('p', name))
      },
    })
    const A = mount(Win('A', true), { attachTo: document.body, global: { plugins: [createPinia()] } })
    showingA.value = false
    const B = mount(Win('B', false), { attachTo: document.body, global: { plugins: [createPinia()] } })
    // Zpět na A: okno B se odloží i s otevřeným oknem.
    showingA.value = true; await flushPromises()
    escape(); await flushPromises()
    expect(closed).toEqual(['A'])
    A.unmount(); B.unmount()
  })

  it('vnořené okno vykreslené naráz s rodičem zavře Escape jako první', async () => {
    const closed: string[] = []
    const Both = defineComponent({ setup: () => () => h(Modal, { title: 'Parent', onClose: () => closed.push('parent') }, () => [
      h(Modal, { title: 'Child', onClose: () => closed.push('child') }, () => h('p', 'child')),
    ]) })
    const w = mount(Both, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    escape(); await flushPromises()
    expect(closed).toEqual(['child'])
    w.unmount()
  })
})

describe('Drawer a Escape (#394)', () => {
  const DrawerHost = defineComponent({
    setup() {
      const drawer = ref(true), inner = ref(false)
      return () => h('div', [
        drawer.value ? h(Drawer, { title: 'Synthetic drawer', onClose: () => { drawer.value = false } }, () => [
          h('p', { 'data-test': 'drawer-body' }, 'drawer'),
          h(SearchableSelect, { modelValue: null, options: [{ value: 'a', label: 'Synthetic A' }] }),
          h('button', { 'data-test': 'open-inner', onClick: () => { inner.value = true } }),
          inner.value ? h(Modal, { title: 'Inner', onClose: () => { inner.value = false } }, () => h('p', 'inner')) : null,
        ]) : null,
      ])
    },
  })
  const drawerOpen = () => document.querySelector('[data-test="drawer-body"]') !== null

  it('Escape v okně otevřeném z draweru zavře jen okno', async () => {
    const w = mount(DrawerHost, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    ;(document.querySelector('[data-test="open-inner"]') as HTMLButtonElement).click(); await flushPromises()
    escape(); await flushPromises()
    expect(titles().some(text => text.includes('Inner'))).toBe(false)
    expect(drawerOpen()).toBe(true)
    escape(); await flushPromises()
    expect(drawerOpen()).toBe(false)
    w.unmount()
  })

  it('Escape v rozbaleném výběru v draweru zavře jen výběr', async () => {
    const w = mount(DrawerHost, { attachTo: document.body, global: { plugins: [createPinia()] } })
    await flushPromises()
    const input = document.querySelector<HTMLInputElement>('input[role="combobox"]')!
    input.dispatchEvent(new FocusEvent('focus')); await flushPromises()
    escape(input); await flushPromises()
    expect(input.getAttribute('aria-expanded')).toBe('false')
    expect(drawerOpen()).toBe(true)
    w.unmount()
  })
})

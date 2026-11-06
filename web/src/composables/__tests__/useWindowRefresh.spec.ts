import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, defineComponent, h, KeepAlive, provide, ref } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'
import { useWindowRefresh } from '../useWindowRefresh'

vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => ({ supplierId: 123, windowId: 'window-test' }) }))

const wrappers: ReturnType<typeof mount>[] = []
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  document.body.innerHTML = ''
})

function open(activeInitially = true, visible?: { value: boolean }) {
  const active = ref(activeInitially)
  const editing = ref(false)
  const refresh = vi.fn().mockResolvedValue(undefined)
  const draft = ref('unsaved value')
  let pending = ref(false)
  const Child = defineComponent({
    setup() {
      pending = useWindowRefresh(refresh, () => !editing.value).pending
      return () => h('input', { value: draft.value, onInput: (event: Event) => { draft.value = (event.target as HTMLInputElement).value } })
    },
  })
  const wrapper = mount(defineComponent({
    setup() {
      provide(windowContextKey, {
        active: computed(() => active.value), paneId: computed(() => 'primary' as const), root: document.body, portal: document.body,
        ...(visible ? { visible: computed(() => visible.value) } : {}),
      })
      return () => h(Child)
    },
  }), { attachTo: document.body })
  wrappers.push(wrapper)
  return { wrapper, active, editing, refresh, pending, draft }
}

function changed(resource: unknown = 'invoices', supplierId: unknown = 123) {
  window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId, resource } }))
}

describe('retained window data refresh', () => {
  it('coalesces parked changes and refreshes once when the window becomes active', async () => {
    const { active, refresh, pending, wrapper } = open(false)
    await wrapper.find('input').setValue('still editing this draft')
    changed()
    changed('clients')
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    expect(pending.value).toBe(true)
    active.value = true
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(pending.value).toBe(false)
    expect(wrapper.find('input').element.value).toBe('still editing this draft')
  })

  it('ignores another company and malformed messages', async () => {
    const { refresh } = open()
    changed('invoices', 456)
    changed('invoices', '123')
    changed(null)
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: null }))
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    changed('clients')
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('defers refreshing inline editing until its draft is saved or cancelled', async () => {
    const { refresh, editing } = open()
    editing.value = true
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    editing.value = false
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('keeps an open modal intact and refreshes after it closes', async () => {
    const { refresh } = open()
    const dialog = document.createElement('div')
    dialog.setAttribute('role', 'dialog')
    document.body.append(dialog)
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    dialog.remove()
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('serializes another invalidation arriving during refresh', async () => {
    const { refresh } = open()
    let complete!: () => void
    refresh.mockImplementationOnce(() => new Promise<void>(resolve => { complete = resolve }))
    changed()
    changed()
    expect(refresh).toHaveBeenCalledTimes(1)
    complete()
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('refreshes a visible window in a pane that is not active', async () => {
    const visible = ref(true)
    const { refresh } = open(false, visible)
    changed('payroll')
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
    visible.value = false
    changed('payroll')
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
    visible.value = true
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('waits for a modal without a dialog role, typical for this application', async () => {
    const { refresh } = open()
    const modal = document.createElement('div')
    modal.className = 'fixed inset-0 bg-black/40'
    document.body.append(modal)
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    modal.remove()
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('does not refresh when the window is shown again without any change', async () => {
    const { active, refresh } = open(false)
    active.value = true
    await flushPromises()
    active.value = false
    await flushPromises()
    active.value = true
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
  })

  it('observes the DOM only while a refresh waits for a modal', async () => {
    const observe = vi.spyOn(MutationObserver.prototype, 'observe')
    const disconnect = vi.spyOn(MutationObserver.prototype, 'disconnect')
    try {
      const { refresh } = open()
      await flushPromises()
      expect(observe).not.toHaveBeenCalled()
      const dialog = document.createElement('div')
      dialog.setAttribute('aria-modal', 'true')
      document.body.append(dialog)
      changed('payroll')
      await flushPromises()
      expect(observe).toHaveBeenCalledTimes(1)
      dialog.remove()
      await flushPromises()
      expect(refresh).toHaveBeenCalledTimes(1)
      expect(disconnect).toHaveBeenCalled()
    } finally {
      observe.mockRestore()
      disconnect.mockRestore()
    }
  })

  it('waits for KeepAlive activation of a cached tab', async () => {
    const refresh = vi.fn().mockResolvedValue(undefined)
    const shown = ref(true)
    const Tab = defineComponent({
      setup() {
        useWindowRefresh(refresh)
        return () => h('div', 'tab')
      },
    })
    const wrapper = mount(defineComponent({
      setup() {
        provide(windowContextKey, {
          active: computed(() => true), paneId: computed(() => 'primary' as const), root: document.body, portal: document.body,
        })
        return () => h(KeepAlive, null, [shown.value ? h(Tab) : h('span')])
      },
    }), { attachTo: document.body })
    wrappers.push(wrapper)
    await flushPromises()
    shown.value = false
    await flushPromises()
    changed('payroll')
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    shown.value = true
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('stops listening after the retained window is evicted', async () => {
    const { wrapper, refresh } = open()
    wrapper.unmount()
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
  })
})

import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, nextTick, ref, watch } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { provideBusyScope } from '../busy'
import { usePreviewFlow } from '../flows'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(r => { resolve = r })
  return { promise, resolve: (value: T) => resolve(value) }
}

describe('preview flow invalidation', () => {
  it('discards a delayed preview after selection invalidation and stays busy until it settles', async () => {
    const response = deferred<{ preview_hash: string }>()
    const selected = ref(true)
    const supplier = ref<number | null>(7)
    let flow!: ReturnType<typeof usePreviewFlow<{ ids: number[] }, { preview_hash: string }, void>>
    const wrapper = mount(defineComponent({
      setup() {
        provideBusyScope()
        flow = usePreviewFlow({
          supplier: () => supplier.value,
          validate: () => true,
          canPreview: () => true,
          canConfirm: () => true,
          build: () => ({ ids: selected.value ? [11] : [] }),
          preview: () => response.promise,
          confirm: async () => undefined,
        })
        watch(selected, () => flow.invalidate())
        return () => null
      },
    }))

    const pending = flow.showPreview()
    expect(flow.busy.value).toBe(true)
    selected.value = false
    await nextTick()
    expect(flow.preview.value).toBeNull()
    expect(flow.busy.value).toBe(true)

    response.resolve({ preview_hash: 'stale-selection' })
    await pending
    await flushPromises()
    expect(flow.preview.value).toBeNull()
    expect(flow.busy.value).toBe(false)
    wrapper.unmount()
  })
})

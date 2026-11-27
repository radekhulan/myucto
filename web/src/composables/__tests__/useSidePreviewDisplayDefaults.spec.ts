import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, defineComponent, h } from 'vue'

const m = vi.hoisted(() => ({
  page: {} as { flags?: Record<string, boolean>; options?: Record<string, unknown> },
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => computed(() => m.page),
  patchPagePrefs: vi.fn(),
}))

import { useSidePreview, type SidePreviewCtrl } from '@/composables/useSidePreview'
import { setLoadedDisplayPrefs } from '@/composables/useDisplayPrefs'

async function mountPreview(flagKey?: string, options?: { defaultOpen?: boolean }): Promise<SidePreviewCtrl> {
  let ctrl!: SidePreviewCtrl
  mount(defineComponent({
    setup() {
      ctrl = useSidePreview('purchase_invoices', flagKey, options)
      return () => h('div')
    },
  }))
  await flushPromises()
  return ctrl
}

describe('useSidePreview a Předvolby zobrazení', () => {
  beforeEach(() => {
    m.page = {}
    setLoadedDisplayPrefs({})
  })

  it('bez předvolby i bez zapamatované volby je náhled zavřený a vedle formuláře', async () => {
    const ctrl = await mountPreview()
    expect(ctrl.open.value).toBe(false)
    expect(ctrl.placement.value).toBe('side')
  })

  it('bez zapamatované volby agendy platí předvolba: otevřít a pod formulářem', async () => {
    setLoadedDisplayPrefs({ preview_open: true, preview_placement: 'below' })
    const ctrl = await mountPreview()
    expect(ctrl.open.value).toBe(true)
    expect(ctrl.placement.value).toBe('below')
  })

  it('zapamatovaná volba agendy má přednost před předvolbou', async () => {
    setLoadedDisplayPrefs({ preview_open: true, preview_placement: 'below' })
    m.page = { flags: { preview_open: false }, options: { preview_placement: 'side' } }
    const ctrl = await mountPreview()
    expect(ctrl.open.value).toBe(false)
    expect(ctrl.placement.value).toBe('side')
  })

  it('okno s vlastním výchozím stavem (příchozí doklady, kontrola vytěžení) předvolbu neposlouchá', async () => {
    setLoadedDisplayPrefs({ preview_open: false, preview_placement: 'below' })
    const inbox = await mountPreview('inbox_preview_open', { defaultOpen: true })
    expect(inbox.open.value).toBe(true)
    expect(inbox.placement.value).toBe('side')
  })
})

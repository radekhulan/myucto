import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { stubSplitWidth } from './previewSplitTestUtils'

const m = vi.hoisted(() => ({
  prefs: { value: {} as { flags?: Record<string, boolean>; options?: Record<string, unknown> } },
  patchPagePrefs: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => m.prefs,
  patchPagePrefs: m.patchPagePrefs,
}))

import DocumentPreviewSplit from '@/components/documents/DocumentPreviewSplit.vue'
import { useSidePreview } from '@/composables/useSidePreview'

const SIDE = '[data-test="document-side-preview"]'
const SPLITTER = '[data-test="document-preview-splitter"]'
const NOTEBOOK_16 = 976
const MIN_SIDE = 480 + 12 + 352

interface HostProps { src?: string | null; docType?: string; show?: boolean; embedded?: boolean; flagKey?: string }

async function mountSplit(props: HostProps = {}) {
  let ctrl!: ReturnType<typeof useSidePreview>
  const Host = defineComponent({
    setup() {
      ctrl = useSidePreview('purchase_invoices', props.flagKey, { defaultOpen: true })
      return () => h(DocumentPreviewSplit, {
        ctrl,
        show: props.show ?? ctrl.open.value,
        src: props.src === undefined ? '/original.pdf' : props.src,
        docType: props.docType,
        embedded: props.embedded,
      }, { default: () => h('form', { 'data-test': 'form' }) })
    },
  })
  const wrapper = mount(Host)
  await flushPromises()
  return { wrapper, ctrl: () => ctrl }
}

function previewWidth(wrapper: Awaited<ReturnType<typeof mountSplit>>['wrapper']): number {
  const style = wrapper.get(SPLITTER).element.parentElement!.getAttribute('style') ?? ''
  return Number(/width:\s*([\d.]+)px/.exec(style)?.[1]) - 12
}

describe('DocumentPreviewSplit', () => {
  beforeEach(() => {
    m.prefs = { value: {} }
    m.patchPagePrefs.mockReset()
  })
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  describe('co se vejde', () => {
    it('na 16" notebooku je náhled vedle formuláře a Modal od něj odsadí backdrop', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(true)
      expect(wrapper.get(SIDE).attributes('data-side-preview')).toBeDefined()
      expect(wrapper.get('[data-test="document-preview-split"]').classes()).toContain('flex')
      expect(wrapper.findAll('iframe')).toHaveLength(1)
    })

    it('pod minimem se náhled přesune pod formulář a uloženou volbu nemění', async () => {
      stubSplitWidth(MIN_SIDE - 1)
      const { wrapper, ctrl } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(false)
      expect(wrapper.get(SIDE).attributes('data-side-preview')).toBeUndefined()
      expect(ctrl().placement.value).toBe('side')
      expect(m.patchPagePrefs).not.toHaveBeenCalled()
      // Vedle sebe to nejde, přepínač by neměl co nabídnout.
      expect(wrapper.find('[data-test="document-preview-placement-side"]').exists()).toBe(false)
    })

    it('přesně na minimu se ještě vejde vedle formuláře', async () => {
      stubSplitWidth(MIN_SIDE)
      const { wrapper } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(true)
    })

    it('rozhodne změřená šířka, ne okno: reaguje na změnu rozměru panelu', async () => {
      let notify: () => void = () => {}
      vi.stubGlobal('ResizeObserver', class {
        constructor(cb: () => void) { notify = cb }
        observe() {}
        disconnect() {}
      })
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(true)

      vi.restoreAllMocks()
      stubSplitWidth(600)
      notify()
      await flushPromises()
      expect(wrapper.find(SPLITTER).exists()).toBe(false)
      expect(wrapper.findAll('iframe')).toHaveLength(1)
    })

    it('šířka těsně pod minimem rozložení nerozhodí, dokud nepřekročí rezervu', async () => {
      let notify: () => void = () => {}
      vi.stubGlobal('ResizeObserver', class {
        constructor(cb: () => void) { notify = cb }
        observe() {}
        disconnect() {}
      })
      stubSplitWidth(MIN_SIDE)
      const { wrapper } = await mountSplit()

      vi.restoreAllMocks()
      stubSplitWidth(MIN_SIDE - 8)
      notify()
      await flushPromises()
      expect(wrapper.find(SPLITTER).exists()).toBe(true)
    })

    it('skrytý kořen (šířka 0) dokument vůbec nestahuje', async () => {
      stubSplitWidth(0)
      const { wrapper } = await mountSplit()
      expect(wrapper.find('iframe').exists()).toBe(false)
      expect(wrapper.find(SIDE).exists()).toBe(false)
    })

    it('sloupec s formulářem je vedle sebe vlastní kontejner, pod náhledem ne', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper, ctrl } = await mountSplit()
      const column = () => wrapper.get('[data-test="form"]').element.parentElement!
      expect(column().classList.contains('document-preview-split-form')).toBe(true)

      ctrl().setPlacement('below')
      await flushPromises()
      expect(column().classList.contains('document-preview-split-form')).toBe(false)
    })
  })

  describe('přepínač umístění', () => {
    it('přepne pod formulář, uloží volbu a dokument znovu nestahuje', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit()
      const frame = wrapper.get('iframe').element

      await wrapper.get('[data-test="document-preview-placement-below"]').trigger('click')

      expect(wrapper.find(SPLITTER).exists()).toBe(false)
      expect(wrapper.get('iframe').element).toBe(frame)
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', {
        options: { preview_placement: 'below' },
      })
    })

    it('uložené umístění a šířka se po načtení použijí', async () => {
      stubSplitWidth(NOTEBOOK_16)
      m.prefs = { value: { options: { preview_placement: 'below', preview_width: 40 } } }
      const { wrapper, ctrl } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(false)
      expect(ctrl().width.value).toBe(40)
    })

    it('neplatná uložená data se zahodí a platí výchozí stav', async () => {
      stubSplitWidth(NOTEBOOK_16)
      m.prefs = { value: { options: { preview_placement: 'nahoru', preview_width: 'hodně' } } }
      const { wrapper, ctrl } = await mountSplit()
      expect(wrapper.find(SPLITTER).exists()).toBe(true)
      expect(ctrl().width.value).toBeNull()
      expect(previewWidth(wrapper)).toBeCloseTo(NOTEBOOK_16 * 0.46)
    })

    it('okno kontroly vytěžení má rozložení zvlášť od detailu dokladu', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit({ flagKey: 'review_preview_open' })
      await wrapper.get('[data-test="document-preview-placement-below"]').trigger('click')
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', {
        options: { review_preview_placement: 'below' },
      })
    })

    it('úprava rozložení nepřepíše ostatní uložené volby stránky', async () => {
      stubSplitWidth(NOTEBOOK_16)
      m.prefs = { value: { options: { analytics_split: 3 } } }
      const { wrapper } = await mountSplit()
      await wrapper.get('[data-test="document-preview-placement-below"]').trigger('click')
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', {
        options: { analytics_split: 3, preview_placement: 'below' },
      })
    })
  })

  describe('posuvné rozhraní myší', () => {
    it('šířku mění při tažení a uloží ji až po puštění, rám během tažení nepřijímá ukazatel', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)

      await handle.trigger('pointerdown', { button: 0, pointerId: 1, clientX: 540 })
      expect(wrapper.get('iframe').classes()).toContain('pointer-events-none')

      await handle.trigger('pointermove', { clientX: 606 })
      expect(previewWidth(wrapper)).toBe(388)
      expect(m.patchPagePrefs).not.toHaveBeenCalled()

      await handle.trigger('pointerup', { clientX: 606 })
      expect(wrapper.get('iframe').classes()).not.toContain('pointer-events-none')
      expect(m.patchPagePrefs).toHaveBeenCalledTimes(1)
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { options: { preview_width: 38.8 } })
    })

    it('tažení drží min. šířku náhledu i formuláře', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)

      await handle.trigger('pointerdown', { button: 0, pointerId: 1, clientX: 540 })
      await handle.trigger('pointermove', { clientX: 990 })
      expect(previewWidth(wrapper)).toBe(352)
      await handle.trigger('pointermove', { clientX: 10 })
      expect(previewWidth(wrapper)).toBe(1000 - 480 - 12)
    })

    it('pohyb bez stisku ani jiné tlačítko nic nemění', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)
      const before = previewWidth(wrapper)

      await handle.trigger('pointermove', { clientX: 606 })
      await handle.trigger('pointerdown', { button: 2, pointerId: 1, clientX: 540 })
      await handle.trigger('pointermove', { clientX: 606 })
      await handle.trigger('pointerup', { clientX: 606 })

      expect(previewWidth(wrapper)).toBe(before)
      expect(m.patchPagePrefs).not.toHaveBeenCalled()
    })

    it('přerušené tažení se vrátí a neuloží', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)
      const before = previewWidth(wrapper)

      await handle.trigger('pointerdown', { button: 0, pointerId: 1, clientX: 540 })
      await handle.trigger('pointermove', { clientX: 606 })
      await handle.trigger('pointercancel')

      expect(previewWidth(wrapper)).toBe(before)
      expect(m.patchPagePrefs).not.toHaveBeenCalled()
    })

    it('dvojklik vrátí výchozí šířku', async () => {
      stubSplitWidth(1000)
      m.prefs = { value: { options: { preview_width: 38 } } }
      const { wrapper } = await mountSplit()
      await wrapper.get(SPLITTER).trigger('dblclick')
      expect(previewWidth(wrapper)).toBeCloseTo(460)
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { options: { preview_width: null } })
    })
  })

  describe('posuvné rozhraní klávesnicí', () => {
    it('je zaostřitelné a nese hodnoty separátoru podle velikosti formuláře', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)
      expect(handle.attributes('role')).toBe('separator')
      expect(handle.attributes('tabindex')).toBe('0')
      expect(handle.attributes('aria-orientation')).toBe('vertical')
      expect(handle.attributes('aria-valuenow')).toBe('54')
      expect(handle.attributes('aria-valuemin')).toBe('49')
      expect(handle.attributes('aria-valuemax')).toBe('65')
    })

    it('šipky mění šířku po kroku, se Shiftem po větším, a volbu ukládají', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)

      await handle.trigger('keydown', { key: 'ArrowRight' })
      expect(previewWidth(wrapper)).toBeCloseTo(440)
      expect(m.patchPagePrefs).toHaveBeenLastCalledWith('purchase_invoices', { options: { preview_width: 44 } })

      await handle.trigger('keydown', { key: 'ArrowLeft' })
      expect(previewWidth(wrapper)).toBeCloseTo(460)

      await handle.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
      expect(previewWidth(wrapper)).toBeCloseTo(360)
    })

    it('Home a End jdou na meze, šipka za mez nepřejde', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const handle = wrapper.get(SPLITTER)

      await handle.trigger('keydown', { key: 'Home' })
      expect(previewWidth(wrapper)).toBe(508)
      await handle.trigger('keydown', { key: 'ArrowLeft' })
      expect(previewWidth(wrapper)).toBe(508)

      await handle.trigger('keydown', { key: 'End' })
      expect(previewWidth(wrapper)).toBe(352)
      await handle.trigger('keydown', { key: 'ArrowRight' })
      expect(previewWidth(wrapper)).toBe(352)
    })

    it('Enter vrátí výchozí šířku', async () => {
      stubSplitWidth(1000)
      m.prefs = { value: { options: { preview_width: 38 } } }
      const { wrapper } = await mountSplit()
      await wrapper.get(SPLITTER).trigger('keydown', { key: 'Enter' })
      expect(previewWidth(wrapper)).toBeCloseTo(460)
    })

    it('obsloužená klávesa neprobublá k zkratkám stránky, ostatní ano', async () => {
      stubSplitWidth(1000)
      const { wrapper } = await mountSplit()
      const onKey = vi.fn()
      wrapper.element.addEventListener('keydown', onKey)
      const handle = wrapper.get(SPLITTER)

      await handle.trigger('keydown', { key: 'ArrowLeft' })
      expect(onKey).not.toHaveBeenCalled()
      await handle.trigger('keydown', { key: 'Tab' })
      expect(onKey).toHaveBeenCalledTimes(1)
    })

    it('uložená šířka mimo meze aktuálního okna se oříznutím nepřepíše', async () => {
      stubSplitWidth(900)
      m.prefs = { value: { options: { preview_width: 90 } } }
      const { wrapper } = await mountSplit()
      expect(previewWidth(wrapper)).toBe(900 - 480 - 12)
      expect(m.patchPagePrefs).not.toHaveBeenCalled()
    })
  })

  describe('obsah náhledu', () => {
    it('obrázek ukáže přes <img>, ne v rámu', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit({ docType: 'image', src: '/sken.png' })
      expect(wrapper.find('iframe').exists()).toBe(false)
      expect(wrapper.get('img').attributes('src')).toBe('/sken.png')
    })

    it('soubor z paměti jde přes <embed> a bez fragmentu', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit({ src: 'blob:http://localhost/abc' })
      expect(wrapper.find('iframe').exists()).toBe(false)
      expect(wrapper.get('embed').attributes('src')).toBe('blob:http://localhost/abc')
    })

    it('formát bez náhledu ukáže prázdný stav', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit({ docType: 'xml', src: '/doklad.xml' })
      expect(wrapper.find('iframe').exists()).toBe(false)
      expect(wrapper.find('[data-test="document-side-preview-empty"]').exists()).toBe(true)
    })

    it('zavření náhled odstraní a volbu uloží', async () => {
      stubSplitWidth(NOTEBOOK_16)
      const { wrapper } = await mountSplit()
      await wrapper.get('[data-test="document-side-preview-close"]').trigger('click')
      expect(wrapper.find(SIDE).exists()).toBe(false)
      expect(m.patchPagePrefs).toHaveBeenCalledWith('purchase_invoices', { flags: { preview_open: false } })
    })
  })

  describe('dočasné otevření', () => {
    it('otevře náhled proti uložené volbě a nic neuloží, ani když se volba načte později', async () => {
      m.prefs = { value: { flags: { preview_open: false } } }
      stubSplitWidth(NOTEBOOK_16)
      let ctrl!: ReturnType<typeof useSidePreview>
      const Host = defineComponent({
        setup() {
          ctrl = useSidePreview('purchase_invoices')
          ctrl.openTemporarily()
          return () => null
        },
      })
      mount(Host)
      await flushPromises()
      expect(ctrl.open.value).toBe(true)
      expect(m.patchPagePrefs).not.toHaveBeenCalled()
    })
  })
})

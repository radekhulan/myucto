import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import TableViewportScrollbars from './TableViewportScrollbars.vue'
import type { TableViewportScrollBar } from '@/utils/tableViewportScroll'

const mock = vi.hoisted(() => ({ scrollTo: vi.fn(), destroy: vi.fn(), onChange: (_: TableViewportScrollBar[]) => {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: () => 'Scroll table horizontally' }) }))
vi.mock('@/utils/tableViewportScroll', () => ({
  TABLE_VIEWPORT_SCROLLBAR_HEIGHT: 16,
  installTableViewportScroll: ({ onChange }: { onChange: typeof mock.onChange }) => {
    mock.onChange = onChange
    return { scrollTo: mock.scrollTo, destroy: mock.destroy, refresh: vi.fn() }
  },
}))

let wrapper: VueWrapper
const dialogs: HTMLElement[] = []
const bar: TableViewportScrollBar = {
  id: 'table-1', sourceId: 'source-table-1', left: 20, top: 500, width: 200, viewportWidth: 200,
  contentWidth: 1000, maxScrollLeft: 800, scrollLeft: 400, zIndex: 20,
}

function track(): HTMLElement {
  return document.querySelector('[role="scrollbar"]')!
}

async function show(value = bar) {
  mock.onChange([value])
  await nextTick()
}

function pointer(type: string, x: number, options: Partial<PointerEventInit> = {}) {
  track().dispatchEvent(new PointerEvent(type, {
    bubbles: true, cancelable: true, pointerId: 1, isPrimary: true, button: 0, clientX: x, ...options,
  }))
}

beforeEach(() => {
  vi.clearAllMocks()
  wrapper = mount(TableViewportScrollbars)
})

afterEach(() => {
  wrapper.unmount()
  dialogs.splice(0).forEach(dialog => dialog.remove())
  vi.restoreAllMocks()
})

describe('floating horizontal table controls', () => {
  it('renders only overflowing visible tables provided by the controller and mirrors their position', async () => {
    expect(document.querySelector('[role="scrollbar"]')).toBeNull()
    await show()
    expect(track().getAttribute('aria-label')).toBe('Scroll table horizontally')
    expect(track().getAttribute('aria-orientation')).toBe('horizontal')
    expect(track().getAttribute('aria-controls')).toBe('source-table-1')
    expect(track().getAttribute('aria-valuenow')).toBe('400')
    expect(track().getAttribute('aria-valuemax')).toBe('800')
    expect(track().style.top).toBe('500px')
    expect(track().style.left).toBe('20px')
    expect(track().style.width).toBe('200px')
    mock.onChange([{ ...bar, scrollLeft: 700 }])
    await nextTick()
    expect(track().getAttribute('aria-valuenow')).toBe('700')
    mock.onChange([])
    await nextTick()
    expect(document.querySelector('[role="scrollbar"]')).toBeNull()
  })

  it.each([
    ['ArrowLeft', 360], ['ArrowRight', 440], ['PageUp', 200], ['PageDown', 600], ['Home', 0], ['End', 800],
  ])('supports %s without scrolling the document', async (key, position) => {
    await show()
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })
    track().dispatchEvent(event)
    expect(mock.scrollTo).toHaveBeenCalledWith(bar.id, position)
    expect(event.defaultPrevented).toBe(true)
  })

  it('clamps keyboard movement at both ends and leaves unrelated keys available', async () => {
    await show({ ...bar, scrollLeft: 0 })
    track().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }))
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 0)
    await show({ ...bar, scrollLeft: 800 })
    track().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 800)
    const event = new KeyboardEvent('keydown', { key: 'ArrowDown', cancelable: true })
    track().dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    expect(mock.scrollTo).toHaveBeenCalledTimes(2)
  })

  it('drags the thumb to both extremes and releases capture', async () => {
    await show()
    const captured = new Set<number>()
    track().setPointerCapture = vi.fn(id => { captured.add(id) })
    track().hasPointerCapture = id => captured.has(id)
    track().releasePointerCapture = vi.fn(id => { captured.delete(id) })
    vi.spyOn(track(), 'getBoundingClientRect').mockReturnValue({ left: 20 } as DOMRect)
    pointer('pointerdown', 120)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 400)
    pointer('pointermove', 200)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 800)
    pointer('pointermove', 40)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 0)
    pointer('pointerup', 40)
    expect(track().releasePointerCapture).toHaveBeenCalledWith(1)
    mock.scrollTo.mockClear()
    pointer('pointermove', 120)
    expect(mock.scrollTo).not.toHaveBeenCalled()
  })

  it('moves the thumb to track clicks and supports touch pointers', async () => {
    await show()
    track().setPointerCapture = vi.fn()
    track().hasPointerCapture = () => false
    vi.spyOn(track(), 'getBoundingClientRect').mockReturnValue({ left: 20 } as DOMRect)
    pointer('pointerdown', 220, { pointerType: 'touch' })
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 800)
    expect(track()).toBe(document.activeElement)
    pointer('pointercancel', 220)
    pointer('pointerdown', 20, { pointerType: 'touch' })
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 0)
  })

  it('releases a drag when the source table disappears', async () => {
    await show()
    const element = track()
    element.setPointerCapture = vi.fn()
    element.hasPointerCapture = () => true
    element.releasePointerCapture = vi.fn()
    vi.spyOn(element, 'getBoundingClientRect').mockReturnValue({ left: 20 } as DOMRect)
    pointer('pointerdown', 120)
    mock.onChange([])
    await nextTick()
    expect(element.releasePointerCapture).toHaveBeenCalledWith(1)
    expect(document.querySelector('[role="scrollbar"]')).toBeNull()
  })

  it('keeps a modal scrollbar inside its accessible dialog and drags using rendered coordinates', async () => {
    const dialog = document.createElement('div')
    dialog.setAttribute('role', 'dialog')
    dialog.setAttribute('aria-modal', 'true')
    document.body.append(dialog)
    dialogs.push(dialog)
    await show({ ...bar, portalTarget: dialog, left: 10, top: 300 })
    const element = dialog.querySelector<HTMLElement>('[role="scrollbar"]')!
    expect(element).not.toBeNull()
    expect(element.closest('[role="dialog"]')).toBe(dialog)
    expect(element.style.left).toBe('10px')
    expect(element.style.top).toBe('300px')
    element.setPointerCapture = vi.fn()
    element.hasPointerCapture = () => false
    vi.spyOn(element, 'getBoundingClientRect').mockReturnValue({ left: 120 } as DOMRect)
    pointer('pointerdown', 220)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 400)
    pointer('pointermove', 300)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 800)
    pointer('pointercancel', 300)

    await show(bar)
    expect(dialog.querySelector('[role="scrollbar"]')).toBeNull()
    expect(track().parentElement).toBe(document.body)
  })

  it('maps pointer movement through a scaled modal containing block', async () => {
    await show()
    track().setPointerCapture = vi.fn()
    track().hasPointerCapture = () => false
    vi.spyOn(track(), 'getBoundingClientRect').mockReturnValue({ left: 120, width: 400 } as DOMRect)
    pointer('pointerdown', 320)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 400)
    pointer('pointermove', 480)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 800)
    pointer('pointermove', 160)
    expect(mock.scrollTo).toHaveBeenLastCalledWith(bar.id, 0)
  })

  it('destroys document observers and removes controls on unmount', async () => {
    await show()
    wrapper.unmount()
    expect(mock.destroy).toHaveBeenCalledOnce()
    expect(document.querySelector('[role="scrollbar"]')).toBeNull()
  })
})

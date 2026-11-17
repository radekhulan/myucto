import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import FullscreenToggle from '../FullscreenToggle.vue'

const { error } = vi.hoisted(() => ({ error: vi.fn() }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

let element: Element | null
const request = vi.fn(async () => {
  element = document.documentElement
  document.dispatchEvent(new Event('fullscreenchange'))
})
const exit = vi.fn(async () => {
  element = null
  document.dispatchEvent(new Event('fullscreenchange'))
})

beforeEach(() => {
  vi.clearAllMocks()
  element = null
  Object.defineProperties(document, {
    fullscreenElement: { configurable: true, get: () => element },
    fullscreenEnabled: { configurable: true, get: () => true },
    exitFullscreen: { configurable: true, value: exit },
  })
  Object.defineProperty(document.documentElement, 'requestFullscreen', { configurable: true, value: request })
})
afterEach(() => {
  vi.restoreAllMocks()
  Reflect.deleteProperty(document, 'fullscreenElement')
  Reflect.deleteProperty(document, 'fullscreenEnabled')
  Reflect.deleteProperty(document, 'exitFullscreen')
  Reflect.deleteProperty(document.documentElement, 'requestFullscreen')
})

describe('fullscreen v mobilním menu', () => {
  it('přepíná celý dokument a sleduje opuštění přes prohlížeč', async () => {
    const wrapper = mount(FullscreenToggle)
    await wrapper.vm.$nextTick()
    const button = wrapper.get('button')
    await button.trigger('click')
    await flushPromises()
    expect(request).toHaveBeenCalledOnce()
    expect(button.attributes('aria-pressed')).toBe('true')
    expect(button.attributes('aria-label')).toBe('nav.mobile.fullscreen_exit')
    await button.trigger('click')
    await flushPromises()
    expect(exit).toHaveBeenCalledOnce()
    expect(button.attributes('aria-pressed')).toBe('false')
    await button.trigger('click')
    await flushPromises()
    element = null
    document.dispatchEvent(new Event('fullscreenchange'))
    await wrapper.vm.$nextTick()
    expect(button.attributes('aria-pressed')).toBe('false')
    wrapper.unmount()
  })

  it('zakáže tlačítko, když prohlížeč fullscreen nepovoluje', () => {
    vi.spyOn(document, 'fullscreenEnabled', 'get').mockReturnValue(false)
    const wrapper = mount(FullscreenToggle)
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button').attributes('aria-label')).toBe('nav.mobile.fullscreen_unsupported')
    wrapper.unmount()
  })

  it('odmítnutí požadavku zobrazí jako chybu a umožní další pokus', async () => {
    request.mockRejectedValueOnce(new Error('Denied'))
    const wrapper = mount(FullscreenToggle)
    await wrapper.vm.$nextTick()
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(error).toHaveBeenCalledWith('nav.mobile.fullscreen_failed')
    expect(wrapper.get('button').attributes('aria-pressed')).toBe('false')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })
})

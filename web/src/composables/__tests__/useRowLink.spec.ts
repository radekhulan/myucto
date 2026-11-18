import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useRowLink } from '@/composables/useRowLink'
import { windowContextKey, type WindowContext } from '@/workspace/windowContext'

function setup(windowContext?: Partial<WindowContext>) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
  const push = vi.spyOn(router, 'push').mockResolvedValue(undefined)
  let navigate!: ReturnType<typeof useRowLink>
  mount(defineComponent({ setup() { navigate = useRowLink(); return () => h('div') } }), { global: { plugins: [router], provide: windowContext ? { [windowContextKey as symbol]: windowContext } : {} } })
  return { navigate, push }
}

const click = (init: MouseEventInit = {}) => new MouseEvent('click', { button: 0, ...init })

describe('useRowLink', () => {
  let open: MockInstance<typeof window.open>
  beforeEach(() => { open = vi.spyOn(window, 'open').mockReturnValue(null) })
  afterEach(() => { vi.restoreAllMocks() })

  it('navigates in place on a plain click and opens a new panel on Ctrl, ⌘ or the middle button', () => {
    const { navigate, push } = setup()
    navigate('/a', click())
    expect(push).toHaveBeenLastCalledWith('/a')
    expect(open).not.toHaveBeenCalled()
    navigate('/b', click({ ctrlKey: true }))
    navigate('/c', click({ metaKey: true }))
    navigate('/d', new MouseEvent('auxclick', { button: 1 }))
    expect(open.mock.calls.map(c => c[0])).toEqual(['/b', '/c', '/d'])
    expect(push).toHaveBeenCalledTimes(1)
  })

  it('opens a new panel with the newPanel option on a plain click and without an event', () => {
    const { navigate, push } = setup()
    navigate('/a', click(), { newPanel: true })
    navigate('/b', undefined, { newPanel: true })
    expect(open.mock.calls).toEqual([['/a', '_blank'], ['/b', '_blank']])
    expect(push).not.toHaveBeenCalled()
    navigate('/c', click(), { newPanel: false })
    expect(push).toHaveBeenLastCalledWith('/c')
    expect(open).toHaveBeenCalledTimes(2)
  })

  it('prefers the newPanel option over navigating inside a workspace pane', () => {
    const paneNavigate = vi.fn().mockResolvedValue(undefined)
    const { navigate, push } = setup({ navigate: paneNavigate })
    navigate('/a', click())
    expect(paneNavigate).toHaveBeenLastCalledWith('/a')
    navigate('/b', click(), { newPanel: true })
    navigate('/c', undefined, { newPanel: true })
    expect(open.mock.calls.map(c => c[0])).toEqual(['/b', '/c'])
    expect(paneNavigate).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
  })

  it('ignores the right button even with the newPanel option', () => {
    const { navigate, push } = setup()
    navigate('/a', new MouseEvent('auxclick', { button: 2 }))
    navigate('/b', new MouseEvent('auxclick', { button: 2 }), { newPanel: true })
    expect(open).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
    // Ani s modifikátorem: Ctrl + pravé tlačítko jen otevře kontextové menu (#405).
    navigate('/c', new MouseEvent('auxclick', { button: 2, ctrlKey: true }))
    navigate('/d', new MouseEvent('auxclick', { button: 2, metaKey: true }))
    expect(open).not.toHaveBeenCalled()
  })

  it('reports a new panel blocked by the browser (#404)', () => {
    const { navigate } = setup()
    expect(navigate('/a', click({ ctrlKey: true }))).toBe(false)
    expect(navigate('/b', undefined, { newPanel: true })).toBe(false)
    open.mockReturnValue({} as Window)
    expect(navigate('/c', click({ ctrlKey: true }))).toBe(true)
    expect(navigate('/d', click())).toBe(true)
  })
})

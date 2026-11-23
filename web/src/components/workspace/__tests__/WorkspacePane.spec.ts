import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import WorkspacePane from '@/components/workspace/WorkspacePane.vue'
import { i18n } from '@/i18n'
import { useWorkspaceStore } from '@/stores/workspace'
import { destroyPaneRuntimes } from '@/workspace/runtimeRegistry'

describe('WorkspacePane', () => {
  afterEach(() => destroyPaneRuntimes())

  it('externí odkaz v panelu otevře mimo panel a zachová nativní kliknutí', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] })
    await router.push('/')
    await router.isReady()
    useWorkspaceStore().resetLayout(2, '/')
    const wrapper = mount(WorkspacePane, {
      props: { paneId: 'primary', index: 1, globalRouter: router, showHeader: false },
      slots: { default: '<a href="https://public.example.test/payroll/order" rel="nofollow"><span data-test="external-label">Synthetic external order</span></a>' },
      global: { plugins: [pinia, router, i18n] },
    })
    const anchor = wrapper.get('a').element as HTMLAnchorElement
    let preventedInTarget: boolean | undefined
    anchor.addEventListener('click', event => {
      preventedInTarget = event.defaultPrevented
      event.preventDefault()
    })

    wrapper.get('[data-test="external-label"]').element.dispatchEvent(new MouseEvent('click', { button: 0, bubbles: true, cancelable: true }))

    expect(anchor.target).toBe('_blank')
    expect(anchor.rel).toBe('nofollow noopener')
    expect(preventedInTarget).toBe(false)
    expect(router.currentRoute.value.fullPath).toBe('/')
    wrapper.unmount()
  })


  it('neblokuje mezerník ve vnořeném textovém poli', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: { template: '<div />' } }],
    })
    await router.push('/')
    await router.isReady()

    const workspace = useWorkspaceStore()
    workspace.resetLayout(2, '/')
    const wrapper = mount(WorkspacePane, {
      props: {
        paneId: 'primary',
        index: 1,
        globalRouter: router,
        primaryRouter: router,
        showHeader: false,
        single: false,
      },
      slots: { default: '<input data-test="editor">' },
      global: { plugins: [pinia, router, i18n] },
    })

    const event = new KeyboardEvent('keydown', {
      key: ' ',
      code: 'Space',
      bubbles: true,
      cancelable: true,
    })
    wrapper.get('[data-test="editor"]').element.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)

    workspace.activatePane('secondary-2')
    const panelEvent = new KeyboardEvent('keydown', {
      key: ' ',
      code: 'Space',
      bubbles: true,
      cancelable: true,
    })
    wrapper.element.dispatchEvent(panelEvent)

    expect(panelEvent.defaultPrevented).toBe(true)
    expect(workspace.activePaneId).toBe('primary')
    wrapper.unmount()
  })
})

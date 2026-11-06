import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { DphCrossCheckDocument } from '@/api/reports'
import { installShiftClickNavigation } from '@/workspace/frameNavigation'

const m = vi.hoisted(() => ({ canRead: vi.fn(), openBeside: vi.fn(), error: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import DphCrossCheckDocumentLink from '../DphCrossCheckDocumentLink.vue'

const router = createRouter({ history: createMemoryHistory(), routes: [
  { path: '/invoices/:id', name: 'invoice-detail', component: { render: () => null }, meta: { requiresAuth: true } },
  { path: '/purchase-invoices/:id', name: 'purchase-invoice-detail', component: { render: () => null }, meta: { requiresAuth: true } },
  { path: '/accounting/cash', name: 'accounting-cash', component: { render: () => null }, meta: { requiresAuth: true } },
  { path: '/accounting/journal', name: 'accounting-journal', component: { render: () => null }, meta: { requiresAuth: true } },
] })
const show = (document: Partial<DphCrossCheckDocument> = {}, attachTo?: HTMLElement) => mount(DphCrossCheckDocumentLink, {
  props: { document: { invoice_id: 42, source: 'purchase', doc_number: 'TEST-42', declared: 0, counter: -210, difference: -210, ...document } },
  global: { plugins: [router] },
  attachTo,
})
beforeEach(() => {
  vi.clearAllMocks()
  m.canRead.mockReturnValue(true)
  m.openBeside.mockReset().mockResolvedValue(undefined)
})

describe('proklik dokladu z křížové kontroly DPH', () => {
  it.each([
    [{ source: 'sale' }, 'invoices', '/invoices/42'],
    [{ source: 'purchase' }, 'purchase_invoices', '/purchase-invoices/42'],
    [{ source: 'cash' }, 'cash', '/accounting/cash?document_id=42'],
    [{ source: 'manual' }, 'accounting', '/accounting/journal?entry_id=42'],
    [{ source: 'sale', doc_type: 'cash' }, 'cash', '/accounting/cash?document_id=42'],
    [{ source: 'purchase', doc_type: 'journal_entry' }, 'accounting', '/accounting/journal?entry_id=42'],
  ] as const)('otevře správný typ dokladu i při kolizi ID: %j', async (doc, permission, href) => {
    const wrapper = show(doc)
    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe(href)
    expect(link.attributes('data-workspace-open')).toBe('beside')
    expect(link.text()).toBe('TEST-42')
    await link.trigger('click', { button: 0 })
    expect(m.canRead).toHaveBeenCalledWith(permission)
    expect(m.openBeside).toHaveBeenCalledOnce()
    expect(router.resolve(m.openBeside.mock.lastCall![0]).href).toBe(href)
    wrapper.unmount()
  })

  it('Shift klik předá společné navigaci do pravého panelu', async () => {
    const openRight = vi.fn()
    const remove = installShiftClickNavigation(router, () => true, openRight)
    const wrapper = show({}, document.body)
    try {
      const event = new MouseEvent('click', { button: 0, shiftKey: true, bubbles: true, cancelable: true })
      wrapper.get('a').element.dispatchEvent(event)
      await flushPromises()
      expect(event.defaultPrevented).toBe(true)
      expect(openRight).toHaveBeenCalledExactlyOnceWith('/purchase-invoices/42', event)
      expect(m.openBeside).not.toHaveBeenCalled()
    } finally {
      wrapper.unmount()
      remove()
    }
  })

  it.each([{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }])(
    'modifikovaný klik %j nepřepisuje vlastní obsluhou', async options => {
      const wrapper = show()
      const event = new MouseEvent('click', { button: 0, cancelable: true, ...options })
      wrapper.get('a').element.dispatchEvent(event)
      await flushPromises()
      expect(event.defaultPrevented).toBe(false)
      expect(m.openBeside).not.toHaveBeenCalled()
      wrapper.unmount()
    },
  )

  it.each(['sale', 'purchase', 'cash', 'manual'])('bez oprávnění ponechá číslo %s bez odkazu', source => {
    m.canRead.mockReturnValue(false)
    const wrapper = show({ source })
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toBe('TEST-42')
    wrapper.unmount()
  })

  it.each([0, -1, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1])('neplatné ID %s nemá odkaz', invoice_id => {
    const wrapper = show({ invoice_id })
    expect(wrapper.find('a').exists()).toBe(false)
    wrapper.unmount()
  })

  it('neznámý zdroj neodkazuje na přijatou fakturu se stejným ID', () => {
    const wrapper = show({ source: 'unknown' })
    expect(wrapper.find('a').exists()).toBe(false)
    wrapper.unmount()
  })

  it('při chybějícím čísle použije ID a ohlásí chybu navigace', async () => {
    m.openBeside.mockRejectedValueOnce(new Error('navigation failed'))
    const wrapper = show({ doc_number: null })
    expect(wrapper.get('a').text()).toBe('#42')
    await wrapper.get('a').trigger('click', { button: 0 })
    await flushPromises()
    expect(m.error).toHaveBeenCalledExactlyOnceWith('common.error')
    wrapper.unmount()
  })
})

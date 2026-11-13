import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const m = vi.hoisted(() => ({ list: vi.fn() }))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => `${v} Kč`, formatDate: (v: string) => v }))
vi.mock('@/api/documentRequests', () => ({ documentRequestsApi: { list: m.list } }))

import DocumentRequests from '../DocumentRequests.vue'

const items = [
  { id: 1, description: 'Účtenka za parkování', amount: 120, deadline: '2093-01-10', status: 'requested', purchase_invoice_id: null, pi_vendor_invoice_number: null },
  { id: 2, description: 'Faktura za materiál', amount: null, deadline: null, status: 'resolved', purchase_invoice_id: 5, pi_vendor_invoice_number: 'FV-1' },
]

async function mountPage() {
  m.list.mockResolvedValue(items)
  const wrapper = mount(DocumentRequests, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, DateInput: true, EmptyState: true } } })
  await flushPromises()
  return wrapper
}

describe('Žádosti o doklady na telefonu', () => {
  it('mimo telefon drží tabulku', async () => {
    phoneRef.value = false
    const wrapper = await mountPage()
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('[data-test="document-requests-cards"]').exists()).toBe(false)
  })

  it('na telefonu ukáže karty se stavovým čipem', async () => {
    phoneRef.value = true
    const wrapper = await mountPage()
    expect(wrapper.find('table').exists()).toBe(false)
    const cards = wrapper.findAll('[data-test="document-request-card"]')
    expect(cards).toHaveLength(2)
    expect(cards[0]!.text()).toContain('document_requests.status_requested')
    expect(cards[0]!.text()).toContain('document_requests.resolve')
    expect(cards[1]!.text()).toContain('document_requests.status_resolved')
    expect(cards[1]!.text()).toContain('FV-1')
  })
})

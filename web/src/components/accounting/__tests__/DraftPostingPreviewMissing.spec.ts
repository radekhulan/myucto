import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ draftPostingPreview: vi.fn() }))

vi.mock('@/api/accounting', () => ({
  accountingApi: { draftPostingPreview: m.draftPostingPreview },
  postingErrorMessage: () => 'error',
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, string>) => params ? `${key}|${Object.values(params).join('|')}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (amount: number) => amount.toFixed(2) }))

import DraftPostingPreviewPanel from '../DraftPostingPreviewPanel.vue'

describe('DraftPostingPreviewPanel: co chybí k náhledu', () => {
  it('nevyplněný doklad vyjmenuje chybějící údaje', () => {
    const wrapper = mount(DraftPostingPreviewPanel, {
      props: { source: 'purchase-invoices', docId: null, payload: null, missing: ['partner', 'number'] },
    })
    const text = wrapper.find('[data-test="draft-posting-missing"]').text()
    expect(text).toContain('accounting.editor_posting.missing')
    expect(text).toContain('accounting.editor_posting.missing_partner')
    expect(text).toContain('accounting.editor_posting.missing_number')
  })

  it('validační chyba serveru ukáže jeho text místo obecné věty', async () => {
    vi.useFakeTimers()
    m.draftPostingPreview.mockRejectedValue({ response: { status: 422, data: { error: { code: 'validation_failed', message: 'Chybí datum zdanitelného plnění.' } } } })
    const wrapper = mount(DraftPostingPreviewPanel, {
      props: { source: 'purchase-invoices', docId: null, payload: { items: [] } },
    })
    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()
    expect(wrapper.find('[data-test="draft-posting-invalid"]').text()).toContain('Chybí datum zdanitelného plnění.')
    vi.useRealTimers()
  })
})

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AttachmentCheckResult } from '@/api/attachmentChecks'

const m = vi.hoisted(() => ({ forEntity: vi.fn(), acknowledge: vi.fn() }))

vi.mock('@/api/attachmentChecks', () => ({
  attachmentChecksApi: { forEntity: m.forEntity, acknowledge: m.acknowledge },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key), locale: { value: 'cs' } }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'chyba' }))
vi.mock('@/components/ui/Modal.vue', () => ({
  default: { name: 'Modal', props: ['title', 'widthClass'], emits: ['close'], template: '<div class="modal"><slot /></div>' },
}))

import AttachmentCheckBadge from '@/components/documents/AttachmentCheckBadge.vue'

function result(state: AttachmentCheckResult['summary']['state'], rows: Partial<AttachmentCheckResult['rows'][number]>[] = []): AttachmentCheckResult {
  const base = {
    entity_type: 'purchase_invoice' as const, entity_id: 7, sha256: 'a'.repeat(64), document_id: 3, document_name: 'sken.pdf',
    doc_no: 'FA-2091-1', partner_name: 'Syntetický dodavatel', amount: 1210, currency: 'CZK',
    doc_tax_date: '2091-04-01', attachment_tax_date: '2091-03-31', status: 'mismatch' as const, severity: 'warning' as const,
    findings: [{ field: 'tax_date_period' as const, severity: 'warning' as const, doc: '2091-04-01', attachment: '2091-03-31' }],
    acknowledged: false, open: true, ack_reason: null, ack_at: null,
  }
  const full = rows.map(r => ({ ...base, ...r }))
  return { rows: full, summary: { state, open: full.filter(r => r.open).length, compared: full.length } }
}

async function mountBadge() {
  const wrapper = mount(AttachmentCheckBadge, { props: { entityType: 'purchase_invoice', entityId: 7 } })
  await flushPromises()
  return wrapper
}

describe('AttachmentCheckBadge', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('doklad bez vytěžené přílohy odznak nezobrazí', async () => {
    m.forEntity.mockResolvedValue(result('none'))
    const wrapper = await mountBadge()
    expect(m.forEntity).toHaveBeenCalledWith('purchase_invoice', 7)
    expect(wrapper.find('[data-testid="attachment-check-badge"]').exists()).toBe(false)
  })

  it('rozpor DUZP ukáže varovný odznak s počtem', async () => {
    m.forEntity.mockResolvedValue(result('warning', [{}]))
    const badge = (await mountBadge()).find('[data-testid="attachment-check-badge"]')
    expect(badge.exists()).toBe(true)
    expect(badge.attributes('data-state')).toBe('warning')
    expect(badge.text()).toBe('attachment_check.badge_warning:{"n":1}')
    expect(badge.classes()).toContain('bg-warning-50')
  })

  it('shoda a potvrzený rozdíl mají vlastní stav', async () => {
    m.forEntity.mockResolvedValue(result('ok', [{ status: 'match', severity: null, findings: [], open: false }]))
    expect((await mountBadge()).find('[data-testid="attachment-check-badge"]').text()).toBe('attachment_check.badge_ok')

    m.forEntity.mockResolvedValue(result('acknowledged', [{ acknowledged: true, open: false, ack_reason: 'Dodací list' }]))
    const badge = (await mountBadge()).find('[data-testid="attachment-check-badge"]')
    expect(badge.attributes('data-state')).toBe('acknowledged')
    expect(badge.text()).toBe('attachment_check.badge_acknowledged')
  })

  it('chyba API odznak jen skryje', async () => {
    m.forEntity.mockRejectedValue(new Error('500'))
    expect((await mountBadge()).find('[data-testid="attachment-check-badge"]').exists()).toBe(false)
  })

  it('klik otevře porovnání a potvrzení s důvodem odešle otisk přílohy', async () => {
    m.forEntity.mockResolvedValue(result('warning', [{}]))
    m.acknowledge.mockResolvedValue(result('acknowledged', [{ acknowledged: true, open: false, ack_reason: 'Dodací list' }]))
    const wrapper = await mountBadge()

    await wrapper.find('[data-testid="attachment-check-badge"]').trigger('click')
    const review = wrapper.find('[data-testid="attachment-check-review"]')
    expect(review.exists()).toBe(true)
    expect(review.text()).toContain('attachment_check.field.tax_date_period')
    expect(review.text()).toContain('attachment_check.vat_hint')

    // Bez důvodu se nic neodešle.
    await wrapper.find('[data-testid="attachment-check-acknowledge"]').trigger('click')
    expect(m.acknowledge).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="attachment-check-reason"]').setValue('Dodací list')
    await wrapper.find('[data-testid="attachment-check-acknowledge"]').trigger('click')
    await flushPromises()
    expect(m.acknowledge).toHaveBeenCalledWith('purchase_invoice', 7, 'a'.repeat(64), 'Dodací list')
    expect(wrapper.find('[data-testid="attachment-check-badge"]').attributes('data-state')).toBe('acknowledged')
  })
})

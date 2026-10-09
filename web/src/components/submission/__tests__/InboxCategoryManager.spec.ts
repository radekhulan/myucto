import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  inboxCategories: vi.fn(),
  renameInboxCategory: vi.fn(),
  updateInboxRule: vi.fn(),
  createInboxRule: vi.fn(),
  createInboxCategory: vi.fn(),
  deleteInboxCategory: vi.fn(),
  deleteInboxRule: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({ dataBoxApi: m }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

import InboxCategoryManager from '../InboxCategoryManager.vue'

const overview = {
  categories: [
    { id: 1, code: 'tax_office', name: null, sort_order: 10, is_system: true },
    { id: 2, code: null, name: 'Stavební řízení', sort_order: 100, is_system: false },
  ],
  rules: [
    { id: 11, category_id: 1, match_field: 'sender_box', pattern: 'tst0001', origin: 'auto', created_by: null, created_at: '2026-10-01' },
  ],
}

function mountManager() {
  return mount(InboxCategoryManager, {
    props: { senders: [{ box_id: 'tst0001', name: 'Finanční úřad pro Testov' }] },
    global: { stubs: { teleport: true } },
  })
}

describe('InboxCategoryManager', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.inboxCategories.mockResolvedValue(structuredClone(overview))
    m.renameInboxCategory.mockResolvedValue(structuredClone(overview))
    m.updateInboxRule.mockResolvedValue(structuredClone(overview))
    m.createInboxRule.mockResolvedValue(structuredClone(overview))
  })

  it('úpravy sbírá a uloží je jedním tlačítkem', async () => {
    const wrapper = mountManager()
    await flushPromises()

    expect(wrapper.get('[data-test="inbox-manager-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="inbox-manager-rule"]').text()).toContain('Finanční úřad pro Testov (tst0001)')

    await wrapper.findAll('[data-test="inbox-manager-category"] input')[0].setValue('Finanční úřad')
    await wrapper.get('[data-test="inbox-manager-rule"] select').setValue('2')
    expect(wrapper.text()).toContain('databox.inboxBrowse.manager.unsaved')

    await wrapper.get('[data-test="inbox-manager-save"]').trigger('click')
    await flushPromises()

    expect(m.renameInboxCategory).toHaveBeenCalledTimes(1)
    expect(m.renameInboxCategory).toHaveBeenCalledWith(1, 'Finanční úřad')
    expect(m.updateInboxRule).toHaveBeenCalledWith(11, 2)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('nové pravidlo založí hned a vyčistí formulář', async () => {
    const wrapper = mountManager()
    await flushPromises()

    const form = wrapper.findAll('form')[1]
    await form.findAll('select')[0].setValue('subject')
    await wrapper.get('[data-test="inbox-manager-rule-pattern"]').setValue('výzva')
    await form.findAll('select')[1].setValue('2')
    await form.trigger('submit')
    await flushPromises()

    expect(m.createInboxRule).toHaveBeenCalledWith(2, 'subject', 'výzva')
    expect((wrapper.get('[data-test="inbox-manager-rule-pattern"]').element as HTMLInputElement).value).toBe('')
  })
})

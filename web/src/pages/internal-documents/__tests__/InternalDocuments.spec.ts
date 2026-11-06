import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  remove: vi.fn(),
  canWrite: vi.fn(),
  download: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/internalDocuments', async () => {
  const actual = await vi.importActual<typeof import('@/api/internalDocuments')>('@/api/internalDocuments')
  return {
    ...actual,
    internalDocumentsApi: { ...actual.internalDocumentsApi, list: m.list, remove: m.remove },
  }
})
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number) => String(value),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import InternalDocuments from '../InternalDocuments.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import ActionBar from '@/components/ui/ActionBar.vue'

function doc(id: number, status: 'draft' | 'posted' | 'reversed', title = 'Syntetický zápočet') {
  return {
    id, kind: 'offset', title, document_date: '2099-03-30', entry_date: '2099-03-31',
    partner_id: null, partner_name: 'Syntetický partner', amount: 1500, note: null, status,
    document_no: status === 'draft' ? null : `ID-2099-000${id}`, journal_entry_id: status === 'draft' ? null : 10 + id,
    reversal_entry_id: null, reversal_reason: null, posted_at: null, reversed_at: null, document_count: 0, document_links: [],
  }
}

const stubs = { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } }

describe('InternalDocuments', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.remove.mockResolvedValue({})
    m.download.mockResolvedValue({})
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })
  afterEach(() => { vi.useRealTimers() })

  it('u konceptu nabídne úpravu a smazání, po smazání znovu načte seznam', async () => {
    m.list.mockResolvedValueOnce({ items: [doc(1, 'draft')], total: 1, page: 1, per_page: 50, totals: { posted_amount: 0 } })
    m.list.mockResolvedValueOnce({ items: [], total: 0, page: 1, per_page: 50, totals: { posted_amount: 0 } })
    const wrapper = shallowMount(InternalDocuments, stubs)
    await flushPromises()

    expect(m.list).toHaveBeenCalledWith(expect.objectContaining({ status: 'all', page: 1 }))
    expect(wrapper.text()).toContain('internal_documents.no_number')
    expect(wrapper.text()).toContain('common.edit')
    await wrapper.findAll('button').find(button => button.text().includes('common.delete'))!.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith('internal_documents.confirm_delete')
    expect(m.remove).toHaveBeenCalledWith(1)
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('zaúčtovaný doklad ukáže číslo a nenabídne úpravu ani smazání; bez práva chybí i u konceptu', async () => {
    m.list.mockResolvedValue({ items: [doc(2, 'posted'), doc(3, 'draft')], total: 2, page: 1, per_page: 50, totals: { posted_amount: 1500 } })
    m.canWrite.mockReturnValue(false)
    const wrapper = shallowMount(InternalDocuments, stubs)
    await flushPromises()

    expect(wrapper.text()).toContain('ID-2099-0002')
    expect(wrapper.text()).toContain('internal_documents.status.posted')
    expect(wrapper.text()).not.toContain('common.edit')
    expect(wrapper.text()).not.toContain('common.delete')
    const actions = wrapper.findComponent(ActionBar).props('actions') as Array<{ key: string, show?: unknown }>
    expect(actions.find(a => a.key === 'new')?.show).toBe(false)
  })

  it('filtry, hledání a řazení posílá na server a export použije stejné filtry', async () => {
    m.list.mockResolvedValue({ items: [doc(2, 'posted')], total: 1, page: 1, per_page: 50, totals: { posted_amount: 1500 } })
    const wrapper = shallowMount(InternalDocuments, stubs)
    await flushPromises()

    await wrapper.find('[data-test="filter-status"]').setValue('posted')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'posted', page: 1 }))

    await wrapper.find('[data-test="filter-search"]').setValue('zápočet')
    vi.advanceTimersByTime(350)
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'posted', q: 'zápočet' }))

    wrapper.findAllComponents(SortableTh).find(th => th.props('sortKey') === 'document_no')!.vm.$emit('toggle', 'document_no')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ sort_by: 'document_no', sort_dir: 'desc' }))

    const actions = wrapper.findComponent(ActionBar).props('actions') as Array<{ key: string, run?: () => void }>
    actions.find(a => a.key === 'export')!.run!()
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith(
      '/accounting/internal-documents/export?status=posted&q=z%C3%A1po%C4%8Det&sort_by=document_no&sort_dir=desc',
      'interni-doklady.csv',
    )
    wrapper.unmount()
  })

  it('další stránku připojí k první', async () => {
    m.list.mockResolvedValueOnce({ items: [doc(2, 'posted')], total: 2, page: 1, per_page: 1, totals: { posted_amount: 3000 } })
    m.list.mockResolvedValueOnce({ items: [doc(4, 'posted', 'Druhý doklad')], total: 2, page: 2, per_page: 1, totals: { posted_amount: 3000 } })
    const wrapper = shallowMount(InternalDocuments, stubs)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(wrapper.text()).toContain('Syntetický zápočet')
    expect(wrapper.text()).toContain('Druhý doklad')
    wrapper.unmount()
  })
})

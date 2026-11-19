import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  documentItems: vi.fn(),
  clients: vi.fn(),
  classifications: vi.fn(),
  rates: vi.fn(),
}))

vi.mock('@/api/bulkActions', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/bulkActions')>(),
  bulkActionsApi: { documentItems: m.documentItems },
}))
vi.mock('@/api/clients', () => ({ clientsApi: { list: m.clients } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: m.classifications } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { vatRates: m.rates } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, c: string) => `${v} ${c}` }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

import BulkItemFinder from '../BulkItemFinder.vue'
import type { BulkDocumentItem } from '@/api/bulkActions'

function row(id: number, patch: Partial<BulkDocumentItem> = {}): BulkDocumentItem {
  return {
    item_id: id, document_id: 100 + id, document_no: `FV${id}`, document_kind: 'invoice', status: 'issued',
    document_date: '2026-03-01', issue_date: '2026-03-01', partner_id: 5, partner_name: 'Odběratel s.r.o.',
    description: `Položka ${id}`, quantity: 1, unit: 'ks', total_without_vat: 100, total_vat: 21, total_with_vat: 121,
    currency: 'CZK', vat_rate_id: 1, vat_rate: 21, vat_classification_code: null, document_vat_classification_code: 'A1',
    account_code: '602', product_code: null, oss: false, ...patch,
  }
}

function page(items: BulkDocumentItem[], patch: Record<string, unknown> = {}) {
  return { items, next_cursor: null, count: items.length, count_capped: false, limit: 500, ...patch }
}

const stubs = { SearchableSelect: true }
const year = new Date().getFullYear()

function mountFinder(target: 'invoice_item' | 'purchase_invoice_item' = 'invoice_item') {
  return mount(BulkItemFinder, { props: { target }, global: { stubs } })
}

function lastEmitted<T>(wrapper: ReturnType<typeof mountFinder>, event: string): T {
  const all = wrapper.emitted(event) ?? []
  return all[all.length - 1]?.[0] as T
}

describe('BulkItemFinder', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
    m.clients.mockResolvedValue({ data: [] })
    m.classifications.mockResolvedValue([])
    m.rates.mockResolvedValue([])
  })
  afterEach(() => { vi.useRealTimers() })

  it('načte položky za letošní rok, ukáže doklad, částku a členění (vlastní kód, jinak z hlavičky)', async () => {
    m.documentItems.mockResolvedValue(page([row(1), row(2, { vat_classification_code: 'mimo', vat_rate: 0 })]))
    const wrapper = mountFinder()
    await flushPromises()

    expect(m.documentItems).toHaveBeenCalledWith('invoice_item',
      { date_field: 'tax', date_from: `${year}-01-01`, date_to: `${year}-12-31` }, null)
    const rows = wrapper.findAll('[data-test="bulk-items-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('FV1')
    expect(rows[0]!.text()).toContain('121 CZK')
    expect(rows[0]!.get('[data-test="bulk-items-vat"]').find('.text-neutral-400').text()).toBe('A1')
    expect(rows[1]!.get('[data-test="bulk-items-vat"]').text()).toContain('mimo')
    expect(wrapper.findAll('[data-test="bulk-items-card"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="bulk-items-count"]').text()).toContain('bulk_actions.items_count:{"n":2}')
    // Bez zaškrtnutí je výběrem filtr.
    expect(lastEmitted(wrapper, 'update:selection')).toEqual({
      list_query: { filter: { date_field: 'tax', date_from: `${year}-01-01`, date_to: `${year}-12-31` } },
    })
    expect(lastEmitted(wrapper, 'update:count')).toBe(2)
  })

  it('přijaté faktury filtrují výchozí podle data vystavení', async () => {
    m.documentItems.mockResolvedValue(page([]))
    mountFinder('purchase_invoice_item')
    await flushPromises()
    expect(m.documentItems.mock.calls[0]![1]).toMatchObject({ date_field: 'issue' })
    expect(m.clients).toHaveBeenCalledWith(expect.objectContaining({ role: 'vendors' }))
    expect(m.classifications).toHaveBeenCalledWith('purchase')
  })

  it('filtr sestaví query, text se posílá se zpožděním jednou', async () => {
    m.documentItems.mockResolvedValue(page([row(1)]))
    const wrapper = mountFinder()
    await flushPromises()
    vi.useFakeTimers()

    await wrapper.get('[data-test="bulk-items-vat-code"]').setValue('none')
    await wrapper.get('[data-test="bulk-items-q"]').setValue('kon')
    await wrapper.get('[data-test="bulk-items-q"]').setValue('konz')
    await wrapper.get('[data-test="bulk-items-account"]').setValue(' 60 ')
    expect(m.documentItems).toHaveBeenCalledTimes(1)
    // Výběr se během čekání neposílá (filtr ještě neplatí).
    expect(lastEmitted(wrapper, 'update:selection')).toBeNull()

    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()
    expect(m.documentItems).toHaveBeenCalledTimes(2)
    expect(m.documentItems).toHaveBeenLastCalledWith('invoice_item', {
      date_field: 'tax', date_from: `${year}-01-01`, date_to: `${year}-12-31`, q: 'konz', vat_code: 'none', account: '60',
    }, null)
  })

  it('zaškrtnutí přepne na vybrané, hlavička vybere načtené, „Načíst další" připojí stránku', async () => {
    m.documentItems.mockResolvedValueOnce(page([row(1), row(2)], { next_cursor: 'c1', count: 3 }))
    const wrapper = mountFinder()
    await flushPromises()

    await wrapper.findAll('[data-test="bulk-items-check"]')[1]!.trigger('change')
    expect(lastEmitted(wrapper, 'update:selection')).toEqual({ ids: [2] })
    expect(lastEmitted(wrapper, 'update:count')).toBe(1)

    m.documentItems.mockResolvedValueOnce(page([row(3)], { count: null, count_capped: null }))
    await wrapper.get('[data-test="bulk-items-more"]').trigger('click')
    await flushPromises()
    expect(m.documentItems).toHaveBeenLastCalledWith('invoice_item', expect.objectContaining({ date_field: 'tax' }), 'c1')
    expect(wrapper.findAll('[data-test="bulk-items-row"]')).toHaveLength(3)
    expect(wrapper.find('[data-test="bulk-items-more"]').exists()).toBe(false)

    await wrapper.get('[data-test="bulk-items-check-all"]').trigger('change')
    expect(lastEmitted(wrapper, 'update:selection')).toEqual({ ids: [2, 1, 3] })

    await wrapper.get('[data-test="bulk-items-scope-filter"]').trigger('click')
    expect(lastEmitted(wrapper, 'update:selection')).toEqual({ list_query: { filter: expect.objectContaining({ date_field: 'tax' }) } })
    expect(lastEmitted(wrapper, 'update:count')).toBe(3)

    await wrapper.get('[data-test="bulk-items-check-all"]').trigger('change')
    expect(wrapper.get('[data-test="bulk-items-scope-selected"]').attributes('disabled')).toBeDefined()
  })

  it('víc než limit položek: volba všech podle filtru je vypnutá a výběr prázdný', async () => {
    m.documentItems.mockResolvedValue(page([row(1)], { count: 501, count_capped: true, next_cursor: 'x' }))
    const wrapper = mountFinder()
    await flushPromises()

    expect(wrapper.get('[data-test="bulk-items-count"]').text()).toContain('bulk_actions.items_count_capped:{"limit":500}')
    expect(wrapper.get('[data-test="bulk-items-scope-filter"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="bulk-items-capped"]').exists()).toBe(true)
    // Výběr zůstává prázdný (model se nemění z výchozího null).
    expect(lastEmitted(wrapper, 'update:selection') ?? null).toBeNull()
    expect(lastEmitted(wrapper, 'update:count') ?? 0).toBe(0)

    await wrapper.get('[data-test="bulk-items-check"]').trigger('change')
    expect(lastEmitted(wrapper, 'update:selection')).toEqual({ ids: [1] })
  })
})

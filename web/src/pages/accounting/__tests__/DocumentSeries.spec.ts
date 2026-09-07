import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  update: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  toastSuccess: vi.fn(),
  canWrite: vi.fn((_permission: string) => true),
  accountingMode: 'double_entry' as string,
}))

vi.mock('@/api/closing', async () => {
  const actual = await vi.importActual<typeof import('@/api/closing')>('@/api/closing')
  return {
    SERIES_DEFAULT_PREFIXES: actual.SERIES_DEFAULT_PREFIXES,
    SERIES_DOUBLE_ENTRY_ONLY: actual.SERIES_DOUBLE_ENTRY_ONLY,
    seriesApi: { list: m.list, update: m.update },
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.accountingMode } }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, warning: m.toastWarning, success: m.toastSuccess }),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { check: 'M0 0' },
  btnFilled: () => 'btn-filled',
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import DocumentSeries from '@/pages/accounting/DocumentSeries.vue'

const YEAR = new Date().getFullYear()

function stored(overrides: Record<string, unknown> = {}) {
  return { id: 1, series_code: 'cash_in', fiscal_year: YEAR, prefix: 'PPD', number_format: null, next_number: 5, ...overrides }
}

async function mountPage() {
  const wrapper = mount(DocumentSeries, { props: { embedded: true } })
  await flushPromises()
  return wrapper
}

describe('DocumentSeries', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.accountingMode = 'double_entry'
    m.list.mockResolvedValue([])
  })

  it('právo zápisu se ptá na modul accounting, ne na správu období', async () => {
    // `accounting.periods.manage` má systémová role „účetní" explicitně vyřazenou,
    // ale API chce jen `accounting` WRITE — účetní tak viděla záložku jen ke čtení.
    m.canWrite.mockImplementation((p: string) => p === 'accounting')
    const wrapper = await mountPage()
    expect(wrapper.find('button').exists()).toBe(true)
  })

  it('v daňové evidenci nenabízí řady účetního deníku', async () => {
    m.accountingMode = 'tax_evidence'
    const wrapper = await mountPage()
    const rows = wrapper.findAll('tbody tr')

    // 12 řad minus 6 deníkových (closing/opening/fx/transfer/manual/offset)
    expect(rows).toHaveLength(6)
    expect(wrapper.text()).toContain('PPD')
    expect(wrapper.text()).not.toContain('UZ')
  })

  it('nabídne i řady, které ještě nevydaly číslo', async () => {
    const wrapper = await mountPage()
    const rows = wrapper.findAll('tbody tr')

    // 12 řad z DEFAULT_PREFIXES, žádná zatím uložená
    expect(rows).toHaveLength(12)
    expect(wrapper.text()).toContain('accounting.closing.series.not_issued')
  })

  it('uloženou řadu nezdvojuje a bere z ní hodnoty', async () => {
    m.list.mockResolvedValue([stored()])
    const wrapper = await mountPage()

    expect(wrapper.findAll('tbody tr')).toHaveLength(12)
    const inputs = wrapper.findAll('tbody tr')[0].findAll('input')
    expect(inputs[0].element.value).toBe('PPD')
    expect(inputs[2].element.value).toBe('5')
  })

  it('náhled skládá číslo podle rozeditované šablony', async () => {
    m.list.mockResolvedValue([stored({ number_format: '{PREFIX}{CCCCC}', next_number: 11, prefix: '26HP' })])
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('26HP00011')
  })

  it('Uložit odešle jen změněné řady a je vypnuté, dokud se nic nezměnilo', async () => {
    m.list.mockResolvedValue([stored()])
    m.update.mockResolvedValue([stored({ prefix: '26HP', number_format: '{PREFIX}{CCCCC}', next_number: 11 })])
    const wrapper = await mountPage()

    const save = wrapper.find('button')
    expect(save.attributes('disabled')).toBeDefined()

    const inputs = wrapper.findAll('tbody tr')[0].findAll('input')
    await inputs[0].setValue('26HP')
    await inputs[1].setValue('{PREFIX}{CCCCC}')
    await inputs[2].setValue('11')
    expect(save.attributes('disabled')).toBeUndefined()

    await save.trigger('click')
    await flushPromises()

    expect(m.update).toHaveBeenCalledTimes(1)
    expect(m.update).toHaveBeenCalledWith('cash_in', YEAR, {
      // L-3: scope řady — 0 = společná řada firmy, >0 by mířilo na vlastní řadu pokladny.
      register_id: 0,
      prefix: '26HP',
      number_format: '{PREFIX}{CCCCC}',
      next_number: 11,
    })
  })

  it('při změně samotného prefixu neposílá čítač — přeposláním by se řada vrátila zpět', async () => {
    m.list.mockResolvedValue([stored()])
    m.update.mockResolvedValue([stored({ prefix: '26HP' })])
    const wrapper = await mountPage()

    const inputs = wrapper.findAll('tbody tr')[0].findAll('input')
    await inputs[0].setValue('26HP')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith('cash_in', YEAR, { register_id: 0, prefix: '26HP' })
    expect(m.toastSuccess).toHaveBeenCalled()
  })

  it('šablonu bez čítače neuloží', async () => {
    m.list.mockResolvedValue([stored()])
    const wrapper = await mountPage()

    const inputs = wrapper.findAll('tbody tr')[0].findAll('input')
    await inputs[1].setValue('26HP')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(m.update).not.toHaveBeenCalled()
    expect(m.toastWarning).toHaveBeenCalledWith('accounting.closing.series.format_invalid')
  })

  it('bez práva zápisu jsou pole jen ke čtení a Uložit chybí', async () => {
    m.canWrite.mockReturnValue(false)
    m.list.mockResolvedValue([stored()])
    const wrapper = await mountPage()

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')[0].findAll('input')[0].attributes('disabled')).toBeDefined()
  })
})

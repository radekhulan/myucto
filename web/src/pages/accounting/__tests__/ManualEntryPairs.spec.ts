import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getEntryForm: vi.fn(),
  createEntry: vi.fn(),
  push: vi.fn(),
  success: vi.fn(),
  query: {} as Record<string, string>,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      key.startsWith('accounting.error.') ? `přeloženo ${key}` : params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ push: m.push }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listAccounts: vi.fn().mockResolvedValue([
      { id: 1, account_code: '221', name: 'Banka', is_active: true },
      { id: 2, account_code: '311', name: 'Odběratelé', is_active: true },
      { id: 3, account_code: '602', name: 'Tržby', is_active: true },
    ]),
    listCostCenters: vi.fn().mockResolvedValue([]),
    getEntryForm: m.getEntryForm,
    createEntry: m.createEntry,
    getEntry: vi.fn(),
  },
}))
vi.mock('@/api/closing', () => ({ transferApi: {} }))
vi.mock('@/api/documents', () => ({ documentsApi: {} }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useDimensions', () => ({ useDimensions: () => ({ enabled: { value: false } }) }))
vi.mock('@/utils/date', () => ({ appIsoDate: () => '2027-03-15' }))
vi.mock('@/components/accounting/DocumentLinkPicker.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/DateInput.vue', () => ({
  default: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-test="date" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
}))

import ManualEntry from '@/pages/accounting/ManualEntry.vue'

const single = { entry_form: 'single', history: [{ valid_from: '2027-01-01', entry_form: 'single', created_by: 1, created_at: '', note: null }] }
const composite = { entry_form: 'composite', history: [] }

function mountPage() {
  return mount(ManualEntry, { global: { stubs: { EmptyState: true } } })
}

async function fillPair(w: ReturnType<typeof mountPage>, debit: string, credit: string, amount: string) {
  const row = w.get('[data-pair-row="1"]')
  const inputs = row.findAll('input[type="text"]')
  await inputs[0].setValue(debit)
  await inputs[1].setValue(credit)
  await row.get('input[type="number"]').setValue(amount)
}

beforeEach(() => {
  vi.clearAllMocks()
  for (const k of Object.keys(m.query)) delete m.query[k]
  m.createEntry.mockResolvedValue({ id: 55, lines: [] })
})

describe('ManualEntry: jednoduchý zápis po dvojicích', () => {
  it('firma se složenou formou vidí řádky jako dnes a žádné dvojice', async () => {
    m.getEntryForm.mockResolvedValue(composite)
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="manual-pairs"]').exists()).toBe(false)
    expect(w.text()).toContain('accounting.manual.add_line')
    w.unmount()
  })

  it('firma s jednoduchou formou zadává dvojice a posílá je v poli pairs', async () => {
    m.getEntryForm.mockResolvedValue(single)
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="manual-pairs"]').exists()).toBe(true)
    expect(w.text()).not.toContain('accounting.manual.add_line')

    await fillPair(w, '221', '311', '1250.5')
    await flushPromises()
    const post = w.findAll('button').find(b => b.text().includes('accounting.manual.post'))!
    await post.trigger('click')
    await flushPromises()

    expect(m.createEntry).toHaveBeenCalledTimes(1)
    const body = m.createEntry.mock.calls[0][0]
    expect(body.lines).toBeUndefined()
    expect(body.pairs).toEqual([{ debit_account_code: '221', credit_account_code: '311', amount: 1250.5, is_red_storno: false }])
    expect(m.push).toHaveBeenCalledWith('/accounting/journal')
    w.unmount()
  })

  it('dvojici se stejnými účty klient nepustí na server', async () => {
    m.getEntryForm.mockResolvedValue(single)
    const w = mountPage()
    await flushPromises()
    await fillPair(w, '221', '221', '10')
    await flushPromises()
    const post = w.findAll('button').find(b => b.text().includes('accounting.manual.post'))!
    expect((post.element as HTMLButtonElement).disabled).toBe(true)
    expect(w.get('[data-test="pair-error"]').text()).toContain('accounting.error.pair_same_account')
    w.unmount()
  })

  it('chyba pair_* ze serveru se ukáže u dvojice, ke které patří', async () => {
    m.getEntryForm.mockResolvedValue(single)
    m.createEntry.mockRejectedValue({ response: { data: { error: { code: 'pair_amount_not_positive', message: 'x', pair_no: 1 } } } })
    const w = mountPage()
    await flushPromises()
    await fillPair(w, '221', '311', '10')
    await flushPromises()
    await w.findAll('button').find(b => b.text().includes('accounting.manual.post'))!.trigger('click')
    await flushPromises()
    const err = w.get('[data-test="pair-error"]').text()
    expect(err).toContain('přeloženo accounting.error.pair_amount_not_positive')
    expect(m.push).not.toHaveBeenCalled()
    w.unmount()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({
  listAccounts: vi.fn(),
  updateAccount: vi.fn(),
  getDefaults: vi.fn(),
  saveDefaults: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
}))
const dims = vi.hoisted(() => ({ enabled: null as unknown as { value: boolean }, documentTypes: null as unknown as { value: unknown[] } }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: mocks.error, success: mocks.success }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: dims.enabled, documentTypes: dims.documentTypes, load: vi.fn().mockResolvedValue(undefined) }),
}))
vi.mock('@/api/bankPosting', () => ({ bankPostingApi: { listAccounts: mocks.listAccounts, updateAccount: mocks.updateAccount } }))
vi.mock('@/api/dimensions', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/dimensions')>(),
  dimensionsApi: { getDefaults: mocks.getDefaults, saveDefaults: mocks.saveDefaults },
}))
import BankAccountAnalytics from '../BankAccountAnalytics.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'

const account = (id: number) => ({
  id, kind: 'savings', label: `Synthetic ${id}`, account_number: '1000000005', bank_code: '0100', iban: null,
  currency: 'CZK', analytic_suffix: String(100 * id), document_series: `B${id}`, is_active: true,
})

describe('bank account default dimensions', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    dims.enabled = ref(true)
    dims.documentTypes = ref([{ id: 7, name: 'Středisko', is_active: true, show_on_documents: true }])
    mocks.listAccounts.mockResolvedValue([account(1), account(2)])
    mocks.getDefaults.mockImplementation(async (_entity: string, id: number) => (id === 1 ? { 7: 70 } : {}))
    mocks.saveDefaults.mockImplementation(async (_entity: string, _id: number, map: Record<number, number>) => map)
  })

  it('loads defaults per account and saves only the changed dimensions', async () => {
    const wrapper = shallowMount(BankAccountAnalytics)
    await flushPromises()
    expect(mocks.getDefaults).toHaveBeenCalledWith('accounting/bank-accounts', 1)
    expect(mocks.getDefaults).toHaveBeenCalledWith('accounting/bank-accounts', 2)

    const toggles = wrapper.findAll('[data-test="bank-account-dimensions-toggle"]')
    expect(toggles).toHaveLength(2)
    await toggles[1].trigger('click')
    const fields = wrapper.findComponent(DimensionFields)
    expect(fields.exists()).toBe(true)
    fields.vm.$emit('update:modelValue', { 7: 71 })
    await flushPromises()
    expect(wrapper.text()).toContain('bank.analytics.unsaved')

    await wrapper.find('button.bg-primary-600').trigger('click')
    await flushPromises()
    expect(mocks.saveDefaults).toHaveBeenCalledTimes(1)
    expect(mocks.saveDefaults).toHaveBeenCalledWith('accounting/bank-accounts', 2, { 7: 71 })
    expect(mocks.updateAccount).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('does not save dimensions of a row whose account fields fail validation', async () => {
    const wrapper = shallowMount(BankAccountAnalytics)
    await flushPromises()
    await wrapper.findAll('[data-test="bank-account-dimensions-toggle"]')[1].trigger('click')
    wrapper.findComponent(DimensionFields).vm.$emit('update:modelValue', { 7: 71 })
    const series = wrapper.findAll('input[placeholder="BCR"]')[1]
    await series.setValue('B 2!')
    await wrapper.find('button.bg-primary-600').trigger('click')
    await flushPromises()
    expect(mocks.error).toHaveBeenCalledWith('bank.analytics.series_invalid')
    expect(mocks.saveDefaults).not.toHaveBeenCalled()
    expect(mocks.updateAccount).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('saves nothing when another row fails validation', async () => {
    const wrapper = shallowMount(BankAccountAnalytics)
    await flushPromises()
    await wrapper.findAll('[data-test="bank-account-dimensions-toggle"]')[0].trigger('click')
    wrapper.findComponent(DimensionFields).vm.$emit('update:modelValue', { 7: 71 })
    await wrapper.findAll('input[placeholder="BCR"]')[1].setValue('B 2!')
    await wrapper.find('button.bg-primary-600').trigger('click')
    await flushPromises()
    expect(mocks.error).toHaveBeenCalledWith('bank.analytics.series_invalid')
    expect(mocks.saveDefaults).not.toHaveBeenCalled()
    expect(mocks.updateAccount).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps dimensions unsaved when the server rejects the account fields', async () => {
    mocks.updateAccount.mockRejectedValue(new Error('suffix taken'))
    const wrapper = shallowMount(BankAccountAnalytics)
    await flushPromises()
    await wrapper.findAll('[data-test="bank-account-dimensions-toggle"]')[1].trigger('click')
    wrapper.findComponent(DimensionFields).vm.$emit('update:modelValue', { 7: 71 })
    await wrapper.findAll('input[placeholder="BCR"]')[1].setValue('B22')
    await wrapper.find('button.bg-primary-600').trigger('click')
    await flushPromises()
    expect(mocks.updateAccount).toHaveBeenCalledTimes(1)
    expect(mocks.saveDefaults).not.toHaveBeenCalled()
    expect(mocks.error).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('hides the column and skips loading when dimensions are off', async () => {
    dims.enabled = ref(false)
    const wrapper = shallowMount(BankAccountAnalytics)
    await flushPromises()
    expect(mocks.getDefaults).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="bank-account-dimensions-toggle"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

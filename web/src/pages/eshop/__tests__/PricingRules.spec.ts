import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PricingRules from '../PricingRules.vue'

const mocks = vi.hoisted(() => ({
  canWrite: vi.fn<(permission: string) => boolean>(),
  profiles: vi.fn(),
  rules: vi.fn(),
  rates: vi.fn(),
  createProfile: vi.fn(),
  updateProfile: vi.fn(),
  deleteProfile: vi.fn(),
  createRule: vi.fn(),
  updateRule: vi.fn(),
  deleteRule: vi.fn(),
  saveRate: vi.fn(),
  listManufacturers: vi.fn(),
  getManufacturer: vi.fn(),
  listCategories: vi.fn(),
  getCategory: vi.fn(),
  listCurrencies: vi.fn(),
  searchItems: vi.fn(),
  getItem: vi.fn(),
  listClients: vi.fn(),
  getClient: vi.fn(),
  getJob: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: mocks.canWrite }),
}))
vi.mock('@/api/catalogPricing', () => ({
  catalogPricingApi: {
    profiles: mocks.profiles,
    rules: mocks.rules,
    rates: mocks.rates,
    createProfile: mocks.createProfile,
    updateProfile: mocks.updateProfile,
    deleteProfile: mocks.deleteProfile,
    createRule: mocks.createRule,
    updateRule: mocks.updateRule,
    deleteRule: mocks.deleteRule,
    saveRate: mocks.saveRate,
  },
}))
vi.mock('@/api/eshop', () => ({
  eshopApi: {
    listManufacturers: mocks.listManufacturers,
    getManufacturer: mocks.getManufacturer,
    listCategories: mocks.listCategories,
    getCategory: mocks.getCategory,
    listCurrencies: mocks.listCurrencies,
  },
}))
vi.mock('@/api/stock', () => ({
  stockApi: { searchItems: mocks.searchItems, getItem: mocks.getItem },
}))
vi.mock('@/api/clients', () => ({
  clientsApi: { list: mocks.listClients, get: mocks.getClient },
}))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { get: mocks.getJob } }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (error: unknown, fallback: string) => error instanceof Error ? error.message : fallback,
}))

const profile = {
  id: 1,
  code: 'retail',
  name: 'Retail',
  currency_code: 'CZK',
  calculation_mode: 'markup' as const,
  percentage: '20.000',
  rounding: 'none' as const,
  fx_source: 'cnb',
  max_rate_age_days: 7,
  is_active: true,
}

const stubs = {
  Modal: {
    template: '<div data-test="modal"><slot /><slot name="footer" /></div>',
  },
  CatalogJobProgress: true,
  EmptyState: {
    template: '<div data-test="empty-state" />',
  },
  SearchableSelect: {
    props: ['modelValue', 'options'],
    emits: ['update:modelValue', 'search'],
    template: `<select
      data-test="target-select"
      :value="modelValue ?? ''"
      @change="$emit('update:modelValue', $event.target.value === '' ? null : Number($event.target.value))"
    >
      <option value=""></option>
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>`,
  },
}

function mountPage() {
  return shallowMount(PricingRules, { global: { stubs } })
}

beforeEach(() => {
  vi.clearAllMocks()
  mocks.canWrite.mockReturnValue(true)
  mocks.profiles.mockResolvedValue([])
  mocks.rules.mockResolvedValue([])
  mocks.rates.mockResolvedValue([])
  mocks.listManufacturers.mockResolvedValue([])
  mocks.listCategories.mockResolvedValue([])
  mocks.listCurrencies.mockResolvedValue([
    { id: 1, code: 'CZK', name: 'Koruna', symbol: 'Kč', display_order: 1, is_default: true, archived: false },
    { id: 2, code: 'EUR', name: 'Euro', symbol: '€', display_order: 2, is_default: false, archived: false },
  ])
  mocks.searchItems.mockResolvedValue([])
  mocks.listClients.mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 25, pages: 0 } })
})

describe('PricingRules', () => {
  it.each([
    ['markup', '25'],
    ['target_margin', '25.5'],
  ] as const)('saves the %s profile mode and decimal percentage', async (mode, percentage) => {
    mocks.createProfile.mockImplementation(async payload => ({
      profile: { ...profile, ...payload, id: 10 },
      recompute_job_id: null,
    }))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-profile"]').trigger('click')
    await wrapper.get('[data-test="profile-code"]').setValue(`${mode}-profile`)
    await wrapper.get('[data-test="profile-name"]').setValue('Test profile')
    await wrapper.get('[data-test="profile-mode"]').setValue(mode)
    await wrapper.get('[data-test="profile-percentage"]').setValue(percentage)
    await wrapper.get('[data-test="save-pricing"]').trigger('click')
    await flushPromises()

    expect(mocks.createProfile).toHaveBeenCalledWith(expect.objectContaining({
      calculation_mode: mode,
      percentage,
      rounding: 'none',
    }))
  })

  it('clears match_id when a targeted rule is switched to the default rule', async () => {
    mocks.profiles.mockResolvedValue([profile])
    mocks.listCategories.mockResolvedValue([
      { id: 7, parent_id: null, code: 'tools', name: 'Tools', path: 'Tools', depth: 0, display_order: 1, export_eshop: true, archived: false },
    ])
    mocks.createRule.mockImplementation(async payload => ({
      rule: { id: 9, ...payload },
      recompute_job_id: null,
    }))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-rule"]').trigger('click')
    await wrapper.get('[data-test="rule-match-type"]').setValue('category')
    await flushPromises()
    await wrapper.get('[data-test="target-select"]').setValue('7')
    await wrapper.get('[data-test="rule-match-type"]').setValue('default')
    await flushPromises()
    await wrapper.get('[data-test="save-pricing"]').trigger('click')
    await flushPromises()

    expect(mocks.createRule).toHaveBeenCalledWith(expect.objectContaining({
      match_type: 'default',
      match_id: null,
    }))
  })

  it('keeps the filled form open when saving fails', async () => {
    mocks.createProfile.mockRejectedValue(new Error('Server rejected the profile'))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-profile"]').trigger('click')
    await wrapper.get('[data-test="profile-code"]').setValue('keep-me')
    await wrapper.get('[data-test="profile-name"]').setValue('Keep this profile')
    await wrapper.get('[data-test="save-pricing"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="modal"]').exists()).toBe(true)
    expect((wrapper.get('[data-test="profile-code"]').element as HTMLInputElement).value).toBe('keep-me')
    expect(wrapper.get('[data-test="save-error"]').text()).toBe('Server rejected the profile')
  })

  it('renders no mutation controls and makes no write calls without both permissions', async () => {
    mocks.canWrite.mockImplementation(permission => permission !== 'stock.items.write')
    mocks.profiles.mockResolvedValue([profile])
    const wrapper = mountPage()
    await flushPromises()

    expect(mocks.canWrite).toHaveBeenCalledWith('eshop.write')
    expect(mocks.canWrite).toHaveBeenCalledWith('stock.items.write')
    expect(wrapper.find('[data-test="add-profile"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="edit-profile-1"]').exists()).toBe(false)
    expect(mocks.createProfile).not.toHaveBeenCalled()
    expect(mocks.updateProfile).not.toHaveBeenCalled()
    expect(mocks.deleteProfile).not.toHaveBeenCalled()
    expect(mocks.createRule).not.toHaveBeenCalled()
    expect(mocks.saveRate).not.toHaveBeenCalled()
  })

  it('does not fetch or poll a recompute job when a deferred save finishes after unmount', async () => {
    let resolveSave!: (value: unknown) => void
    mocks.createProfile.mockReturnValue(new Promise(resolve => { resolveSave = resolve }))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-profile"]').trigger('click')
    await wrapper.get('[data-test="profile-code"]').setValue('deferred')
    await wrapper.get('[data-test="profile-name"]').setValue('Deferred profile')
    await wrapper.get('[data-test="save-pricing"]').trigger('click')
    expect(mocks.createProfile).toHaveBeenCalledOnce()

    wrapper.unmount()
    resolveSave({ profile: { ...profile, id: 12 }, recompute_job_id: 44 })
    await flushPromises()

    expect(mocks.getJob).not.toHaveBeenCalled()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollDimension } from '@/api/payroll'

const m = vi.hoisted(() => ({
  payrollDimensions: vi.fn(),
  createPayrollDimension: vi.fn(),
  updatePayrollDimension: vi.fn(),
  deletePayrollDimension: vi.fn(),
  payrollCompanyDimensionValues: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    payrollDimensions: m.payrollDimensions,
    createPayrollDimension: m.createPayrollDimension,
    updatePayrollDimension: m.updatePayrollDimension,
    deletePayrollDimension: m.deletePayrollDimension,
    payrollCompanyDimensionValues: m.payrollCompanyDimensionValues,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string) => key,
    te: () => true,
  }),
}))

vi.mock('@/composables/useUserPrefs', async () => {
  const { computed, ref } = await import('vue')
  const store = ref<Record<string, unknown>>({})
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => store.value),
    patchPagePrefs: (_page: string, patch: Record<string, unknown>) => {
      store.value = { ...store.value, ...patch }
    },
  }
})

import PayrollDimensions from '@/pages/payroll/PayrollDimensions.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

function dimension(overrides: Partial<PayrollDimension> = {}): PayrollDimension {
  return {
    id: 1,
    dimension_type: 'cost_center',
    code: 'SPRAVA',
    name: 'Správa',
    valid_from: '2026-01-01',
    valid_to: null,
    is_active: true,
    default_account_code: null,
    row_version: 1,
    ...overrides,
  } as PayrollDimension
}

async function mountPage(items: PayrollDimension[] = [dimension()]) {
  m.payrollDimensions.mockResolvedValue(items)
  const wrapper = mount(PayrollDimensions, { props: { canWrite: true } })
  await flushPromises()
  return wrapper
}

async function openNew(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.findAll('button')
    .find(button => button.text() === 'payroll.employer.dimensions.add')!
    .trigger('click')
}

describe('PayrollDimensions — kód z názvu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.payrollCompanyDimensionValues.mockResolvedValue([])
  })

  it('odvodí kód z názvu, dokud do něj uživatel nesáhne', async () => {
    const wrapper = await mountPage()
    await openNew(wrapper)

    const name = wrapper.get('[data-test="dimension-name"]')
    const code = wrapper.get('[data-test="dimension-code"]')
    await name.setValue('Středisko Brno')

    expect((code.element as HTMLInputElement).value).toBe('STREDISKO_BRNO')

    await code.setValue('BRNO_1')
    await name.setValue('Středisko Ostrava')
    expect((code.element as HTMLInputElement).value).toBe('BRNO_1')

    wrapper.unmount()
  })

  it('kolizi s existující dimenzí odliší suffixem', async () => {
    const wrapper = await mountPage([dimension()])
    await openNew(wrapper)

    await wrapper.get('[data-test="dimension-name"]').setValue('Správa')

    expect((wrapper.get('[data-test="dimension-code"]').element as HTMLInputElement).value)
      .toBe('SPRAVA_2')

    wrapper.unmount()
  })

  it('kód existující dimenze se přepisem názvu nemění', async () => {
    const wrapper = await mountPage()
    const editButtons = wrapper.findAll('button').filter(button => button.text() === 'common.edit')
    expect(editButtons.length).toBeGreaterThan(0)
    await editButtons[0].trigger('click')

    const code = wrapper.get('[data-test="dimension-code"]')
    expect((code.element as HTMLInputElement).value).toBe('SPRAVA')

    await wrapper.get('[data-test="dimension-name"]').setValue('Jiný název')
    expect((code.element as HTMLInputElement).value).toBe('SPRAVA')

    wrapper.unmount()
  })
})

describe('PayrollDimensions — vazba na firemní dimenzi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.payrollCompanyDimensionValues.mockResolvedValue([
      { id: 5, type_id: 2, type_name: 'Střediska', type_kind: 'cost_center', code: 'STR-1', name: 'Středisko 1' },
      { id: 6, type_id: 3, type_name: 'Zakázky', type_kind: 'project', code: 'ZAK-1', name: 'Zakázka 1' },
    ])
  })

  async function editFirst(wrapper: Awaited<ReturnType<typeof mountPage>>) {
    await wrapper.findAll('button').filter(button => button.text() === 'common.edit')[0].trigger('click')
  }

  function selectCompanyValue(wrapper: Awaited<ReturnType<typeof mountPage>>, valueId: number) {
    const select = wrapper.findAllComponents(SearchableSelect)
      .find(component => component.attributes('data-test') === 'dimension-company-value')!
    select.vm.$emit('update:model-value', valueId)
  }

  it('zakázku bez vazby upozorní, že se do mzdového zápisu nepropíše', async () => {
    const wrapper = await mountPage([dimension({ dimension_type: 'project', code: 'ZAK', dimension_value_id: null })])
    await editFirst(wrapper)

    expect(wrapper.find('[data-test="dimension-project-link-missing"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('vazbu zakázky na středisko odmítne a neuloží', async () => {
    const wrapper = await mountPage([dimension({ dimension_type: 'project', code: 'ZAK', dimension_value_id: null })])
    await editFirst(wrapper)
    selectCompanyValue(wrapper, 5)
    await flushPromises()

    expect(wrapper.get('[data-test="dimension-company-kind-error"]').text())
      .toBe('payroll.employer.dimensions.validation.company_kind.project')

    selectCompanyValue(wrapper, 6)
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-company-kind-error"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('dřív uloženou nesouladnou vazbu při úpravě neblokuje', async () => {
    const wrapper = await mountPage([dimension({ dimension_type: 'project', code: 'ZAK', dimension_value_id: 5 })])
    await editFirst(wrapper)

    expect(wrapper.find('[data-test="dimension-company-kind-error"]').exists()).toBe(false)

    wrapper.unmount()
  })
})

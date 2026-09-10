import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  create: vi.fn(),
}))

vi.mock('@/api/salesOrders', () => ({
  salesOrdersApi: {
    get: mocks.get,
    update: mocks.update,
    create: mocks.create,
    confirm: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
    payment: vi.fn(),
  },
}))
vi.mock('@/api/clients', () => ({ clientsApi: { list: vi.fn(async () => ({ data: [] })) } }))
vi.mock('@/api/stock', () => ({ stockApi: { listWarehouses: vi.fn(async () => []), searchItems: vi.fn(async () => []) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: vi.fn(async () => []), vatRates: vi.fn(async () => []) } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'request failed' }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ name: 'stock-sales-order-detail', params: { id: '42' } }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SalesOrderDetail from '../SalesOrderDetail.vue'

const selections = {
  '501': { required: ['large'], optional: ['gift-wrap'] },
  '502': { colour: ['blue'] },
}

const order = {
  id: 42,
  order_uuid: '92d4c35e-9ce8-44f0-89af-29a537e6287e',
  order_number: 'SO-API-42',
  client_id: 10,
  client_name: 'Synthetic customer',
  commercial_status: 'draft',
  payment_status: 'unpaid',
  fulfillment_status: 'unfulfilled',
  allocation_policy: 'all_or_nothing',
  currency_id: 1,
  currency_code: 'CZK',
  exchange_rate: null,
  prices_include_vat: false,
  total_without_vat: '100.00',
  total_vat: '21.00',
  total_with_vat: '121.00',
  reservation_expires_at: null,
  row_version: 3,
  invoice_id: null,
  created_at: '2099-01-01 10:00:00',
  reservations: [],
  returns: [],
  lines: [{
    id: 7,
    line_uuid: 'bdf343e0-0f94-4434-86e2-802be87ac942',
    description: 'Configurable set',
    stock_item_id: 501,
    warehouse_id: 2,
    sku_snapshot: 'SET-501',
    unit: 'ks',
    quantity: '1.000',
    unit_price: '100.000000',
    discount_percent: '0.0000',
    vat_rate_id: 4,
    vat_rate_snapshot: '21.00',
    total_without_vat: '100.00',
    total_vat: '21.00',
    total_with_vat: '121.00',
    product_snapshot: { kind: 'product_set', selections },
    component_snapshot: [],
  }],
}

describe('SalesOrderDetail set selections', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.get.mockResolvedValue(order)
    mocks.update.mockResolvedValue(order)
  })

  it('preserves API-created set selections when editing the draft header', async () => {
    const wrapper = shallowMount(SalesOrderDetail)
    await flushPromises()

    const actions = wrapper.findComponent(ActionBar).props('actions') as ActionItem[]
    await actions.find(action => action.key === 'save')!.run?.()
    await flushPromises()

    expect(mocks.update).toHaveBeenCalledWith(42, expect.objectContaining({
      row_version: 3,
      lines: [expect.objectContaining({ set_selections: selections })],
    }))
    wrapper.unmount()
  })
})

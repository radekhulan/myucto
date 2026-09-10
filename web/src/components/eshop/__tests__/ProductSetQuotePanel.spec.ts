import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ProductSetQuotePanel from '../ProductSetQuotePanel.vue'

const mocks = vi.hoisted(() => ({ quoteProductSet: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/eshop', () => ({ eshopApi: { quoteProductSet: mocks.quoteProductSet } }))

afterEach(() => vi.clearAllMocks())

describe('ProductSetQuotePanel', () => {
  it('sends a required choice under the nested set item ID', async () => {
    mocks.quoteProductSet.mockResolvedValue({
      currency_code: 'CZK',
      quote: { amount: '120.00', components: [] },
    })
    const wrapper = mount(ProductSetQuotePanel, {
      props: {
        itemId: 10,
        definition: { components: [{ item_id: 20, quantity: '1.000' }], groups: [], prices: {} },
        definitions: {
          '10': { components: [{ item_id: 20, quantity: '1.000' }], groups: [], prices: {} },
          '20': {
            components: [],
            groups: [{
              code: 'colour', name: 'Colour', min: 1, max: 1,
              options: [{ code: 'red', name: 'Red', item_id: 30, quantity: '1.000', surcharges: { CZK: '10.00' } }],
            }],
            prices: {},
          },
        },
        cards: [{ id: 30, sku: 'RED', name: 'Red component', unit: 'ks', vat_rate_id: 1, is_active: true, is_stocked: true }],
        currencies: ['CZK'],
      },
    })

    await wrapper.get('select').setValue('CZK')
    await wrapper.get('input[type="radio"]').setValue(true)
    await wrapper.get('[data-test="set-quote"]').trigger('click')
    await flushPromises()

    expect(mocks.quoteProductSet).toHaveBeenCalledWith(10, {
      currency_code: 'CZK',
      quantity: '1',
      selections: { '20': { colour: ['red'] } },
    })
    expect(wrapper.text()).toContain('120.00 CZK')
  })
})

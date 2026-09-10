import { describe, expect, it } from 'vitest'
import { remapRootProductSetSelections, resolveActiveProductSet } from '../productSetSelections'

const definitions = {
  '10': {
    components: [{ item_id: 20, quantity: '1.000' }],
    groups: [{
      code: 'addon', name: 'Addon', min: 0, max: 1,
      options: [{ code: 'extended', name: 'Extended', item_id: 30, quantity: '1.000', surcharges: {} }],
    }],
    prices: {},
  },
  '20': {
    components: [],
    groups: [{
      code: 'colour', name: 'Colour', min: 1, max: 1,
      options: [{ code: 'red', name: 'Red', item_id: 40, quantity: '1.000', surcharges: {} }],
    }],
    prices: {},
  },
  '30': {
    components: [],
    groups: [{
      code: 'support', name: 'Support', min: 1, max: 1,
      options: [{ code: 'premium', name: 'Premium', item_id: 50, quantity: '1.000', surcharges: {} }],
    }],
    prices: {},
  },
}

describe('product set selection resolver', () => {
  it('removes selections and groups under an optional branch after it is deselected', () => {
    const withAddon = resolveActiveProductSet(10, definitions, {
      '10': { addon: ['extended'] },
      '20': { colour: ['red'] },
      '30': { support: ['premium'] },
      '999': { stale: ['value'] },
    })
    expect(withAddon.definitions.map(definition => definition.itemId)).toEqual([10, 20, 30])
    expect(withAddon.selections).toEqual({
      '10': { addon: ['extended'] },
      '20': { colour: ['red'] },
      '30': { support: ['premium'] },
    })

    const withoutAddon = resolveActiveProductSet(10, definitions, {
      '10': { addon: [] },
      '20': { colour: ['red'] },
      '30': { support: ['premium'] },
    })
    expect(withoutAddon.definitions.map(definition => definition.itemId)).toEqual([10, 20])
    expect(withoutAddon.selections).toEqual({ '20': { colour: ['red'] } })
    expect(withoutAddon.componentItemIds).toEqual([40])
  })

  it('remaps only the assembly expansion root and keeps nested set selections', () => {
    expect(remapRootProductSetSelections({
      '10': { addon: ['extended'] },
      '20': { colour: ['red'] },
      '30': { support: ['premium'] },
    }, 10, 99)).toEqual({
      '20': { colour: ['red'] },
      '30': { support: ['premium'] },
      '99': { addon: ['extended'] },
    })
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import { api } from '../client'
import { openingStockImportApi } from '../openingStockImport'

const originalAdapter = api.defaults.adapter
afterEach(() => { api.defaults.adapter = originalAdapter })

describe('opening stock upload', () => {
  it('keeps the file as multipart data through the shared JSON client', async () => {
    const file = new File(['external_id;sku;quantity;unit_cost\n1;TEST;2;25'], 'stock.csv', { type: 'text/csv' })
    let sent: unknown
    api.defaults.adapter = async config => {
      sent = config.data
      return { data: { id: 1 }, status: 201, statusText: 'Created', headers: {}, config }
    }

    await openingStockImportApi.upload(file)

    expect(sent).toBeInstanceOf(FormData)
    expect((sent as FormData).get('file')).toBe(file)
  })
})

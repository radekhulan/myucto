import { beforeEach, describe, expect, it, vi } from 'vitest'

describe('URL samostatného ISDOC vydané faktury', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
  })

  it('použije aktuální firmu pro přímé stažení bez axios hlaviček', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    const { invoicesApi } = await import('../invoices')
    expect(invoicesApi.isdocUrl(9)).toBe('/api/invoices/9/isdoc?supplier_id=22')
  })

  it('zachová firmu okna i po přepnutí firmy v jiném okně', async () => {
    const { installWindowSupplierScope } = await import('@/workspace/supplierContext')
    installWindowSupplierScope({ windowId: 'window-a', supplierId: 11 })
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    const { invoicesApi } = await import('../invoices')
    expect(invoicesApi.isdocUrl(9)).toBe('/api/invoices/9/isdoc?supplier_id=11')
    localStorage.setItem('myinvoice.current_supplier_id', '33')
    expect(invoicesApi.isdocUrl(10)).toBe('/api/invoices/10/isdoc?supplier_id=11')
  })

  it.each([null, '0', 'invalid', '-1'])('nepřidá neplatný výběr firmy %s', async value => {
    if (value !== null) localStorage.setItem('myinvoice.current_supplier_id', value)
    const { invoicesApi } = await import('../invoices')
    expect(invoicesApi.isdocUrl(9)).toBe('/api/invoices/9/isdoc')
  })
})

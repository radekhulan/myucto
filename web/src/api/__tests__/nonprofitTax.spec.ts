import { describe, expect, it, vi } from 'vitest'
const calls = vi.hoisted(() => ({ get: vi.fn().mockResolvedValue({ data: {} }), post: vi.fn().mockResolvedValue({ data: {} }) }))
vi.mock('@/api/client', () => ({ api: calls }))
import { nonprofitTaxApi } from '@/api/nonprofitTax'
describe('nonprofit tax routes', () => {
  it('uses readonly GET for history and frozen JSON/PDF/XLSX exports', async () => {
    await nonprofitTaxApi.read(7)
    await nonprofitTaxApi.history(7)
    await nonprofitTaxApi.export(9)
    await nonprofitTaxApi.document(9, 'pdf')
    await nonprofitTaxApi.document(9, 'xlsx')
    expect(calls.post).not.toHaveBeenCalled()
    expect(calls.get.mock.calls.map(call => call[0])).toEqual(['/accounting/periods/7/nonprofit-tax', '/accounting/periods/7/nonprofit-tax/history', '/accounting/nonprofit-tax-assessments/9/export', '/accounting/nonprofit-tax-assessments/9/export.pdf', '/accounting/nonprofit-tax-assessments/9/export.xlsx'])
  })
})

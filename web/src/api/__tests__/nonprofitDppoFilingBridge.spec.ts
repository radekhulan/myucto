import { beforeEach, expect, it, vi } from 'vitest'
const calls = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/api/client', () => ({ api: calls }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => '1' }))
import { taxReturnApi } from '../taxReturn'
beforeEach(() => { vi.clearAllMocks(); calls.post.mockResolvedValue({ data: {} }) })
it('passes the current native nonprofit filing proof with finalization CAS', async () => { await Reflect.apply(taxReturnApi.finalize, null, ['po', 2026, 4, 'radne', undefined, 'synthetic-native-filing-proof']); expect(calls.post).toHaveBeenCalledWith('/tax-return/po/2026/finalize', { row_version: 4, nonprofit_filing_proof_hash: 'synthetic-native-filing-proof' }) })
it('preserves the existing ordinary taxpayer finalization request', async () => { await taxReturnApi.finalize('fo', 2026, 4); expect(calls.post).toHaveBeenCalledWith('/tax-return/fo/2026/finalize', { row_version: 4 }) })
it('keeps default FO preview and directs explicit verified PO preview to its native route', () => { expect(taxReturnApi.previewXmlUrl(2026)).toContain('/api/tax-return/fo/2026/xml/preview?'); expect(Reflect.apply(taxReturnApi.previewXmlUrl, null, [2026, 'radne', undefined, 'po'])).toContain('/api/tax-return/po/2026/xml/preview?') })

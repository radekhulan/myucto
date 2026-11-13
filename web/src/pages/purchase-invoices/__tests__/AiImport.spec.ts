import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import type { SupplierBrief, User } from '@/api/auth'
import type { AiExtractionStatus } from '@/api/integrations'
import AiImport from '../AiImport.vue'
import { usePendingCapture } from '@/composables/usePendingCapture'

const m = vi.hoisted(() => ({ status: vi.fn(), extract: vi.fn(), batches: vi.fn(), push: vi.fn() }))
vi.mock('@/api/integrations', () => ({ integrationsApi: { getAiExtractionStatus: m.status, extractPdfAi: m.extract } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listImportBatches: m.batches } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-i18n', async (importOriginal) => ({ ...(await importOriginal<typeof import('vue-i18n')>()), useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? key + JSON.stringify(params) : key }) }))

const status = (enabled = true, configured = true): AiExtractionStatus => ({
  ai_provider: 'anthropic', client_ai_extraction_enabled: enabled,
  client_ai_extraction_usage: { period: '2026-10', limit: 100, used: 0, remaining: 100 },
  providers: { anthropic: { configured, models: ['synthetic-model'], default_model: 'synthetic-model' } },
})
function setupRole(client = true) {
  const auth = useAuthStore()
  auth.user = { role: { type: client ? 'client' : 'staff' }, is_superadmin: false } as User
  auth.permissions = { purchase_invoices: 2, 'purchase_invoices.create': 2, 'purchase_invoices.extract': 2 }
  useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as SupplierBrief[], 1)
  return auth
}
async function mountPage() {
  const w = mount(AiImport, { global: { stubs: { RouterLink: true, ExtractionReviewModal: true } } })
  await flushPromises()
  return w
}
async function pickFiles(w: Awaited<ReturnType<typeof mountPage>>, names: string[]) {
  const input = w.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: names.map(name => new File(['synthetic'], name, { type: 'application/pdf' })), configurable: true })
  await input.trigger('change')
}

describe('klientský AI import přijatých faktur', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    m.status.mockReset().mockResolvedValue(status())
    m.extract.mockReset()
    m.batches.mockReset().mockResolvedValue([])
  })

  it.each([
    ['povolený', true, true, true],
    ['zakázaný', false, true, false],
    ['bez klíče', true, false, false],
  ])('%s import zobrazí odpovídající dostupnost bez odkazu do administrace', async (_label, enabled, configured, visible) => {
    setupRole()
    m.status.mockResolvedValue(status(enabled, configured))
    const w = await mountPage()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(visible)
    expect(w.find('[data-test="client-ai-unavailable"]').exists()).toBe(!visible)
    expect(w.find('[to="/admin/integrations?tab=ai"]').exists()).toBe(false)
    w.unmount()
  })

  it.each(['purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.extract'] as const)('bez práva %s nenabídne upload', async permission => {
    setupRole().permissions[permission] = 1
    const w = await mountPage()
    expect(w.find('input[type="file"]').exists()).toBe(false)
    w.unmount()
  })

  it('účetní používá import i bez klientského přepínače', async () => {
    setupRole(false)
    m.status.mockResolvedValue(status(false))
    const w = await mountPage()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(true)
    w.unmount()
  })

  it('po vytěžení otevře kontrolu i u konceptu bez varování', async () => {
    setupRole()
    m.extract.mockResolvedValue({ ok: true, purchase_invoice_id: 17, source: 'ai', document_kind: 'invoice' })
    const w = await mountPage()
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    const review = w.getComponent({ name: 'ExtractionReviewModal' })
    expect(review.props('invoiceIds')).toEqual([17])
    expect(review.props('onlyFlagged')).toBe(false)
    w.unmount()
  })

  it('klient vidí čerpání a používá model firmy bez možnosti override', async () => {
    setupRole()
    m.extract.mockResolvedValue({ ok: true, purchase_invoice_id: 17, source: 'ai' })
    const w = await mountPage()
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('\"remaining\":100')
    expect(w.find('[data-test="ai-model-override"]').exists()).toBe(false)
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    expect(m.extract).toHaveBeenCalledWith(expect.any(File), undefined, expect.any(String))
    expect(m.status).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('účetní může pro import změnit model a klientský limit ho neblokuje', async () => {
    setupRole(false)
    m.status.mockResolvedValue({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit: 0, used: 0, remaining: 0 } })
    m.extract.mockResolvedValue({ ok: true })
    const w = await mountPage()
    await w.get('[data-test="ai-model-override"] select').setValue('synthetic-model')
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    expect(m.extract).toHaveBeenCalledWith(expect.any(File), 'synthetic-model', expect.any(String))
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(false)
    w.unmount()
  })

  it.each([0, 100])('vyčerpaný limit %s blokuje spuštění před uploadem', async limit => {
    setupRole()
    m.status.mockResolvedValue({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit, used: limit, remaining: 0 } })
    const w = await mountPage()
    expect(w.get('[data-test="client-ai-limit-reached"]').text()).toContain('aiGateway.client_extraction_limit_reached')
    expect(w.get('input[type="file"]').attributes('disabled')).toBeDefined()
    await pickFiles(w, ['synthetic.pdf'])
    const run = w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!
    expect(run.attributes('disabled')).toBeDefined()
    await run.trigger('click')
    expect(m.extract).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['success', 'error', 'duplicate'])('po pokusu (%s) aktualizuje čerpání a zastaví dávku na limitu', async outcome => {
    setupRole()
    m.status.mockResolvedValueOnce({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit: 1, used: 0, remaining: 1 } })
    m.status.mockResolvedValue({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit: 1, used: 1, remaining: 0 } })
    if (outcome === 'error') m.extract.mockRejectedValue({ response: { data: { error: { code: 'extraction_failed', message: 'synthetic' } } } })
    else m.extract.mockResolvedValue({ ok: true, purchase_invoice_id: 17, duplicate: outcome === 'duplicate', source: 'ai' })
    const w = await mountPage()
    await pickFiles(w, ['synthetic-a.pdf', 'synthetic-b.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.batch_run'))!.trigger('click')
    await flushPromises()
    expect(m.extract).toHaveBeenCalledTimes(1)
    expect(m.status.mock.calls.length).toBeGreaterThan(1)
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(true)
    w.unmount()
  })

  it('serverové vyčerpání limitu zastaví dávku i při zastaralém lokálním čerpání', async () => {
    setupRole()
    m.extract.mockRejectedValue({ response: { status: 429, data: { error: { code: 'client_ai_extraction_limit_reached', message: 'synthetic' } } } })
    const w = await mountPage()
    await pickFiles(w, ['synthetic-a.pdf', 'synthetic-b.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.batch_run'))!.trigger('click')
    await flushPromises()
    expect(m.extract).toHaveBeenCalledTimes(1)
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(true)
    useSupplierStore().setSupplier(2)
    await flushPromises()
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(false)
    w.unmount()
  })

  it.each(['error', 'duplicate', 'limit'])('po jednotlivém uploadu (%s) aktualizuje měsíční čerpání', async outcome => {
    setupRole()
    m.status.mockResolvedValueOnce(status())
    m.status.mockResolvedValue({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit: 100, used: 1, remaining: 99 } })
    if (outcome === 'duplicate') m.extract.mockResolvedValue({ ok: true, duplicate: true, purchase_invoice_id: 17 })
    else m.extract.mockRejectedValue({ response: { data: { error: { code: outcome === 'limit' ? 'client_ai_extraction_limit_reached' : 'extraction_failed', message: 'synthetic' } } } })
    const w = await mountPage()
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    expect(m.status).toHaveBeenCalledTimes(2)
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('"remaining":99')
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(outcome === 'limit')
    w.unmount()
  })

  it('opožděná aktualizace čerpání po uploadu neovlivní novou firmu', async () => {
    setupRole()
    let finish!: (value: AiExtractionStatus) => void
    m.status.mockResolvedValueOnce(status())
    m.status.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.status.mockResolvedValue(status())
    m.extract.mockResolvedValue({ ok: true })
    const w = await mountPage()
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish({ ...status(), client_ai_extraction_usage: { period: '2026-10', limit: 1, used: 1, remaining: 0 } })
    await flushPromises()
    expect(w.find('[data-test="client-ai-limit-reached"]').exists()).toBe(false)
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('"remaining":100')
    w.unmount()
  })

  it('opožděná konfigurace původní firmy nezpřístupní upload v nové firmě', async () => {
    setupRole()
    let finish!: (value: AiExtractionStatus) => void
    m.status.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.status.mockResolvedValue(status(false))
    const w = await mountPage()
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish(status())
    await flushPromises()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(false)
    expect(w.find('[data-test="client-ai-unavailable"]').exists()).toBe(true)
    w.unmount()
  })

  it('při přepnutí firmy zahodí opožděný výsledek a nepokračuje v původní dávce', async () => {
    setupRole()
    let finish!: (value: unknown) => void
    m.extract.mockImplementation(() => new Promise(resolve => { finish = resolve }))
    const w = await mountPage()
    await pickFiles(w, ['synthetic-a.pdf', 'synthetic-b.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.batch_run'))!.trigger('click')
    expect(m.extract).toHaveBeenCalledTimes(1)
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish({ ok: true, purchase_invoice_id: 17, source: 'ai' })
    await flushPromises()
    expect(m.extract).toHaveBeenCalledTimes(1)
    expect(w.findComponent({ name: 'ExtractionReviewModal' }).exists()).toBe(false)
    expect(w.text()).not.toContain('synthetic-a.pdf')
    w.unmount()
  })

  it('fotku z mobilní nabídky Nákup převezme, nechá oříznout a rovnou vytěží', async () => {
    setupRole(false)
    m.extract.mockResolvedValue({ ok: true, purchase_invoice_id: 21, source: 'ai', document_kind: 'invoice' })
    const photo = new File(['synthetic'], 'snapshot.jpg', { type: 'image/jpeg' })
    usePendingCapture().set(photo)
    const w = mount(AiImport, { global: { stubs: { RouterLink: true, ExtractionReviewModal: true, PhotoCropDialog: true } } })
    await flushPromises()
    expect(usePendingCapture().pending.value).toBeNull()
    const crop = w.getComponent({ name: 'PhotoCropDialog' })
    expect((crop.props('file') as File).name).toBe('snapshot.jpg')
    const cropped = new File(['synthetic-cropped'], 'snapshot-cropped.jpg', { type: 'image/jpeg' })
    crop.vm.$emit('done', cropped)
    await flushPromises()
    expect(m.extract).toHaveBeenCalledTimes(1)
    expect((m.extract.mock.calls[0][0] as File).name).toBe('snapshot-cropped.jpg')
    w.unmount()
  })

  it('fotku z mobilní nabídky bez dostupného vytěžení zahodí a nic neodešle', async () => {
    setupRole()
    m.status.mockResolvedValue(status(false))
    usePendingCapture().set(new File(['synthetic'], 'snapshot.jpg', { type: 'image/jpeg' }))
    const w = mount(AiImport, { global: { stubs: { RouterLink: true, ExtractionReviewModal: true, PhotoCropDialog: true } } })
    await flushPromises()
    expect(usePendingCapture().pending.value).toBeNull()
    expect(w.findComponent({ name: 'PhotoCropDialog' }).exists()).toBe(false)
    expect(m.extract).not.toHaveBeenCalled()
    w.unmount()
  })
})

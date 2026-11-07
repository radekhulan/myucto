import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import type { PurchaseInvoiceSubmission } from '@/api/purchaseInvoiceSubmissions'

const m = vi.hoisted(() => ({
  list: vi.fn(), dimensions: vi.fn(), remove: vi.fn(), resubmit: vi.fn(), upload: vi.fn(),
  success: vi.fn(), error: vi.fn(), canWrite: true,
  supplier: { currentSupplierId: 1 },
  viewport: { wide: true },
}))
vi.mock('@/api/purchaseInvoiceSubmissions', () => ({
  portalPurchaseInvoiceSubmissionsApi: {
    list: m.list, dimensions: m.dimensions, remove: m.remove, resubmit: m.resubmit, upload: m.upload,
    previewUrl: (id: number) => `/preview/${id}`, downloadUrl: (id: number) => `/download/${id}`,
  },
}))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive(m.supplier)
  return { useSupplierStore: () => m.supplier }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite, canRead: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: vi.fn() }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@vueuse/core', async () => {
  const { computed, reactive } = await import('vue')
  m.viewport = reactive(m.viewport)
  return { useMediaQuery: () => computed(() => m.viewport.wide) }
})

import PurchaseInvoiceSubmissions from '../PurchaseInvoiceSubmissions.vue'

function submission(id: number, status: PurchaseInvoiceSubmission['status'] = 'submitted', canDelete = true): PurchaseInvoiceSubmission {
  return {
    id, status, can_delete: canDelete, original_name: `ukazka-${id}.pdf`, size_bytes: 512,
    created_at: '2093-01-01', replacement_submission_id: null, purchase_invoice_id: null,
    status_reason: null, note: null, dimensions: {}, doc_type: 'pdf',
  } as PurchaseInvoiceSubmission
}

async function mountPage(items: PurchaseInvoiceSubmission[]) {
  m.list.mockResolvedValueOnce({ items })
  const wrapper = shallowMount(PurchaseInvoiceSubmissions, {
    global: { stubs: { SubmissionPreview: false, PdfFrame: false, Modal: { template: '<div role="dialog"><slot /></div>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('Předání dokladů: smazání a odmítnutí', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.canWrite = true
    m.viewport.wide = true
    m.supplier.currentSupplierId = 1
    m.dimensions.mockResolvedValue([])
    vi.stubGlobal('confirm', vi.fn(() => true))
  })

  it('na velké obrazovce otevře PDF v pravém panelu a výběrem obrázku přepne obsah', async () => {
    const wrapper = await mountPage([submission(1), { ...submission(2), doc_type: 'image' }])
    await wrapper.findAll('[data-test="portal-preview"]')[0]!.trigger('click')
    const pane = wrapper.get('[data-test="portal-preview-pane"]')
    expect(pane.element.tagName).toBe('ASIDE')
    expect(pane.get('iframe').attributes('src')).toBe('/preview/1#view=FitH')
    await wrapper.findAll('[data-test="portal-preview"]')[1]!.trigger('click')
    expect(pane.find('iframe').exists()).toBe(false)
    expect(pane.get('img').attributes('src')).toBe('/preview/2')
    expect(pane.get('img').attributes('alt')).toBe('ukazka-2.pdf')
    await pane.get('[data-test="portal-preview-close"]').trigger('click')
    expect(wrapper.find('[data-test="portal-preview-pane"]').exists()).toBe(false)
  })

  it('přetažené doklady přidá k výběru, nepodporovaný formát vynechá a odešle zbytek', async () => {
    m.upload.mockResolvedValueOnce({ created: 1, duplicates: 0, errors: [] })
    m.list.mockResolvedValue({ items: [] })
    const wrapper = await mountPage([])
    const pdf = new File(['%PDF'], 'faktura.pdf', { type: 'application/pdf' })
    const exe = new File(['MZ'], 'virus.exe', { type: 'application/octet-stream' })
    await wrapper.get('[data-test="portal-dropzone"]').trigger('drop', { dataTransfer: { files: [pdf, exe] } })
    await wrapper.get('[data-test="portal-dropzone"]').trigger('drop', { dataTransfer: { files: [pdf] } })
    expect(wrapper.get('[data-test="portal-selected-files"]').text()).toBe('faktura.pdf')
    const submit = wrapper.findAll('button').find(button => button.text().includes('purchase_submissions.submit_action'))!
    await submit.trigger('click')
    await flushPromises()
    expect(m.upload).toHaveBeenCalledWith([pdf], '', null, {})
  })

  it('přetažená fotka projde ořezem dokladu a do výběru jde výřez', async () => {
    m.list.mockResolvedValue({ items: [] })
    const wrapper = await mountPage([])
    const photo = new File(['jpg'], 'sken.jpg', { type: 'image/jpeg' })
    const pdf = new File(['%PDF'], 'faktura.pdf', { type: 'application/pdf' })
    await wrapper.get('[data-test="portal-dropzone"]').trigger('drop', { dataTransfer: { files: [pdf, photo] } })
    await flushPromises()
    const dialog = wrapper.findComponent({ name: 'PhotoCropDialog' })
    expect(dialog.exists()).toBe(true)
    expect(wrapper.find('[data-test="portal-selected-files"]').exists()).toBe(false)
    const cropped = new File(['crop'], 'sken.jpg', { type: 'image/jpeg' })
    dialog.vm.$emit('done', cropped)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'PhotoCropDialog' }).exists()).toBe(false)
    expect(wrapper.findAll('[data-test="portal-selected-files"] li').map(li => li.text())).toEqual(['faktura.pdf', 'sken.jpg'])
  })

  it('další výběr a fotka soubory přidají k vybraným, odebraný soubor se neodešle', async () => {
    m.upload.mockResolvedValueOnce({ created: 2, duplicates: 0, errors: [] })
    m.list.mockResolvedValue({ items: [] })
    const wrapper = await mountPage([])
    const first = new File(['%PDF'], 'faktura-1.pdf', { type: 'application/pdf' })
    const second = new File(['%PDF-2'], 'faktura-2.pdf', { type: 'application/pdf' })
    const photo = new File(['jpg'], 'foto-20261106-170000.jpg', { type: 'image/jpeg' })

    // Celá plocha pro přetažení je zároveň klikací výběr souborů (jako v AI importu).
    const dropzone = wrapper.get('[data-test="portal-dropzone"]')
    expect(dropzone.element.tagName).toBe('LABEL')
    expect(dropzone.find('input[type="file"][multiple]').exists()).toBe(true)
    const input = wrapper.get('input[type="file"][multiple]')
    for (const file of [first, second]) {
      Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
      await input.trigger('change')
    }
    wrapper.findComponent({ name: 'CapturePhotoButton' }).vm.$emit('captured', photo)
    await flushPromises()
    expect(wrapper.findAll('[data-test="portal-selected-files"] li').map(li => li.text()))
      .toEqual(['faktura-1.pdf', 'faktura-2.pdf', 'foto-20261106-170000.jpg'])

    await wrapper.findAll('[data-test="portal-remove-file"]')[1]!.trigger('click')
    await wrapper.findAll('button').find(button => button.text() === 'purchase_submissions.submit_action')!.trigger('click')
    await flushPromises()
    expect(m.upload.mock.calls[0]![0]).toEqual([first, photo])
  })

  it('na úzkém displeji otevře náhled v dialogu a při změně firmy ho zavře', async () => {
    m.viewport.wide = false
    const wrapper = await mountPage([submission(1)])
    await wrapper.get('[data-test="portal-preview"]').trigger('click')
    expect(wrapper.find('[data-test="portal-preview-pane"]').exists()).toBe(false)
    expect(wrapper.get('[role="dialog"] iframe').attributes('src')).toBe('/preview/1#view=FitH')
    m.list.mockResolvedValueOnce({ items: [submission(2)] })
    m.supplier.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('nabídne smazání jen serverem povoleného čekajícího podání a respektuje zrušené potvrzení', async () => {
    const wrapper = await mountPage([submission(1), submission(2, 'processing', false), submission(3, 'submitted', false)])
    expect(wrapper.findAll('[data-test="portal-delete"]')).toHaveLength(1)
    vi.mocked(confirm).mockReturnValueOnce(false)
    await wrapper.get('[data-test="portal-delete"]').trigger('click')
    expect(m.remove).not.toHaveBeenCalled()
  })

  it('po potvrzení smaže podání, blokuje opakovaný požadavek a obnoví přehled', async () => {
    let finish!: () => void
    m.remove.mockImplementation(() => new Promise<void>(resolve => { finish = resolve }))
    const wrapper = await mountPage([submission(1)])
    await wrapper.get('[data-test="portal-preview"]').trigger('click')
    await wrapper.get('[data-test="portal-delete"]').trigger('click')
    expect(m.remove).toHaveBeenCalledWith(1)
    expect(wrapper.get('[data-test="portal-delete"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="portal-delete"]').trigger('click')
    expect(m.remove).toHaveBeenCalledTimes(1)
    m.list.mockResolvedValueOnce({ items: [] })
    finish()
    await flushPromises()
    expect(wrapper.find('[data-test="portal-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="portal-preview-pane"]').exists()).toBe(false)
    expect(m.success).toHaveBeenCalledWith('purchase_submissions.portal_delete_success')
  })

  it('když účetní mezitím začala zpracovávat, zobrazí chybu a aktualizuje dostupné akce', async () => {
    const wrapper = await mountPage([submission(1)])
    m.remove.mockRejectedValueOnce(new Error('Doklad se již zpracovává.'))
    m.list.mockResolvedValueOnce({ items: [submission(1, 'processing', false)] })
    await wrapper.get('[data-test="portal-delete"]').trigger('click')
    await flushPromises()
    expect(m.error).toHaveBeenCalledWith('Doklad se již zpracovává.')
    expect(wrapper.find('[data-test="portal-delete"]').exists()).toBe(false)
  })

  it('opožděná chyba po přepnutí firmy nesmí zasáhnout nový přehled', async () => {
    let fail!: (error: Error) => void
    m.remove.mockImplementation(() => new Promise((_, reject) => { fail = reject }))
    const wrapper = await mountPage([submission(1)])
    await wrapper.get('[data-test="portal-delete"]').trigger('click')
    m.list.mockResolvedValueOnce({ items: [submission(2, 'submitted', false)] })
    m.supplier.currentSupplierId = 2
    await flushPromises()
    fail(new Error('Chyba předchozí firmy'))
    await flushPromises()
    expect(m.error).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ukazka-2.pdf')
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('odmítnutý doklad zobrazí důvod a umožní náhradu, nahrazený už další náhradu nenabízí', async () => {
    const rejected = { ...submission(1, 'rejected', false), status_reason: 'Nahrajte čitelný originál.' }
    const replaced = { ...submission(2, 'rejected', false), replacement_submission_id: 3 }
    const wrapper = await mountPage([rejected, replaced])
    expect(wrapper.text()).toContain('Nahrajte čitelný originál.')
    const inputs = wrapper.findAll('[data-test="portal-replace"]')
    expect(inputs).toHaveLength(1)
    const file = new File(['pdf'], 'nahrada.pdf', { type: 'application/pdf' })
    Object.defineProperty(inputs[0]!.element, 'files', { value: [file] })
    m.resubmit.mockResolvedValueOnce({})
    m.list.mockResolvedValueOnce({ items: [replaced] })
    await inputs[0]!.trigger('change')
    await flushPromises()
    expect(m.resubmit).toHaveBeenCalledWith(1, file, '')
  })

  it('uživatel pouze pro čtení nemá smazání ani nahrání náhrady', async () => {
    m.canWrite = false
    const wrapper = await mountPage([submission(1), submission(2, 'rejected', false)])
    expect(wrapper.find('[data-test="portal-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="portal-replace"]').exists()).toBe(false)
  })

  it.each(['success', 'error'] as const)('opožděný upload (%s) po změně firmy nesmí vymazat nový formulář ani obnovovat přehled', async outcome => {
    let finish!: (value: unknown) => void
    let fail!: (error: Error) => void
    m.upload.mockImplementationOnce(() => new Promise((resolve, reject) => { finish = resolve; fail = reject }))
    const wrapper = await mountPage([])
    const fileInput = wrapper.get('input[type="file"][multiple]')
    const oldFile = new File(['pdf'], 'puvodni.pdf', { type: 'application/pdf' })
    Object.defineProperty(fileInput.element, 'files', { configurable: true, value: [oldFile] })
    await fileInput.trigger('change')
    const submit = () => wrapper.findAll('button').find(button => button.text() === 'purchase_submissions.submit_action')!
    await submit().trigger('click')
    expect(m.upload).toHaveBeenCalledTimes(1)

    m.list.mockResolvedValueOnce({ items: [] })
    m.supplier.currentSupplierId = 2
    await flushPromises()
    const newFile = new File(['pdf'], 'novy.pdf', { type: 'application/pdf' })
    Object.defineProperty(fileInput.element, 'files', { configurable: true, value: [newFile] })
    await fileInput.trigger('change')
    const note = wrapper.get('input[maxlength="8000"]')
    await note.setValue('Poznámka nové firmy')
    await wrapper.get('select').setValue('receipt')
    expect(submit().attributes('disabled')).toBeUndefined()

    if (outcome === 'success') finish({ items: [], errors: [], created: 1, duplicates: 0 })
    else fail(new Error('Chyba původní firmy'))
    await flushPromises()
    expect(m.list).toHaveBeenCalledTimes(2)
    expect(m.success).not.toHaveBeenCalled()
    expect(m.error).not.toHaveBeenCalled()
    expect((note.element as HTMLInputElement).value).toBe('Poznámka nové firmy')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('receipt')
    expect(submit().attributes('disabled')).toBeUndefined()
  })
})

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ExistingAboExport from '../ExistingAboExport.vue'
import ReservationsPanel from '../ReservationsPanel.vue'
import { i18nPlugin, page } from './helpers'
import type { AboMetadata, AboResult, Reservation } from '@/api/propertyPaymentOperations'

const mocks = vi.hoisted(() => ({ aboMetadata: vi.fn(), aboDownload: vi.fn(), aboPreview: vi.fn(), aboConfirm: vi.fn(), list: vi.fn(), download: vi.fn() }))
vi.mock('@/api/propertyPaymentOperations', () => ({ propertyPaymentOperationsApi: mocks }))
vi.mock('../aboDownload', () => ({ downloadAbo: mocks.download }))
const reservation: Reservation = { id: 9, credit_id: 5, row_version: 2, status: 'submitted', amount_cents: '300', paid_cents: '0', remaining_cents: '300', unit_id: 1, unit_label: 'Synthetic unit', parties: [], request: { amount_cents: '300', party_ids: [21], disposition: 'Synthetic refund', delivery_method: 'bank' } }
const metadata: AboMetadata = { id: 71, filename: 'vratky-71.kpc', encoding: 'windows-1250', reservation_ids: [9], reservation_statuses: { 9: 'submitted' } }
const result: AboResult = { ...metadata, content_base64: 'U3ludGhldGlj' }
const global = () => ({ plugins: [i18nPlugin()], stubs: { AboExport: true, PropertyButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' } } })
const mountReceipt = (canManage = true) => mount(ExistingAboExport, { props: { supplier: 1, reservation, canManage, reloadKey: 0 }, global: global() })
beforeEach(() => { Object.values(mocks).forEach(mock => mock.mockReset()); mocks.aboMetadata.mockResolvedValue(metadata); mocks.aboDownload.mockResolvedValue(result); mocks.list.mockResolvedValue(page([reservation])) })

describe('stored ABO receipt after reload', () => {
  it('reloads the reservation list and resolves the stored server export ID before an explicit download', async () => {
    const props = { supplier: 1, unitId: 1, reloadKey: 0, canManage: true, canPost: true }
    const first = mount(ReservationsPanel, { props, global: global() }); await flushPromises(); first.unmount()
    const again = mount(ReservationsPanel, { props, global: global() }); await flushPromises()
    expect(mocks.aboMetadata).toHaveBeenNthCalledWith(1, 1, 9)
    expect(mocks.aboMetadata).toHaveBeenNthCalledWith(2, 1, 9)
    expect(mocks.download).not.toHaveBeenCalled()
    expect(mocks.aboDownload).not.toHaveBeenCalled()
    await again.get('[data-action="existing-abo-download"]').trigger('click'); await flushPromises()
    expect(mocks.aboDownload).toHaveBeenCalledExactlyOnceWith(1, 71)
    expect(mocks.download).toHaveBeenCalledExactlyOnceWith(result.filename, result.content_base64, result.encoding)
    expect(mocks.aboPreview).not.toHaveBeenCalled(); expect(mocks.aboConfirm).not.toHaveBeenCalled()
    again.unmount()
  })
  it('shows read-only metadata but never offers a download without the manage capability', async () => {
    const wrapper = mountReceipt(false); await flushPromises()
    expect(mocks.aboMetadata).toHaveBeenCalledExactlyOnceWith(1, 9)
    expect(wrapper.find('[data-field="existing-abo-filename"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="existing-abo-download"]').exists()).toBe(false)
    expect(mocks.aboDownload).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('keeps absence distinct from denied or corrupt metadata and permits a retry', async () => {
    mocks.aboMetadata.mockResolvedValueOnce(null).mockRejectedValueOnce({ response: { data: { error: { message: 'Synthetic integrity failure' } } } }).mockResolvedValueOnce(metadata)
    const wrapper = mountReceipt(); await flushPromises()
    expect(wrapper.find('[data-section="existing-abo"]').exists()).toBe(false)
    await wrapper.setProps({ reloadKey: 1 }); await flushPromises()
    expect(wrapper.text()).toContain('Synthetic integrity failure')
    expect(wrapper.find('[data-action="existing-abo-download"]').exists()).toBe(false)
    await wrapper.get('[data-action="existing-abo-retry"]').trigger('click'); await flushPromises()
    expect(wrapper.find('[data-action="existing-abo-download"]').exists()).toBe(true); wrapper.unmount()
  })
  it('discards metadata that arrives after switching suppliers', async () => {
    let resolve!: (value: AboMetadata) => void
    mocks.aboMetadata.mockReturnValueOnce(new Promise(r => { resolve = r })).mockResolvedValueOnce(null)
    const wrapper = mountReceipt(); await wrapper.setProps({ supplier: 2 }); await flushPromises()
    resolve(metadata); await flushPromises()
    expect(wrapper.find('[data-section="existing-abo"]').exists()).toBe(false)
    expect(mocks.download).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('shows all mixed statuses and warns before explicitly downloading immutable history', async () => {
    const mixed: AboMetadata = { ...metadata, reservation_ids: [9, 10], reservation_statuses: { 9: 'cancelled', 10: 'submitted' } }
    mocks.aboMetadata.mockResolvedValue(mixed); mocks.aboDownload.mockResolvedValue({ ...mixed, content_base64: result.content_base64 })
    const wrapper = mountReceipt(); await flushPromises()
    expect(wrapper.get('[data-flag="existing-abo-history"]').text()).toBe('propertyPayments.abo.historyWarning')
    expect(wrapper.findAll('[data-row="existing-abo-status"]').map(row => row.attributes('data-status'))).toEqual(['cancelled', 'submitted'])
    expect(mocks.download).not.toHaveBeenCalled()
    await wrapper.get('[data-action="existing-abo-download"]').trigger('click'); await flushPromises()
    expect(mocks.download).toHaveBeenCalledExactlyOnceWith(result.filename, result.content_base64, result.encoding); wrapper.unmount()
  })
  it('requires another explicit click when download discovers a newly cancelled reservation', async () => {
    mocks.aboDownload.mockResolvedValue({ ...result, reservation_statuses: { 9: 'cancelled' } })
    const wrapper = mountReceipt(); await flushPromises()
    await wrapper.get('[data-action="existing-abo-download"]').trigger('click'); await flushPromises()
    expect(wrapper.find('[data-flag="existing-abo-history"]').exists()).toBe(true)
    expect(mocks.download).not.toHaveBeenCalled()
    await wrapper.get('[data-action="existing-abo-download"]').trigger('click'); await flushPromises()
    expect(mocks.download).toHaveBeenCalledTimes(1); wrapper.unmount()
  })
  it('discards download bytes after a supplier change', async () => {
    let resolve!: (value: AboResult) => void
    mocks.aboDownload.mockReturnValueOnce(new Promise(r => { resolve = r }))
    const wrapper = mountReceipt(); await flushPromises()
    await wrapper.get('[data-action="existing-abo-download"]').trigger('click')
    mocks.aboMetadata.mockResolvedValue(null)
    await wrapper.setProps({ supplier: 2 }); await flushPromises()
    resolve(result); await flushPromises()
    expect(mocks.download).not.toHaveBeenCalled(); wrapper.unmount()
  })
})

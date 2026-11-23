import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const api = vi.hoisted(() => ({ deletePreview: vi.fn(), delete: vi.fn() }))
vi.mock('@/api/suppliers', () => ({ suppliersApi: api }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))

import SupplierDeleteDialog from '../SupplierDeleteDialog.vue'

const preview = {
  id: 7, company_name: 'Syntetická firma s.r.o.', ic: '00000027', confirm_with: 'ic', total_rows: 1234,
  tables: [{ table: 'journal_entries', rows: 400 }, { table: 'payroll_runs', rows: 4 }], protected_tables: 3, files: 2, is_last: false,
}

function mountDialog(supplier: { id: number; company_name: string; ic?: string | null } = { id: 7, company_name: 'Syntetická firma s.r.o.', ic: '00000027' }) {
  return mount(SupplierDeleteDialog, {
    props: { supplier },
    global: { stubs: { WorkspaceTeleport: { template: '<div><slot /></div>' } } },
  })
}

describe('SupplierDeleteDialog', () => {
  beforeEach(() => {
    api.deletePreview.mockReset().mockResolvedValue(preview)
    api.delete.mockReset().mockResolvedValue({ deleted: true, rows: 1234 })
  })

  it('varuje před nevratným smazáním a ukáže náhled počtů', async () => {
    const wrapper = mountDialog()
    await flushPromises()
    expect(wrapper.find('[data-testid="supplier-delete-warning"]').text()).toContain('supplier.delete_dialog.warning')
    const summary = wrapper.find('[data-testid="supplier-delete-preview"]').text()
    expect(summary).toContain('"rows":1234')
    expect(summary).toContain('journal_entries')
    expect(summary).toContain('supplier.delete_dialog.protected')
    expect(api.deletePreview).toHaveBeenCalledWith(7)
  })

  it('tlačítko je aktivní až po opsání IČO a odešle potvrzení', async () => {
    const wrapper = mountDialog()
    await flushPromises()
    const submit = wrapper.find('[data-testid="supplier-delete-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="supplier-delete-confirm"]').setValue('0000002')
    expect(submit.attributes('disabled')).toBeDefined()
    await submit.trigger('click')
    expect(api.delete).not.toHaveBeenCalled()
    await wrapper.find('[data-testid="supplier-delete-confirm"]').setValue(' 00000027 ')
    expect(submit.attributes('disabled')).toBeUndefined()
    await submit.trigger('click')
    await flushPromises()
    expect(api.delete).toHaveBeenCalledWith(7, '00000027')
    expect(wrapper.emitted('deleted')?.[0]).toEqual([7])
  })

  it('firmu bez IČO potvrdí přesný název', async () => {
    api.deletePreview.mockResolvedValue({ ...preview, ic: null, confirm_with: 'company_name' })
    const wrapper = mountDialog({ id: 7, company_name: 'Syntetická firma s.r.o.', ic: null })
    await flushPromises()
    expect(wrapper.text()).toContain('supplier.delete_dialog.type_name')
    const submit = wrapper.find('[data-testid="supplier-delete-submit"]')
    await wrapper.find('[data-testid="supplier-delete-confirm"]').setValue('syntetická firma s.r.o.')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="supplier-delete-confirm"]').setValue('Syntetická firma s.r.o.')
    expect(submit.attributes('disabled')).toBeUndefined()
  })

  it('poslední firmu smazat nenabídne a chybu serveru ukáže', async () => {
    api.deletePreview.mockResolvedValue({ ...preview, is_last: true })
    const wrapper = mountDialog()
    await flushPromises()
    await wrapper.find('[data-testid="supplier-delete-confirm"]').setValue('00000027')
    expect(wrapper.find('[data-testid="supplier-delete-submit"]').attributes('disabled')).toBeDefined()

    api.deletePreview.mockResolvedValue(preview)
    api.delete.mockRejectedValue({ response: { data: { error: { code: 'no_trigger_privilege', message: 'Databázový účet nemá právo TRIGGER.' } } } })
    const other = mountDialog()
    await flushPromises()
    await other.find('[data-testid="supplier-delete-confirm"]').setValue('00000027')
    await other.find('[data-testid="supplier-delete-submit"]').trigger('click')
    await flushPromises()
    expect(other.text()).toContain('Databázový účet nemá právo TRIGGER.')
    expect(other.emitted('deleted')).toBeUndefined()
  })
})

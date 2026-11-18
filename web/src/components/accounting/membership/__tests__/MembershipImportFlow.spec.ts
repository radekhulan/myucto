import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Flow from '../MembershipImportFlow.vue'
import type { MembershipImportReport } from '@/api/nonprofitMembership'
import { formatDate } from '@/composables/useFormat'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ importMembers: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; disabledReason?: string; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, 'data-reason': a.disabled ? a.disabledReason : undefined, onClick: a.run }, a.label))),
})

const preview: MembershipImportReport = {
  ok: true, dry_run: true, preview_hash: 'a'.repeat(64), counts: { new_revision: 1, new_member: 1, unchanged: 1, error: 0 },
  rows: [
    { line: 2, member_number: 'SYN-1', status: 'new_revision', member_id: 4, new_status: 'suspended', effective_from: '2026-03-01' },
    { line: 3, member_number: 'SYN-NEW', name: 'Synthetic Person', status: 'new_member', contact: 'create', client_id: null, new_status: 'active', effective_from: '2026-02-01' },
    { line: 4, member_number: 'SYN-2', status: 'unchanged', reason: 'same_status', params: { status: 'active', date: '2026-01-01' } },
  ],
}
const withError: MembershipImportReport = {
  ...preview, ok: false, counts: { ...preview.counts, error: 1 },
  rows: [...preview.rows, { line: 5, member_number: 'SYN-3', status: 'error', reason: 'effective_conflict', params: { date: '2026-04-01' } }],
}

function mounted(locale = 'cs') {
  return mount(Flow, { props: { supplier: 5, canManage: true }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DialogActions: ActionBar } } })
}
async function choose(w: ReturnType<typeof mounted>, name = 'clenove.csv', size = 10) {
  const input = w.get('[data-field="import-file"]')
  const file = new File(['x'.repeat(size)], name)
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  return file
}

// resetAllMocks: nespotřebované mockResolvedValueOnce nesmí přejít do dalšího testu.
beforeEach(() => { vi.resetAllMocks() })

describe('Membership import flow', () => {
  it('odmítne jiný formát a velký soubor ještě před odesláním', async () => {
    const w = mounted()
    await choose(w, 'clenove.pdf')
    expect(w.get('[data-section="import-error"]').text()).toBe(cs.nonprofitMembership.import.fileInvalid)
    expect(w.get('[data-action="import-preview"]').attributes('disabled')).toBeDefined()
    await choose(w, 'clenove.xlsx', 2 * 1024 * 1024 + 1)
    expect(w.find('[data-section="import-error"]').exists()).toBe(true)
    expect(api.importMembers).not.toHaveBeenCalled()
  })

  it('ukáže náhled po řádcích s důvodem a potvrdí stejný soubor s otiskem náhledu', async () => {
    api.importMembers.mockResolvedValueOnce(preview).mockResolvedValueOnce({ ...preview, dry_run: false, written: 2, failed_writes: 0,
      rows: preview.rows.map(r => r.status === 'unchanged' ? r : { ...r, outcome: 'written' }) })
    const w = mounted()
    const file = await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    expect(api.importMembers).toHaveBeenLastCalledWith(5, file, null)
    expect(w.get('[data-section="import-summary"]').text()).toContain('Nová revize: 1')
    const rows = w.findAll('[data-section="import-row"]')
    expect(rows[0].get('[data-field="import-detail"]').text()).toBe(`Pozastavené od ${formatDate('2026-03-01')}`)
    expect(rows[1].get('[data-field="import-detail"]').text()).toContain(cs.nonprofitMembership.import.contactCreate)
    expect(rows[2].get('[data-field="import-detail"]').text()).toBe(`Člen už je ve stavu Aktivní od ${formatDate('2026-01-01')}.`)
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    expect(api.importMembers).toHaveBeenLastCalledWith(5, file, 'a'.repeat(64))
    expect(w.get('[data-section="import-notice"]').text()).toContain('Zapsáno řádků: 2')
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-action="import-confirm"]').exists()).toBe(false)
  })

  it('u existujícího kontaktu ukáže jeho jméno a u zakládaného IČO', async () => {
    api.importMembers.mockResolvedValueOnce({ ...preview, rows: [
      { line: 2, member_number: 'SYN-IC', status: 'new_member', contact: 'existing', client_id: 7, contact_name: 'Synthetic Contact s.r.o.', new_status: 'active', effective_from: '2026-02-01' },
      { line: 3, member_number: 'SYN-OLD', status: 'new_member', contact: 'existing', client_id: 8, new_status: 'active', effective_from: '2026-02-01' },
      { line: 4, member_number: 'SYN-ICNEW', status: 'new_member', contact: 'create', client_id: null, contact_ic: '12345679', new_status: 'active', effective_from: '2026-02-01' },
    ] })
    const w = mounted()
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    const rows = w.findAll('[data-field="import-detail"]')
    expect(rows[0].text()).toContain('existující kontakt Synthetic Contact s.r.o.')
    expect(rows[1].text()).toContain(cs.nonprofitMembership.import.contactExisting)
    expect(rows[2].text()).toContain('založí se nový kontakt s IČO 12345679')
  })

  it('přijme soubor přetažený do zóny výběru a ukáže jeho název a velikost', async () => {
    api.importMembers.mockResolvedValueOnce(preview)
    const w = mounted()
    const file = new File(['x'.repeat(3000)], 'pretazeny.csv')
    await w.get('[data-section="import-drop"]').trigger('drop', { dataTransfer: { files: [file] } })
    expect(w.get('[data-section="import-file-name"]').text()).toContain('pretazeny.csv')
    expect(w.get('[data-section="import-file-name"]').text()).toContain('3 kB')
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    expect(api.importMembers).toHaveBeenLastCalledWith(5, file, null)
    // Nepovolený typ přetažený do zóny se odmítne stejně jako vybraný.
    await w.get('[data-action="import-other"]').trigger('click')
    await w.get('[data-section="import-drop"]').trigger('drop', { dataTransfer: { files: [new File(['x'], 'clenove.pdf')] } })
    expect(w.get('[data-section="import-error"]').text()).toBe(cs.nonprofitMembership.import.fileInvalid)
    expect(w.find('[data-section="import-file-name"]').exists()).toBe(false)
  })

  it('s chybným řádkem nejde potvrdit a filtr ukáže jen chyby', async () => {
    api.importMembers.mockResolvedValueOnce(withError)
    const w = mounted('en')
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    const confirm = w.get('[data-action="import-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(confirm.attributes('data-reason')).toBe(en.nonprofitMembership.import.fixErrors)
    await w.get('[data-field="import-only-problems"]').setValue(true)
    const rows = w.findAll('[data-section="import-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('The new revision must start after')
  })

  it('po změně dat převezme čerstvý náhled ze serveru a nic neohlásí jako zapsané', async () => {
    const fresh = { ...preview, preview_hash: 'b'.repeat(64), counts: { new_revision: 0, new_member: 1, unchanged: 2, error: 0 },
      rows: [{ ...preview.rows[0], status: 'unchanged' as const, reason: 'same_status', params: { status: 'suspended', date: '2026-03-01' } }, preview.rows[1], preview.rows[2]] }
    api.importMembers.mockResolvedValueOnce(preview).mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_membership_import_changed', details: { preview: fresh } } } } })
      .mockResolvedValueOnce({ ...fresh, dry_run: false, written: 1, failed_writes: 0 })
    const w = mounted()
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="import-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_import_changed)
    expect(w.findAll('[data-section="import-row"]')[0].get('[data-field="import-status"]').text()).toBe('Beze změny')
    expect(w.emitted('changed')).toBeUndefined()
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    expect(api.importMembers).toHaveBeenLastCalledWith(5, expect.any(File), 'b'.repeat(64))
  })

  it('chyba souboru vrátí krok výběru souboru i z potvrzení a zahodí starý náhled', async () => {
    const fileError = { response: { data: { error: { code: 'nonprofit_membership_import_file_invalid' } } } }
    api.importMembers.mockRejectedValueOnce(fileError)
    const w = mounted()
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="import-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_import_file_invalid)
    expect(w.find('[data-field="import-file"]').exists()).toBe(true)
    // Soubor se mezi náhledem a potvrzením změnil (např. přepsaný na disku): náhled už neplatí.
    api.importMembers.mockResolvedValueOnce(preview).mockRejectedValueOnce(fileError)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="import-rows"]').exists()).toBe(false)
    expect(w.find('[data-field="import-file"]').exists()).toBe(true)
    expect(w.find('[data-action="import-confirm"]').exists()).toBe(false)
  })
})

describe('Membership import flow – souběh', () => {
  it('řádek zapsaný souběžným importem ukáže jako už zapsaný, ne jako chybu', async () => {
    api.importMembers.mockResolvedValueOnce(preview).mockResolvedValueOnce({ ...preview, dry_run: false, written: 1, failed_writes: 0,
      rows: [{ ...preview.rows[0], outcome: 'already_imported' }, { ...preview.rows[1], outcome: 'written' }, preview.rows[2]] })
    const w = mounted()
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    const first = w.findAll('[data-section="import-row"]')[0]
    expect(first.get('[data-field="import-status"]').text()).toBe(cs.nonprofitMembership.import.status_already_imported)
    expect(first.get('[data-field="import-detail"]').text()).toBe(cs.nonprofitMembership.import.reason_already_imported)
  })
})

describe('Membership import flow – chyby celého souboru', () => {
  it.each(['nonprofit_membership_import_encoding', 'nonprofit_membership_import_too_many_rows'])('%s vrátí výběr souboru s přeloženou hláškou', async code => {
    api.importMembers.mockResolvedValueOnce(preview).mockRejectedValueOnce({ response: { data: { error: { code } } } })
    const w = mounted()
    await choose(w)
    await w.get('[data-action="import-preview"]').trigger('click')
    await flushPromises()
    await w.get('[data-action="import-confirm"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="import-error"]').text()).toBe((cs.nonprofitMembership.serverErrors as Record<string, string>)[code])
    expect(w.find('[data-section="import-rows"]').exists()).toBe(false)
    expect(w.find('[data-field="import-file"]').exists()).toBe(true)
  })
})

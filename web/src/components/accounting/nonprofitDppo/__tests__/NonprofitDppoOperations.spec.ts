import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import Workspace from '../NonprofitDppoWorkspace.vue'
const api = vi.hoisted(() => ({ periods: vi.fn(), profile: vi.fn(), savings: vi.fn(), native: vi.fn(), saveReduction: vi.fn(), readiness: vi.fn(), acceptance: vi.fn(), changeAcceptance: vi.fn() }))
vi.mock('@/api/nonprofitDppo', () => ({ nonprofitDppoApi: api }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key, locale: ref('cs') }) }))
const period = { id: 7, supplier_id: 1, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open', closed_at: null, created_at: '' }
const blocked = (message: string) => ({ allowed: false, code: 'synthetic_blocked', message })
const manifest = { form_code: 'DPPDP9', form_version: '2026.1', form_date: '2026-10-01', xsd_sha256: 'synthetic', xsd_verified: true, official_source: 'synthetic' }
const operations = (over: Record<string, unknown> = {}) => ({ compute: { allowed: true, code: null, message: null }, finalize: blocked('Synthetic finalize blocked'), export_xml: blocked('Synthetic export blocked'), submit: blocked('Synthetic submit blocked'), ...over })
function legal(caps: Record<string, unknown> = {}) { return { period, latest: null, kinds: ['narrow_vpp'], entity_types: ['association'], capabilities: { can_confirm: true, session_only: true, native_dppo: true, calculation_ready: true, filing_ready: false, can_edit_reduction: true, preparation_findings: [], operations: operations(), form_manifest: null, ...caps } } }
function nativeState(over: Record<string, unknown> = {}) { return { return: { type: 'po', year: 2026, inputs: {}, status: 'draft', row_version: 4 }, podklady: { accounting_decree: '504', period }, nonprofit_filing_proof_hash: 'native-proof', computed: { nonprofit: { filing_obligation: { required: true, legal_basis: 'Synthetic law' }, saving: { saving_kc: 21000 } } }, ...over } }
const ActionBar = { props: ['actions'], template: '<div><template v-for="a in actions" :key="a.key"><a v-if="a.show !== false && a.to" :data-action="a.key" :data-to="JSON.stringify(a.to)">{{a.label}}</a><button v-else-if="a.show !== false" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{a.label}}</button></template></div>' }
function render(canEditReturn = true) { return mount(Workspace, { props: { supplier: 1, canManage: true, canEditReturn }, global: { stubs: { ActionBar, DateInput: true } } }) }
beforeEach(() => { vi.resetAllMocks(); api.periods.mockResolvedValue([period]); api.savings.mockResolvedValue({ capabilities: { can_confirm: true, session_only: true }, sources: [] }); api.acceptance.mockResolvedValue(null) })
describe('Nonprofit DPPO operation-specific capabilities', () => {
 it('loads the native result for compute-only state, edits the reduction with a matching proof and offers no filing action', async () => {
  api.profile.mockResolvedValue(legal()); api.native.mockResolvedValue(nativeState())
  const w = render(); await flushPromises()
  expect(api.native).toHaveBeenCalledWith(1, 2026)
  expect(w.find('[data-field=reduction]').exists()).toBe(true)
  expect(w.get('[data-action=save-reduction]').attributes('disabled')).toBeUndefined()
  expect(w.find('[data-action=open-filing]').exists()).toBe(false)
  expect(w.get('[data-operation=compute]').attributes('data-allowed')).toBe('true')
  for (const key of ['finalize', 'export_xml', 'submit']) expect(w.get(`[data-operation=${key}]`).attributes('data-allowed')).toBe('false')
  expect(w.get('[data-operation=finalize]').text()).toContain('Synthetic finalize blocked')
  expect(w.get('[data-operation=export_xml]').text()).toContain('Synthetic export blocked')
  expect(w.get('[data-operation=submit]').text()).toContain('Synthetic submit blocked')
  expect(w.get('[data-operation=compute]').text()).toContain('nonprofitDppo.operations.labels.compute')
  expect(w.find('[data-test=operation-message]').exists()).toBe(true)
  w.unmount()
 })
 it('keeps reduction editing disabled without the report write permission', async () => {
  api.profile.mockResolvedValue(legal()); api.native.mockResolvedValue(nativeState())
  const w = render(false); await flushPromises()
  expect(api.native).toHaveBeenCalled()
  expect(w.get('[data-action=save-reduction]').attributes('disabled')).toBeDefined()
  w.unmount()
 })
 it('shows the version and date of the current form when the server provides it', async () => {
  api.profile.mockResolvedValue(legal({ form_manifest: manifest })); api.native.mockResolvedValue(nativeState())
  const w = render(); await flushPromises()
  expect(w.get('[data-test=form-manifest]').text()).toBe('nonprofitDppo.operations.form:{"code":"DPPDP9","version":"2026.1","date":"2026-10-01"}')
  w.unmount()
 })
 it.each([
  ['compute operation blocked', { operations: operations({ compute: blocked('Synthetic compute blocked') }) }],
  ['calculation not ready while legacy filing flag is set', { calculation_ready: false, filing_ready: true }],
  ['native timeframe unsupported', { native_dppo: false }]
 ])('does not call the native endpoint when %s', async (_name, caps) => {
  api.profile.mockResolvedValue(legal(caps))
  const w = render(); await flushPromises()
  expect(api.native).not.toHaveBeenCalled()
  expect(w.find('[data-field=reduction]').exists()).toBe(false)
  expect(w.find('[data-action=open-filing]').exists()).toBe(false)
  w.unmount()
 })
 it('shows the server message of a blocked compute operation', async () => {
  api.profile.mockResolvedValue(legal({ operations: operations({ compute: blocked('Synthetic compute blocked') }) }))
  const w = render(); await flushPromises()
  expect(w.get('[data-operation=compute]').attributes('data-allowed')).toBe('false')
  expect(w.get('[data-operation=compute]').text()).toContain('Synthetic compute blocked')
  w.unmount()
 })
 it.each([
  ['period', { podklady: { accounting_decree: '504', period: { ...period, id: 99 } } }],
  ['year', { return: { type: 'po', year: 2025, inputs: {}, status: 'draft', row_version: 4 } }],
  ['proof', { nonprofit_filing_proof_hash: undefined }]
])('offers neither reduction editing nor filing for a native result with mismatching %s', async (_name, over) => {
  api.profile.mockResolvedValue(legal({ filing_ready: true, operations: operations({ finalize: { allowed: true, code: null, message: null } }) })); api.native.mockResolvedValue(nativeState(over))
  const w = render(); await flushPromises()
  expect(api.native).toHaveBeenCalled()
  expect(w.find('[data-action=open-filing]').exists()).toBe(false)
  const save = w.find('[data-action=save-reduction]')
  expect(!save.exists() || save.attributes('disabled') !== undefined).toBe(true)
  w.unmount()
 })
 it('opens filing only when filing is ready and finalization is allowed', async () => {
  api.profile.mockResolvedValue(legal({ filing_ready: true, operations: operations({ finalize: { allowed: true, code: null, message: null } }) })); api.native.mockResolvedValue(nativeState())
  const ready = render(); await flushPromises()
  expect(JSON.parse(ready.get('[data-action=open-filing]').attributes('data-to')!)).toEqual({ name: 'reports-income-tax', query: { type: 'po', year: '2026', tab: 'nahled' } })
  ready.unmount()
  api.profile.mockResolvedValue(legal({ filing_ready: true })); api.native.mockResolvedValue(nativeState())
  const finalizeBlocked = render(); await flushPromises()
  expect(finalizeBlocked.find('[data-action=open-filing]').exists()).toBe(false)
  expect(finalizeBlocked.get('[data-action=save-reduction]').attributes('disabled')).toBeUndefined()
  finalizeBlocked.unmount()
 })
})

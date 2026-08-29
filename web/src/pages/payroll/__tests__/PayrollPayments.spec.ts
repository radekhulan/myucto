import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  liabilities: vi.fn(),
  materialize: vi.fn(),
  payerOptions: vi.fn(),
  batches: vi.fn(),
  createBatch: vi.fn(),
  generateExport: vi.fn(),
  createDownloadGrant: vi.fn(),
  downloadExport: vi.fn(),
  reconciliation: vi.fn(),
  searchOptions: vi.fn(),
  match: vi.fn(),
  reverse: vi.fn(),
  matchIncomingRefund: vi.fn(),
  reverseIncomingRefund: vi.fn(),
  runs: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))

vi.mock('@/api/payrollPayments', () => ({
  payrollPaymentsApi: {
    liabilities: m.liabilities,
    materializeNetWages: m.materialize,
    materializeLiabilities: m.materialize,
    payerOptions: m.payerOptions,
    batches: m.batches,
    createBatch: m.createBatch,
    generateExport: m.generateExport,
    createDownloadGrant: m.createDownloadGrant,
    downloadExport: m.downloadExport,
    reconciliation: m.reconciliation,
    searchOptions: m.searchOptions,
    match: m.match,
    reverse: m.reverse,
    matchIncomingRefund: m.matchIncomingRefund,
    reverseIncomingRefund: m.reverseIncomingRefund,
  },
}))
vi.mock('@/api/payroll', () => ({
  payrollApi: { runs: m.runs },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: m.canWrite,
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollPayments from '@/pages/payroll/PayrollPayments.vue'

/**
 * Odpověď seznamu závazků. `totals` jsou za CELÉ období a znaménko v nich má
 * server už vyřešené (příchozí závazek odečítá) — tady se kvůli tomu dopočítají
 * stejným pravidlem, ne prostým součtem.
 */
function liabilityList(items: Array<Record<string, unknown>>) {
  const sum = (key: 'amount_minor' | 'allocated_minor' | 'settled_minor'): number =>
    items.reduce((total, item) => {
      const amount = item[key] as number
      return total + (item.direction === 'incoming' ? -amount : amount)
    }, 0)
  return {
    period: '2026-08',
    items,
    total: items.length,
    totals: {
      amount_minor: sum('amount_minor'),
      allocated_minor: sum('allocated_minor'),
      settled_minor: sum('settled_minor'),
    },
    limit: 50,
    offset: 0,
  }
}

describe('PayrollPayments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.canWrite.mockImplementation(
      (permission: string) => permission === 'payroll.payments',
    )
    m.liabilities.mockResolvedValue(liabilityList([{
      id: 41,
      run_id: 11,
      revision_id: 12,
      revision_no: 1,
      employee_id: 31,
      employee_name: 'Syntetická osoba',
      recipient_name: 'Syntetická osoba',
      institution_type: null,
      institution_code: null,
      liability_kind: 'net_wage',
      direction: 'outgoing',
      recipient_kind: 'bank',
      payment_target_status: 'ready',
      payment_target_masked: '••••0005/0100',
      batch_eligibility: 'ready',
      batch_block_reason: null,
      revision_kind: 'regular',
      due_on: '2026-08-15',
      currency_code: 'CZK',
      amount_minor: 4_250_000,
      allocated_minor: 0,
      settled_minor: 0,
      state: 'open',
      created_at: '2026-08-03 08:00:00',
    }]))
    m.runs.mockResolvedValue([{
      id: 11,
      period_start: '2026-08-01',
      payment_date: '2026-08-15',
      status: 'approved',
      current_revision_no: 1,
      revision_id: 12,
      revision_no: 1,
      revision_status: 'approved',
      payment_materialization_supported: true,
      row_version: 5,
      result_snapshot: null,
      available_commands: [],
      validations: [],
    }])
    m.materialize.mockResolvedValue({
      liability_ids: [41],
      created_count: 0,
      preparation_issues: [],
    })
    m.payerOptions.mockResolvedValue([{
      reference: 'currency:7',
      currency_id: 7,
      currency_code: 'CZK',
      bank_name: 'Syntetická banka',
      masked_account: '••••0005/0100',
      export_formats: ['abo'],
    }])
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-15',
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [],
      }],
    })
    m.createBatch.mockResolvedValue({
      batch_id: 51,
      declared_item_count: 1,
    })
    m.generateExport.mockResolvedValue({
      export_id: 61,
      batch_id: 51,
      created: true,
      replayed: false,
    })
    m.createDownloadGrant.mockResolvedValue({
      grant_id: 71,
      export_id: 61,
      token: 'synthetic-one-use-token',
      expires_at: '2026-08-04 08:02:00',
    })
    m.downloadExport.mockResolvedValue(new Blob(['synthetic ABO']))
    m.reconciliation.mockResolvedValue({
      period: '2026-08',
      allocations: [{
        id: 81,
        item_id: 82,
        item_reference: 'payroll-item:synthetic',
        batch_id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        planned_payment_date: '2026-08-15',
        liability_id: 41,
        liability_kind: 'net_wage',
        direction: 'outgoing',
        currency_code: 'CZK',
        employee_name: 'Syntetická osoba',
        amount_minor: 4_250_000,
        settled_minor: 0,
        remaining_minor: 4_250_000,
      }],
      allocations_truncated: false,
      incoming_liabilities: [{
        id: 44,
        liability_reference: 'payroll-liability:incoming:synthetic',
        liability_kind: 'net_wage',
        direction: 'incoming',
        due_on: '2026-08-15',
        currency_code: 'CZK',
        employee_name: 'Syntetická osoba – opravná vratka',
        amount_minor: 50_000,
        settled_minor: 0,
        remaining_minor: 50_000,
      }],
      incoming_liabilities_truncated: false,
      offered_limit: 50,
      matches: [],
      // Historie párování se stránkuje; nabídka storna má vlastní kolekci,
      // aby nezávisela na tom, kterou stránku historie uživatel čte.
      matches_total: 0,
      matches_limit: 25,
      matches_offset: 0,
      reversible_matches: [],
      bank_evidence: [{
        kind: 'bank',
        bank_statement_id: 91,
        bank_transaction_id: 92,
        cash_document_id: null,
        date: '2026-08-15',
        amount_minor: 4_250_000,
        currency_code: 'CZK',
        direction: 'outgoing',
        description: 'Syntetická výplata',
        reference: null,
        status: 'unmatched',
        available_match_minor: 4_250_000,
        available_reversal_minor: 4_250_000,
      }, {
        kind: 'bank',
        bank_statement_id: 95,
        bank_transaction_id: 96,
        cash_document_id: null,
        date: '2026-08-20',
        amount_minor: 50_000,
        currency_code: 'CZK',
        direction: 'incoming',
        description: 'Syntetická přijatá vratka',
        reference: null,
        available_match_minor: 50_000,
        available_reversal_minor: 50_000,
      }],
      bank_evidence_truncated: false,
      cash_evidence: [],
      cash_evidence_truncated: false,
    })
    m.match.mockResolvedValue({
      event: {
        id: 101,
        event_kind: 'matched',
        allocation_id: 81,
        source_match_id: null,
        evidence_kind: 'bank',
        bank_statement_id: 91,
        bank_transaction_id: 92,
        cash_document_id: null,
        actual_payment_date: '2026-08-15',
        amount_minor: 4_250_000,
        evidence_currency_code: 'CZK',
        evidence_hash: 'a'.repeat(64),
        idempotency_key: 'synthetic-match',
        reversible_minor: 4_250_000,
        created_at: '2026-08-15 12:00:00',
        employee_name: 'Syntetická osoba',
        liability_kind: 'net_wage',
      },
    })
    m.reverse.mockResolvedValue({
      event: {
        id: 102,
        event_kind: 'reversed',
        allocation_id: 81,
        source_match_id: 101,
        evidence_kind: 'bank',
        bank_statement_id: 93,
        bank_transaction_id: 94,
        cash_document_id: null,
        actual_payment_date: '2026-08-16',
        amount_minor: -4_250_000,
        evidence_currency_code: 'CZK',
        evidence_hash: 'b'.repeat(64),
        idempotency_key: 'synthetic-reversal',
        reversible_minor: 0,
        created_at: '2026-08-16 12:00:00',
        employee_name: 'Syntetická osoba',
        liability_kind: 'net_wage',
      },
    })
    m.matchIncomingRefund.mockResolvedValue({
      id: 103,
      allocation_id: null,
      liability_id: 44,
      event_kind: 'matched',
      source_match_id: null,
      amount_minor: 50_000,
      evidence_kind: 'bank',
      bank_statement_id: 95,
      bank_transaction_id: 96,
      cash_document_id: null,
      actual_payment_date: '2026-08-20',
      evidence_amount_minor: 50_000,
      evidence_currency_code: 'CZK',
      evidence_fact_hash: 'c'.repeat(64),
      replayed: false,
    })
    m.reverseIncomingRefund.mockResolvedValue({
      id: 104,
      allocation_id: null,
      liability_id: 44,
      event_kind: 'reversed',
      source_match_id: 103,
      amount_minor: -50_000,
      evidence_kind: 'bank',
      bank_statement_id: 97,
      bank_transaction_id: 98,
      cash_document_id: null,
      actual_payment_date: '2026-08-21',
      evidence_amount_minor: 50_000,
      evidence_currency_code: 'CZK',
      evidence_fact_hash: 'd'.repeat(64),
      replayed: false,
    })
  })

  it('opens the period from a payroll run link and preselects its compatible liabilities', async () => {
    m.routeQuery = {
      period: '2026-08',
      run: '11',
      focus: 'bank-order',
    }
    const base = (await m.liabilities()).items[0]
    m.liabilities.mockResolvedValue(liabilityList([
      base,
      { ...base, id: 42, employee_id: 32, employee_name: 'Druhá osoba' },
      { ...base, id: 43, run_id: 12, employee_id: 33, employee_name: 'Jiný běh' },
      { ...base, id: 44, run_id: 11, due_on: '2026-08-20', employee_id: null },
    ]))

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(m.liabilities).toHaveBeenCalledWith(
      '2026-08',
      { limit: 50, offset: 0 },
    )
    expect(wrapper.get('[data-test="run-payment-shortcut"]').text())
      .toContain('payroll.payments.run_shortcut.ready')
    expect(wrapper.get('[data-test="batch-selection-summary"]').text())
      .toContain('"count":2')
  })

  /*
   * Dva různé scénáře na téže obrazovce, které dřív vypadaly stejně:
   * „nenačetlo se" vs. „za období opravdu nic není". První musí nabídnout
   * opakování, druhý smí tvrdit, že je prázdno.
   */
  it('offers a retry instead of an empty state when the liabilities fail to load', async () => {
    m.liabilities.mockRejectedValue(new Error('network'))

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.payments.load_failed_hint')
    // Prázdný stav se nesmí ukázat — o závazcích nic nevíme.
    expect(wrapper.text()).not.toContain('payroll.payments.empty_blocked')
    expect(m.error).toHaveBeenCalled()

    m.liabilities.mockResolvedValue(liabilityList([]))
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the period genuinely has no liabilities', async () => {
    m.liabilities.mockResolvedValue(liabilityList([]))

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.payments.empty')
    expect(wrapper.text()).not.toContain('payroll.payments.load_failed_hint')
  })

  it('explains why creating liabilities is disabled without an approved revision', async () => {
    // Bez použitelné revize je hlavní akce mrtvá; vysvětlení dřív viselo jen
    // v prázdném stavu, takže u neprázdného seznamu nebylo vidět vůbec.
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const button = wrapper.get('[data-test="materialize"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe('payroll.payments.materialize_blocked')
    expect(wrapper.get('[data-test="materialize-blocked"]').text())
      .toBe('payroll.payments.materialize_blocked')
  })

  it('drops the disabled reason once the action is usable', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const button = wrapper.get('[data-test="materialize"]')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('title')).toBeUndefined()
    expect(wrapper.find('[data-test="materialize-blocked"]').exists()).toBe(false)
  })

  it('renders matching desktop and mobile liability views without sensitive references', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.text()).toContain('payroll.payments.recipient.bank')
    expect(wrapper.text()).toContain('payroll.payments.state.open')
    expect(wrapper.text()).not.toContain('employee-account:')
    expect(wrapper.text()).not.toContain('bank_account_hash')
    expect(wrapper.findAll('nav button')).toHaveLength(3)
  })

  it('shows institutions and blocks an incoming correction on both layouts', async () => {
    const healthItem = {
      id: 42,
      run_id: 11,
      revision_id: 12,
      revision_no: 1,
      employee_id: null,
      employee_name: null,
      recipient_name: 'Syntetická pojišťovna 111',
      institution_type: 'health_insurer',
      institution_code: '111',
      liability_kind: 'health_insurance',
      direction: 'outgoing',
      recipient_kind: 'bank',
      payment_target_status: 'ready',
      payment_target_masked: '••••0005/0100',
      batch_eligibility: 'ready',
      batch_block_reason: null,
      revision_kind: 'regular',
      due_on: '2026-08-20',
      currency_code: 'CZK',
      amount_minor: 1_350_000,
      allocated_minor: 0,
      settled_minor: 0,
      state: 'open',
      created_at: '2026-08-03 08:00:00',
    }
    m.liabilities.mockResolvedValue(liabilityList([
      healthItem,
      {
        ...healthItem,
        id: 43,
        recipient_name: 'Syntetická pojišťovna 201',
        institution_code: '201',
        payment_target_masked: '••••1005/0100',
        amount_minor: 650_000,
      },
      {
        ...healthItem,
        id: 44,
        revision_id: 13,
        revision_no: 2,
        direction: 'incoming',
        batch_eligibility: 'blocked',
        batch_block_reason: 'unsupported_direction',
        revision_kind: 'correction',
        amount_minor: 50_000,
      },
      {
        ...healthItem,
        id: 45,
        recipient_name: 'Syntetická správa sociálního zabezpečení',
        institution_type: 'social_security',
        institution_code: 'P',
        liability_kind: 'social_insurance',
        payment_target_masked: '••••2005/0100',
        amount_minor: 3_190_000,
      },
      {
        ...healthItem,
        id: 46,
        recipient_name: 'Syntetický finanční úřad',
        institution_type: 'tax_office',
        institution_code: 'advance_tax',
        liability_kind: 'advance_tax',
        payment_target_masked: '••••3005/0100',
        amount_minor: 1_250_000,
      },
    ]))

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    for (const selector of ['[data-layout="desktop"]', '[data-layout="mobile"]']) {
      const layout = wrapper.get(selector)
      expect(layout.text()).toContain('Syntetická pojišťovna 111')
      expect(layout.text()).toContain('Syntetická pojišťovna 201')
      expect(layout.text()).toContain('Syntetická správa sociálního zabezpečení')
      expect(layout.text()).toContain('Syntetický finanční úřad')
      expect(layout.text()).toContain('••••0005/0100')
      expect(layout.text()).toContain('••••1005/0100')
      expect(layout.text()).toContain('social_insurance')
      expect(layout.text()).toContain('advance_tax')
      expect(layout.text()).toContain('payroll.payments.target.ready')
      expect(layout.text()).toContain('payroll.payments.correction')
    }
    const rowCheckboxes = wrapper.get('[data-layout="desktop"]')
      .findAll('tbody input[type="checkbox"]')
    expect(rowCheckboxes).toHaveLength(5)
    expect(rowCheckboxes[0].attributes('disabled')).toBeUndefined()
    expect(rowCheckboxes[1].attributes('disabled')).toBeUndefined()
    expect(rowCheckboxes[2].attributes('disabled')).toBeDefined()
    expect(rowCheckboxes[3].attributes('disabled')).toBeUndefined()
    expect(rowCheckboxes[4].attributes('disabled')).toBeUndefined()
  })

  it('materializes only the approved current revision and safely replays it', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const button = wrapper.findAll('header button')
      .find(item => item.text().includes('payroll.payments.materialize'))
    expect(button).toBeDefined()
    await button!.trigger('click')
    await flushPromises()

    expect(m.materialize).toHaveBeenCalledOnce()
    expect(m.materialize).toHaveBeenCalledWith(12)
    expect(m.success).toHaveBeenCalledWith(
      expect.stringContaining('payroll.payments.materialized_replay'),
    )
    expect(m.liabilities).toHaveBeenCalledTimes(2)
  })

  it('continues with supported revisions after one materialization fails', async () => {
    m.runs.mockResolvedValue([
      {
        id: 11,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 12,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: true,
        row_version: 5,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
      {
        id: 21,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 22,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: true,
        row_version: 2,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
      {
        id: 31,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 32,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: false,
        row_version: 1,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
    ])
    m.materialize
      .mockRejectedValueOnce(new Error('synthetic blocked revision'))
      .mockResolvedValueOnce({
        liability_ids: [42],
        created_count: 1,
        preparation_issues: [],
      })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    const button = wrapper.findAll('header button')
      .find(item => item.text().includes('payroll.payments.materialize'))
    await button!.trigger('click')
    await flushPromises()

    expect(m.materialize).toHaveBeenCalledTimes(2)
    expect(m.materialize).toHaveBeenNthCalledWith(1, 12)
    expect(m.materialize).toHaveBeenNthCalledWith(2, 22)
    expect(m.materialize).not.toHaveBeenCalledWith(32)
    expect(m.success).toHaveBeenCalled()
    expect(m.error).toHaveBeenCalled()
  })

  it('reuses the pending idempotency key after an export timeout', async () => {
    m.generateExport
      .mockRejectedValueOnce(new Error('synthetic timeout'))
      .mockResolvedValueOnce({
        export_id: 61,
        batch_id: 51,
        created: false,
        replayed: true,
      })
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    const firstButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    expect(firstButton).toBeDefined()
    await firstButton!.trigger('click')
    await flushPromises()

    const retryButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    await retryButton!.trigger('click')
    await flushPromises()

    expect(m.generateExport).toHaveBeenCalledTimes(2)
    expect(m.generateExport.mock.calls[0][0]).toBe(51)
    expect(m.generateExport.mock.calls[1][0]).toBe(51)
    expect(m.generateExport.mock.calls[1][1])
      .toBe(m.generateExport.mock.calls[0][1])
  })

  it('creates an ABO batch from a selected liability with the automatic payer option', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const desktop = wrapper.get('[data-layout="desktop"]')
    const rowCheckbox = desktop.findAll('input[type="checkbox"]')[1]
    await rowCheckbox.setValue(true)
    await flushPromises()

    const createButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.create'))
    expect(createButton).toBeDefined()
    expect(createButton!.attributes('disabled')).toBeUndefined()
    await createButton!.trigger('click')
    await flushPromises()

    expect(m.createBatch).toHaveBeenCalledOnce()
    expect(m.createBatch).toHaveBeenCalledWith({
      export_format: 'abo',
      payer_reference: 'currency:7',
      items: [{
        liability_id: 41,
        amount_minor: 4_250_000,
      }],
    })
    expect(wrapper.find('[data-layout="batch-desktop"]').exists()).toBe(true)
    expect(wrapper.find('[data-layout="batch-mobile"]').exists()).toBe(true)
  })

  it('admits an idempotent replay instead of claiming a second batch was created', async () => {
    m.createBatch.mockResolvedValue({
      batch_id: 51,
      declared_item_count: 1,
      created: false,
      replayed: true,
    })
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const desktop = wrapper.get('[data-layout="desktop"]')
    await desktop.findAll('input[type="checkbox"]')[1].setValue(true)
    await flushPromises()
    const createButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.create'))
    await createButton!.trigger('click')
    await flushPromises()

    expect(m.success).toHaveBeenCalledWith(
      expect.stringContaining('payroll.payments.batch.replayed'),
    )
  })

  it('generates a batch export and downloads it through a one-use grant', async () => {
    const exportedFile = {
      id: 61,
      revision_no: 1,
      file_sha256: 'a'.repeat(64),
      size_bytes: 13,
      mime_type: 'text/plain',
      suggested_filename: 'mzdy-2026-08.abo',
      created_at: '2026-08-04 08:00:00',
    }
    m.batches
      .mockResolvedValueOnce({
        period: '2026-08',
        items: [{
          id: 51,
          batch_reference: 'payroll-batch:synthetic',
          channel: 'bank',
          export_format: 'abo',
          planned_payment_date: '2026-08-15',
          currency_code: 'CZK',
          declared_total_minor: 4_250_000,
          declared_item_count: 1,
          settled_minor: 0,
          created_at: '2026-08-04 08:00:00',
          exports: [exportedFile],
        }],
      })
      .mockResolvedValue({
        period: '2026-08',
        items: [{
          id: 51,
          batch_reference: 'payroll-batch:synthetic',
          channel: 'bank',
          export_format: 'abo',
          planned_payment_date: '2026-08-15',
          currency_code: 'CZK',
          declared_total_minor: 4_250_000,
          declared_item_count: 1,
          settled_minor: 0,
          created_at: '2026-08-04 08:00:00',
          exports: [exportedFile],
        }],
      })
    const createObjectUrl = vi.fn(() => 'blob:synthetic-payroll-export')
    const revokeObjectUrl = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: createObjectUrl,
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: revokeObjectUrl,
    })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => undefined)

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    const desktop = wrapper.get('[data-layout="batch-desktop"]')
    const mobile = wrapper.get('[data-layout="batch-mobile"]')
    expect(desktop.text()).toContain('payroll.payments.batch.download')
    expect(mobile.text()).toContain('payroll.payments.batch.download')

    const generateButton = desktop.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    await generateButton!.trigger('click')
    await flushPromises()

    expect(m.generateExport).toHaveBeenCalledOnce()
    expect(m.generateExport).toHaveBeenCalledWith(
      51,
      expect.stringMatching(/^payroll-export-51-/),
    )

    const downloadButton = wrapper.get('[data-layout="batch-desktop"]')
      .findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.download'))
    await downloadButton!.trigger('click')
    await flushPromises()

    expect(m.createDownloadGrant).toHaveBeenCalledWith(61)
    expect(m.downloadExport).toHaveBeenCalledWith('synthetic-one-use-token')
    expect(createObjectUrl).toHaveBeenCalledWith(expect.any(Blob))
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectUrl).toHaveBeenCalledWith(
      'blob:synthetic-payroll-export',
    )
    click.mockRestore()
  })

  it('hides generate and download actions from a read-only user on both layouts', async () => {
    m.canWrite.mockReturnValue(false)
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-15',
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [{
          id: 61,
          revision_no: 1,
          file_sha256: 'a'.repeat(64),
          size_bytes: 13,
          mime_type: 'text/plain',
          suggested_filename: 'mzdy-2026-08.abo',
          created_at: '2026-08-04 08:00:00',
        }],
      }],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.payments.readonly_hint')
    expect(wrapper.get('[data-layout="desktop"]').findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.get('[data-layout="mobile"]').findAll('input[type="checkbox"]')).toHaveLength(0)

    await wrapper.findAll('nav button')[1].trigger('click')

    const desktop = wrapper.get('[data-layout="batch-desktop"]')
    const mobile = wrapper.get('[data-layout="batch-mobile"]')
    for (const layout of [desktop, mobile]) {
      expect(layout.text()).not.toContain('payroll.payments.batch.generate')
      expect(layout.text()).not.toContain('payroll.payments.batch.download')
    }
    expect(m.generateExport).not.toHaveBeenCalled()
    expect(m.createDownloadGrant).not.toHaveBeenCalled()
    expect(m.downloadExport).not.toHaveBeenCalled()
  })

  it('renders settlement matching with standard searchable inputs and hides writes from read-only users', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('payroll.payments.settlements.new_match')
    expect(wrapper.text()).toContain('payroll.payments.settlements.new_reversal')
    expect(wrapper.text()).toContain('payroll.payments.settlements.incoming_title')
    expect(wrapper.findAllComponents({ name: 'SearchableSelect' })).toHaveLength(6)

    m.canWrite.mockReturnValue(false)
    const readonlyWrapper = mount(PayrollPayments)
    await flushPromises()
    await readonlyWrapper.findAll('nav button')[2].trigger('click')

    expect(readonlyWrapper.text()).not.toContain('payroll.payments.settlements.new_match')
    expect(readonlyWrapper.text()).not.toContain('payroll.payments.settlements.new_reversal')
    expect(readonlyWrapper.text()).toContain('payroll.payments.settlements.history')
  })

  it('matches a partial incoming refund only after an explicit receipt confirmation', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    const form = wrapper.get('[data-test="incoming-refund-form"]')
    const selects = form.findAllComponents({ name: 'SearchableSelect' })
    await selects[0].vm.$emit('update:modelValue', 44)
    await flushPromises()
    await selects[1].vm.$emit('update:modelValue', 'bank:95:96')
    await flushPromises()

    const amount = form.get('input[inputmode="decimal"]')
    await amount.setValue('250,00')
    const submit = form.get('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await form.get('[data-test="incoming-refund-confirmation"]').setValue(true)
    expect(submit.attributes('disabled')).toBeUndefined()
    await amount.setValue('300,00')
    expect(form.get('[data-test="incoming-refund-confirmation"]')
      .element).toHaveProperty('checked', false)
    await amount.setValue('250,00')
    await form.get('[data-test="incoming-refund-confirmation"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()

    expect(m.matchIncomingRefund).toHaveBeenCalledWith({
      liability_id: 44,
      amount_minor: 25_000,
      evidence: {
        kind: 'bank',
        bank_statement_id: 95,
        bank_transaction_id: 96,
      },
      idempotency_key: expect.stringMatching(/^payroll-incoming-44-bank:95:96-25000-/),
    })
    expect(m.match).not.toHaveBeenCalled()
    expect(m.success).toHaveBeenCalledWith(
      'payroll.payments.settlements.incoming_success',
    )
  })

  it('supports a posted cash receipt as explicit incoming-refund evidence', async () => {
    const base = await m.reconciliation()
    m.reconciliation.mockResolvedValue({
      ...base,
      cash_evidence: [{
        kind: 'cash',
        bank_statement_id: null,
        bank_transaction_id: null,
        cash_document_id: 501,
        date: '2026-08-20',
        amount_minor: 50_000,
        currency_code: 'CZK',
        direction: 'incoming',
        status: 'posted',
        description: 'Syntetický příjmový pokladní doklad',
        reference: 'PP-2026-001',
        available_match_minor: 50_000,
        available_reversal_minor: 50_000,
      }],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    const form = wrapper.get('[data-test="incoming-refund-form"]')
    const selects = form.findAllComponents({ name: 'SearchableSelect' })
    await selects[0].vm.$emit('update:modelValue', 44)
    await form.get('input[type="radio"][value="cash"]').setValue(true)
    await flushPromises()
    await selects[1].vm.$emit('update:modelValue', 'cash:501')
    await form.get('[data-test="incoming-refund-confirmation"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()

    expect(m.matchIncomingRefund).toHaveBeenCalledWith(expect.objectContaining({
      liability_id: 44,
      amount_minor: 50_000,
      evidence: {
        kind: 'cash',
        cash_document_id: 501,
      },
    }))
  })

  it('routes reversal of a direct incoming match to the incoming-refund endpoint', async () => {
    const base = await m.reconciliation()
    const directIncomingMatch = {
      id: 103,
      allocation_id: null,
      liability_id: 44,
      event_kind: 'matched' as const,
      source_match_id: null,
      amount_minor: 50_000,
      evidence_kind: 'bank' as const,
      bank_statement_id: 95,
      bank_transaction_id: 96,
      cash_document_id: null,
      actual_payment_date: '2026-08-20',
      evidence_amount_minor: 50_000,
      evidence_currency_code: 'CZK',
      evidence_fact_hash: 'c'.repeat(64),
      batch_reference: null,
      liability_kind: 'net_wage',
      allocation_direction: 'incoming' as const,
      allocation_currency_code: 'CZK',
      employee_name: 'Syntetická osoba – opravná vratka',
      reversible_minor: 50_000,
      created_at: '2026-08-20 10:00:00',
    }
    m.reconciliation.mockResolvedValue({
      ...base,
      reversible_matches: [directIncomingMatch],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    const source = wrapper.findAllComponents({ name: 'SearchableSelect' })
      .find(select => select.props('placeholder')
        === 'payroll.payments.settlements.select_source_match')
    expect(source).toBeDefined()
    await source!.vm.$emit('update:modelValue', 103)
    await flushPromises()

    const reversalForm = wrapper.get('[data-test="payment-reversal-form"]')
    const reverseButton = reversalForm.get('button[type="submit"]')
    expect(reverseButton).toBeDefined()
    expect(reverseButton!.attributes('disabled')).toBeUndefined()
    await reversalForm.trigger('submit')
    await flushPromises()

    expect(m.reverseIncomingRefund).toHaveBeenCalledWith(expect.objectContaining({
      source_match_id: 103,
      amount_minor: 50_000,
    }))
    expect(m.reverse).not.toHaveBeenCalled()
  })

  it('keeps a 500-liability incoming picker bounded and searches it on the server', async () => {
    const base = await m.reconciliation()
    const fiveHundred = Array.from({ length: 500 }, (_, index) => ({
      ...base.incoming_liabilities[0],
      id: 10_000 + index,
      liability_reference: `payroll-liability:incoming:${index + 1}`,
      employee_name: `Syntetická vratka ${index + 1}`,
    }))
    m.reconciliation.mockResolvedValue({
      ...base,
      incoming_liabilities: fiveHundred.slice(0, 50),
      incoming_liabilities_truncated: true,
    })
    m.searchOptions.mockResolvedValue({
      kind: 'incoming_liabilities',
      items: fiveHundred.slice(200, 220),
      truncated: true,
      limit: 20,
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    const liabilitySelect = wrapper.findAllComponents({ name: 'SearchableSelect' })
      .find(select => select.props('placeholder')
        === 'payroll.payments.settlements.select_incoming_liability')
    expect(liabilitySelect).toBeDefined()
    expect(liabilitySelect!.props('remote')).toBe(true)
    await liabilitySelect!.find('input').trigger('focus')
    await flushPromises()

    expect(m.searchOptions).toHaveBeenCalledWith({
      period: expect.any(String),
      kind: 'incoming_liabilities',
      q: '',
    })
    expect(liabilitySelect!.props('options')).toHaveLength(20)
    expect(liabilitySelect!.find('[data-test="searchable-select-truncated"]').text())
      .toContain('payroll.payments.settlements.options_truncated')
    expect(wrapper.text()).not.toContain('Syntetická vratka 500')
  })

  /**
   * Historie párování je append-only a roste s každým plněním i stornem, takže
   * se stránkuje. Nabídka storna se ale NESMÍ brát ze zobrazené stránky —
   * jinak by šlo stornovat jen to, co má uživatel zrovna na obrazovce.
   */
  it('paginates the settlement history without shrinking the reversal offer', async () => {
    const event = (id: number, name: string) => ({
      id,
      allocation_id: 81,
      event_kind: 'matched' as const,
      source_match_id: null,
      amount_minor: 10_000,
      evidence_kind: 'bank' as const,
      bank_statement_id: 91,
      bank_transaction_id: 92,
      cash_document_id: null,
      actual_payment_date: '2026-08-15',
      evidence_amount_minor: 10_000,
      evidence_currency_code: 'CZK',
      evidence_fact_hash: 'a'.repeat(64),
      batch_reference: 'payroll-batch:synthetic',
      liability_kind: 'net_wage',
      employee_name: name,
      reversible_minor: 10_000,
      created_at: '2026-08-15 10:00:00',
    })
    const reversible = [
      event(101, 'Syntetická osoba A'),
      event(102, 'Syntetická osoba B'),
    ]
    const base = await m.reconciliation()
    m.reconciliation.mockResolvedValue({
      ...base,
      matches: [event(101, 'Syntetická osoba A')],
      matches_total: 40,
      matches_limit: 25,
      matches_offset: 0,
      reversible_matches: reversible,
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(m.reconciliation).toHaveBeenLastCalledWith(
      expect.any(String),
      { limit: 25, offset: 0 },
    )
    expect(wrapper.text()).toContain('Syntetická osoba A')
    expect(wrapper.text()).not.toContain('Syntetická osoba B')

    m.reconciliation.mockResolvedValue({
      ...base,
      matches: [event(102, 'Syntetická osoba B')],
      matches_total: 40,
      matches_limit: 25,
      matches_offset: 25,
      reversible_matches: reversible,
    })
    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()

    expect(m.reconciliation).toHaveBeenLastCalledWith(
      expect.any(String),
      { limit: 25, offset: 25 },
    )
    // Nabídka storna zůstává úplná, i když historie ukazuje jinou stránku.
    const reversalSelect = wrapper.findAllComponents({ name: 'SearchableSelect' })[4]
    expect(reversalSelect.props('options')).toHaveLength(2)
  })
  /**
   * Nabídka důkazů se posílá oříznutá. Mlčky oříznutý picker je nejdražší
   * možná lež: uživatel z chybějící transakce usoudí, že platba neproběhla.
   * Test hlídá obojí — že se hledá NA SERVERU a že se oříznutí přizná větou.
   */
  it('searches truncated pickers on the server and admits the cut', async () => {
    const base = await m.reconciliation()
    m.reconciliation.mockResolvedValue({
      ...base,
      bank_evidence_truncated: true,
    })
    m.searchOptions.mockResolvedValue({
      kind: 'bank_evidence',
      items: [{
        kind: 'bank',
        bank_statement_id: 91,
        bank_transaction_id: 999,
        cash_document_id: null,
        date: '2026-08-02',
        amount_minor: 4_250_000,
        currency_code: 'CZK',
        direction: 'outgoing',
        description: 'Jehla v kupce sena',
        reference: null,
        available_match_minor: 4_250_000,
        available_reversal_minor: 4_250_000,
      }],
      truncated: true,
      limit: 20,
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    await flushPromises()

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[2].vm.$emit('update:modelValue', 81)
    await flushPromises()

    expect(m.searchOptions).toHaveBeenCalledWith(expect.objectContaining({
      kind: 'bank_evidence',
      usage: 'match',
      currency: 'CZK',
      direction: 'outgoing',
    }))

    const evidenceSelect = wrapper.findAllComponents({ name: 'SearchableSelect' })
      .find(select => select.props('placeholder')
        === 'payroll.payments.settlements.select_evidence')
    expect(evidenceSelect).toBeDefined()
    await evidenceSelect!.find('input').trigger('focus')
    await flushPromises()

    expect(wrapper.find('[data-test="searchable-select-truncated"]').text())
      .toContain('payroll.payments.settlements.options_truncated')
    expect(wrapper.text()).toContain('Jehla v kupce sena')
  })

  /*
   * Datum příkazu není zákonný termín — u odvodů jde příkaz dřív, aby částka
   * stihla být PŘIPSÁNA. Backend to už dávno posílal (`statutory_due_on`,
   * `is_shifted`), obrazovka ale ukazovala jen datum příkazu, takže dřívější
   * datum vypadalo jako chyba a účetní ho „opravila" na zákonný termín.
   */
  it('explains why the order date differs from the statutory due date', async () => {
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-18',
        statutory_due_on: '2026-08-20',
        is_shifted: true,
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [],
      }],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.find('[data-test="batch-statutory-due"]').exists()).toBe(true)
    expect(wrapper.get('[data-layout="batch-desktop"]').text())
      .toContain('payroll.payments.batch.shifted_from_statutory')
    expect(wrapper.get('[data-layout="batch-mobile"]').text())
      .toContain('payroll.payments.batch.shifted_from_statutory')
  })

  /** Když se příkaz s termínem kryje, není co vysvětlovat — a nic se nepíše. */
  it('says nothing when the order date already is the statutory due date', async () => {
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-20',
        statutory_due_on: '2026-08-20',
        is_shifted: false,
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [],
      }],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.find('[data-test="batch-statutory-due"]').exists()).toBe(false)
  })

  /** Krátká nabídka zůstává v prohlížeči — picker ji nesmí objednávat znovu. */
  it('keeps short pickers local and asks the server for nothing', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    await flushPromises()

    expect(m.searchOptions).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="searchable-select-truncated"]').exists()).toBe(false)
  })
})

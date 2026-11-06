import { describe, expect, it } from 'vitest'
import type {
  AttendanceComponentCheck,
  AttendanceEmploymentOption,
  AttendancePerson,
  AttendanceProfile,
  AttendanceSheet,
  RegistrationEmploymentOption,
  RegistrationRecord,
} from '@/api/payrollImports'
import {
  autoCreatablePersons,
  autoPersonCreateDefaults,
  personHasIdentifier,
  buildAttendanceLinks,
  buildPersonsPayload,
  personCreateDetails,
  buildRegistrationPairs,
  buildRelationChoices,
  chunk,
  hasTakeoverItem,
  pruneRelationChoices,
  recordNeedsRelationChoice,
  setRelationChoice,
  creatablePersonKeys,
  hasReadyItem,
  isRegistrationApplicable,
  buildReceivedOn,
  isBenefitRecord,
  pruneReceivedOn,
  recordAcceptsReceivedOn,
  setReceivedOn,
  minutesToHours,
  openingBalanceTotals,
  personCanBeCreated,
  personsWithoutEmploymentCount,
  pruneRegistrationPairs,
  registrationApplyBlock,
  registrationNeedsPairSelect,
  resolveHistoryToggle,
  setRegistrationPair,
  componentsToCreate,
  dataUrlToBase64,
  draftComponents,
  draftRules,
  draftSignature,
  duplicateDraft,
  effectiveEmploymentId,
  emptyComponentDraft,
  emptyProfileDraft,
  emptyRuleDraft,
  formatHours,
  guessRelationType,
  IMPORT_XML_LIMITS,
  isValidPeriod,
  mergeImportFiles,
  moveItem,
  normalizeHeader,
  normalizeRuleComponentCode,
  obstacleRateIsReduced,
  parseProfileExport,
  profileDraftIssues,
  profileExportFilename,
  profileToDraft,
  pruneManualLinks,
  pruneRegistrationSelection,
  readFileAsBase64,
  recognitionStats,
  ruleFromUnrecognized,
  ruleIssues,
  ruleUnitForMeaning,
  selectableRegistrationKeys,
  sheetLabel,
  splitDisplayName,
  summaryComponents,
  summaryMeanings,
  uniqueProfileName,
} from '../importHelpers'

function person(key: string, overrides: Partial<AttendancePerson> = {}): AttendancePerson {
  return {
    key,
    display_name: 'Testovací Jana',
    personal_number: null,
    birth_number_masked: null,
    relation_label: null,
    department: null,
    cost_center: null,
    position: null,
    weekly_hours: null,
    start_end_note: null,
    monthly_wage: null,
    sources: [],
    match: {
      status: 'not_found',
      matched_by: null,
      employment_id: null,
      employee_id: null,
      employee_name: null,
      employment_code: null,
      candidates: [],
    },
    metrics: [],
    components: [],
    reference: { gross_minor: null, net_minor: null, hours: null },
    warnings: [],
    ...overrides,
  }
}

function option(id: number): AttendanceEmploymentOption {
  return { employment_id: id, employee_id: id + 100, label: `Osoba ${id}`, code: `ZAM-${id}`, status: 'active' }
}

function profile(overrides: Partial<AttendanceProfile> = {}): AttendanceProfile {
  return {
    id: 7,
    name: 'Vzor GIRITON',
    is_sample: true,
    sample_version: 1,
    updated_at: '2026-06-30 10:00:00',
    rules: [
      { sheet: null, header: 'Jméno', meaning: 'person_name', unit: 'text', component_code: null },
      { sheet: 'Výpočet', header: 'Dovolená*', meaning: 'vacation_hours', unit: 'excel_duration', component_code: null },
      { sheet: null, header: 'Odměna*', meaning: 'component', unit: 'amount', component_code: '*' },
    ],
    components: [{ code: 'ODMENA', name: 'Odměna', kind: 'bonus' }],
    ...overrides,
  }
}

function sheet(id: string, used: boolean, meanings: string[]): AttendanceSheet {
  return {
    id,
    file: id.split('#')[0],
    sheet: id.split('#')[1],
    header_row: 1,
    data_rows: 2,
    used,
    columns: meanings.map((meaning, index) => ({
      letter: String.fromCharCode(65 + index),
      header: `H${index}`,
      meaning: meaning as AttendanceSheet['columns'][number]['meaning'],
      unit: null,
      component_code: null,
      rule_source: 'profile',
      samples: [],
    })),
  }
}

describe('soubory importu', () => {
  const file = (name: string, size: number) => ({ name, size })

  it('přijme povolené přípony a odmítne ostatní i duplicity', () => {
    const result = mergeImportFiles(
      [file('a.xlsx', 10)],
      [file('b.CSV', 10), file('c.pdf', 10), file('a.xlsx', 10)],
      ['xlsx', 'csv'],
    )
    expect(result.files.map(item => item.name)).toEqual(['a.xlsx', 'b.CSV'])
    expect(result.rejected).toEqual([
      { name: 'c.pdf', reason: 'unsupported_file' },
      { name: 'a.xlsx', reason: 'duplicate_file' },
    ])
  })

  it('hlídá velikost souboru, počet a celkový objem', () => {
    const limits = { maxFiles: 2, maxFileBytes: 100, maxTotalBytes: 150 }
    const result = mergeImportFiles(
      [],
      [file('big.xml', 101), file('a.xml', 80), file('b.xml', 80), file('c.xml', 10), file('d.xml', 10)],
      ['xml'],
      limits,
    )
    expect(result.files.map(item => item.name)).toEqual(['a.xml', 'c.xml'])
    expect(result.rejected.map(item => item.reason)).toEqual(['file_too_large', 'total_too_large', 'too_many_files'])
  })

  it('XML registrací a hlášení připustí balík JMHZ do 20 MB, obecný limit zůstává 5 MB', () => {
    const bundle = file('jmhz-1500.xml', 9_000_000)
    expect(mergeImportFiles([], [bundle], ['xml']).rejected).toEqual([{ name: 'jmhz-1500.xml', reason: 'file_too_large' }])
    const result = mergeImportFiles([], [bundle, file('obri.xml', 20_000_001)], ['xml'], IMPORT_XML_LIMITS)
    expect(result.files.map(item => item.name)).toEqual(['jmhz-1500.xml'])
    expect(result.rejected).toEqual([{ name: 'obri.xml', reason: 'file_too_large' }])
  })

  it('převede data URL i soubor na holé base64', async () => {
    expect(dataUrlToBase64('data:text/plain;base64,YWhvag==')).toBe('YWhvag==')
    expect(dataUrlToBase64('YWhvag==')).toBe('YWhvag==')
    await expect(readFileAsBase64(new Blob(['ahoj'], { type: 'text/plain' }))).resolves.toBe('YWhvag==')
  })
})

describe('registrace', () => {
  const record = (key: string, selectable: boolean) => ({ key, selectable }) as RegistrationRecord

  it('vybírá jen použitelné věty a výběr po novém náhledu pročistí', () => {
    const records = [record('a:1', true), record('a:2', false), record('b:1', true)]
    expect(selectableRegistrationKeys(records)).toEqual(['a:1', 'b:1'])
    expect(pruneRegistrationSelection(['a:1', 'a:2', 'x:9'], records)).toEqual(['a:1'])
  })
})

describe('podání dávek předchozího programu', () => {
  const benefit = (
    key: string,
    operation: RegistrationRecord['operation'],
    needsReceivedOn = false,
  ) => ({
    key,
    selectable: true,
    operation,
    benefit: {
      document: needsReceivedOn ? 'OZUSPOJ' : 'NEMPRI',
      benefit_kind: null,
      decision_number: null,
      incapacity_from: null,
      incapacity_to: null,
      case_id: null,
      case_source: null,
      received_on: null,
      needs_received_on: needsReceivedOn,
    },
  }) as RegistrationRecord

  it('pozná podání dávky a nabídne u něj den doručení', () => {
    const nempri = benefit('a:1', 'update_case')
    const none = benefit('a:2', 'none')
    const plain = { key: 'a:3', operation: 'update', selectable: true } as RegistrationRecord
    expect(isBenefitRecord(nempri)).toBe(true)
    expect(isBenefitRecord(plain)).toBe(false)
    expect(recordAcceptsReceivedOn(nempri)).toBe(true)
    expect(recordAcceptsReceivedOn(none)).toBe(false)
    expect(recordAcceptsReceivedOn(plain)).toBe(false)
    expect(recordAcceptsReceivedOn(benefit('a:4', 'none', true)), 'OZUSPOJ bez dne doručení ho musí nabídnout.').toBe(true)
  })

  it('dny doručení posílá jen platné a po novém náhledu nechá jen u podání dávek', () => {
    let map = setReceivedOn({}, 'a:1', '2026-09-30')
    map = setReceivedOn(map, 'a:2', 'rozepsáno')
    map = setReceivedOn(map, 'x:9', '2026-10-01')
    expect(buildReceivedOn(map)).toEqual([
      { key: 'a:1', received_on: '2026-09-30' },
      { key: 'x:9', received_on: '2026-10-01' },
    ])
    expect(pruneReceivedOn(map, [benefit('a:1', 'update_case'), benefit('a:2', 'none')])).toEqual({
      'a:1': '2026-09-30',
      'a:2': 'rozepsáno',
    })
    expect(setReceivedOn(map, 'a:1', '')).not.toHaveProperty('a:1')
  })
})

describe('měsíční hlášení JMHZ', () => {
  const jmhz = (key: string, overrides: Partial<RegistrationRecord> = {}): RegistrationRecord => ({
    key,
    file: 'hlaseni.xml',
    sequence: 1,
    document_type: 'JMHZ',
    action_code: 0,
    action_label: 'Měsíční hlášení 2026-03',
    prepared_on: null,
    effective_on: '2026-03-01',
    person: { full_name: 'Testovací Jana', first_name: null, last_name: null, birth_date: null, birth_number_masked: null, has_oic: false },
    employment: {
      start_on: null,
      end_on: null,
      activity_code: null,
      relation_type: null,
      relation_type_options: [],
      start_estimated: false,
      position_name: null,
      has_id_ppv: false,
    },
    match: { status: 'not_found', matched_by: null, employee_id: null, employee_name: null, employment_id: null, employment_code: null, candidates: [] },
    operation: 'pair_required',
    changes: [],
    warnings: [],
    blocker: null,
    selectable: true,
    period: '2026-03',
    form_id: 'form-1',
    history: null,
    ...overrides,
  })
  const options: RegistrationEmploymentOption[] = [
    { employment_id: 5, employee_id: 50, label: 'Testovací Jana · employment · od 2024-01-01', code: 'Z005' },
    { employment_id: 6, employee_id: 60, label: 'Zkušební Petr · dpp · od 2025-02-01', code: 'Z006' },
  ]

  it('formulář čekající na vztah nejde vybrat, po přiřazení ano', () => {
    const records = [jmhz('f:1'), jmhz('f:2', { operation: 'update' }), jmhz('f:3', { operation: 'none', selectable: false })]
    expect(isRegistrationApplicable(records[0])).toBe(false)
    expect(selectableRegistrationKeys(records)).toEqual(['f:2'])
    expect(pruneRegistrationSelection(['f:1', 'f:2'], records)).toEqual(['f:2'])
  })

  it('sestaví ruční párování a nastavené přiřazení umí zrušit', () => {
    let pairs = setRegistrationPair({}, 'f:1', 5)
    pairs = setRegistrationPair(pairs, 'f:2', 6)
    expect(buildRegistrationPairs(pairs)).toEqual([
      { key: 'f:1', employment_id: 5 },
      { key: 'f:2', employment_id: 6 },
    ])
    expect(buildRegistrationPairs(setRegistrationPair(pairs, 'f:1', null))).toEqual([{ key: 'f:2', employment_id: 6 }])
    expect(buildRegistrationPairs(setRegistrationPair(pairs, 'f:3', 0))).toHaveLength(2)
    expect(buildRegistrationPairs({})).toEqual([])
  })

  it('po novém náhledu zahodí párování zmizelých formulářů a neexistujících vztahů', () => {
    const records = [jmhz('f:1'), jmhz('f:2'), jmhz('r:1', { document_type: 'REGZEC25' })]
    expect(pruneRegistrationPairs({ 'f:1': 5, 'f:2': 99, 'r:1': 6, 'x:1': 5 }, records, options)).toEqual({ 'f:1': 5 })
  })

  it('výběr vztahu ukáže u formuláře bez vztahu, u ručního přiřazení i u odmítnutého přiřazení', () => {
    const matchedAuto = jmhz('f:2', {
      operation: 'update',
      match: { status: 'matched', matched_by: 'oic', employee_id: 50, employee_name: 'Testovací Jana', employment_id: 5, employment_code: 'Z005', candidates: [] },
    })
    const matchedManual = jmhz('f:3', { operation: 'update', match: { ...matchedAuto.match, matched_by: 'manual' } })
    const refused = jmhz('f:4', { operation: 'none', selectable: false, blocker: 'Vybraný pracovní vztah je archivovaný.' })
    expect(registrationNeedsPairSelect(jmhz('f:1'), {})).toBe(true)
    expect(registrationNeedsPairSelect(matchedAuto, {})).toBe(false)
    expect(registrationNeedsPairSelect(matchedManual, {})).toBe(true)
    expect(registrationNeedsPairSelect(refused, { 'f:4': 5 })).toBe(true)
    expect(registrationNeedsPairSelect(jmhz('r:1', { document_type: 'PREZEC26' }), {})).toBe(false)
  })

  it('formulář dalšího vztahu téže osoby jde vybrat a výběr vztahu nenabízí', () => {
    const concurrent = jmhz('f:5', {
      operation: 'create_employment',
      match: { status: 'new', matched_by: null, employee_id: 50, employee_name: 'Testovací Jana', employment_id: null, employment_code: null, candidates: [] },
    })
    expect(isRegistrationApplicable(concurrent)).toBe(true)
    expect(registrationNeedsPairSelect(concurrent, {})).toBe(false)
  })

  it('volbu DPP/DPČ nabídne jen věta s víc druhy a volbu mimo nabídku zahodí', () => {
    const derived = jmhz('d:1', {
      document_type: 'JMHZ_DERIVED',
      operation: 'create_employment',
      employment: { ...jmhz('x').employment, relation_type: 'dpp', relation_type_options: ['dpp', 'dpc'] },
    })
    expect(recordNeedsRelationChoice(derived)).toBe(true)
    expect(recordNeedsRelationChoice(jmhz('f:1'))).toBe(false)

    let choices = setRelationChoice({}, derived, 'dpc')
    expect(buildRelationChoices(choices)).toEqual([{ key: 'd:1', relation_type: 'dpc' }])
    expect(setRelationChoice(choices, derived, 'dpp'), 'Výchozí druh se neposílá.').toEqual({})
    expect(setRelationChoice({}, derived, 'employment'), 'Druh mimo nabídku se nepřijme.').toEqual({})

    choices = { 'd:1': 'dpc', 'd:2': 'dpc' }
    expect(pruneRelationChoices(choices, [derived])).toEqual({ 'd:1': 'dpc' })
  })

  it('převzetí historie jde zapnout i u měsíce převzatého jen částečně', () => {
    expect(hasTakeoverItem([{ status: 'blocked' }, { status: 'partial' }])).toBe(true)
    expect(hasTakeoverItem([{ status: 'blocked' }, { status: 'computed' }])).toBe(false)
    expect(hasReadyItem([{ status: 'partial' }]), 'Počáteční stavy a průměry „částečně" neznají.').toBe(false)
  })

  it('převzetí historie je výchozí jen s připravenou položkou a vědomé vypnutí se drží', () => {
    expect(hasReadyItem([{ status: 'blocked' }, { status: 'ready' }])).toBe(true)
    expect(hasReadyItem([{ status: 'unchanged' }])).toBe(false)
    expect(resolveHistoryToggle(false, false, true)).toBe(true)
    expect(resolveHistoryToggle(false, true, true)).toBe(false)
    expect(resolveHistoryToggle(true, true, false)).toBe(false)
  })

  it('tlačítko Použít povolí i bez vybraných vět, když se přebírá historie', () => {
    const base = { hasPreview: true, selectedCount: 0, historySelected: false, confirmed: true }
    expect(registrationApplyBlock({ ...base, hasPreview: false })).toBe('no_preview')
    expect(registrationApplyBlock(base)).toBe('no_selection')
    expect(registrationApplyBlock({ ...base, historySelected: true })).toBeNull()
    expect(registrationApplyBlock({ ...base, historySelected: true, confirmed: false })).toBe('no_confirmation')
    expect(registrationApplyBlock({ ...base, selectedCount: 2 })).toBeNull()
  })

  it('sečte počáteční stavy za měsíce a převede minuty na hodiny', () => {
    const month = (index: number, advanceBase: number, advanceTax: number, withholding = 0) => ({
      month: index,
      social_assessment_base_minor_units: advanceBase,
      advance_base_minor_units: advanceBase,
      advance_tax_minor_units: advanceTax,
      withholding_base_minor_units: withholding,
      withholding_tax_minor_units: Math.round(withholding * 0.15),
      applied_non_refundable_credits_minor_units: 0,
      applied_child_credit_minor_units: 0,
      tax_bonus_minor_units: 0,
      bonus_qualifying_income_minor_units: 0,
    })
    expect(openingBalanceTotals({ months: [month(1, 3_000_000, 180_000), month(2, 3_100_000, 195_000, 100_000)] })).toEqual({
      months: 2,
      advance_base_minor: 6_100_000,
      advance_tax_minor: 375_000,
      withholding_base_minor: 100_000,
      withholding_tax_minor: 15_000,
    })
    expect(openingBalanceTotals({ months: [] }).months).toBe(0)
    expect(minutesToHours(9_630)).toBe('160.50')
  })
})

describe('pravidla mapování', () => {
  it('normalizuje hlavičku stejně jako server', () => {
    expect(normalizeHeader('  Práce\n celkem  ')).toBe('prace celkem')
    expect(normalizeHeader('DOVOLENÁ')).toBe('dovolena')
  })

  it('odvodí jednotku z významu a u hodin nechá rozhodnout formát buňky', () => {
    expect(ruleUnitForMeaning('ignore', 'hours')).toBeNull()
    expect(ruleUnitForMeaning('person_name', null)).toBe('text')
    expect(ruleUnitForMeaning('component', 'hours')).toBe('amount')
    expect(ruleUnitForMeaning('sick_hours', 'excel_duration')).toBe('excel_duration')
    expect(ruleUnitForMeaning('sick_hours', 'hours')).toBe('hours')
    expect(ruleUnitForMeaning('sick_hours', 'text')).toBeNull()
  })

  it('kód složky převede na velká písmena a hvězdičku zachová', () => {
    expect(normalizeRuleComponentCode(' odmena ')).toBe('ODMENA')
    expect(normalizeRuleComponentCode(' * ')).toBe('*')
    expect(normalizeRuleComponentCode('  ')).toBeNull()
    expect(normalizeRuleComponentCode(null)).toBeNull()
  })

  it('profil převede do editoru a zpět beze ztráty', () => {
    const source = profile()
    const draft = profileToDraft(source)
    expect(draft.rules[0].sheet).toBe('')
    expect(new Set(draft.rules.map(rule => rule.uid)).size).toBe(3)
    expect(draftRules(draft)).toEqual(source.rules)
    expect(draftComponents(draft)).toEqual(source.components)
  })

  it('pravidla k odeslání pročistí: prázdný list = všechny listy, kód jen u složky, bez prázdných hlaviček', () => {
    const draft = emptyProfileDraft('Test')
    draft.rules = [
      emptyRuleDraft({ sheet: '  ', header: ' Přesčas ', meaning: 'overtime_hours', unit: 'text', component_code: 'X' }),
      emptyRuleDraft({ header: 'Prémie', meaning: 'component', unit: null, component_code: ' premie ' }),
      emptyRuleDraft({ header: 'Poznámka', meaning: 'ignore', unit: 'text' }),
      emptyRuleDraft({ header: '   ', meaning: 'worked_hours' }),
    ]
    expect(draftRules(draft)).toEqual([
      { sheet: null, header: 'Přesčas', meaning: 'overtime_hours', unit: null, component_code: null },
      { sheet: null, header: 'Prémie', meaning: 'component', unit: 'amount', component_code: 'PREMIE' },
      { sheet: null, header: 'Poznámka', meaning: 'ignore', unit: null, component_code: null },
    ])
  })

  it('složky profilu: prázdné řádky vynechá, kód normalizuje', () => {
    const draft = emptyProfileDraft('Test')
    draft.components = [
      { ...emptyComponentDraft(), code: ' mzda_ukolova ', name: ' Úkolová mzda ', kind: 'task_wage' },
      emptyComponentDraft(),
    ]
    expect(draftComponents(draft)).toEqual([{ code: 'MZDA_UKOLOVA', name: 'Úkolová mzda', kind: 'task_wage' }])
  })

  it('otisk se změní jen při změně obsahu, ne při přeskládání uid', () => {
    const draft = profileToDraft(profile())
    const signature = draftSignature(draft)
    expect(draftSignature(profileToDraft(profile()))).toBe(signature)
    draft.rules[1].header = 'Dovolená celkem'
    expect(draftSignature(draft)).not.toBe(signature)
  })

  it('najde chyby bránící uložení a zkoušce', () => {
    const draft = emptyProfileDraft(' ')
    draft.rules = [
      emptyRuleDraft({ header: '', meaning: 'worked_hours' }),
      emptyRuleDraft({ header: 'Odměna', meaning: 'component', component_code: ' ' }),
    ]
    draft.components = [
      { ...emptyComponentDraft(), code: 'ODMENA', name: 'Odměna' },
      { ...emptyComponentDraft(), code: 'odmena', name: '' },
      { ...emptyComponentDraft(), code: '*', name: 'Cokoli' },
    ]
    expect(profileDraftIssues(draft).map(issue => issue.kind)).toEqual([
      'name_missing',
      'rule_header_missing',
      'rule_component_missing',
      'component_code_duplicate',
      'component_name_missing',
      'component_code_auto',
    ])
    expect(ruleIssues(draft).map(issue => issue.kind)).toEqual(['rule_header_missing', 'rule_component_missing'])
    expect(ruleIssues(emptyProfileDraft('X')).map(issue => issue.kind)).toEqual(['no_rules'])
  })

  it('podmínku pravidla pošle jen vyplněnou a neúplnou nebo s kódem * nahlásí', () => {
    const draft = emptyProfileDraft('Test')
    draft.rules = [
      emptyRuleDraft({ header: 'Odměny*', meaning: 'component', component_code: 'mzda_ukolova', when_header: ' oddělení ', when_value: ' výroba ' }),
      emptyRuleDraft({ header: 'Odměny*', meaning: 'component', component_code: 'ODMENA' }),
      emptyRuleDraft({ header: 'Srážky', meaning: 'net_other_deduction', when_header: 'oddělení' }),
      emptyRuleDraft({ header: 'Prémie', meaning: 'component', component_code: '*', when_header: 'oddělení', when_value: 'sklad' }),
    ]
    expect(draftRules(draft).slice(0, 2)).toEqual([
      { sheet: null, header: 'Odměny*', meaning: 'component', unit: 'amount', component_code: 'MZDA_UKOLOVA', when_header: 'oddělení', when_value: 'výroba' },
      { sheet: null, header: 'Odměny*', meaning: 'component', unit: 'amount', component_code: 'ODMENA' },
    ])
    expect(draftRules(draft)[2]).toMatchObject({ meaning: 'net_other_deduction', unit: 'amount' })
    expect(ruleIssues(draft)).toEqual([
      { kind: 'rule_condition_incomplete', row: 3 },
      { kind: 'rule_condition_auto', row: 4 },
    ])
    expect(profileToDraft(profile({ rules: draftRules(draft).slice(0, 1) })).rules[0])
      .toMatchObject({ when_header: 'oddělení', when_value: 'výroba' })
  })

  it('sazbu náhrady pošle jen u překážky zaměstnavatele a hlídá rozsah i jednotnost', () => {
    const draft = emptyProfileDraft('Test')
    draft.rules = [
      emptyRuleDraft({ header: 'Doma za 80*', meaning: 'obstacle_employer_hours', rate_percent: ' 70 ' }),
      emptyRuleDraft({ header: 'Dovolená', meaning: 'vacation_hours', rate_percent: '90' }),
    ]
    expect(draftRules(draft)).toEqual([
      { sheet: null, header: 'Doma za 80*', meaning: 'obstacle_employer_hours', unit: null, component_code: null, rate_percent: 70 },
      { sheet: null, header: 'Dovolená', meaning: 'vacation_hours', unit: null, component_code: null },
    ])
    expect(ruleIssues(draft)).toEqual([])
    expect(obstacleRateIsReduced(draft.rules[0])).toBe(true)
    draft.rules[0].rate_percent = '55'
    expect(ruleIssues(draft)).toEqual([{ kind: 'rule_rate_invalid', row: 1 }])
    draft.rules[0].rate_percent = '60'
    draft.rules.push(emptyRuleDraft({ header: 'Prostoj', meaning: 'obstacle_employer_hours' }))
    expect(ruleIssues(draft)).toEqual([{ kind: 'rule_rate_mixed' }])
    expect(profileToDraft(profile({ rules: draftRules(draft).slice(0, 1) })).rules[0].rate_percent).toBe('60')
  })

  it('hlásí název, který už ve firmě je, bez ohledu na velikost písmen', () => {
    const draft = profileToDraft(profile({ name: 'Vzor GIRITON' }))
    expect(profileDraftIssues(draft, ['vzor giriton']).map(issue => issue.kind)).toEqual(['name_taken'])
    expect(profileDraftIssues(draft, ['Jiný profil'])).toEqual([])
  })

  it('posune pravidlo nahoru a dolů a mimo rozsah nic nezmění', () => {
    expect(moveItem(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b'])
    expect(moveItem(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c'])
    expect(moveItem(['a', 'b', 'c'], 0, -1)).toEqual(['a', 'b', 'c'])
    expect(moveItem(['a', 'b', 'c'], 2, 1)).toEqual(['a', 'b', 'c'])
  })

  it('duplikát je nový profil a vzor přestává být vzorem', () => {
    const original = profileToDraft(profile())
    const copy = duplicateDraft(original, 'Vzor GIRITON (kopie)')
    expect(copy.id).toBeNull()
    expect(copy.is_sample).toBe(false)
    expect(draftRules(copy)).toEqual(draftRules(original))
    expect(copy.rules[0].uid).not.toBe(original.rules[0].uid)
    copy.rules[0].header = 'Zaměstnanec'
    expect(original.rules[0].header).toBe('Jméno')
  })

  it('vymyslí volný název profilu', () => {
    expect(uniqueProfileName('Docházka', [])).toBe('Docházka')
    expect(uniqueProfileName('Docházka', ['docházka'])).toBe('Docházka (2)')
    expect(uniqueProfileName('Docházka', ['Docházka', 'Docházka (2)'])).toBe('Docházka (3)')
  })
})

describe('export a import profilu', () => {
  it('přijme soubor ve formátu exportu a doplní chybějící složky', () => {
    const text = JSON.stringify({ format: 'myucto-attendance-profile', version: 1, name: 'Vzor', rules: [] })
    expect(parseProfileExport(text)).toEqual({
      ok: true,
      profile: { format: 'myucto-attendance-profile', version: 1, name: 'Vzor', rules: [], components: [] },
    })
  })

  it('odmítne neplatný JSON, cizí formát, jinou verzi i poškozený obsah', () => {
    expect(parseProfileExport('{')).toEqual({ ok: false, reason: 'invalid_json' })
    expect(parseProfileExport('[]')).toEqual({ ok: false, reason: 'wrong_format' })
    expect(parseProfileExport(JSON.stringify({ format: 'jiny', version: 1 }))).toEqual({ ok: false, reason: 'wrong_format' })
    expect(parseProfileExport(JSON.stringify({ format: 'myucto-attendance-profile', version: 2, name: 'x', rules: [] })))
      .toEqual({ ok: false, reason: 'unsupported_version' })
    expect(parseProfileExport(JSON.stringify({ format: 'myucto-attendance-profile', version: 1, name: 'x', rules: {} })))
      .toEqual({ ok: false, reason: 'invalid_content' })
    expect(parseProfileExport(JSON.stringify({ format: 'myucto-attendance-profile', version: 1, name: 'x', rules: [], components: 'x' })))
      .toEqual({ ok: false, reason: 'invalid_content' })
  })

  it('název souboru exportu je bez diakritiky a mezer', () => {
    expect(profileExportFilename('Vzor GIRITON – provoz 2')).toBe('profil-dochazky-vzor-giriton-provoz-2.json')
    expect(profileExportFilename('  ')).toBe('profil-dochazky-export.json')
  })
})

describe('rozpoznání sloupců', () => {
  const sheets = [
    sheet('a.xlsx#Vstup', true, ['person_name', 'worked_hours', 'ignore']),
    sheet('a.xlsx#Pomocný', false, ['person_name', 'vacation_hours']),
    sheet('b.csv#data', true, ['person_name']),
  ]

  it('počítá rozpoznané sloupce jen v použitých listech', () => {
    const unrecognized = [{ sheet_id: 'a.xlsx#Vstup', letter: 'C', header: 'Poznámka', samples: ['x'] }]
    expect(recognitionStats({ sheets, unrecognized_columns: unrecognized })).toEqual({ recognized: 3, sheets: 2, unrecognized: 1 })
  })

  it('pojmenuje list a z nerozpoznaného sloupce navrhne pravidlo pro jeho list', () => {
    expect(sheetLabel(sheets, 'b.csv#data')).toBe('data · b.csv')
    expect(sheetLabel(sheets, 'zmizely#list')).toBe('zmizely#list')
    const rule = ruleFromUnrecognized({ sheet_id: 'a.xlsx#Vstup', letter: 'C', header: 'Poznámka', samples: [] }, sheets)
    expect(rule).toMatchObject({ sheet: 'Vstup', header: 'Poznámka', meaning: 'ignore', unit: null, component_code: '' })
  })

  it('vybere složky, které import založí', () => {
    const checks: AttendanceComponentCheck[] = [
      { component_code: 'ODMENA', status: 'ok', message: null, name: null, kind: null },
      { component_code: 'PRIPLATEK_NOC', status: 'will_create', message: null, name: 'Příplatek za noc', kind: 'allowance' },
    ]
    expect(componentsToCreate(checks).map(check => check.component_code)).toEqual(['PRIPLATEK_NOC'])
  })
})

describe('osoby a vazby', () => {
  const matched = person('jana', {
    match: { status: 'matched', matched_by: 'name', employment_id: 1, employee_id: 101, employee_name: 'Jana Testovací', employment_code: 'ZAM-1', candidates: [] },
  })
  const missing = person('petr', { display_name: 'Zkušební Petr Pavel' })

  it('ruční volba přebije automatickou shodu včetně vědomého bez vztahu', () => {
    expect(effectiveEmploymentId(matched, {})).toBe(1)
    expect(effectiveEmploymentId(matched, { jana: null })).toBeNull()
    expect(effectiveEmploymentId(missing, { petr: 2 })).toBe(2)
    expect(buildAttendanceLinks([matched, missing], { petr: 2 })).toEqual([
      { person_key: 'jana', employment_id: 1 },
      { person_key: 'petr', employment_id: 2 },
    ])
  })

  it('po novém náhledu zahodí volby na neplatné vztahy a zmizelé osoby', () => {
    const pruned = pruneManualLinks({ jana: null, petr: 9, ghost: 1 }, [matched, missing], [option(1), option(2)])
    expect(pruned).toEqual({})
    expect(pruneManualLinks({ petr: null }, [missing], [option(1)])).toEqual({ petr: null })
  })

  it('navrhne jméno, příjmení a druh vztahu', () => {
    expect(splitDisplayName('Zkušební Petr Pavel')).toEqual({ last_name: 'Zkušební', first_name: 'Petr Pavel' })
    expect(splitDisplayName('Testovací')).toEqual({ last_name: 'Testovací', first_name: '' })
    expect(guessRelationType('DPP')).toBe('dpp')
    expect(guessRelationType('Dohoda o pracovní činnosti')).toBe('dpc')
    expect(guessRelationType('Zaměstnání malého rozsahu')).toBe('small_scale_employment')
    expect(guessRelationType(null)).toBe('employment')
  })

  it('sestaví payload zakládaných osob', () => {
    const withData = person('eva', { display_name: 'Vzorová Eva', personal_number: 'Z001', weekly_hours: '37,5', monthly_wage: '42 000' })
    const payload = buildPersonsPayload(
      [withData, missing],
      { petr: { first_name: 'Petr', last_name: 'Zkušební' } },
      { relation_type: 'dpp', weekly_hours: '40', planned_start_on: '2026-06-01', activate: true },
    )
    expect(payload).toEqual([
      {
        person_key: 'eva', full_name: 'Eva Vzorová', first_name: 'Eva', last_name: 'Vzorová', birth_number: null,
        relation_type: 'dpp', weekly_hours: '37.5', monthly_gross: 42000, planned_start_on: '2026-06-01',
        hourly_rate: null, fixed_term_end_on: null, personal_number: 'Z001', activate: true,
      },
      {
        person_key: 'petr', full_name: 'Petr Zkušební', first_name: 'Petr', last_name: 'Zkušební', birth_number: null,
        relation_type: 'dpp', weekly_hours: '40', monthly_gross: null, planned_start_on: '2026-06-01',
        hourly_rate: null, fixed_term_end_on: null, personal_number: null, activate: true,
      },
    ])
  })

  it('předá hodinovou sazbu a konec dohody tak, jak jsou v podkladech; číslo účtu ne', () => {
    const dpp = person('alena', {
      display_name: 'Sazbová Alena', hourly_rate: ' 180,50 ', end_date: '31. 12. 2026', agreed_end_on: '2026-12-31',
      bank_account_masked: '••••••0005/0100',
    })
    const [payload] = buildPersonsPayload(
      [dpp],
      {},
      { relation_type: 'dpp', weekly_hours: '', planned_start_on: '2026-06-01', activate: true },
    )
    expect(payload).toMatchObject({ hourly_rate: '180,50', fixed_term_end_on: '31. 12. 2026' })
    expect(payload).not.toHaveProperty('bank_account')
  })

  it('náhled zakládání ukáže převzaté údaje a nečitelné označí', () => {
    const dpp = person('alena', {
      display_name: 'Sazbová Alena', hourly_rate: '180,50 Kč', end_date: '31. 12. 2026', agreed_end_on: '2026-12-31',
      monthly_wage: null, bank_account_masked: '••••••0005/0100',
    })
    expect(personCreateDetails(dpp, '2026-06-01')).toEqual([
      { key: 'start_on', value: '2026-06-01', invalid: false },
      { key: 'agreed_end_on', value: '2026-12-31', invalid: false },
      { key: 'hourly_rate', value: '180.5', invalid: false },
      { key: 'bank_account', value: '••••••0005/0100', invalid: false },
    ])
    const unreadable = person('bela', { display_name: 'Chybná Běla', hourly_rate: 'dle dohody', end_date: 'do odvolání', agreed_end_on: null, start_on: '2026-06-15' })
    expect(personCreateDetails(unreadable, '2026-06-01')).toEqual([
      { key: 'start_on', value: '2026-06-15', invalid: false },
      { key: 'agreed_end_on', value: 'do odvolání', invalid: true },
      { key: 'hourly_rate', value: 'dle dohody', invalid: true },
    ])
  })

  it('sestaví payload s druhem vztahu odhadnutým per osoba', () => {
    const dpp = person('karel', { display_name: 'Vzorový Karel', relation_label: 'DPP' })
    const payload = buildPersonsPayload(
      [missing, dpp],
      {},
      { relation_type: 'employment', weekly_hours: '40', planned_start_on: '2026-06-01', activate: true },
      candidate => guessRelationType(candidate.relation_label),
    )
    expect(payload.map(item => item.relation_type)).toEqual(['employment', 'dpp'])
  })

  it('osobu lze rovnou založit, jen když ji import nenašel a nemá vztah; nejasnou ne', () => {
    const ambiguous = person('petra', { match: { ...missing.match, status: 'ambiguous' } })
    expect(personCanBeCreated(missing, {})).toBe(true)
    expect(personCanBeCreated(ambiguous, {})).toBe(false)
    expect(personCanBeCreated(matched, {})).toBe(false)
    expect(personCanBeCreated(missing, { petr: 2 })).toBe(false)
    expect(creatablePersonKeys([matched, missing, ambiguous], {})).toEqual(['petr'])
  })

  it('automaticky zakládá jen osoby s číslem, nesou-li podklady čísla', () => {
    const numbered = person('karel', { personal_number: 'Z010' })
    const masked = person('jitka', { birth_number_masked: '••••••/1234' })
    expect(personHasIdentifier(numbered)).toBe(true)
    expect(personHasIdentifier(missing)).toBe(false)
    expect(autoCreatablePersons([missing, numbered, masked, matched], {}).map(item => item.key)).toEqual(['karel', 'jitka'])
    // Podklady bez čísel vůbec: zakládá se jako dřív každý nenalezený.
    expect(autoCreatablePersons([missing, matched], {}).map(item => item.key)).toEqual(['petr'])
  })

  it('nástup z poznámky v podkladech má přednost před dnem pro celou dávku', () => {
    const payload = buildPersonsPayload(
      [person('nova', { display_name: 'Nová Dana', start_on: '2026-06-15' }), missing],
      {},
      { relation_type: 'employment', weekly_hours: '40', planned_start_on: '2026-06-01', activate: true },
    )
    expect(payload.map(item => item.planned_start_on)).toEqual(['2026-06-15', '2026-06-01'])
  })

  it('spočítá osoby bez vztahu a sestaví výchozí hodnoty automatického založení', () => {
    expect(personsWithoutEmploymentCount([matched, missing], {})).toBe(1)
    expect(personsWithoutEmploymentCount([matched, missing], { jana: null })).toBe(2)
    expect(autoPersonCreateDefaults('2026-06')).toEqual({
      relation_type: 'employment', weekly_hours: '40', planned_start_on: '2026-06-01', activate: true,
    })
  })
})

describe('dávky zakládání osob', () => {
  it('rozdělí 227 osob na dávky po 100 bez ztráty pořadí', () => {
    const items = Array.from({ length: 227 }, (_, index) => index)
    const parts = chunk(items, 100)
    expect(parts.map(part => part.length)).toEqual([100, 100, 27])
    expect(parts.flat()).toEqual(items)
  })

  it('prázdný seznam nedá žádnou dávku a nulová velikost je chyba', () => {
    expect(chunk([], 100)).toEqual([])
    expect(() => chunk([1], 0)).toThrow(RangeError)
  })
})

describe('souhrn', () => {
  it('seřadí sloupce hodin podle číselníku a složky abecedně', () => {
    const persons = [
      person('a', {
        metrics: [
          { meaning: 'sick_hours', hours: '8.00', source: 'a!b!C1', conflicts: [] },
          { meaning: 'worked_hours', hours: '160.00', source: 'a!b!D1', conflicts: [] },
        ],
        components: [{ component_code: 'PREMIE_PRIPLATKY', amount: '1.00', amount_minor: 100, source: 'x', conflicts: [] }],
      }),
      person('b', {
        components: [{ component_code: 'ODMENA', amount: '1.00', amount_minor: 100, source: 'x', conflicts: [] }],
      }),
    ]
    expect(summaryMeanings(persons)).toEqual(['worked_hours', 'sick_hours'])
    expect(summaryComponents(persons)).toEqual(['ODMENA', 'PREMIE_PRIPLATKY'])
  })

  it('zformátuje hodiny podle jazyka a prázdnou hodnotu nenahradí nulou', () => {
    expect(formatHours('36.5', 'cs-CZ')).toBe('36,50')
    expect(formatHours(null, 'cs-CZ')).toBe('—')
    expect(formatHours('', 'cs-CZ')).toBe('—')
  })

  it('ověří formát období', () => {
    expect(isValidPeriod('2026-06')).toBe(true)
    expect(isValidPeriod('2026-13')).toBe(false)
    expect(isValidPeriod('')).toBe(false)
  })
})

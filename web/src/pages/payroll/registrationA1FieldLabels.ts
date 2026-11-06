/**
 * Lidský název položky profilu REGZEC A1 podle její technické cesty.
 *
 * Seznamy „Doplňte ručně z personálního podkladu" a „Co brání podání"
 * vypisovaly technické klíče (`permanent_address.house_number`,
 * `employment.work_mode_code`, `pension`) místo popisků, které má formulář
 * hned pod nimi. Tady se cesta převede na TÝŽ popisek jako u pole; neznámá
 * cesta zůstane, jak je — lepší technický klíč než nic.
 *
 * Klíče jsou vypsané výčtem, ne ověřované přes `te()`: generátor jmenných
 * prostorů i18n čte literály a prefix šablony, nic víc.
 */

const PREFIX = 'payroll.people.registration.a1'

const SECTIONS = new Set([
  'permanent_address',
  'czech_residence_address',
  'contact_address',
  'tax_residency',
  'employment',
  'pension',
  'foreign_legislation',
  'foreign_insurance',
  'proof_identity',
  'foreign_worker',
  'attachments',
])

const ADDRESS_SECTIONS = new Set(['permanent_address', 'czech_residence_address', 'contact_address'])

const ADDRESS_LEAVES = new Set([
  'street',
  'house_number',
  'orientation_number',
  'city',
  'postal_code',
  'country_code',
  'ruian_point',
])

const IDENTITY_LEAVES = new Set([
  'first_name',
  'last_name',
  'birth_surname',
  'birth_place',
  'citizenship_country_code',
  'sex',
  'birth_date',
  'birth_country_code',
])

const GROUP_LEAVES: Record<string, ReadonlySet<string>> = {
  employment: new Set([
    'activity_code', 'relationship_detail_code', 'actual_start_on', 'contract_start_on',
    'small_scale', 'employment_status_code', 'work_mode_code', 'continuous_operation',
    'prevailing_workplace_code', 'expected_workplaces', 'contract_workplace',
    'workplace_city', 'workplace_municipality_code', 'profession_code',
    'required_education_code', 'position_name', 'leadership',
  ]),
  tax_residency: new Set(['country_code', 'identifier_type', 'identifier', 'residence_address']),
  facts: new Set(['highest_education_code', 'disability_card', 'health_restrictions']),
  pension: new Set(['type_code', 'received_from', 'early_retirement', 'reduced_retirement_age']),
  foreign_legislation: new Set(['applies', 'country_code']),
  foreign_insurance: new Set([
    'current', 'name', 'street', 'house_number', 'orientation_number',
    'postal_code', 'city', 'country_code', 'identifier', 'sector',
  ]),
  proof_identity: new Set(['type_code', 'number', 'foreign_issuer', 'country_code']),
  foreign_worker: new Set([
    'free_access', 'free_access_reason_code', 'permit_type_code',
    'issuing_labour_office_code', 'permit_identifier', 'permit_from', 'permit_to',
    'stay_valid_from', 'stay_valid_to',
  ]),
}

export function registrationA1FieldLabel(path: string, t: (key: string) => string): string {
  if (path === 'health_insurance_code') return t(`${PREFIX}.health_insurance_code`)
  if (path === 'facts') return t(`${PREFIX}.section.health_and_facts`)
  if (SECTIONS.has(path)) return t(`${PREFIX}.section.${path}`)

  const dot = path.indexOf('.')
  if (dot < 0) return path
  const head = path.slice(0, dot)
  const leaf = path.slice(dot + 1)

  // Údaje osoby — tentýž popisek jako v seznamu chybějících údajů přípravy.
  if (head === 'identity' && IDENTITY_LEAVES.has(leaf)) {
    return t(`payroll.people.registration.missing.fields.${leaf}`)
  }
  if (ADDRESS_SECTIONS.has(head) && ADDRESS_LEAVES.has(leaf)) {
    return `${t(`${PREFIX}.section.${head}`)} · ${t(`${PREFIX}.address.${leaf}`)}`
  }
  if (head === 'tax_residency' && leaf.startsWith('residence_address.')) {
    const addressLeaf = leaf.slice('residence_address.'.length)
    if (ADDRESS_LEAVES.has(addressLeaf)) {
      return `${t(`${PREFIX}.section.tax_residency`)} · ${t(`${PREFIX}.address.${addressLeaf}`)}`
    }
  }
  if (GROUP_LEAVES[head]?.has(leaf)) return t(`${PREFIX}.${head}.${leaf}`)

  return path
}

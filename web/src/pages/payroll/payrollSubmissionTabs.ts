/**
 * Záložky stránky Podání a hlášení (`/payroll/submissions/:tab`).
 *
 * Seznam je jediné místo pravdy pro stránku i pro test rout: klíč záložky
 * musí projít parametrem routy, jinak klik na záložku skončí na 404 a zahodí
 * otevřené pracovní panely (tak dopadla `registration_a1` s číslicí).
 */
export const PAYROLL_SUBMISSION_TABS = [
  'monthly', 'queue', 'transport', 'regzel', 'registration_a1', 'registration_completion', 'jmhz',
  'discount_intents', 'sickness', 'eldp',
  'health', 'tax_statements', 'statutory', 'other', 'inbox', 'certificate',
] as const

export type PayrollSubmissionTab = typeof PAYROLL_SUBMISSION_TABS[number]

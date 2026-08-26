export type PayrollManualChapterRule = [RegExp, string]

export const PAYROLL_MANUAL_CHAPTERS: PayrollManualChapterRule[] = [
  [/^\/payroll\/absences(?:\/|$)/, '59_Absence_a_dovolena'],
  [/^\/payroll\/time(?:\/|$)/, '60_Dochazka_a_smeny'],
  [/^\/payroll\/travel(?:\/|$)/, '61_Cestovni_nahrady'],
  [/^\/payroll\/quick-inputs(?:\/|$)/, '62_Rychly_mesicni_vstup'],
  [/^\/payroll\/runs(?:\/|$)/, '63_Mzdove_behy'],
  [/^\/payroll\/posting-reconciliation(?:\/|$)/, '64_Shoda_uctovani_mezd'],
  [/^\/payroll\/payments(?:\/|$)/, '65_Platby_a_uhrady'],
  [/^\/payroll\/documents(?:\/|$)/, '66_Dokumenty_a_vystupy'],
  [/^\/payroll\/annual-settlement(?:\/|$)/, '67_Rocni_zuctovani'],
  [/^\/payroll\/submissions(?:\/|$)/, '68_Podani_a_hlaseni'],
  [/^\/payroll\/people(?:\/|$)/, '69_Zamestnanci'],
  [/^\/payroll\/deduction-agreements(?:\/|$)/, '70_Dohody_o_srazkach'],
  [/^\/payroll\/enforcement(?:\/|$)/, '71_Srazky_a_exekuce'],
  [/^\/payroll\/benefit-baskets(?:\/|$)/, '72_Kose_benefitu'],
  [/^\/payroll\/settings(?:\/|$)/, '73_Nastaveni_mezd'],
  [/^\/payroll\/components(?:\/|$)/, '74_Mzdove_slozky_a_vstupy'],
  [/^\/payroll\/rulesets(?:\/|$)/, '75_Legislativni_pravidla_mezd'],
  [/^\/payroll\/retention(?:\/|$)/, '76_Retencni_lhuty'],
  [/^\/payroll\/erasure(?:\/|$)/, '77_Vymaz_osobnich_udaju'],
  [/^\/payroll(?:\/|$)/, '58_Uplne_mzdy'],
]

export function payrollManualChapter(path: string): string | undefined {
  return PAYROLL_MANUAL_CHAPTERS.find(([pattern]) => pattern.test(path))?.[1]
}

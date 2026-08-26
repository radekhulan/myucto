export type PayrollManualChapterRule = [RegExp, string]

export const PAYROLL_MANUAL_CHAPTERS: PayrollManualChapterRule[] = [
  [/^\/payroll\/absences(?:\/|$)/, '58a_Absence_a_dovolena'],
  [/^\/payroll\/time(?:\/|$)/, '58b_Dochazka_a_smeny'],
  [/^\/payroll\/travel(?:\/|$)/, '58c_Cestovni_nahrady'],
  [/^\/payroll\/quick-inputs(?:\/|$)/, '58d_Rychly_mesicni_vstup'],
  [/^\/payroll\/runs(?:\/|$)/, '58e_Mzdove_behy'],
  [/^\/payroll\/posting-reconciliation(?:\/|$)/, '58f_Shoda_uctovani_mezd'],
  [/^\/payroll\/payments(?:\/|$)/, '58g_Platby_a_uhrady'],
  [/^\/payroll\/documents(?:\/|$)/, '58h_Dokumenty_a_vystupy'],
  [/^\/payroll\/annual-settlement(?:\/|$)/, '58i_Rocni_zuctovani'],
  [/^\/payroll\/submissions(?:\/|$)/, '58j_Podani_a_hlaseni'],
  [/^\/payroll\/people(?:\/|$)/, '58k_Zamestnanci'],
  [/^\/payroll\/deduction-agreements(?:\/|$)/, '58l_Dohody_o_srazkach'],
  [/^\/payroll\/enforcement(?:\/|$)/, '58m_Srazky_a_exekuce'],
  [/^\/payroll\/benefit-baskets(?:\/|$)/, '58n_Kose_benefitu'],
  [/^\/payroll\/settings(?:\/|$)/, '58o_Nastaveni_mezd'],
  [/^\/payroll\/components(?:\/|$)/, '58p_Mzdove_slozky_a_vstupy'],
  [/^\/payroll\/rulesets(?:\/|$)/, '58q_Legislativni_pravidla_mezd'],
  [/^\/payroll\/retention(?:\/|$)/, '58r_Retencni_lhuty'],
  [/^\/payroll\/erasure(?:\/|$)/, '58s_Vymaz_osobnich_udaju'],
  [/^\/payroll(?:\/|$)/, '58_Uplne_mzdy'],
]

export function payrollManualChapter(path: string): string | undefined {
  return PAYROLL_MANUAL_CHAPTERS.find(([pattern]) => pattern.test(path))?.[1]
}

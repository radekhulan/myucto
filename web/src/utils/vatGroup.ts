/** DIČ české skupiny pro DPH; registr zveřejňuje účty všech jejích členů. */
export function isCzechVatGroup(dic: string): boolean {
  return /^(?:CZ)?699\d{6}$/i.test(dic.trim())
}

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fieldSelector, revealField } from '../revealField'

/**
 * Doskok na položku je jediné, co dělá z hlášky „tenhle údaj chybí" použitelnou
 * navigaci. Testuje se tu hlavně to, co v praxi selhávalo: cíl schovaný ve
 * sbaleném `<details>`, kam odrolování nic nepřineslo.
 */
describe('revealField', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  it('rozbalí sbalené předky, vysvítí cíl a dá mu kurzor', async () => {
    vi.useFakeTimers()
    document.body.innerHTML = `
      <details id="wrap">
        <summary>Údaje pro registraci zaměstnance</summary>
        <label data-a1-field="identity.citizenship_country_code">
          <input id="cit">
        </label>
      </details>`
    const target = document.querySelector<HTMLElement>('[data-a1-field]')!
    target.scrollIntoView = vi.fn()

    expect(revealField(fieldSelector('identity.citizenship_country_code'))).toBe(true)

    expect(document.querySelector<HTMLDetailsElement>('#wrap')!.open).toBe(true)
    expect(target.classList.contains('field-flash')).toBe(true)
    expect(target.scrollIntoView).toHaveBeenCalled()

    vi.advanceTimersByTime(400)
    expect(document.activeElement?.id).toBe('cit')

    // Orámování je dočasné — jinak by na formuláři zůstalo svítit napořád.
    vi.advanceTimersByTime(3000)
    expect(target.classList.contains('field-flash')).toBe(false)
  })

  it('přizná, že cíl na stránce není', () => {
    expect(revealField(fieldSelector('pension.type_code'))).toBe(false)
  })

  it('escapuje hodnotu do selektoru', () => {
    expect(fieldSelector('employment.position_name'))
      .toBe('[data-a1-field="employment.position_name"]')
    expect(fieldSelector('a"b')).toBe('[data-a1-field="a\\"b"]')
  })
})

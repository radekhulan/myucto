import { describe, expect, it } from 'vitest'
import { renderMarkdown } from '@/utils/miniMarkdown'

const flat = (md: string) => renderMarkdown(md).replace(/\n/g, '')

describe('renderMarkdown – seznamy', () => {
  it('číslovaný seznam s odsazenými odstavci zůstane jeden <ol>', () => {
    const md = [
      '1. **První krok**',
      '   Popis kroku.',
      '',
      '   **MD 353 / D 419**',
      '',
      '2. **Druhý krok**',
      '',
      '3. **Třetí krok**',
    ].join('\n')
    const html = flat(md)
    expect(html.match(/<ol/g)).toHaveLength(1)
    expect(html.match(/<li>/g)).toHaveLength(3)
    expect(html).toContain('<li><p><strong>První krok</strong> Popis kroku.</p><p><strong>MD 353 / D 419</strong></p></li>')
  })

  it('neodsazený odstavec po prázdném řádku seznam ukončí a další seznam pokračuje v číslování', () => {
    const html = flat(['1. a', '', 'Mezitext', '', '2. b'].join('\n'))
    expect(html).toBe('<ol><li>a</li></ol><p>Mezitext</p><ol start="2"><li>b</li></ol>')
  })

  it('vnořená odrážka pod číslovanou položkou', () => {
    const html = flat(['1. Založte:', '   - typ: pohledávka,', '   - účet: 353.', '2. Spárujte'].join('\n'))
    expect(html).toBe('<ol><li><p>Založte:</p><ul><li>typ: pohledávka,</li><li>účet: 353.</li></ul></li><li>Spárujte</li></ol>')
  })

  it('dvě mezery na konci řádku jsou tvrdé zalomení', () => {
    expect(flat('první  \ndruhý')).toBe('<p>první<br>druhý</p>')
    expect(flat('- první  \n  druhý')).toBe('<ul><li>první<br>druhý</li></ul>')
  })

  it('položky oddělené prázdným řádkem patří do jednoho seznamu', () => {
    expect(flat('- a\n\n- b')).toBe('<ul><li>a</li><li>b</li></ul>')
  })

  it('escapuje HTML i uvnitř vnořeného obsahu položky', () => {
    expect(flat('1. a\n\n   <script>x</script>')).not.toContain('<script>')
  })
})

describe('obrázky nabídky', () => {
  it('vykreslí jen autorizovanou přílohu a escapuje popisek i adresu', () => {
    const file = '/api/sales-quotes/12/files/17?supplier_id=1&version_id=4'
    const render = (id: number) => id === 17 ? file : undefined
    const html = renderMarkdown('![A < B"](quote-file:17) ![cizí](quote-file:18) ![externí](https://example.test/photo.jpg)', render)
    expect(html).toContain('<img src="/api/sales-quotes/12/files/17?supplier_id=1&amp;version_id=4" alt="A &lt; B&quot;" data-quote-file="17">')
    expect(html.match(/<img /g)).toHaveLength(1)
    expect(html).toContain('cizí')
  })

  it('nepovolí vzdálený cíl ani při chybném resolveru', () => {
    expect(renderMarkdown('![obrázek](quote-file:7)', () => 'https://example.test/photo.jpg')).toBe('<p>obrázek</p>')
    expect(renderMarkdown('![obrázek](quote-file:7)')).not.toContain('<img ')
  })
})

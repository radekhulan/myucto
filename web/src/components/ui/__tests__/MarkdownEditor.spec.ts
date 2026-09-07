import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import MarkdownEditor from '@/components/ui/MarkdownEditor.vue'
import { renderMarkdown } from '@/utils/miniMarkdown'

const NL = String.fromCharCode(10)

const area = (wrapper: ReturnType<typeof mount>) => wrapper.get<HTMLTextAreaElement>('textarea')
const button = (wrapper: ReturnType<typeof mount>, title: string) =>
  wrapper.findAll('button').find(b => b.attributes('title') === title)!

describe('miniMarkdown', () => {
  it('vykreslí základní značky', () => {
    expect(renderMarkdown('# Nadpis')).toContain('<h1>Nadpis</h1>')
    expect(renderMarkdown('- a\n- b')).toContain('<ul>')
    expect(renderMarkdown('**tučně**')).toContain('<strong>tučně</strong>')
  })

  /**
   * Náhled se vkládá přes `v-html`, takže tohle je jediná obrana proti tomu,
   * aby popis produktu propašoval skript do administrace.
   */
  it('escapuje HTML ze vstupu', () => {
    const html = renderMarkdown('<img src=x onerror=alert(1)> a <b>tučně</b>')
    expect(html).not.toContain('<img')
    expect(html).not.toContain('<b>')
    expect(html).toContain('&lt;img')
  })

  it('vykreslí GFM tabulku včetně zarovnání', () => {
    const html = renderMarkdown([
      '| Sloupec | Cena |',
      '| --- | ---: |',
      '| Malý | 10 |',
      '| Velký | 20 |',
    ].join(NL))

    expect(html).toContain('<table>')
    expect(html).toContain('<th>Sloupec</th>')
    expect(html).toContain('<th style="text-align:right">Cena</th>')
    expect(html).toContain('<td>Malý</td>')
    expect(html).toContain('<td style="text-align:right">20</td>')
    // Oddělovač se nesmí vykreslit jako text odstavce.
    expect(html).not.toContain('---')
  })

  it('vykreslí všechny úrovně nadpisů', () => {
    const html = renderMarkdown(['# a', '', '## b', '', '### c', '', '#### d'].join(NL))
    expect(html).toContain('<h1>a</h1>')
    expect(html).toContain('<h2>b</h2>')
    expect(html).toContain('<h3>c</h3>')
    expect(html).toContain('<h4>d</h4>')
  })

  it('řádek s pajpou bez oddělovače zůstane odstavcem', () => {
    expect(renderMarkdown('a | b')).toContain('<p>a | b</p>')
  })

  it('rozliší odrážkový a číslovaný seznam', () => {
    // Renderer sází bloky na samostatné řádky; pro porovnání je slepíme.
    const flat = (md: string) => renderMarkdown(md).split(NL).join('')

    expect(flat(['- a', '- b'].join(NL))).toContain('<ul><li>a</li><li>b</li></ul>')
    expect(flat(['1. a', '2. b'].join(NL))).toContain('<ol><li>a</li><li>b</li></ol>')
    // Přechod mezi typy musí předchozí seznam uzavřít, ne ho pokračovat.
    expect(flat(['- a', '1. b'].join(NL))).toContain('</ul><ol>')
  })

  it('vykreslí citaci, vodorovnou linku a přeškrtnutí', () => {
    expect(renderMarkdown(['> pozor', '> na to'].join(NL)))
      .toContain('<blockquote>pozor na to</blockquote>')
    expect(renderMarkdown(['a', '', '---', '', 'b'].join(NL))).toContain('<hr>')
    expect(renderMarkdown('~~zrušeno~~')).toContain('<del>zrušeno</del>')
  })

  it('propustí jen bezpečná schémata odkazů', () => {
    expect(renderMarkdown('[ok](https://example.test)')).toContain('href="https://example.test"')
    // eslint-disable-next-line no-script-url
    const evil = renderMarkdown('[zlo](javascript:alert(1))')
    expect(evil).not.toContain('href=')
    expect(evil).toContain('zlo')
  })
})

describe('MarkdownEditor', () => {
  it('obalí vybraný text tučnou značkou', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: 'ahoj svete' } })
    const field = area(wrapper)
    field.element.setSelectionRange(5, 10)

    await button(wrapper, 'markdown_editor.bold').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['ahoj **svete**'])
  })

  it('bez výběru vloží ukázkové slovo, ne prázdné značky', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '' } })

    await button(wrapper, 'markdown_editor.italic').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0])
      .toEqual(['*markdown_editor.sample_italic*'])
  })

  it('odrážka se předřadí před řádek a nezdvojí značku', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '## nadpis' } })
    area(wrapper).element.setSelectionRange(3, 3)

    await button(wrapper, 'markdown_editor.bullet').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['- nadpis'])
  })

  it('náhled ukáže vykreslený text a schová textareu', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '# Nadpis' } })

    await wrapper.findAll('button').at(-1)!.trigger('click')
    await nextTick()

    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(wrapper.get('.markdown-preview').html()).toContain('<h1>Nadpis</h1>')
  })

  it('nabídka nadpisů umí H1 až H4 a návrat na odstavec', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: 'nadpis' } })
    const select = wrapper.get('select')
    area(wrapper).element.setSelectionRange(0, 0)

    await select.setValue('3')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['### nadpis'])

    await wrapper.setProps({ modelValue: '### nadpis' })
    await select.setValue('1')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['# nadpis'])

    await wrapper.setProps({ modelValue: '# nadpis' })
    await select.setValue('0')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['nadpis'])
  })

  it('vloží kostru tabulky, kterou renderer pozná', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '' } })

    await button(wrapper, 'markdown_editor.table').trigger('click')
    const inserted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as string
    expect(inserted).toContain('| --- | --- |')
    expect(renderMarkdown(inserted)).toContain('<table>')
  })

  it('přepnutí typu seznamu značku vymění, nezdvojí', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '- polozka' } })
    area(wrapper).element.setSelectionRange(3, 3)

    await button(wrapper, 'markdown_editor.numbered').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['1. polozka'])

    await wrapper.setProps({ modelValue: '1. polozka' })
    await button(wrapper, 'markdown_editor.quote').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['> polozka'])
  })

  it('psaní do pole emituje čistý Markdown', async () => {
    const wrapper = mount(MarkdownEditor, { props: { modelValue: '' } })

    await area(wrapper).setValue('# Nadpis\n\n- bod')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['# Nadpis\n\n- bod'])
  })
})

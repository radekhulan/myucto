/**
 * Minimální Markdown → HTML bez závislosti.
 *
 * Why: aplikace potřebuje vykreslit kus Markdownu na dvou nesouvisejících
 * místech — poznámky k vydání (Systém → Aktualizace) a náhled popisu skladové
 * karty. Plnohodnotná knihovna by kvůli tomu do bundlu přidala desítky kB
 * a sanitizaci navíc; tady stačí podmnožina, kterou uživatel opravdu píše.
 *
 * Podporuje: nadpisy `#`–`######`, odrážky `-`/`*`, číslované seznamy,
 * odstavce, ``` bloky kódu, `**tučně**`, `*kurzíva*`, `` `kód` ``, odkazy
 * `~~přeškrtnuté~~`, citace `>`, vodorovnou linku `---`
 * a GFM tabulky (`| a | b |` + oddělovač `---`, včetně zarovnání přes `:`).
 *
 * BEZPEČNOST: vstup se nejdřív celý escapuje, teprve pak se doplňují značky —
 * do výstupu se tedy nedostane HTML z textu. U odkazů projdou jen bezpečná
 * schémata (`http(s)`, `mailto`, relativní, kotva), ostatní se vykreslí jako
 * holý text. Výstup je proto možné vložit přes `v-html`.
 */
export function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

export function renderMarkdown(md: string): string {
  if (!md) return ''
  const lines = md.replace(/\r\n/g, '\n').split('\n')
  const out: string[] = []
  let listType: 'ul' | 'ol' | null = null
  let para: string[] = []
  let li: string[] | null = null
  let inFence = false
  let fenceBuf: string[] = []
  let quote: string[] = []

  const flushQuote = () => {
    if (quote.length) {
      out.push('<blockquote>' + inline(quote.join(' ')) + '</blockquote>')
      quote = []
    }
  }
  const flushPara = () => {
    if (para.length) {
      out.push('<p>' + inline(para.join(' ')) + '</p>')
      para = []
    }
  }
  // Odrážka se sbírá po řádcích: pokračovací řádky (zalomený text odstavce
  // pod `- `) patří pořád do téže položky, ne do samostatného odstavce.
  const flushLi = () => {
    if (li) {
      out.push('<li>' + inline(li.join(' ')) + '</li>')
      li = null
    }
  }
  const closeList = () => {
    flushLi()
    if (listType) {
      out.push(`</${listType}>`)
      listType = null
    }
  }
  const ensureList = (t: 'ul' | 'ol') => {
    if (listType !== t) {
      closeList()
      out.push(`<${t}>`)
      listType = t
    }
  }

  /** Buňky řádku tabulky bez krajních `|` a bez okolních mezer. */
  function splitRow(row: string): string[] {
    return row.replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim())
  }

  function inline(s: string): string {
    let r = escapeHtml(s)
    r = r.replace(/`([^`]+)`/g, '<code>$1</code>')
    r = r.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    r = r.replace(/(?<!\w)\*([^*\n]+)\*(?!\w)/g, '<em>$1</em>')
    r = r.replace(/~~([^~]+)~~/g, '<del>$1</del>')
    // URL je už HTML-escapovaná (inline() escapuje celý řetězec výše). Povol jen bezpečná
    // schémata — `javascript:`/`data:` apod. zahoď a vykresli jen text (XSS guard).
    r = r.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, text: string, url: string) =>
      /^(https?:\/\/|mailto:|\/|#)/i.test(url)
        ? `<a href="${url}" target="_blank" rel="noopener">${text}</a>`
        : text,
    )
    return r
  }

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    if (/^```/.test(line.trim())) {
      if (!inFence) {
        flushPara()
        closeList()
        inFence = true
        fenceBuf = []
      } else {
        out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
        inFence = false
      }
      continue
    }
    if (inFence) {
      fenceBuf.push(line)
      continue
    }

    const trim = line.trim()
    if (trim === '') {
      flushPara()
      flushQuote()
      closeList()
      continue
    }
    // Citace `> text`; sousední řádky patří do téhož bloku.
    if (/^>\s?/.test(trim)) {
      flushPara()
      closeList()
      quote.push(trim.replace(/^>\s?/, ''))
      continue
    }
    flushQuote()
    // Vodorovná linka. Oddělovač tabulky se sem nedostane — spotřebuje ho
    // větev tabulky výš, která si ho bere spolu s hlavičkou.
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(trim)) {
      flushPara()
      closeList()
      out.push('<hr>')
      continue
    }
    // GFM tabulka: hlavička s `|`, pod ní oddělovač `---` (volitelně s `:` pro
    // zarovnání) a pak řádky až po prázdný řádek. Musí se testovat DŘÍV než
    // odstavec, jinak by hlavička spadla do <p> a oddělovač se vykreslil jako text.
    const separator = lines[i + 1]?.trim() ?? ''
    if (trim.includes('|') && /^\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?$/.test(separator)) {
      flushPara()
      closeList()
      const aligns = splitRow(separator).map(cell =>
        cell.startsWith(':') && cell.endsWith(':') ? 'center'
          : cell.endsWith(':') ? 'right'
            : cell.startsWith(':') ? 'left' : '')
      const cellAttr = (idx: number) => aligns[idx] ? ` style="text-align:${aligns[idx]}"` : ''
      const head = splitRow(trim)
      out.push('<table><thead><tr>'
        + head.map((c, idx) => `<th${cellAttr(idx)}>${inline(c)}</th>`).join('')
        + '</tr></thead><tbody>')
      i += 1
      while (i + 1 < lines.length && lines[i + 1].trim() !== '' && lines[i + 1].includes('|')) {
        i += 1
        const cells = splitRow(lines[i].trim())
        out.push('<tr>' + head.map((_c, idx) => `<td${cellAttr(idx)}>${inline(cells[idx] ?? '')}</td>`).join('') + '</tr>')
      }
      out.push('</tbody></table>')
      continue
    }

    const heading = trim.match(/^(#{1,6})\s+(.+)$/)
    if (heading) {
      flushPara()
      closeList()
      const lvl = heading[1].length
      out.push(`<h${lvl}>${inline(heading[2])}</h${lvl}>`)
      continue
    }
    if (/^[-*]\s+/.test(trim)) {
      flushPara()
      flushLi()
      ensureList('ul')
      li = [trim.replace(/^[-*]\s+/, '')]
      continue
    }
    if (/^\d+\.\s+/.test(trim)) {
      flushPara()
      flushLi()
      ensureList('ol')
      li = [trim.replace(/^\d+\.\s+/, '')]
      continue
    }
    if (li) {
      li.push(trim)
      continue
    }
    closeList()
    para.push(trim)
  }
  flushPara()
  flushQuote()
  closeList()
  if (inFence) {
    out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
  }
  return out.join('\n')
}

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

export function renderMarkdown(md: string, quoteImageUrl?: (id: number) => string | undefined): string {
  if (!md) return ''
  const lines = md.replace(/\r\n/g, '\n').split('\n')
  const out: string[] = []
  let listType: 'ul' | 'ol' | null = null
  let para: string[] = []
  let li: string[] | null = null
  let liIndent = 0
  let inFence = false
  let fenceBuf: string[] = []
  let quote: string[] = []

  // Dvě a víc mezer na konci řádku = tvrdé zalomení (<br>) jako v CommonMarku.
  // Značka \u0000 přežije escapování v inline() a nahradí se až po něm.
  const joinLines = (ls: string[]) =>
    inline(ls.map((l, idx) => l.trim() + (idx < ls.length - 1 && /\S {2,}$/.test(l) ? '\u0000' : '')).join(' '))
      .replace(/\u0000 /g, '<br>')

  const flushQuote = () => {
    if (quote.length) {
      out.push('<blockquote>' + inline(quote.join(' ')) + '</blockquote>')
      quote = []
    }
  }
  const flushPara = () => {
    if (para.length) {
      out.push('<p>' + joinLines(para) + '</p>')
      para = []
    }
  }
  // Odrážka se sbírá po řádcích: pokračovací řádky (zalomený text odstavce
  // pod `- `) patří pořád do téže položky, ne do samostatného odstavce.
  // Položka s prázdným řádkem nebo vnořeným seznamem je blok a vykreslí se rekurzivně.
  const flushLi = () => {
    if (li) {
      while (li.length > 1 && li[li.length - 1].trim() === '') li.pop()
      const block = li.slice(1).some(l => l.trim() === '' || /^\s*([-*]|\d+\.)\s+/.test(l))
      out.push('<li>' + (block ? renderMarkdown(li.join('\n'), quoteImageUrl) : joinLines(li)) + '</li>')
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
  // Číslovaný seznam přerušený odstavcem (typicky AI: „1. krok", pod ním blok
  // se zápisem, pak „2. krok") se otevře znovu; bez `start` by každý začal od 1.
  const ensureList = (t: 'ul' | 'ol', start = 1) => {
    if (listType !== t) {
      closeList()
      out.push(t === 'ol' && start !== 1 ? `<ol start="${start}">` : `<${t}>`)
      listType = t
    }
  }

  /** Buňky řádku tabulky bez krajních `|` a bez okolních mezer. */
  function splitRow(row: string): string[] {
    return row.replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim())
  }

  function inline(s: string): string {
    const images: string[] = []
    const withImageMarkers = quoteImageUrl ? s.replace(/!\[([^\]\n]*)\]\(quote-file:(\d+)\)/g, (_match, alt: string, id: string) => {
      const url = quoteImageUrl(Number(id))
      if (!url || !/^\/api\/sales-quotes\/\d+\/files\/\d+(?:\?|$)/.test(url)) return alt
      const index = images.push(`<img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" data-quote-file="${id}">`) - 1
      return `\u0001${index}\u0002`
    }) : s
    let r = escapeHtml(withImageMarkers)
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
    return images.length ? r.replace(/\u0001(\d+)\u0002/g, (_match, index: string) => images[Number(index)] ?? '') : r
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
    const indent = line.length - line.trimStart().length
    // V položce seznamu prázdný ani odsazený řádek seznam neukončí: odsazený
    // obsah (další odstavec, vnořená odrážka) patří do téže položky. Neodsazený
    // řádek po prázdném seznam zavře, ledaže jde o další položku téhož typu.
    if (li) {
      if (trim === '') {
        li.push('')
        continue
      }
      if (indent >= 2) {
        li.push(line.slice(Math.min(indent, liIndent)))
        continue
      }
      if (li[li.length - 1] === '' && !(listType === 'ol' ? /^\d+\.\s+/ : /^[-*]\s+/).test(trim)) closeList()
    }
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
    const ulItem = trim.match(/^[-*]\s+/)
    if (ulItem) {
      flushPara()
      flushLi()
      ensureList('ul')
      li = [line.trimStart().slice(ulItem[0].length)]
      liIndent = indent + ulItem[0].length
      continue
    }
    const olItem = trim.match(/^(\d+)\.\s+/)
    if (olItem) {
      flushPara()
      flushLi()
      ensureList('ol', Number(olItem[1]))
      li = [line.trimStart().slice(olItem[0].length)]
      liIndent = indent + olItem[0].length
      continue
    }
    if (li) {
      li.push(line)
      continue
    }
    closeList()
    para.push(line)
  }
  flushPara()
  flushQuote()
  closeList()
  if (inFence) {
    out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
  }
  return out.join('\n')
}

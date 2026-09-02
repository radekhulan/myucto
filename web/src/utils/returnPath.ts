/**
 * Kam se vrátit po přihlášení, když uživatel přišel na konkrétní adresu.
 *
 * PROČ TO EXISTUJE: kdo dostane odkaz do aplikace a nemá živou relaci, skončil
 * dosud na přehledu a hledanou stránku si musel najít znovu. U odkazu na
 * konkrétní doklad nebo mzdový běh to znamená proklikat se tam ručně.
 *
 * PROČ SAMOSTATNĚ: je to bezpečnostní kontrola, ne pohodlí. Hodnota přichází
 * z adresního řádku, takže ji může podstrčit kdokoli — a přihlašovací
 * obrazovka je přesně to místo, kam phishing míří. Otevřený redirect by
 * z našeho loginu udělal odrazový můstek na cizí web s naší adresou
 * v odkazu.
 */

/** Cíle, které samy vedou k přihlášení. Návrat na ně je smyčka. */
const LOOPING_PATHS = ['/login', '/setup', '/setup-mfa', '/setup-totp']

/**
 * Vrátí `candidate`, jen když je to bezpečná vlastní cesta v aplikaci.
 * Jinak `fallback`.
 *
 * Přijímá se jen cesta začínající JEDNÍM lomítkem. `//cizi.example` je pro
 * prohlížeč plnohodnotná adresa na cizí původ, `https://…` a `javascript:`
 * taky — všechno tohle propadne na `fallback`.
 */
export function safeReturnPath(candidate: unknown, fallback: string): string {
  if (typeof candidate !== 'string' || candidate === '') return fallback
  if (!candidate.startsWith('/') || candidate.startsWith('//')) return fallback
  // Zpětné lomítko umí část prohlížečů číst jako oddělovač původu, takže
  // `/\cizi.example` by mohlo skončit jinde, než jak to vypadá.
  if (candidate.startsWith('/\\')) return fallback

  const path = candidate.split(/[?#]/)[0]
  if (LOOPING_PATHS.includes(path)) return fallback

  return candidate
}

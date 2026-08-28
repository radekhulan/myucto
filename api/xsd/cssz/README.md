# Připnuté XSD ČSSZ mimo JMHZ

Tento adresář obsahuje dvousouborové oficiální XSD balíčky pro ruční zákonné
agendy. `CsszSchemaCatalog` je fail-closed: použití schématu povolí jen při
shodě SHA-256 vstupního XSD i jeho relativního importu. Katalog nenaznačuje,
že aplikace tyto agendy umí automaticky vytvářet nebo odesílat.

| Agenda | Balíček | XSD | Payload | Vstupní XSD (SHA-256) | Import (SHA-256) |
| --- | --- | --- | --- | --- | --- |
| NEMPRI25 | 1.0 | 1.0 | 1.0 | [NEMPRI25.xsd](https://www.cssz.gov.cz/documents/20143/2739697/NEMPRI25.xsd/ccb22dda-af2d-8752-1ba2-7b6742052fc5) (`c381ca7560eed2aae5ceb91c6a26a1904b4e17c85755921fe02316167af2c8ca`) | [baseTypes2.xsd](https://www.cssz.gov.cz/documents/20143/99647/baseTypes2.xsd/9f54f063-f1de-2ce6-ae8c-4d2dc7542af4) (`579888e1dd29a60eb66b26dcf32031658ead51618c74f9f96fabf8d7a1305747`) |
| HZUPN20 | 1.2 | 1.1 | 20201.01 | [HZUPN20 v1.2.xsd](https://www.cssz.gov.cz/documents/20143/284890/HZUPN20%2Bv1.2.xsd/0d032732-e46d-f5b2-eb5f-037c7555a799) (`5cea60ea30e5f6872c7b67324274abd1591af5e1c33fa2f42ef0c1e8ff740621`) | [baseTypes.xsd](https://www.cssz.gov.cz/documents/20143/99647/baseTypes.xsd/e2c9cd79-5b72-02be-b959-9aa63fd02044) (`021d63bd7d2432ab431d225e980e6919b36616a02cd11fcafea9de6172294f30`) |

U HZUPN20 nejde o překlep: ČSSZ publikuje balíček v1.2, ale jeho kořenové
`xs:schema` nese verzi 1.1 a oficiální datová věta používá payload `20201.01`.

Změna URL, verze, otisku, názvu nebo relativního importu je vědomá změna
balíčku a musí aktualizovat katalog i jeho test.

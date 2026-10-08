# FA(3) schema files

These are the official XSD files of the Polish structured invoice FA(3), version `1-0E`
(target namespace `http://crd.gov.pl/wzor/2025/06/25/13775/`), published by the Ministry of Finance:

- `schemat_FA3_v1-0E.xsd` – https://github.com/CIRFMF/ksef-docs (`faktury/schemy/FA/schemat_FA(3)_v1-0E.xsd`)
- `StrukturyDanych_v10-0E.xsd`, `ElementarneTypyDanych_v10-0E.xsd`, `KodyKrajow_v10-0E.xsd` –
  http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/

The only modification is that absolute `schemaLocation` URLs were replaced with relative file
names, so that validation works offline and never triggers network access.

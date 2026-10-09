# FA_RR (1) schema

Official XSD of the structured invoice FA_RR (1) (flat-rate farmer purchase invoices, art. 116 of the VAT Act),
schema version `1-1E`, target namespace `http://crd.gov.pl/wzor/2026/03/06/14189/`, published by the Ministry of Finance:
https://github.com/CIRFMF/ksef-docs (`faktury/schemy/RR/schemat_FA_RR(1)_v1-1E.xsd`).

The only modification: the absolute `schemaLocation` of the shared data-types schema points to the copy bundled in
`../fa3/` so that validation works offline.

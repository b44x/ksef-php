# Fixtures

- `pef-invoice.xml` – a minimal Peppol BIS Billing 3.0 invoice written for this project; placeholders are `{{NAME}}`.
- `pef-correction.xml` – the PEF_KOR (3) correction template of the Ministry of Finance's KSeF client
  (https://github.com/CIRFMF/ksef-client-csharp, `KSeF.Client.Tests.Core/Templates/invoice-template-fa-3-pef-correction.xml`),
  used unchanged under its MIT licence:

  > Copyright (c) 2025 Ministerstwo Finansów
  >
  > Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
  > documentation files (the "Software"), to deal in the Software without restriction, including without limitation
  > the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and
  > to permit persons to whom the Software is furnished to do so, subject to the following conditions: the above
  > copyright notice and this permission notice shall be included in all copies or substantial portions of the
  > Software. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.

  Its placeholders are `#supplier_nip#`, `#buyer_nip#`, `#buyer_reference#`, `#iban#`, `#invoice_number#`,
  `#issue_date#`, `#due_date#` and `#ksef_number#` (the KSeF number of the corrected invoice). The template carries an
  attachment, so the seller must have given the attachment consent first.

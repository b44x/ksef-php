<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The role of an additional party on the invoice (`Rola` of `Podmiot3`). */
enum ThirdPartyRole: string
{
    /** Factor (`Faktor`). */
    case Factor = '1';
    /** Recipient: an internal unit or branch of the buyer that is not the buyer itself (`Odbiorca`). */
    case Recipient = '2';
    /** Original entity taken over or transformed by the seller (`Podmiot pierwotny`). */
    case OriginalEntity = '3';
    /** Additional buyer (`Dodatkowy nabywca`). */
    case AdditionalBuyer = '4';
    /** Entity issuing the invoice on behalf of the seller (`Wystawca faktury`). */
    case Issuer = '5';
    /** Entity paying instead of the buyer (`Dokonujący płatności`). */
    case Payer = '6';
    /** Local government unit - issuer (`JST - wystawca`). */
    case LocalGovernmentIssuer = '7';
    /** Local government unit - recipient (`JST - odbiorca`). */
    case LocalGovernmentRecipient = '8';
    /** VAT group member - issuer (`Członek grupy VAT - wystawca`). */
    case VatGroupIssuer = '9';
    /** VAT group member - recipient (`Członek grupy VAT - odbiorca`). */
    case VatGroupRecipient = '10';
    /** Employee (`Pracownik`). */
    case Employee = '11';
}

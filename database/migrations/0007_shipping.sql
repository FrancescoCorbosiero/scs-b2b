-- Costi di spedizione sulla richiesta d'ordine (vedi docs/06 § /carrello):
-- tariffa forfettaria sotto la soglia pezzi (SHIPPING_FEE, default 10,00 €),
-- gratuita da FREE_SHIPPING_MIN_ITEMS pezzi in su (default 7).
-- L'importo è netto (VAT esclusa) ed entra nell'imponibile: vat_amount e
-- total_gross tengono già conto della spedizione.

ALTER TABLE order_requests ADD COLUMN shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_amount;

-- NB: le richieste precedenti alla migrazione restano a 0,00 (spedizione già
-- inclusa/omaggio nel vecchio flusso): totali e ricevute storiche non cambiano.

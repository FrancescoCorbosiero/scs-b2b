-- Dettagli ordine/pacchetto letti dal fornitore (GET order-details e
-- package-details, docs/09): snapshot JSON dell'ultima lettura, separato
-- da response_payload che resta la risposta IMMUTABILE della creazione.

ALTER TABLE dropship_orders ADD COLUMN details_payload TEXT NULL;

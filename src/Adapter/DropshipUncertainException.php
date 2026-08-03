<?php

declare(strict_types=1);

namespace App\Adapter;

/**
 * Esito INCERTO di una creazione ordine dropship: la richiesta potrebbe
 * essere arrivata al fornitore (timeout dopo l'invio, HTTP 5xx, risposta
 * illeggibile). Chi la intercetta NON deve ritentare l'invio: prima va
 * verificato presso il fornitore se l'ordine esiste, altrimenti si rischia
 * un ordine doppio con addebito reale.
 */
final class DropshipUncertainException extends DropshipException
{
}

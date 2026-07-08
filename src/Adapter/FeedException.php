<?php

declare(strict_types=1);

namespace App\Adapter;

/**
 * Errore di download o validazione del feed: il sync abortisce senza
 * toccare il database (vedi docs/03 § strategia di sync).
 */
final class FeedException extends \RuntimeException
{
}

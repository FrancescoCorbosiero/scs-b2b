<?php

declare(strict_types=1);

namespace App\Adapter;

/** Estesa da DropshipUncertainException (esito ambiguo: mai ritentare alla cieca). */
class DropshipException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Scripts;

enum WriteConfirmationMode
{
    case Single;
    case Bulk;
}

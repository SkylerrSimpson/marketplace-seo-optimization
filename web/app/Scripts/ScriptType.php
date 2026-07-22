<?php

declare(strict_types=1);

namespace App\Scripts;

enum ScriptType: string
{
    case Read = 'read';
    case Write = 'write';
}

<?php

declare(strict_types=1);

namespace App\Scripts;

enum ParamType: string
{
    case String = 'string';
    case Enum = 'enum';
    case Bool = 'bool';
    case Int = 'int';
    case File = 'file';
}

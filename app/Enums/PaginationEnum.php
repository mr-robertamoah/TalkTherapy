<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum PaginationEnum : int {
    use EnumTrait;

    case preferencesPagination = 10;
    case pagination = 5;
    case homePagination = 20;
}
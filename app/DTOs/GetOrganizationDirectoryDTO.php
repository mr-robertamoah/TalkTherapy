<?php

namespace App\DTOs;

use MrRobertAmoah\DTO\BaseDTO;

class GetOrganizationDirectoryDTO extends BaseDTO
{
    public ?bool $isProvider = null;

    public ?bool $isConsumer = null;
}

<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Exceptions\OrganizationException;

class EnsureOrganizationCounsellorCompensationDataIsValidAction extends Action
{
    // Defense-in-depth on top of the FormRequest's conditional validation -- cross-field
    // consistency the request rules alone don't fully capture. Symmetric across all 3 types:
    // each one requires exactly its own fields AND rejects every other type's fields, so e.g. a
    // `fixed` payload can't also carry a leftover `percentage`/`basis` (reviewer-found gap).
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        if ($dto->type === OrganizationCounsellorCompensationTypeEnum::fixed->value) {
            if (is_null($dto->amount) || is_null($dto->currency)) {
                throw new OrganizationException('A fixed compensation amount requires both an amount and a currency.', 422);
            }

            if (! is_null($dto->percentage) || ! is_null($dto->basis)) {
                throw new OrganizationException('A fixed compensation cannot carry a percentage or basis.', 422);
            }

            return;
        }

        if ($dto->type === OrganizationCounsellorCompensationTypeEnum::percentage->value) {
            if (is_null($dto->percentage) || is_null($dto->basis)) {
                throw new OrganizationException('A percentage compensation requires both a percentage and a basis.', 422);
            }

            if (! is_null($dto->amount) || ! is_null($dto->currency)) {
                throw new OrganizationException('A percentage compensation cannot carry an amount or currency.', 422);
            }

            return;
        }

        if ($dto->type === OrganizationCounsellorCompensationTypeEnum::free->value) {
            if (! is_null($dto->amount) || ! is_null($dto->currency) || ! is_null($dto->percentage) || ! is_null($dto->basis)) {
                throw new OrganizationException('Free compensation cannot carry an amount, currency, percentage, or basis.', 422);
            }

            return;
        }

        throw new OrganizationException('Compensation type must be fixed, percentage, or free.', 422);
    }
}

<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Exceptions\OrganizationException;

// TT-7.3b-a/SCRUM-231: only an org admin can register a payment instrument, for a verified,
// consumer-capable org -- mirrors EnsureOrganizationCanPayForModelAction's own eligibility gate
// (is_consumer + verified), since a payment instrument is only ever useful for an org that could
// legitimately pay for something via it in the first place. Re-checked here rather than assumed,
// so a later ticket calling this action directly stays safe even without going through whatever
// controller TT-7.3b-i eventually builds.
class EnsureCanRegisterOrganizationPaymentInstrumentAction extends Action
{
    public function execute(OrganizationPaymentInstrumentDTO $dto): void
    {
        if (is_null($dto->user) || is_null($dto->organization) || ! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to register a payment instrument for this organization.', 403);
        }

        if (! $dto->organization->isVerified() || ! $dto->organization->is_consumer) {
            throw new OrganizationException('Only a verified, consumer-capable organization can register a payment instrument.', 422);
        }
    }
}

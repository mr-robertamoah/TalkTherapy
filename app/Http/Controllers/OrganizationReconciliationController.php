<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationDTO;
use App\Http\Resources\OrganizationFinancedTransactionResource;
use App\Http\Resources\OrganizationInvoiceResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// TT-7.3b-j/SCRUM-241: org-admin reconciliation view -- mirrors OrganizationController::dashboard()'s
// own page-plus-dedicated-JSON-endpoints shape (TT-6.6a) exactly, including the paginator-repoint
// trick (each initial paginator's path is set to its own dedicated route, not this page's own
// route, so a later "load more" axios GET gets JSON back rather than a full Inertia page).
class OrganizationReconciliationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $dto = OrganizationDTO::new()->fromArray([
                'user' => $request->user(),
                'organization' => Organization::find($request->route('organizationId')),
            ]);

            $organization = OrganizationService::new()->getOrganization($dto);
            $dto->organization = $organization;

            $transactionsPaginator = OrganizationService::new()->getOrganizationFinancedTransactions($dto);
            $transactionsPaginator->setPath(route('organizations.reconciliation.transactions', ['organizationId' => $organization->id]));

            $invoicesPaginator = OrganizationService::new()->getOrganizationRetainerInvoices($dto);
            $invoicesPaginator->setPath(route('organizations.reconciliation.invoices', ['organizationId' => $organization->id]));

            return Inertia::render('Organization/Reconciliation', [
                'organization' => new OrganizationResource($organization),
                'financedTransactions' => $this->paginatedResource(OrganizationFinancedTransactionResource::collection($transactionsPaginator)),
                'retainerInvoices' => $this->paginatedResource(OrganizationInvoiceResource::collection($invoicesPaginator)),
            ]);
        } catch (Throwable $th) {
            $message = $this->messageFor($th, $this->statusFor($th));

            return Redirect::route('home')->withErrors(['alert' => $message]);
        }
    }

    public function transactions(Request $request)
    {
        try {
            $dto = OrganizationDTO::new()->fromArray([
                'user' => $request->user(),
                'organization' => Organization::find($request->route('organizationId')),
            ]);

            return OrganizationFinancedTransactionResource::collection(
                OrganizationService::new()->getOrganizationFinancedTransactions($dto)
            );
        } catch (Throwable $th) {
            $status = $this->statusFor($th);

            return response()->json(['message' => $this->messageFor($th, $status)], $status);
        }
    }

    public function invoices(Request $request)
    {
        try {
            $dto = OrganizationDTO::new()->fromArray([
                'user' => $request->user(),
                'organization' => Organization::find($request->route('organizationId')),
            ]);

            return OrganizationInvoiceResource::collection(
                OrganizationService::new()->getOrganizationRetainerInvoices($dto)
            );
        } catch (Throwable $th) {
            $status = $this->statusFor($th);

            return response()->json(['message' => $this->messageFor($th, $status)], $status);
        }
    }

    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
    }
}

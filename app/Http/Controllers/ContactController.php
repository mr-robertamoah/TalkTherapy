<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateContactDTO;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Services\ContactService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class ContactController extends Controller
{
    public function createContact(Request $request)
    {
        try {
            $contact = ContactService::new()->createContact(
                CreateContactDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'name' => $request->name,
                    'organisation' => $request->organisation,
                    'email' => $request->email,
                    'type' => $request->type,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return $this->returnSuccess($request, $contact);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function updateContact(Request $request)
    {
        $contact = Contact::find($request->contactId);
        try {
            $contact = ContactService::new()->updateContact(
                CreateContactDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'name' => $request->name,
                    'organisation' => $request->organisation,
                    'email' => $request->email,
                    'type' => $request->type,
                    'contact' => $contact,
                ])
            );

            return $this->returnSuccess($request, $contact);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function deleteContact(Request $request)
    {
        $contact = Contact::find($request->contactId);
        try {
            ContactService::new()->deleteContact(
                CreateContactDTO::new()->fromArray([
                    'user' => $request->user(),
                    'contact' => $contact,
                ])
            );

            return $this->returnSuccess($request, $contact);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function getContacts(Request $request)
    {
        try {
            $contacts = ContactService::new()->getContacts(
                CreateContactDTO::new()->fromArray([
                    'user' => $request->user(),
                    'like' => $request->like,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return ContactResource::collection($contacts);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Contact $contact)
    {
        $contact = new ContactResource($contact);
        
        if ($request->acceptsJson()) return response()->json(['contact' => $contact]);
        
        return Redirect::back()->with(['contact' => $contact]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);

        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}

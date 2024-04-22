<?php

namespace App\Services;

use App\Models\Visitor;
use Illuminate\Http\Request;

class VisitorService extends Service
{
    public function storeVisitor(Request $request)
    {
        Visitor::updateOrCreate([
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
        ]);
    }
}
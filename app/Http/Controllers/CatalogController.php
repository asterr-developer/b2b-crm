<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\SalesEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        return Inertia::render('Catalog', [
            'items' => CatalogItem::where('department_id', $user->department_id)->orderBy('name')->orderBy('id')->paginate(25),
            'canEdit' => $user->leadsSalesDepartment(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
        ]);
        DB::transaction(function () use ($user, $data) {
            $item = CatalogItem::updateOrCreate(['department_id' => $user->department_id, 'name' => $data['name']], ['price' => $data['price']]);
            SalesEvent::record($user, 'catalog.saved', ['item_id' => $item->id, 'price' => $item->price]);
        }, 3);

        return to_route('catalog.index');
    }
}

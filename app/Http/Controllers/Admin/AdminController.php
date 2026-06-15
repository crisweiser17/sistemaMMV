<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ResourceRegistry;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        $recursos = ResourceRegistry::all();

        return view('admin.index', compact('recursos'));
    }
}

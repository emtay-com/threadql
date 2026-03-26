<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class PanelController extends Controller
{
    /**
     * Display the admin panel.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.panel');
    }
}

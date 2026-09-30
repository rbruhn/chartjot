<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class FriendsController extends Controller
{
    public function index(): View
    {
        return view('friends.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function index(): View
    {
        $user = User::firstOrFail();

        $email = $user->emai; // Typo
        $email = $user->email;

        $maybe_user = null;

        /** @var ?User */
        $maybe_user_with_correct_type = null;

        return view('welcome', [
            'user' => $user,
            'controller' => $this,
            'users' => User::all(),
            'testing' => false,
            'maybe_user' => $maybe_user,
            'maybe_user_with_correct_type' => $maybe_user_with_correct_type,
        ]);
    }

    public function namespaced_views(): string
    {
        return view()->make('Namespace::welcome')->render();
    }

    public function test(): string
    {
        return 'test';
    }
}

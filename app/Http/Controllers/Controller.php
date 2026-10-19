<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Src\Shared\Traits\ApiResponse;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;
}

<?php

namespace App\Http\Controllers;

use App\Services\OperationalReadiness;
use Inertia\Inertia;
use Inertia\Response;

class OperationalReadinessController extends Controller
{
    public function __invoke(OperationalReadiness $readiness): Response
    {
        return Inertia::render('OperationalReadiness', ['report' => $readiness->report()]);
    }
}

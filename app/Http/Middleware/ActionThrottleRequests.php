<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class ActionThrottleRequests extends ThrottleRequests
{
    /**
     * Keep the framework's parameter signature; named and explicit shared budgets retain their semantics.
     *
     * @param  Request  $request
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        if (is_string($maxAttempts) && func_num_args() === 3 && $this->limiter->limiter($maxAttempts)) {
            return parent::handle($request, $next, $maxAttempts);
        }
        $route = $request->route();
        if ($prefix === '' && $route instanceof Route) {
            $prefix = 'route-action:'.hash('sha256', $route->uri().'|'.implode('|', $route->methods())).':';
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Response;

class AuthorizeStudent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if(auth()->user()->isStudent())
            return $next($request);

        return response("Forbidden", Response::HTTP_FORBIDDEN);
    }
}

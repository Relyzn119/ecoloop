<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveDonationPartner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'business_owner') {
            return $next($request);
        }

        $partner = $request->user()?->donationPartner;

        if (! $partner?->is_active) {
            return to_route('partner.pending');
        }

        return $next($request);
    }
}

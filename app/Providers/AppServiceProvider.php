<?php

namespace App\Providers;

use App\Models\Participant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Auth::viaRequest('participant-token', function (Request $r) {
            $token = $r->bearerToken();

            return $token ? Participant::where('token_hash', hash('sha256', $token))->whereNull('left_at')->first() : null;
        });
        Broadcast::routes(['prefix' => 'api', 'middleware' => ['api', 'auth:participant', 'throttle:state']]);
        require base_path('routes/channels.php');
        RateLimiter::for('admission', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('state', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('actions', fn (Request $r) => Limit::perMinute(900)->by($r->user()->id));
    }
}

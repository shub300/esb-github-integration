<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Observers\MappingObserver;
use App\Models\PlatformDataMapping;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'Aacotroneo\Saml2\Events\Saml2LoginEvent' => [
            'App\Listeners\LoginListener',
        ],
		'Aacotroneo\Saml2\Events\Saml2LogoutEvent' => [
            'App\Listeners\LogoutListener',
        ],
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //PlatformDataMapping model will be observe by MappingObserver
        // PlatformDataMapping::observe(MappingObserver::class);

        
    }
}

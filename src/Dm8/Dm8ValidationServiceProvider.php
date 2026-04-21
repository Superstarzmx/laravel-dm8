<?php

namespace Duorenwei\LaravelDm8\Dm8;

use Illuminate\Validation\ValidationServiceProvider;
use Duorenwei\LaravelDm8\Dm8\Validation\Dm8DatabasePresenceVerifier;

class Dm8ValidationServiceProvider extends ValidationServiceProvider
{
    protected function registerPresenceVerifier()
    {
        $this->app->singleton('validation.presence', function ($app) {
            return new Dm8DatabasePresenceVerifier($app['db']);
        });
    }
}

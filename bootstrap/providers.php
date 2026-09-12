<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    // Was never registered, so everything in it -- the Fortify action
    // bindings and both auth rate limiters -- has been dead code. The routes
    // papered over it with hardcoded throttle:5,1 / throttle:10,1 strings.
    FortifyServiceProvider::class,
];

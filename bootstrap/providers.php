<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\MetricsServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    MetricsServiceProvider::class,
    ModuleServiceProvider::class,
];

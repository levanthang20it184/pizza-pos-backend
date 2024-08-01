<?php

// Autoload Composer
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel Application
$app = require_once __DIR__.'/../bootstrap/app.php';

// Run Laravel Application
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);
$response->send();
$kernel->terminate($request, $response);

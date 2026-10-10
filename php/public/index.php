<?php

use App\Support\RequestCapture;
use Illuminate\Foundation\Application;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// The request is captured with its body read once, never more than one byte past the API's 64 kilobyte
// cap, and with the fields of a posted form parsed by PHP. See App\Support\RequestCapture.
$app->handleRequest(RequestCapture::capture());

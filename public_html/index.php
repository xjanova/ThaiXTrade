<?php

/**
 * ThaiXTrade - Laravel Application Entry Point
 * Developed by Xman Studio
 *
 * This file serves as the entry point for all HTTP requests.
 * It loads the Composer autoloader and bootstraps the Laravel application.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());

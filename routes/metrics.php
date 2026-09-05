<?php

use BoringO11y\Httptheus\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get(config('httptheus.route.path'), MetricsController::class)->name('httptheus.metrics');

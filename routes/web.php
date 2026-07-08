<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/dashboard', '/panel');
    Route::redirect('/admin', '/panel');
    Route::redirect('/admin/{any}', '/panel/{any}')->where('any', '.*');
    Route::redirect('/categorias', '/panel/categorias');
    Route::redirect('/categorias/{any}', '/panel/categorias')->where('any', '.*');
    Route::redirect('/productos', '/panel/productos');
    Route::redirect('/productos/{any}', '/panel/productos')->where('any', '.*');
    Route::redirect('/vehiculos', '/panel/vehiculos');
    Route::redirect('/vehiculos/{any}', '/panel/vehiculos')->where('any', '.*');
    Route::redirect('/ordenes-trabajo', '/panel/orden-trabajos');
    Route::redirect('/ordenes-trabajo/{any}', '/panel/orden-trabajos')->where('any', '.*');
    Route::redirect('/transacciones-inventario', '/panel/transaccion-inventarios');
    Route::redirect('/transacciones-inventario/{any}', '/panel/transaccion-inventarios')->where('any', '.*');
    Route::redirect('/users', '/panel/users');
    Route::redirect('/users/{any}', '/panel/users')->where('any', '.*');
});

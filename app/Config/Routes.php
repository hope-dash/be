<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 * $routes->get('/', 'Home::index');
 */


$routes->group('api', function ($routes) {
    $routes->post('register', 'UserController::create');
    $routes->post('login', 'UserController::login');
    $routes->get('user/(:num)', 'UserController::userById/$1');
    $routes->put('user/(:num)', 'UserController::edit/$1');
    $routes->delete('user/(:num)', 'UserController::delete/$1');
    $routes->post('cashflow', 'CashflowController::create');
    $routes->put('cashflow/(:num)', 'CashflowController::edit/$1');
    $routes->get('cashflow', 'CashflowController::listCashflow');

    //toko
    $routes->post('toko', 'TokoController::create');
    $routes->get('toko/(:num)', 'TokoController::getDetailById/$1');
    $routes->get('toko', 'TokoController::getAllToko');
    $routes->put('toko/(:num)', 'TokoController::update/$1');
    $routes->delete('toko/(:num)', 'TokoController::delete/$1');

    // Model Barang Routes
    $routes->post('model_barang', 'BarangController::createModelBarang');
    $routes->get('model_barang', 'BarangController::listModelBarang');
    $routes->put('model_barang/(:num)', 'BarangController::updateModelBarang/$1');


    // Seri Routes
    $routes->post('seri', 'BarangController::createSeri');
    $routes->get('seri', 'BarangController::listSeri');
    $routes->put('seri/(:num)', 'BarangController::updateSeri/$1');

    $routes->post('product', 'ProductController::createProduct');

});




<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 * $routes->get('/', 'Home::index');
 */
$routes->options('api/(:any)', function () {
    return service('response')->setStatusCode(200);
});

$routes->post('api/login', 'UserController::login');
$routes->post('api/register', 'UserController::create');

$routes->group('api', ['filter' => 'jwtAuth'], function ($routes) {
    //user
    //$routes->post('register', 'UserController::create');
    $routes->get('user/(:num)', 'UserController::userById/$1');
    $routes->get('user', 'UserController::getAllUser');
    $routes->put('user/(:num)', 'UserController::edit/$1');
    $routes->delete('user/(:num)', 'UserController::delete/$1');
    $routes->get('user/detail', 'UserController::userByToken');

    //customer
    $routes->post('customer', 'CustomerController::createCustomer');
    $routes->get('customer/(:num)', 'CustomerController::getByIdCustomer/$1');
    $routes->get('customer', 'CustomerController::getAllCustomer');
    $routes->put('customer/(:num)', 'CustomerController::updateCustomer/$1');
    $routes->delete('customer/(:num)', 'CustomerController::deleteCustomer/$1');

    //cashflow
    $routes->post('cashflow', 'CashflowController::create');
    $routes->put('cashflow/(:num)', 'CashflowController::edit/$1');
    $routes->get('cashflow', 'CashflowController::listCashflow');

    //toko
    $routes->post('toko', 'TokoController::create');
    $routes->get('toko/(:num)', 'TokoController::getDetailById/$1');
    $routes->get('toko', 'TokoController::getAllToko');
    $routes->put('toko/(:num)', 'TokoController::update/$1');
    $routes->delete('toko/(:num)', 'TokoController::delete/$1');
    $routes->get('dropdown/toko', 'TokoController::dropdownToko');


    // Model Barang Routes
    $routes->post('model_barang', 'BarangController::createModelBarang');
    $routes->get('model_barang', 'BarangController::listModelBarang');
    $routes->put('model_barang/(:num)', 'BarangController::updateModelBarang/$1');
    $routes->delete('model_barang/(:num)', 'BarangController::deleteModel/$1');
    $routes->get('dropdown/model_barang', 'BarangController::dropdownModel');

    // Seri Routes
    $routes->post('seri', 'BarangController::createSeri');
    $routes->get('seri', 'BarangController::listSeri');
    $routes->put('seri/(:num)', 'BarangController::updateSeri/$1');
    $routes->delete('seri/(:num)', 'BarangController::deleteSeri/$1');
    $routes->get('dropdown/seri', 'BarangController::dropdownSeri');

    $routes->post('product', 'ProductController::createProduct');
    $routes->get('product/(:num)', 'ProductController::getDetailById/$1');
    $routes->get('product', 'ProductController::getAllProduct');
    $routes->post('product-stock', 'ProductController::getProductStock');
    $routes->put('product/(:num)', 'ProductController::updateProduct/$1');
    

    //transaction
    $routes->post('transaction', 'TransactionController::createTransaction');
    $routes->get('transaction', 'TransactionController::getListTransaction');
    $routes->get('dropdown/status-transaction', 'TransactionController::dropdownStatusTransaction');

    //reporting
    $routes->get('reporting/revenue-profit', 'TransactionController::calculateRevenueAndProfit');
    $routes->get('reporting/debit-credit', 'TransactionController::calculateDebitAndCredit');
    $routes->get('reporting/alokasi-pengeluaran', 'TransactionController::calculateExpenseAllocation');
    $routes->get('reporting/top-customers', 'TransactionController::topCustomers');
    $routes->get('reporting/top-products', 'TransactionController::topSoldProducts');
    $routes->get('reporting/arus-kas', 'TransactionController::getFinancialSummary');

    $routes->resource('suplier', ['controller' => 'SuplierController']);
    $routes->get('dropdown/suplier', 'SuplierController::dropdownSuplier');
    

});




<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->post('/api/register', 'UserController::create');
$routes->post('/api/login', 'UserController::login');
$routes->put('/api/update/(:num)', 'UserController::edit/$1');
$routes->delete('/api/delete/(:num)', 'UserController::delete/$1');
$routes->get('/api/user/(:num)', 'UserController::userById/$1');

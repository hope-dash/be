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
$routes->post('api/register-be', 'UserController::create_be');
$routes->post('api/pricelist', 'ProductController::getProductStockForPricelist');
$routes->post('api/login/customer', 'CustomerController::checkSpecialCustomer');
$routes->get('api/dropdown/toko', 'TokoController::dropdownToko');
$routes->get('api/dropdown/model_barang', 'BarangController::dropdownModel');
$routes->get('api/dropdown/status-transaction', 'TransactionController::dropdownStatusTransaction');
$routes->get('api/dropdown/suplier', 'SuplierController::dropdownSuplier');
$routes->get('api/detail/toko/(:num)', 'TokoController::getDetailById/$1');
$routes->get('api/dropdown/seri', 'BarangController::dropdownSeri');
$routes->get('api/dropdown/seri-by-product', 'ProductController::getListSeribySearchProduct');
$routes->get('api/product/(:num)', 'ProductController::getDetailById/$1');
$routes->post('api/closing/auto-monthly', 'ClosingController::autoCloseMonthly');


$routes->group('api', ['filter' => 'jwtAuth'], function ($routes) {
    //user
    $routes->post('register', 'UserController::create');
    $routes->get('user/(:num)', 'UserController::userById/$1');
    $routes->get('user', 'UserController::getAllUser');
    $routes->put('user/(:num)', 'UserController::edit/$1');
    $routes->delete('user/(:num)', 'UserController::delete/$1');
    $routes->get('user/detail', 'UserController::userByToken');
    $routes->get('user/dropdown', 'UserController::dropdownUser');

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

    // Model Barang Routes
    $routes->post('model_barang', 'BarangController::createModelBarang');
    $routes->get('model_barang', 'BarangController::listModelBarang');
    $routes->put('model_barang/(:num)', 'BarangController::updateModelBarang/$1');
    $routes->delete('model_barang/(:num)', 'BarangController::deleteModel/$1');

    // Seri Routes
    $routes->post('seri', 'BarangController::createSeri');
    $routes->get('seri', 'BarangController::listSeri');
    $routes->put('seri/(:num)', 'BarangController::updateSeri/$1');
    $routes->delete('seri/(:num)', 'BarangController::deleteSeri/$1');

    //products
    $routes->post('product', 'ProductController::createProduct');
    $routes->post('product/image', 'ProductController::uploadImages');
    $routes->get('product', 'ProductController::getAllProduct');
    $routes->post('product-stock', 'ProductController::getProductStock');
    $routes->put('product/(:num)', 'ProductController::updateProduct/$1');
    $routes->get('product-summary', 'ProductController::getProductStockSummary');
    $routes->delete('product/(:num)', 'ProductController::deleteByProductId/$1');
    $routes->post('bulk-product', 'ProductController::bulkUpload');
    $routes->get('model_barang/count', 'ProductController::getTotalByModelId');
    $routes->get('seri_barang/count', 'ProductController::getTotalBySeriId');


    //Pembelian
    $routes->post('transaction/belanja', 'PembelianController::createPembelian');
    $routes->put('transaction/belanja/cancel/(:num)', 'PembelianController::cancelPembelian/$1');
    $routes->put('transaction/belanja/review/(:num)', 'PembelianController::reviewPembelian/$1');
    $routes->post('transaction/belanja/execute/(:num)', 'PembelianController::executePembelian/$1');
    $routes->get('transaction/belanja', 'PembelianController::listPembelian');
    $routes->get('transaction/belanja/(:num)', 'PembelianController::getPembelianById/$1');

    //transaction
    $routes->post('transaction', 'TransactionController::createTransaction');
    $routes->get('transaction', 'TransactionController::getListTransaction');
    $routes->get('transaction/(:num)', 'TransactionController::getTransactionDetailById/$1');

    $routes->post('count-transaction', 'TransactionController::countTransaction');
    $routes->put('transaction/(:num)', 'TransactionController::updateTransaction/$1');

    $routes->post('transaction/refund/(:num)', 'TransactionController::updateTransactionStatusToRefunded/$1');
    $routes->post('transaction/cancel/(:num)', 'TransactionController::updateTransactionStatusToCancel/$1');
    $routes->post('transaction/dp/(:num)', 'TransactionController::updateTransactionStatusToPartiallyPaid/$1');
    $routes->post('transaction/paid/(:num)', 'TransactionController::updateTransactionStatusToFullyPaid/$1');
    $routes->post('transaction/complaint/(:num)', 'TransactionController::complainProduct/$1');
    $routes->post('transaction/update-status/(:num)', 'TransactionController::updateTransactionStatus/$1');
    $routes->post('transaction/notes/', 'TransactionController::createUpdateNotesTransaction');


    //dashboard
    $routes->get('dashboard/summary', 'DashboardController::getSummary');
    $routes->get('dashboard/branch-performance', 'DashboardController::getBranchPerformance');

    //reporting
    $routes->get('reporting/revenue-profit', 'TransactionController::calculateRevenueAndProfit');
    $routes->get('reporting/debit-credit', 'CashflowController::calculateDebitAndCredit');
    $routes->get('reporting/alokasi-pengeluaran', 'TransactionController::calculateExpenseAllocation');
    $routes->get('reporting/top-customers', 'TransactionController::topCustomers');
    $routes->get('reporting/top-products', 'TransactionController::topSoldProducts');
    $routes->get('reporting/stock-allocation', 'TransactionController::listKeluarBarang');
    $routes->get('reporting/arus-kas', 'TransactionController::getFinancialSummary');
    $routes->get('reporting/due-transaction', 'TransactionController::getUpcomingDueTransactions');
    $routes->get('reporting/revenue-profit-detail', 'TransactionController::listSalesProductWithTransaction');
    $routes->get('reporting/log', 'LogAktivitasController::index');
    $routes->get('reporting/sales-product', 'TransactionController::listSalesProductWithTransactionBaru');

    $routes->group('closing', function ($routes) {
        $routes->post('process', 'ClosingController::closeMonthly');
        $routes->post('rollback', 'ClosingController::rollbackClosingByMonth');
        $routes->post('detail', 'ClosingController::getClosingDetailsByMonth');
        $routes->get('list', 'ClosingController::listClosings');
        $routes->get('suplier', 'ClosingController::getSupplierClosingReport');



    });

    $routes->resource('suplier', ['controller' => 'SuplierController']);

    // V2 Transaction Routes
    $routes->group('v2', function ($routes) {
        $routes->post('transaction', 'TransactionControllerV2::create');
        $routes->get('transaction/(:num)', 'TransactionControllerV2::getDetail/$1');
        $routes->post('transaction/count', 'TransactionControllerV2::calculate');
        $routes->post('transaction/(:num)/payment', 'TransactionControllerV2::addPayment/$1');
        $routes->post('transaction/(:num)/cancel', 'TransactionControllerV2::cancel/$1');
        $routes->post('transaction/(:num)/return', 'TransactionControllerV2::returnProduct/$1');
        $routes->post('transaction/(:num)/refund', 'TransactionControllerV2::refund/$1');
        $routes->post('transaction/(:num)/delivery-status', 'TransactionControllerV2::updateDeliveryStatus/$1');

        // Journal Routes
        $routes->get('journal', 'JournalController::index');
        $routes->get('journal/(:num)', 'JournalController::show/$1');
        $routes->post('journal/manual', 'JournalController::createManualJournal');

        // Expense Routes
        $routes->get('expense/accounts', 'ExpenseController::accounts');
        $routes->get('expense', 'ExpenseController::getList');
        $routes->post('expense', 'ExpenseController::create');

        // Purchase V2 (Overriding/Alternative to old Pembelian)
        $routes->post('purchase', 'PembelianControllerV2::create');
        $routes->post('purchase/(:num)/execute', 'PembelianControllerV2::execute/$1');

        // Accounting Reports
        $routes->get('accounts', 'AccountingReportController::getAccounts');
        $routes->get('report/journal', 'AccountingReportController::journal');
        $routes->get('report/ledger', 'AccountingReportController::ledger');
        $routes->get('report/ledger/detail', 'AccountingReportController::ledgerDetail');
        $routes->get('report/income-statement', 'AccountingReportController::incomeStatement');
        $routes->get('report/balance-sheet', 'AccountingReportController::balanceSheet');

        // Closing
        $routes->get('closing/preview', 'ClosingControllerV2::preview');
        $routes->post('closing/process', 'ClosingControllerV2::process');

        // Finance (Transfer & Profit)
        $routes->post('finance/transfer', 'FinanceController::transfer');
        $routes->post('finance/distribute-profit', 'FinanceController::distributeProfit');

        // Inventory (Stock Transfer)
        $routes->post('inventory/transfer', 'InventoryController::transfer');

        // Product V2
        $routes->post('product', 'ProductController::createProductV2');
        $routes->put('product/(:num)', 'ProductController::updateProductV2/$1');

        // Transaction List & Meta
        $routes->get('transaction/list', 'TransactionControllerV2::getTransactionsByStatus');
        $routes->post('transaction/(:num)/meta', 'TransactionControllerV2::addTransactionMeta/$1');

        // Voucher Management (Admin)
        $routes->post('voucher', 'VoucherController::create');
        $routes->get('voucher', 'VoucherController::index');
        $routes->put('voucher/(:num)', 'VoucherController::update/$1');
        $routes->delete('voucher/(:num)', 'VoucherController::delete/$1');
        // Global Upload
        $routes->post('upload/image', 'UploadController::uploadImage');
    });

});

// Customer V2 Public Routes (No Auth Required) - OUTSIDE jwtAuth group
$routes->group('api/v2/customer', function ($routes) {
    $routes->post('register', 'CustomerControllerV2::register');
    $routes->post('verify-email', 'CustomerControllerV2::verifyEmail');
    $routes->post('login', 'CustomerControllerV2::login');
    $routes->post('voucher/validate', 'CustomerControllerV2::validateVoucher');
    $routes->get('products', 'CustomerControllerV2::getProducts');
    $routes->get('products/(:num)', 'CustomerControllerV2::getProductDetail/$1');

});

// Customer Email Verification Page (Public - GET from email link)
$routes->get('api/customer/verify', 'CustomerControllerV2::verifyEmailPage');

// Customer V2 Protected Routes (Requires Customer Auth)
$routes->group('api/v2/customer', ['filter' => 'customerJwtAuth'], function ($routes) {
    $routes->get('profile', 'CustomerControllerV2::getProfile');
    $routes->put('profile', 'CustomerControllerV2::updateProfile');
    $routes->post('pricelist', 'ProductController::getProductStockForPricelistV2');
    $routes->post('voucher/(:num)/apply', 'CustomerControllerV2::applyVoucher/$1');

    // Cart Routes
    $routes->get('cart', 'CustomerTransactionControllerV2::getCart');
    $routes->post('cart', 'CustomerTransactionControllerV2::saveCart');
    $routes->delete('cart/(:num)', 'CustomerTransactionControllerV2::deleteCartItem/$1');

    // Transaction Routes
    $routes->post('checkout', 'CustomerTransactionControllerV2::checkout');
    $routes->post('payment/upload', 'CustomerTransactionControllerV2::uploadPaymentProof');
});

// Invoice & Receipt (Public - No Auth Required)
// Download routes must come BEFORE view routes to avoid conflicts
$routes->get('api/invoice/download/(:num)', 'InvoiceController::downloadPdf/$1');
$routes->get('api/invoice/download-mpdf/(:num)', 'InvoiceController::downloadPdfMpdf/$1');
$routes->get('api/receipt/download/(:num)', 'InvoiceController::downloadReceiptPdf/$1');
$routes->get('api/invoice/(:num)', 'InvoiceController::view/$1');
$routes->get('api/receipt/(:num)', 'InvoiceController::receipt/$1');

// Wilayah Indonesia API (Public - No Auth Required)
$routes->group('api/wilayah', function ($routes) {
    $routes->get('provinces', 'WilayahController::getProvinces');
    $routes->get('provinces/search', 'WilayahController::searchProvinces');
    $routes->get('cities/(:segment)', 'WilayahController::getCitiesByProvince/$1');
    $routes->get('cities/search', 'WilayahController::searchCities');
    $routes->get('districts/(:segment)', 'WilayahController::getDistrictsByCity/$1');
    $routes->get('districts/search', 'WilayahController::searchDistricts');
    $routes->get('villages/(:segment)', 'WilayahController::getVillagesByDistrict/$1');
    $routes->get('villages/search', 'WilayahController::searchVillages');
});

// Expedition API
$routes->group('api/expedition', function ($routes) {
    $routes->get('shipping-cost', 'ExpeditionController::getShippingCost');
});




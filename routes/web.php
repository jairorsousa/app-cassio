<?php

use App\Http\Controllers\BrokerReportPdfController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\WritPdfController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->to(route('dashboard', absolute: false))
        : redirect()->to(route('login', absolute: false));
});

Volt::route('dashboard', 'dashboard.index')
    ->middleware(['auth', 'verified', 'inactivity'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'inactivity'])
    ->name('profile');

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('google/calendar')->name('google.calendar.')->group(function () {
    Route::get('/connect', [GoogleCalendarController::class, 'connect'])->name('connect');
    Route::get('/callback', [GoogleCalendarController::class, 'callback'])->name('callback');
    Route::post('/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('disconnect');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('partnership')->name('partnership.')->group(function () {
    Volt::route('/', 'partnership.index')->name('index');
    Volt::route('/create', 'partnership.form')->name('create');
    Volt::route('/{partnership}/edit', 'partnership.form')->name('edit');
    Volt::route('/{partnership}', 'partnership.show')->name('show');
    Volt::route('/{partnership}/contributions', 'partnership.contributions.index')->name('contributions.index');
    Volt::route('/{partnership}/expenses', 'partnership.expenses.index')->name('expenses.index');
    Volt::route('/{partnership}/distributions', 'partnership.distributions.index')->name('distributions.index');
    Volt::route('/{partnership}/reports', 'partnership.reports')->name('reports');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('investments')->name('investments.')->group(function () {
    Volt::route('/', 'investments.dashboard')->name('dashboard');
    Volt::route('/assets', 'investments.assets.index')->name('assets.index');
    Volt::route('/operations', 'investments.operations.index')->name('operations.index');
    Volt::route('/dividends', 'investments.dividends.index')->name('dividends.index');
    Volt::route('/positions', 'investments.positions')->name('positions');
    Volt::route('/reports/profitability', 'investments.reports.profitability')->name('reports');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('writs')->name('writs.')->group(function () {
    Volt::route('/', 'writs.kanban')->name('kanban');
    Volt::route('/create', 'writs.form')->name('create');
    Route::get('/{writ}/pdf', WritPdfController::class)->name('pdf')->whereNumber('writ');
    Volt::route('/{writ}/edit', 'writs.form')->name('edit');
    Volt::route('/{writ}', 'writs.show')->name('show');
    Volt::route('/reports/profitability', 'writs.reports')->name('reports');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('banking')->name('banking.')->group(function () {
    Volt::route('/', 'banking.dashboard')->name('dashboard');
    Volt::route('/categories', 'banking.categories.index')->name('categories.index');
    Volt::route('/accounts', 'banking.accounts.index')->name('accounts.index');
    Volt::route('/cards', 'banking.cards.index')->name('cards.index');
    Volt::route('/cards/{card}/invoices', 'banking.cards.invoices')->name('cards.invoices');
    Volt::route('/transactions', 'banking.transactions.index')->name('transactions.index');
    Volt::route('/transactions/create', 'banking.transactions.form')->name('transactions.create');
    Volt::route('/transactions/{transaction}/edit', 'banking.transactions.form')->name('transactions.edit');
    Volt::route('/recurring', 'banking.recurring.index')->name('recurring.index');
    Volt::route('/reports/cashflow', 'banking.reports.cashflow')->name('reports.cashflow');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('brokers')->name('brokers.')->group(function () {
    Volt::route('/', 'brokers.index')->name('index');
    Route::get('/create', fn () => redirect()->route('contacts.create', ['type' => 'corretor']))->name('create');
    Volt::route('/tipos-caso', 'brokers.tipos-caso.index')->name('tipos-caso.index');
    Route::redirect('/case-types', '/brokers/tipos-caso');
    Volt::route('/reports/overview', 'brokers.reports')->name('reports');
    Route::get('/reports/overview/pdf', BrokerReportPdfController::class)->name('reports.pdf');
    Volt::route('/{broker}/edit', 'brokers.form')->name('edit')->whereNumber('broker');
    Volt::route('/{broker}', 'brokers.show')->name('show')->whereNumber('broker');
    Volt::route('/{broker}/advances', 'brokers.advances.index')->name('advances.index')->whereNumber('broker');
    Volt::route('/{broker}/advances/create', 'brokers.advances.form')->name('advances.create')->whereNumber('broker');
    Volt::route('/{broker}/commissions', 'brokers.commissions.index')->name('commissions.index')->whereNumber('broker');
    Volt::route('/{broker}/commissions/register', 'brokers.commissions.form')->name('commissions.create')->whereNumber('broker');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('contacts')->name('contacts.')->group(function () {
    Volt::route('/', 'contacts.index')->name('index');
    Volt::route('/create', 'contacts.form')->name('create');
    Volt::route('/{contact}/edit', 'contacts.form')->name('edit');
    Volt::route('/{contact}', 'contacts.show')->name('show');
});

Route::middleware(['auth', 'verified', 'inactivity'])->prefix('broadcasts')->name('broadcasts.')->group(function () {
    Volt::route('/', 'broadcasts.index')->name('index');
});

require __DIR__.'/auth.php';

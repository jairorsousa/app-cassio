<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Volt::route('dashboard', 'dashboard.index')
    ->middleware(['auth', 'verified', 'inactivity'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'inactivity'])
    ->name('profile');

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
    Volt::route('/create', 'brokers.form')->name('create');
    Volt::route('/{broker}/edit', 'brokers.form')->name('edit');
    Volt::route('/{broker}', 'brokers.show')->name('show');
    Volt::route('/{broker}/advances', 'brokers.advances.index')->name('advances.index');
    Volt::route('/{broker}/advances/create', 'brokers.advances.form')->name('advances.create');
    Volt::route('/{broker}/commissions', 'brokers.commissions.index')->name('commissions.index');
    Volt::route('/{broker}/commissions/register', 'brokers.commissions.form')->name('commissions.create');
    Volt::route('/case-types', 'brokers.case-types.index')->name('case-types.index');
    Volt::route('/reports/overview', 'brokers.reports')->name('reports');
});

require __DIR__.'/auth.php';

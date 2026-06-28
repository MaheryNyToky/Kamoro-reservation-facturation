<?php

use App\Http\Controllers\HotelManagementController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PMSController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/dashboard/predictions', [HotelManagementController::class, 'getAiPredictionsAndPricing']);
    Route::get('/dashboard/audit-date', [HotelManagementController::class, 'auditDate']);
    Route::get('/dashboard/ai-revenue-summary', [HotelManagementController::class, 'aiRevenueSummary']);
    Route::get('/dashboard/client-history', [HotelManagementController::class, 'searchClientHistory']);
    Route::get('/dashboard/extras-capacity', [HotelManagementController::class, 'extrasCapacity']);
    Route::get('/dashboard/reservation-status-summary', [HotelManagementController::class, 'reservationStatusSummary']);
    Route::get('/live-availability', [HotelManagementController::class, 'getLiveAvailability']);
    Route::get('/available-rooms', [HotelManagementController::class, 'getAvailableRoomsForDates']);
    Route::get('/available-room-suggestions', [HotelManagementController::class, 'getAvailableRoomSuggestions']);
    Route::get('/reservations/all', [HotelManagementController::class, 'getAllReservations']);
    Route::get('/active-reservations', [HotelManagementController::class, 'getActiveReservations']);
    Route::get('/clients/search', [ClientController::class, 'search']);
    Route::get('/organizations/search', [OrganizationController::class, 'search']);
    Route::get('/reservations/{id}/folio', [PMSController::class, 'getFolio']);
    Route::get('/invoices/{id}/pdf', [PMSController::class, 'downloadPdf']);
    Route::get('/users', [HotelManagementController::class, 'getUsers']);
});

Route::post('/bookings', [HotelManagementController::class, 'saveBooking']);
Route::post('/bookings/update-status', [HotelManagementController::class, 'updateBookingStatus']);
Route::put('/reservations/{id}', [HotelManagementController::class, 'updateReservation']);
Route::patch('/reservations/{id}', [HotelManagementController::class, 'updateReservation']);
Route::post('/reservations/{id}/checkin', [PMSController::class, 'checkIn']);
Route::post('/reservations/{id}/manual-checkout', [PMSController::class, 'manualCheckout']);
Route::post('/reservations/{id}/deposit', [PMSController::class, 'addDeposit']);
Route::post('/invoices/{id}/items', [PMSController::class, 'addInvoiceItem']);
Route::post('/invoices/{id}/payments', [PMSController::class, 'addPayment']);
Route::put('/invoices/{id}/payments/{paymentId}', [PMSController::class, 'updatePayment']);
Route::post('/users', [HotelManagementController::class, 'createUser']);
Route::post('/users/update', [HotelManagementController::class, 'updateUser']);
Route::delete('/users/{id}', [HotelManagementController::class, 'deleteUser']);
Route::post('/login', [HotelManagementController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('throttle:60,1')->group(function () {
    Route::post('/invoices/{id}/generate-pdf', [PMSController::class, 'generatePdf']);
    Route::post('/invoices/{id}/send-email', [PMSController::class, 'sendInvoiceEmail']);
});

<?php

use App\Http\Controllers\HotelManagementController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\PMSController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard/predictions', [HotelManagementController::class, 'getAiPredictionsAndPricing']);
Route::get('/dashboard/audit-date', [HotelManagementController::class, 'auditDate']);
Route::get('/dashboard/ai-revenue-summary', [HotelManagementController::class, 'aiRevenueSummary']);
Route::get('/live-availability', [HotelManagementController::class, 'getLiveAvailability']);
Route::get('/available-rooms', [HotelManagementController::class, 'getAvailableRoomsForDates']);
Route::post('/bookings', [HotelManagementController::class, 'saveBooking']);
Route::post('/bookings/update-status', [HotelManagementController::class, 'updateBookingStatus']);
Route::get('/reservations/all', [HotelManagementController::class, 'getAllReservations']);
Route::put('/reservations/{id}', [HotelManagementController::class, 'updateReservation']);
Route::patch('/reservations/{id}', [HotelManagementController::class, 'updateReservation']);
Route::get('/active-reservations', [HotelManagementController::class, 'getActiveReservations']);

// PMS Endpoints
Route::get('/clients/search', [ClientController::class, 'search']);
Route::post('/reservations/{id}/checkin', [PMSController::class, 'checkIn']);
Route::get('/reservations/{id}/folio', [PMSController::class, 'getFolio']);
Route::post('/invoices/{id}/items', [PMSController::class, 'addInvoiceItem']);
Route::post('/invoices/{id}/payments', [PMSController::class, 'addPayment']);
Route::post('/invoices/{id}/generate-pdf', [PMSController::class, 'generatePdf']);
Route::get('/invoices/{id}/pdf', [PMSController::class, 'downloadPdf']);
Route::post('/invoices/{id}/send-email', [PMSController::class, 'sendInvoiceEmail']);

Route::get('/users', [HotelManagementController::class, 'getUsers']);
Route::post('/users', [HotelManagementController::class, 'createUser']);
Route::post('/users/update', [HotelManagementController::class, 'updateUser']);
Route::delete('/users/{id}', [HotelManagementController::class, 'deleteUser']);
Route::post('/login', [HotelManagementController::class, 'login']);

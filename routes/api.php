<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes (/api/v1)
|--------------------------------------------------------------------------
*/

// Root health check (spec: GET /health)
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});

Route::prefix('v1')->group(function () {

    // 3.1 Public routes
    Route::get('/health', function () {
        return response()->json(['status' => 'ok'], 200);
    });

    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public / authenticated book cover stream
    Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

    // Authenticated routes
    Route::middleware('auth.jwt')->group(function () {

        // 3.3 Categories (all authenticated)
        Route::get('/categories', [CategoryController::class, 'index']);

        // 3.4 Books (read)
        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);

        // 3.5 Members (admin & librarian)
        Route::middleware('auth.jwt:admin,librarian')->group(function () {
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/members/{id}', [MemberController::class, 'show']);
            Route::post('/members', [MemberController::class, 'store']);

            // 3.6 Loans (admin & librarian)
            Route::post('/loans', [LoanController::class, 'store']);
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/overdue', [LoanController::class, 'overdue']);
            Route::get('/loans/{id}', [LoanController::class, 'show']);
            Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
        });

        // Admin-only routes
        Route::middleware('auth.jwt:admin')->group(function () {
            // 3.2 Users (Librarians management)
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::delete('/users/{id}', [UserController::class, 'destroy']);

            // 3.4 Books (write)
            Route::post('/books', [BookController::class, 'store']);
            Route::put('/books/{id}', [BookController::class, 'update']);
            Route::delete('/books/{id}', [BookController::class, 'destroy']);
            Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
            Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

            // 3.7 Reports
            Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
        });

    });

});

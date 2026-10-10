<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $fromStr = $request->query('from');
        $toStr = $request->query('to');

        $fromDate = Carbon::parse($fromStr)->startOfDay();
        $toDate = Carbon::parse($toStr)->endOfDay();

        // CA-10.2: Fin anterior al inicio: 422
        if ($fromDate->gt($toDate)) {
            throw new BusinessException(
                ErrorCodes::INVALID_DATE_RANGE,
                'La fecha final no puede ser anterior a la fecha inicial.',
                422,
                [['field' => 'to', 'issue' => 'Fecha final previa a la inicial']]
            );
        }

        $fromFormatted = $fromDate->format('Y-m-d H:i:s');
        $toFormatted = $toDate->format('Y-m-d H:i:s');

        // HU-10 & ADR-004:
        // Group by frozen book_id, book_title, category_name
        // Filter by loan.loaned_at within range
        // Order by times_borrowed desc, book_title asc
        // Does not join with live catalog, no members or users exposed
        $rows = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->where('loan.loaned_at', '>=', $fromFormatted)
            ->where('loan.loaned_at', '<=', $toFormatted)
            ->select(
                'loan_item.book_id',
                'loan_item.book_title',
                'loan_item.category_name',
                DB::raw('COUNT(loan_item.id) as times_borrowed')
            )
            ->groupBy('loan_item.book_id', 'loan_item.book_title', 'loan_item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('loan_item.book_title', 'asc')
            ->get();

        $formatted = $rows->map(function ($r) {
            return [
                'book_id' => $r->book_id,
                'book_title' => $r->book_title,
                'category_name' => $r->category_name,
                'times_borrowed' => (int) $r->times_borrowed,
            ];
        });

        return response()->json([
            'from' => $fromStr,
            'to' => $toStr,
            'rows' => $formatted,
        ], 200);
    }
}

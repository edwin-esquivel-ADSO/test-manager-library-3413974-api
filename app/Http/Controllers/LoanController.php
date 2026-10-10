<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;

class LoanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'member_id' => 'required|uuid',
            'items' => 'required|array|min:1|max:3', // CA-06.1: 1 to 3 items
            'items.*.book_id' => 'required|uuid',
        ]);

        $memberId = $request->input('member_id');
        $items = $request->input('items');
        $userId = $request->get('auth_user_id');

        $member = DB::table('member')->where('id', $memberId)->first();
        if (!$member) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'El socio no existe.', 404);
        }

        // CA-06.2: No repeating same book in loan (422)
        $bookIds = array_column($items, 'book_id');
        if (count($bookIds) !== count(array_unique($bookIds))) {
            throw new BusinessException(
                ErrorCodes::DUPLICATE_BOOK_IN_LOAN,
                'No se puede repetir el mismo libro en un préstamo.',
                422,
                [['field' => 'items', 'issue' => 'Libros duplicados en la solicitud']]
            );
        }

        $now = $request->filled('loaned_at')
            ? Carbon::parse($request->input('loaned_at'), 'UTC')
            : Carbon::now('UTC');

        // Check if member has overdue loans (CA-06.5 -> 409)
        $hasOverdue = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->where('loan.member_id', $memberId)
            ->whereNull('loan_item.returned_at')
            ->where('loan_item.due_at', '<', $now)
            ->exists();

        if ($hasOverdue) {
            throw new BusinessException(
                ErrorCodes::MEMBER_HAS_OVERDUE,
                'El socio tiene libros vencidos sin devolver y no puede realizar nuevos préstamos.',
                409
            );
        }

        // Check total active unreturned books (CA-06.4: Max 3 books, 422 with count)
        $currentActiveCount = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->where('loan.member_id', $memberId)
            ->whereNull('loan_item.returned_at')
            ->count();

        $requestedCount = count($bookIds);
        if ($currentActiveCount + $requestedCount > 3) {
            throw new BusinessException(
                ErrorCodes::MEMBER_LIMIT_EXCEEDED,
                "El socio ya tiene {$currentActiveCount} libros sin devolver. No puede superar el límite de 3.",
                422,
                [['field' => 'items', 'issue' => "Límite excedido: tiene {$currentActiveCount}, solicitó {$requestedCount}"]]
            );
        }

        // Execute transaction: verify available copies, deduct, freeze data, insert loan & items
        return DB::transaction(function () use ($memberId, $userId, $bookIds, $now) {
            $books = DB::table('book')
                ->join('category', 'book.category_id', '=', 'category.id')
                ->whereIn('book.id', $bookIds)
                ->whereNull('book.deleted_at')
                ->select('book.*', 'category.name as category_name')
                ->get()
                ->keyBy('id');

            if (count($books) !== count($bookIds)) {
                throw new BusinessException(ErrorCodes::NOT_FOUND, 'Uno o más libros no existen o están inactivos.', 404);
            }

            // Check availability (CA-06.3)
            foreach ($bookIds as $bId) {
                $b = $books[$bId];
                if ((int) $b->available_copies < 1) {
                    throw new BusinessException(
                        ErrorCodes::BOOK_NOT_AVAILABLE,
                        "No quedan ejemplares disponibles de «{$b->title}».",
                        409,
                        [['field' => "book_id: {$bId}", 'issue' => 'sin ejemplares']]
                    );
                }
            }

            $loanId = (string) Str::uuid();
            $dueAt = (clone $now)->addDays(14); // CA-06.6: loaned_at + 14 days

            DB::table('loan')->insert([
                'id' => $loanId,
                'member_id' => $memberId,
                'loaned_by_user_id' => $userId,
                'loaned_at' => $now,
            ]);

            $insertedItems = [];
            foreach ($bookIds as $bId) {
                $b = $books[$bId];
                $itemId = (string) Str::uuid();

                // Deduct 1 available copy
                $affected = DB::table('book')
                    ->where('id', $bId)
                    ->where('available_copies', '>=', 1)
                    ->decrement('available_copies', 1);

                if ($affected === 0) {
                    throw new BusinessException(
                        ErrorCodes::BOOK_NOT_AVAILABLE,
                        "No quedan ejemplares disponibles de «{$b->title}».",
                        409
                    );
                }

                // Freeze values in loan_item (CA-06.6, Art. VIII)
                DB::table('loan_item')->insert([
                    'id' => $itemId,
                    'loan_id' => $loanId,
                    'book_id' => $bId,
                    'book_title' => $b->title,
                    'isbn' => $b->isbn,
                    'category_name' => $b->category_name,
                    'due_at' => $dueAt,
                    'returned_at' => null,
                ]);

                $insertedItems[] = [
                    'id' => $itemId,
                    'book_id' => $bId,
                    'book_title' => $b->title,
                    'isbn' => $b->isbn,
                    'category_name' => $b->category_name,
                    'due_at' => $dueAt->toISOString(),
                    'returned_at' => null,
                ];
            }

            return response()->json([
                'id' => $loanId,
                'member_id' => $memberId,
                'loaned_at' => $now->toISOString(),
                'status' => 'open', // newly created with items is open
                'items' => $insertedItems,
            ], 201);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $status = $request->query('status'); // open, overdue, closed
        $memberId = $request->query('member_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = DB::table('loan')
            ->join('member', 'loan.member_id', '=', 'member.id')
            ->select('loan.*', 'member.full_name as member_name');

        if ($from) {
            $query->where('loan.loaned_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to) {
            $query->where('loan.loaned_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($memberId) {
            $query->where('loan.member_id', $memberId);
        }

        $loans = $query->orderBy('loan.loaned_at', 'desc')->get();
        $now = Carbon::now('UTC');

        // Fetch all items for these loans
        $loanIds = $loans->pluck('id')->toArray();
        $itemsByLoan = DB::table('loan_item')
            ->whereIn('loan_id', $loanIds)
            ->get()
            ->groupBy('loan_id');

        $result = [];
        foreach ($loans as $loan) {
            $items = $itemsByLoan->get($loan->id, collect());
            $calculatedStatus = $this->calculateLoanStatus($items, $now);

            if ($status && $calculatedStatus !== $status) {
                continue;
            }

            $result[] = [
                'id' => $loan->id,
                'member_id' => $loan->member_id,
                'member_name' => $loan->member_name,
                'loaned_at' => Carbon::parse($loan->loaned_at)->toISOString(),
                'status' => $calculatedStatus,
                'items_count' => $items->count(),
                'items' => $items->map(function ($it) {
                    return [
                        'id' => $it->id,
                        'book_id' => $it->book_id,
                        'book_title' => $it->book_title,
                        'isbn' => $it->isbn,
                        'category_name' => $it->category_name,
                        'due_at' => Carbon::parse($it->due_at)->toISOString(),
                        'returned_at' => $it->returned_at ? Carbon::parse($it->returned_at)->toISOString() : null,
                    ];
                }),
            ];
        }

        $total = count($result);
        $paginated = array_slice($result, ($page - 1) * $size, $size);

        return response()->json([
            'items' => $paginated,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $loan = DB::table('loan')->where('id', $id)->first();
        if (!$loan) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Préstamo no encontrado.', 404);
        }

        $items = DB::table('loan_item')->where('loan_id', $id)->get();
        $now = Carbon::now('UTC');
        $calculatedStatus = $this->calculateLoanStatus($items, $now);

        return response()->json([
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => Carbon::parse($loan->loaned_at)->toISOString(),
            'status' => $calculatedStatus,
            'items' => $items->map(function ($it) {
                return [
                    'id' => $it->id,
                    'book_id' => $it->book_id,
                    'book_title' => $it->book_title,
                    'isbn' => $it->isbn,
                    'category_name' => $it->category_name,
                    'due_at' => Carbon::parse($it->due_at)->toISOString(),
                    'returned_at' => $it->returned_at ? Carbon::parse($it->returned_at)->toISOString() : null,
                ];
            }),
        ], 200);
    }

    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        return DB::transaction(function () use ($loanId, $itemId) {
            $item = DB::table('loan_item')
                ->where('loan_id', $loanId)
                ->where('id', $itemId)
                ->first();

            if (!$item) {
                throw new BusinessException(ErrorCodes::NOT_FOUND, 'Línea de préstamo no encontrada.', 404);
            }

            // CA-07.2: If already returned, responds 409
            if ($item->returned_at !== null) {
                throw new BusinessException(
                    ErrorCodes::ITEM_ALREADY_RETURNED,
                    'Esta línea del préstamo ya fue devuelta previamente.',
                    409
                );
            }

            $now = Carbon::now('UTC');

            // Update returned_at
            DB::table('loan_item')->where('id', $itemId)->update([
                'returned_at' => $now,
            ]);

            // Increment book available copies
            DB::table('book')->where('id', $item->book_id)->increment('available_copies', 1);

            return response()->json([
                'id' => $item->id,
                'book_id' => $item->book_id,
                'book_title' => $item->book_title,
                'isbn' => $item->isbn,
                'category_name' => $item->category_name,
                'due_at' => Carbon::parse($item->due_at)->toISOString(),
                'returned_at' => $now->toISOString(),
            ], 200);
        });
    }

    public function overdue(): JsonResponse
    {
        $now = Carbon::now('UTC');

        $items = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->join('member', 'loan.member_id', '=', 'member.id')
            ->whereNull('loan_item.returned_at')
            ->where('loan_item.due_at', '<', $now)
            ->select(
                'loan_item.loan_id',
                'loan_item.id as item_id',
                'loan_item.book_title',
                'member.full_name as member_name',
                'loan_item.due_at'
            )
            ->get();

        $rows = $items->map(function ($it) use ($now) {
            $dueDate = Carbon::parse($it->due_at);
            $daysLate = (int) $dueDate->diffInDays($now);
            if ($daysLate < 1) {
                $daysLate = 1; // at least 1 day late if due_at < now
            }

            return [
                'loan_id' => $it->loan_id,
                'item_id' => $it->item_id,
                'book_title' => $it->book_title,
                'member_name' => $it->member_name,
                'due_at' => $dueDate->toISOString(),
                'days_late' => $daysLate,
            ];
        })->sortByDesc('days_late')->values();

        return response()->json([
            'items' => $rows,
            'total' => $rows->count(),
        ], 200);
    }

    private function calculateLoanStatus($items, Carbon $now): string
    {
        $allReturned = $items->every(fn($it) => $it->returned_at !== null);
        if ($allReturned && $items->count() > 0) {
            return 'closed';
        }

        $hasOverdue = $items->contains(function ($it) use ($now) {
            return $it->returned_at === null && Carbon::parse($it->due_at)->isPast();
        });

        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }
}

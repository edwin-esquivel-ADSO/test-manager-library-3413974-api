<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = DB::table('member');

        if ($q) {
            $query->where(function ($m) use ($q) {
                $m->where('full_name', 'like', "%{$q}%")
                  ->orWhere('document_number', 'like', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();

        return response()->json([
            'items' => $members,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $member = DB::table('member')->where('id', $id)->first();
        if (!$member) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Socio no encontrado.', 404);
        }

        // Count active unreturned books (spec §3.5)
        $activeLoans = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->where('loan.member_id', $id)
            ->whereNull('loan_item.returned_at')
            ->count();

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => (int) $activeLoans,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'full_name' => 'required|string|max:200',
            'document_number' => 'required|string|max:30',
        ]);

        $doc = trim($request->input('document_number'));
        $name = trim($request->input('full_name'));

        // CA-05.1: Document is unique, repeated responds with 409
        $exists = DB::table('member')->where('document_number', $doc)->exists();
        if ($exists) {
            throw new BusinessException(
                ErrorCodes::DOCUMENT_TAKEN,
                "El documento '{$doc}' ya está registrado.",
                409,
                [['field' => 'document_number', 'issue' => 'Ya existe']]
            );
        }

        $id = (string) Str::uuid();
        DB::table('member')->insert([
            'id' => $id,
            'full_name' => $name,
            'document_number' => $doc,
        ]);

        return response()->json([
            'id' => $id,
            'full_name' => $name,
            'document_number' => $doc,
        ], 201);
    }
}

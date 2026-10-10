<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Admin lists librarians
        $users = DB::table('user')
            ->where('role', 'librarian')
            ->select('id', 'username', 'role')
            ->orderBy('username', 'asc')
            ->get();

        return response()->json($users, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'role' => 'required|string',
        ]);

        $role = $request->input('role');
        // CA-02.3: Asking for admin role via API responds with 422
        if ($role !== 'librarian') {
            throw new BusinessException(
                ErrorCodes::VALIDATION_ERROR,
                'Solo se permite crear usuarios con el rol librarian.',
                422,
                [['field' => 'role', 'issue' => 'Rol inválido o no permitido']]
            );
        }

        // CA-02.2: username in lowercase, trimmed, unique
        $username = strtolower(trim($request->input('username')));
        $exists = DB::table('user')->where('username', $username)->exists();
        if ($exists) {
            throw new BusinessException(
                ErrorCodes::USERNAME_TAKEN,
                "El nombre de usuario '{$username}' ya está en uso.",
                409,
                [['field' => 'username', 'issue' => 'Ya existe']]
            );
        }

        $id = (string) Str::uuid();
        $passwordHash = password_hash($request->input('password'), PASSWORD_BCRYPT);

        DB::table('user')->insert([
            'id' => $id,
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => 'librarian',
        ]);

        // Never return password or password_hash (CA-02.3, RNF-3)
        return response()->json([
            'id' => $id,
            'username' => $username,
            'role' => 'librarian',
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = DB::table('user')->where('id', $id)->first();
        if (!$user) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Usuario no encontrado.', 404);
        }

        // Can only delete librarians, cannot delete admin
        if ($user->role === 'admin') {
            throw new BusinessException(
                ErrorCodes::FORBIDDEN_ROLE,
                'No se puede dar de baja a un administrador.',
                403
            );
        }

        DB::table('user')->where('id', $id)->delete();

        return response()->json(null, 204);
    }
}

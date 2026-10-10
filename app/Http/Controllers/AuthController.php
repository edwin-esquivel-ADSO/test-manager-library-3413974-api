<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Infrastructure\Auth\JwtService;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;

class AuthController extends Controller
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = strtolower(trim($request->input('username')));
        $password = $request->input('password');

        $user = DB::table('user')->where('username', $username)->first();

        // CA-01.2: does not reveal if username or password was invalid
        if (!$user || !password_verify($password, $user->password_hash)) {
            throw new BusinessException(
                ErrorCodes::INVALID_CREDENTIALS,
                'Credenciales inválidas.',
                401
            );
        }

        $token = $this->jwtService->generateToken($user->id, $user->role);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 28800,
            'role' => $user->role,
        ], 200);
    }
}

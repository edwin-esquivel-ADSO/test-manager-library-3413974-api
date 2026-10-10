<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Infrastructure\Auth\JwtService;
use App\Domain\Exceptions\ErrorCodes;
use Illuminate\Support\Facades\DB;

class AuthenticateJwt
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $header = $request->header('Authorization');
        if (!$header || !preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return response()->json([
                'code' => ErrorCodes::INVALID_CREDENTIALS,
                'message' => 'Token no proporcionado o formato inválido.',
                'details' => [],
            ], 401);
        }

        $token = $matches[1];
        $payload = $this->jwtService->decodeToken($token);

        if (!$payload) {
            return response()->json([
                'code' => ErrorCodes::INVALID_CREDENTIALS,
                'message' => 'Token inválido o expirado.',
                'details' => [],
            ], 401);
        }

        $user = DB::table('user')->where('id', $payload->sub)->first();
        if (!$user) {
            return response()->json([
                'code' => ErrorCodes::INVALID_CREDENTIALS,
                'message' => 'Usuario no encontrado.',
                'details' => [],
            ], 401);
        }

        if (!empty($roles) && !in_array($user->role, $roles, true)) {
            return response()->json([
                'code' => ErrorCodes::FORBIDDEN_ROLE,
                'message' => 'No tiene permisos suficientes para realizar esta acción.',
                'details' => [],
            ], 403);
        }

        $request->merge([
            'auth_user_id' => $user->id,
            'auth_user_role' => $user->role,
            'auth_user_name' => $user->username,
        ]);

        return $next($request);
    }
}

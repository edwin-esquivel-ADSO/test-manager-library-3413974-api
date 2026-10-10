<?php

namespace App\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = config('app.jwt_secret', env('JWT_SECRET', 'library-secret-key-at-least-32-chars-long!!'));
        $this->ttl = 28800; // 8 hours (spec CA-01.1)
    }

    public function generateToken(string $userId, string $role): string
    {
        $now = time();
        $payload = [
            'iss' => 'manager-library-api',
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function decodeToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Exception $e) {
            return null;
        }
    }
}

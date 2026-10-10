<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureAdminUser extends Command
{
    protected $signature = 'library:ensure-admin';
    protected $description = 'Provision initial admin user from environment variables';

    public function handle(): int
    {
        $username = strtolower(trim(env('ADMIN_USERNAME', 'admin')));
        $password = env('ADMIN_PASSWORD', 'Admin123*');

        $exists = DB::table('user')->where('username', $username)->first();

        if (!$exists) {
            DB::table('user')->insert([
                'id' => (string) Str::uuid(),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role' => 'admin',
            ]);
            $this->info("Admin user '{$username}' created.");
        } else {
            $this->info("Admin user '{$username}' already exists.");
        }

        return 0;
    }
}

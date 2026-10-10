<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LibraryContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure admin user
        $this->artisan('library:ensure-admin');
    }

    public function test_health_check()
    {
        $rootResponse = $this->getJson('/health');
        $rootResponse->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $response = $this->getJson('/api/v1/health');
        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_auth_login_success_and_failure()
    {
        // Success
        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'Admin123*',
        ]);
        $res->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'role']);

        // Invalid credentials (CA-01.2)
        $resFail = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'wrong',
        ]);
        $resFail->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.',
            ]);
    }

    public function test_book_crud_and_rules()
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'Admin123*',
        ]);
        $token = $login->json('access_token');
        $headers = ['Authorization' => "Bearer {$token}"];

        // Get categories
        $catRes = $this->getJson('/api/v1/categories', $headers);
        $catRes->assertStatus(200);
        $novelaId = '11111111-1111-4111-8111-111111111111';

        // Create book
        $res = $this->postJson('/api/v1/books', [
            'title' => 'Cien años de soledad',
            'author' => 'Gabriel García Márquez',
            'isbn' => '9780307474728',
            'category_id' => $novelaId,
            'total_copies' => 3,
        ], $headers);

        $res->assertStatus(201)
            ->assertJson([
                'title' => 'Cien años de soledad',
                'total_copies' => 3,
                'available_copies' => 3,
            ]);

        $bookId = $res->json('id');

        // Create duplicate ISBN -> 409
        $dup = $this->postJson('/api/v1/books', [
            'title' => 'Otro libro',
            'author' => 'Otro autor',
            'isbn' => '9780307474728',
            'category_id' => $novelaId,
            'total_copies' => 1,
        ], $headers);
        $dup->assertStatus(409)
            ->assertJson(['code' => 'ISBN_TAKEN']);
    }

    public function test_loan_and_return_cycle()
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'Admin123*',
        ]);
        $token = $login->json('access_token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $novelaId = '11111111-1111-4111-8111-111111111111';

        // Create book with 1 copy
        $bRes = $this->postJson('/api/v1/books', [
            'title' => 'Rayuela',
            'author' => 'Julio Cortázar',
            'isbn' => '9788437604572',
            'category_id' => $novelaId,
            'total_copies' => 1,
        ], $headers);
        $bookId = $bRes->json('id');

        // Create member
        $mRes = $this->postJson('/api/v1/members', [
            'full_name' => 'Carlos Gardel',
            'document_number' => 'CC12345678',
        ], $headers);
        $mRes->assertStatus(201);
        $memberId = $mRes->json('id');

        // Loan book
        $lRes = $this->postJson('/api/v1/loans', [
            'member_id' => $memberId,
            'items' => [
                ['book_id' => $bookId],
            ],
        ], $headers);
        $lRes->assertStatus(201)
            ->assertJson(['status' => 'open']);
        $loanId = $lRes->json('id');
        $itemId = $lRes->json('items.0.id');

        // Book now has 0 available -> loan again fails with 409
        $lResFail = $this->postJson('/api/v1/loans', [
            'member_id' => $memberId,
            'items' => [
                ['book_id' => $bookId],
            ],
        ], $headers);
        $lResFail->assertStatus(409)
            ->assertJson(['code' => 'BOOK_NOT_AVAILABLE']);

        // Return item
        $retRes = $this->postJson("/api/v1/loans/{$loanId}/items/{$itemId}/return", [], $headers);
        $retRes->assertStatus(200);

        // Loan status is now closed
        $checkLoan = $this->getJson("/api/v1/loans/{$loanId}", $headers);
        $checkLoan->assertStatus(200)
            ->assertJson(['status' => 'closed']);

        // Returning again fails with 409 ITEM_ALREADY_RETURNED
        $retAgain = $this->postJson("/api/v1/loans/{$loanId}/items/{$itemId}/return", [], $headers);
        $retAgain->assertStatus(409)
            ->assertJson(['code' => 'ITEM_ALREADY_RETURNED']);
    }
}

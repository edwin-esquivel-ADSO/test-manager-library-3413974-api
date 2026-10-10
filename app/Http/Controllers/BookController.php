<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $categoryId = $request->query('category_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = DB::table('book')
            ->join('category', 'book.category_id', '=', 'category.id')
            ->whereNull('book.deleted_at')
            ->select(
                'book.id',
                'book.title',
                'book.author',
                'book.isbn',
                'book.category_id',
                'category.name as category_name',
                'book.total_copies',
                'book.available_copies',
                'book.cover_key'
            );

        if ($q) {
            $query->where(function ($b) use ($q) {
                $b->where('book.title', 'like', "%{$q}%")
                  ->orWhere('book.author', 'like', "%{$q}%");
            });
        }

        if ($categoryId) {
            $query->where('book.category_id', $categoryId);
        }

        $total = $query->count();
        $books = $query->orderBy('book.title', 'asc')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();

        $items = $books->map(function ($b) {
            return [
                'id' => $b->id,
                'title' => $b->title,
                'author' => $b->author,
                'isbn' => $b->isbn,
                'category' => [
                    'id' => $b->category_id,
                    'name' => $b->category_name,
                ],
                'total_copies' => (int) $b->total_copies,
                'available_copies' => (int) $b->available_copies,
                'cover_key' => $b->cover_key,
            ];
        });

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $book = DB::table('book')
            ->join('category', 'book.category_id', '=', 'category.id')
            ->where('book.id', $id)
            ->whereNull('book.deleted_at')
            ->select(
                'book.id',
                'book.title',
                'book.author',
                'book.isbn',
                'book.category_id',
                'category.name as category_name',
                'book.total_copies',
                'book.available_copies',
                'book.cover_key'
            )
            ->first();

        if (!$book) {
            throw new BusinessException(
                ErrorCodes::NOT_FOUND,
                'El libro solicitado no existe o ha sido dado de baja.',
                404
            );
        }

        return response()->json([
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $book->category_id,
                'name' => $book->category_name,
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:250',
            'author' => 'required|string|max:200',
            'isbn' => 'required|digits:13',
            'category_id' => 'required|uuid',
            'total_copies' => 'required|integer|min:1',
        ]);

        $isbn = $request->input('isbn');
        $categoryId = $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies');

        $category = DB::table('category')->where('id', $categoryId)->first();
        if (!$category) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'La categoría seleccionada no existe.', 404);
        }

        // ISBN unique among active books
        $isbnTaken = DB::table('book')
            ->where('isbn', $isbn)
            ->whereNull('deleted_at')
            ->exists();

        if ($isbnTaken) {
            throw new BusinessException(
                ErrorCodes::ISBN_TAKEN,
                "El ISBN '{$isbn}' ya está registrado para un libro activo.",
                409,
                [['field' => 'isbn', 'issue' => 'Ya existe']]
            );
        }

        $id = (string) Str::uuid();
        $title = trim($request->input('title'));
        $author = trim($request->input('author'));

        DB::table('book')->insert([
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category_id' => $categoryId,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies, // CA-03.1
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        return response()->json([
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies,
            'cover_key' => null,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:250',
            'author' => 'required|string|max:200',
            'category_id' => 'required|uuid',
            'total_copies' => 'required|integer|min:1',
        ]);

        $book = DB::table('book')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$book) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Libro no encontrado.', 404);
        }

        $category = DB::table('category')->where('id', $request->input('category_id'))->first();
        if (!$category) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Categoría no encontrada.', 404);
        }

        $newTotal = (int) $request->input('total_copies');
        $diff = $newTotal - (int) $book->total_copies;
        $newAvailable = (int) $book->available_copies + $diff;

        // CA-03.3: Total copies adjustment adjusts available and never leaves available < 0
        if ($newAvailable < 0) {
            throw new BusinessException(
                ErrorCodes::VALIDATION_ERROR,
                'El nuevo total de ejemplares dejaría los disponibles por debajo de 0.',
                422,
                [['field' => 'total_copies', 'issue' => 'Excede ejemplares prestados']]
            );
        }

        $title = trim($request->input('title'));
        $author = trim($request->input('author'));

        DB::table('book')->where('id', $id)->update([
            'title' => $title,
            'author' => $author,
            'category_id' => $category->id,
            'total_copies' => $newTotal,
            'available_copies' => $newAvailable,
        ]);

        return response()->json([
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'total_copies' => $newTotal,
            'available_copies' => $newAvailable,
            'cover_key' => $book->cover_key,
        ], 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $book = DB::table('book')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$book) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Libro no encontrado.', 404);
        }

        // CA-03.5: Cannot delete book with open loans
        $hasOpenLoans = DB::table('loan_item')
            ->where('book_id', $id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            throw new BusinessException(
                ErrorCodes::BOOK_HAS_OPEN_LOANS,
                'No se puede dar de baja un libro que tiene préstamos abiertos.',
                409
            );
        }

        DB::table('book')->where('id', $id)->update([
            'deleted_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = DB::table('book')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$book) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Libro no encontrado.', 404);
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,png|max:2048', // 2 MB max, mime validated
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $coverKey = (string) Str::uuid() . '.' . $ext;

        $destDir = storage_path('app/covers');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $file->move($destDir, $coverKey);

        DB::table('book')->where('id', $id)->update([
            'cover_key' => $coverKey,
        ]);

        return response()->json(['cover_key' => $coverKey], 200);
    }

    public function getCover(string $id)
    {
        $book = DB::table('book')->where('id', $id)->first();
        if (!$book || !$book->cover_key) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Portada no encontrada.', 404);
        }

        $path = storage_path('app/covers/' . $book->cover_key);
        if (!file_exists($path)) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Archivo de portada no encontrado.', 404);
        }

        return response()->file($path);
    }

    public function deleteCover(string $id): JsonResponse
    {
        $book = DB::table('book')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$book) {
            throw new BusinessException(ErrorCodes::NOT_FOUND, 'Libro no encontrado.', 404);
        }

        $oldKey = $book->cover_key;
        DB::table('book')->where('id', $id)->update(['cover_key' => null]);

        if ($oldKey) {
            $path = storage_path('app/covers/' . $oldKey);
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        return response()->json(null, 204);
    }
}

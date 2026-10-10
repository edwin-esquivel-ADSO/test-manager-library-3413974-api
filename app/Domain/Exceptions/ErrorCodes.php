<?php

namespace App\Domain\Exceptions;

class ErrorCodes
{
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS'; // 401
    public const FORBIDDEN_ROLE = 'FORBIDDEN_ROLE'; // 403
    public const NOT_FOUND = 'NOT_FOUND'; // 404
    public const USERNAME_TAKEN = 'USERNAME_TAKEN'; // 409
    public const ISBN_TAKEN = 'ISBN_TAKEN'; // 409
    public const DOCUMENT_TAKEN = 'DOCUMENT_TAKEN'; // 409
    public const BOOK_HAS_OPEN_LOANS = 'BOOK_HAS_OPEN_LOANS'; // 409
    public const BOOK_NOT_AVAILABLE = 'BOOK_NOT_AVAILABLE'; // 409
    public const MEMBER_HAS_OVERDUE = 'MEMBER_HAS_OVERDUE'; // 409
    public const ITEM_ALREADY_RETURNED = 'ITEM_ALREADY_RETURNED'; // 409
    public const MEMBER_LIMIT_EXCEEDED = 'MEMBER_LIMIT_EXCEEDED'; // 422
    public const DUPLICATE_BOOK_IN_LOAN = 'DUPLICATE_BOOK_IN_LOAN'; // 422
    public const VALIDATION_ERROR = 'VALIDATION_ERROR'; // 422
    public const INVALID_DATE_RANGE = 'INVALID_DATE_RANGE'; // 422
}

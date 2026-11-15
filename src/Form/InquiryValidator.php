<?php

namespace App\Form;

use Symfony\Component\HttpFoundation\Request;

/**
 * Field-level validation shared by all inquiry forms.
 */
class InquiryValidator
{
    private const MAX_LENGTHS = [
        'name' => 200,
        'company' => 200,
        'email' => 320,
        'phone' => 50,
        'role' => 100,
        'portfolio' => 500,
        'message' => 5000,
    ];

    /** Fields that end up in mail headers and the calendar invite: no line breaks or other control characters. */
    private const SINGLE_LINE = ['name', 'company', 'email', 'phone', 'role', 'portfolio'];

    /** @return array<string, string> field => reason */
    public function validate(Request $request, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (trim($request->request->getString($field)) === '') {
                $errors[$field] = 'required';
            }
        }
        $email = trim($request->request->getString('email'));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid';
        }
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($request->request->getString($field)) > $max) {
                $errors[$field] = 'too_long';
            }
        }
        foreach (self::SINGLE_LINE as $field) {
            if (preg_match('/\p{Cc}/u', $request->request->getString($field)) === 1) {
                $errors[$field] = 'invalid';
            }
        }

        return $errors;
    }
}

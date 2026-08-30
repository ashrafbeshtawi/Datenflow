<?php

namespace App\Tests\Unit;

use App\Form\InquiryValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class InquiryValidatorTest extends TestCase
{
    private InquiryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InquiryValidator();
    }

    public function testValidInputPasses(): void
    {
        $request = self::post(['name' => 'Maria Muster', 'email' => 'maria@example.com', 'message' => 'Hallo']);

        self::assertSame([], $this->validator->validate($request, ['name', 'email', 'message']));
    }

    public function testMissingAndWhitespaceOnlyRequiredFieldsAreReported(): void
    {
        $request = self::post(['name' => '   ', 'email' => 'maria@example.com']);

        $errors = $this->validator->validate($request, ['name', 'email', 'message']);

        self::assertSame(['name' => 'required', 'message' => 'required'], $errors);
    }

    public function testInvalidEmailIsReported(): void
    {
        $request = self::post(['name' => 'Maria', 'email' => 'keine-email', 'message' => 'Hallo']);

        self::assertSame(['email' => 'invalid'], $this->validator->validate($request, ['name', 'email', 'message']));
    }

    public function testEmailIsOnlyCheckedWhenPresent(): void
    {
        $request = self::post(['name' => 'Maria']);

        self::assertSame([], $this->validator->validate($request, ['name']));
    }

    public function testOverlongFieldsAreReported(): void
    {
        $request = self::post([
            'name' => str_repeat('a', 201),
            'email' => 'maria@example.com',
            'message' => str_repeat('b', 5001),
        ]);

        $errors = $this->validator->validate($request, ['name', 'email', 'message']);

        self::assertSame(['name' => 'too_long', 'message' => 'too_long'], $errors);
    }

    private static function post(array $fields): Request
    {
        return new Request(request: $fields);
    }
}

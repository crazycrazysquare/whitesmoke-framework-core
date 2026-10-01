<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private function errors(array $input, array $rules): array
    {
        return validate($input, $rules)->errors();
    }

    public function testRequired(): void
    {
        $this->assertSame(['name' => 'Name is required.'], $this->errors(['name' => '   '], ['name' => 'required']));
        $this->assertSame(['name' => 'Name is required.'], $this->errors([], ['name' => 'required']));
        $this->assertSame([], $this->errors(['name' => 'x'], ['name' => 'required']));
    }

    public function testLabelsFromFieldNames(): void
    {
        $this->assertSame(['due_date' => 'Due date is required.'], $this->errors([], ['due_date' => 'required']));
    }

    public function testTrimsAndReturnsOnlyListedFields(): void
    {
        $v = validate(['name' => '  Aisha ', 'is_admin' => '1'], ['name' => 'required']);

        $this->assertFalse($v->fails());
        $this->assertSame(['name' => 'Aisha'], $v->data());
    }

    public function testBlankOptionalBecomesNull(): void
    {
        $v = validate(['phone' => '', 'notes' => '  '], ['phone' => 'nullable|min:7', 'notes' => 'max:5', 'missing' => 'max:5']);

        $this->assertSame(['phone' => null, 'notes' => null, 'missing' => null], $v->data());
    }

    public function testArraysRejected(): void
    {
        $this->assertSame(['name' => 'Name is invalid.'], $this->errors(['name' => ['a']], ['name' => 'required']));
    }

    public function testEmail(): void
    {
        $this->assertArrayHasKey('email', $this->errors(['email' => 'nope'], ['email' => 'email']));
        $this->assertSame([], $this->errors(['email' => 'a@b.co'], ['email' => 'email']));
    }

    public function testLengthRulesCountCharactersNotBytes(): void
    {
        $this->assertSame([], $this->errors(['n' => 'عائشة'], ['n' => 'max:5']));
        $this->assertArrayHasKey('n', $this->errors(['n' => 'abc'], ['n' => 'min:4']));
        $this->assertArrayHasKey('n', $this->errors(['n' => 'abcdef'], ['n' => 'max:5']));
    }

    public function testIntCastsAndComparesNumbers(): void
    {
        $v = validate(['age' => '42'], ['age' => 'int|min:1|max:120']);
        $this->assertSame(['age' => 42], $v->data());

        $this->assertSame(['age' => 'Age must be at most 120.'], $this->errors(['age' => '200'], ['age' => 'int|max:120']));
        $this->assertSame(['age' => 'Age must be a whole number.'], $this->errors(['age' => '4x'], ['age' => 'int']));
        $this->assertSame(['age' => 'Age must be a whole number.'], $this->errors(['age' => '4.5'], ['age' => 'int']));
    }

    public function testInAndSame(): void
    {
        $this->assertArrayHasKey('role', $this->errors(['role' => 'root'], ['role' => 'in:admin,staff']));
        $this->assertSame([], $this->errors(['role' => 'staff'], ['role' => 'in:admin,staff']));
        $this->assertArrayHasKey('pw2', $this->errors(['pw' => 'a', 'pw2' => 'b'], ['pw2' => 'same:pw']));
        $this->assertSame([], $this->errors(['pw' => 'a', 'pw2' => ' a '], ['pw2' => 'same:pw']));
    }

    public function testRegexWithPipeUsingArrayRules(): void
    {
        $rules = ['code' => ['required', 'regex:~^(A|B)\d{2}$~']];

        $this->assertSame([], $this->errors(['code' => 'B12'], $rules));
        $this->assertArrayHasKey('code', $this->errors(['code' => 'C12'], $rules));
    }

    public function testStopsAtFirstFailingRule(): void
    {
        $this->assertSame(['e' => 'E must be a valid email address.'], $this->errors(['e' => 'x'], ['e' => 'email|min:5']));
    }

    public function testUnknownRuleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validate(['a' => 'x'], ['a' => 'required|bogus']);
    }
}

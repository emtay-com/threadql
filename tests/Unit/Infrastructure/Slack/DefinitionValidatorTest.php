<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Slack;

use App\Infrastructure\Slack\DefinitionValidator;
use PHPUnit\Framework\TestCase;

class DefinitionValidatorTest extends TestCase
{
    private DefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DefinitionValidator();
    }

    public function test_validate_returns_array_with_required_keys(): void
    {
        $state = $this->buildValidState('subject', 'definition');

        $result = $this->validator->validate($state);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('definition', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_validate_with_valid_data_has_no_errors(): void
    {
        $state = $this->buildValidState('ARR', 'Annual Recurring Revenue');

        $result = $this->validator->validate($state);

        $this->assertEmpty($result['errors']);
        $this->assertEquals('ARR', $result['subject']);
        $this->assertEquals('Annual Recurring Revenue', $result['definition']);
    }

    public function test_validate_trims_whitespace_from_subject(): void
    {
        $state = $this->buildValidState('  subject with spaces  ', 'definition');

        $result = $this->validator->validate($state);

        $this->assertEquals('subject with spaces', $result['subject']);
    }

    public function test_validate_trims_whitespace_from_definition(): void
    {
        $state = $this->buildValidState('subject', '  definition with spaces  ');

        $result = $this->validator->validate($state);

        $this->assertEquals('definition with spaces', $result['definition']);
    }

    public function test_validate_empty_subject_creates_error(): void
    {
        $state = $this->buildValidState('', 'valid definition');

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('subject_block', $result['errors']);
        $this->assertEquals('Please provide a subject', $result['errors']['subject_block']);
    }

    public function test_validate_whitespace_only_subject_creates_error(): void
    {
        $state = $this->buildValidState('   ', 'valid definition');

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('subject_block', $result['errors']);
    }

    public function test_validate_empty_definition_creates_error(): void
    {
        $state = $this->buildValidState('valid subject', '');

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('definition_block', $result['errors']);
        $this->assertEquals('Please provide a definition', $result['errors']['definition_block']);
    }

    public function test_validate_whitespace_only_definition_creates_error(): void
    {
        $state = $this->buildValidState('valid subject', '   ');

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('definition_block', $result['errors']);
    }

    public function test_validate_both_empty_creates_both_errors(): void
    {
        $state = $this->buildValidState('', '');

        $result = $this->validator->validate($state);

        $this->assertCount(2, $result['errors']);
        $this->assertArrayHasKey('subject_block', $result['errors']);
        $this->assertArrayHasKey('definition_block', $result['errors']);
    }

    public function test_validate_missing_subject_key_treated_as_empty(): void
    {
        $state = [
            'definition_block' => [
                'definition' => [
                    'value' => 'valid definition',
                ],
            ],
        ];

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('subject_block', $result['errors']);
        $this->assertEquals('', $result['subject']);
    }

    public function test_validate_missing_definition_key_treated_as_empty(): void
    {
        $state = [
            'subject_block' => [
                'subject' => [
                    'value' => 'valid subject',
                ],
            ],
        ];

        $result = $this->validator->validate($state);

        $this->assertArrayHasKey('definition_block', $result['errors']);
        $this->assertEquals('', $result['definition']);
    }

    public function test_validate_malformed_state_structure(): void
    {
        $state = [
            'subject_block' => [
                'wrong_key' => 'value',
            ],
            'definition_block' => [
                'wrong_key' => 'value',
            ],
        ];

        $result = $this->validator->validate($state);

        $this->assertCount(2, $result['errors']);
        $this->assertEquals('', $result['subject']);
        $this->assertEquals('', $result['definition']);
    }

    public function test_has_errors_returns_false_when_no_errors(): void
    {
        $validationResult = [
            'subject' => 'test',
            'definition' => 'test',
            'errors' => [],
        ];

        $this->assertFalse($this->validator->hasErrors($validationResult));
    }

    public function test_has_errors_returns_true_when_errors_exist(): void
    {
        $validationResult = [
            'subject' => '',
            'definition' => '',
            'errors' => [
                'subject_block' => 'Please provide a subject',
            ],
        ];

        $this->assertTrue($this->validator->hasErrors($validationResult));
    }

    public function test_has_errors_returns_true_when_multiple_errors(): void
    {
        $validationResult = [
            'subject' => '',
            'definition' => '',
            'errors' => [
                'subject_block' => 'Please provide a subject',
                'definition_block' => 'Please provide a definition',
            ],
        ];

        $this->assertTrue($this->validator->hasErrors($validationResult));
    }

    public function test_validate_preserves_special_characters_in_subject(): void
    {
        $subject = 'ARR (Annual Recurring Revenue) - 2024!';
        $state = $this->buildValidState($subject, 'definition');

        $result = $this->validator->validate($state);

        $this->assertEquals($subject, $result['subject']);
    }

    public function test_validate_preserves_special_characters_in_definition(): void
    {
        $definition = 'Definition with special chars: @#$% & (parentheses) - dash';
        $state = $this->buildValidState('subject', $definition);

        $result = $this->validator->validate($state);

        $this->assertEquals($definition, $result['definition']);
    }

    public function test_validate_preserves_multiline_definition(): void
    {
        $definition = "Line 1\nLine 2\nLine 3";
        $state = $this->buildValidState('subject', $definition);

        $result = $this->validator->validate($state);

        $this->assertEquals($definition, $result['definition']);
    }

    public function test_validate_very_long_subject(): void
    {
        $subject = str_repeat('a', 1000);
        $state = $this->buildValidState($subject, 'definition');

        $result = $this->validator->validate($state);

        $this->assertEquals($subject, $result['subject']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_very_long_definition(): void
    {
        $definition = str_repeat('a', 5000);
        $state = $this->buildValidState('subject', $definition);

        $result = $this->validator->validate($state);

        $this->assertEquals($definition, $result['definition']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Helper method to build valid Slack state structure
     */
    private function buildValidState(string $subject, string $definition): array
    {
        return [
            'subject_block' => [
                'subject' => [
                    'value' => $subject,
                ],
            ],
            'definition_block' => [
                'definition' => [
                    'value' => $definition,
                ],
            ],
        ];
    }
}

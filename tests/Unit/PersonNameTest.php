<?php

namespace Tests\Unit;

use App\Support\PersonName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    #[DataProvider('splitProvider')]
    public function test_split(string $input, ?string $first, ?string $last, bool $ambiguous): void
    {
        $result = PersonName::split($input);

        $this->assertSame($first, $result['first_name']);
        $this->assertSame($last, $result['last_name']);
        $this->assertSame($ambiguous, $result['ambiguous']);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: bool}>
     */
    public static function splitProvider(): array
    {
        return [
            'two parts' => ['Sam Client', 'Sam', 'Client', false],
            'three parts' => ['Mary Ann Smith', 'Mary', 'Ann Smith', false],
            'single token' => ['Madonna', null, null, true],
            'blank' => ['   ', null, null, true],
            'extra spaces' => ['  Sam   Client  ', 'Sam', 'Client', false],
        ];
    }

    public function test_display_composes_names(): void
    {
        $this->assertSame('Sam Client', PersonName::display('Sam', 'Client'));
        $this->assertSame('Fallback', PersonName::display(null, null, 'Fallback'));
        $this->assertSame('', PersonName::display(null, null));
    }
}

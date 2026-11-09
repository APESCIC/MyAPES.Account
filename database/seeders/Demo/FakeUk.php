<?php

namespace Database\Seeders\Demo;

use Faker\Generator;

/**
 * Clearly fake UK contact details for local demo data.
 *
 * Phone numbers come only from Ofcom's ranges reserved for drama
 * (07700 900000 to 900999 and 020 7946 0000 to 0999). Towns are made up and
 * postcodes use the non-geographic ZZ99 prefix, so nothing points at a real address.
 */
final class FakeUk
{
    /** @var list<string> */
    public const TOWNS = [
        'Brackenford Parva',
        'Little Marsham',
        'Westholme Fen',
        'Oakhurst Magna',
        'Fernleigh Cross',
        'Ashcombe Sleet',
        'Hollowmere',
        'Quillstead',
    ];

    /** @var list<string> */
    public const COUNTIES = ['Marshamshire', 'West Fenland Vale', 'Oakhurst County'];

    public function __construct(private readonly Generator $faker) {}

    public function mobile(int $index): string
    {
        return '+447700900'.sprintf('%03d', 200 + ($index % 800));
    }

    public function landline(int $index): string
    {
        return '+442079460'.sprintf('%03d', 200 + ($index % 800));
    }

    public function town(): string
    {
        return $this->faker->randomElement(self::TOWNS);
    }

    public function county(): string
    {
        return $this->faker->randomElement(self::COUNTIES);
    }

    public function postcode(): string
    {
        return sprintf(
            'ZZ99 %d%s',
            $this->faker->numberBetween(1, 9),
            strtoupper($this->faker->lexify('??')),
        );
    }

    public function streetAddress(): string
    {
        return $this->faker->buildingNumber().' '.$this->faker->streetName();
    }
}

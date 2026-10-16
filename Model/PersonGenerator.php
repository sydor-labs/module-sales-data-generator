<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

/**
 * Builds fake but plausible people with addresses in several countries.
 *
 * @phpstan-type Address array{
 *     country_id: string, region_code: string, city: string, postcode: string, street: string, telephone: string
 * }
 * @phpstan-type Person array{
 *     firstname: string, lastname: string, gender: int, dob: string, email: string, address: Address
 * }
 */
class PersonGenerator
{
    public const int GENDER_MALE = 1;
    public const int GENDER_FEMALE = 2;

    private const array COUNTRY_WEIGHTS = ['UA' => 55, 'PL' => 20, 'DE' => 15, 'US' => 10];

    private const array NAMES = [
        'UA' => [
            'male' => ['Oleksandr', 'Andrii', 'Dmytro', 'Serhii', 'Mykola', 'Taras', 'Bohdan', 'Ivan', 'Yurii',
                'Volodymyr', 'Maksym', 'Ostap', 'Roman', 'Vasyl', 'Petro'],
            'female' => ['Olena', 'Iryna', 'Natalia', 'Oksana', 'Kateryna', 'Sofiia', 'Yuliia', 'Mariia', 'Anna',
                'Tetiana', 'Khrystyna', 'Solomiia', 'Viktoriia', 'Halyna', 'Daryna'],
            'last' => ['Shevchenko', 'Kovalenko', 'Bondarenko', 'Tkachenko', 'Kravchenko', 'Melnyk', 'Boyko',
                'Kovalchuk', 'Oliinyk', 'Lysenko', 'Moroz', 'Savchenko', 'Rudenko', 'Marchenko', 'Hnatiuk'],
        ],
        'PL' => [
            'male' => ['Piotr', 'Krzysztof', 'Tomasz', 'Pawel', 'Michal', 'Marcin', 'Jakub', 'Lukasz'],
            'female' => ['Anna', 'Katarzyna', 'Malgorzata', 'Agnieszka', 'Magdalena', 'Joanna', 'Zofia', 'Ewa'],
            'last' => ['Nowak', 'Kowalski', 'Wisniewski', 'Wojcik', 'Kaminski', 'Lewandowski', 'Zielinski',
                'Szymanski'],
        ],
        'DE' => [
            'male' => ['Lukas', 'Jonas', 'Felix', 'Maximilian', 'Paul', 'Leon', 'Thomas', 'Stefan'],
            'female' => ['Laura', 'Julia', 'Lena', 'Sarah', 'Hannah', 'Lea', 'Sabine', 'Claudia'],
            'last' => ['Muller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker'],
        ],
        'US' => [
            'male' => ['James', 'John', 'Robert', 'Michael', 'David', 'William', 'Daniel', 'Matthew'],
            'female' => ['Mary', 'Jennifer', 'Linda', 'Emily', 'Jessica', 'Sarah', 'Ashley', 'Olivia'],
            'last' => ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Wilson'],
        ],
    ];

    /**
     * City, region code, postcode pattern ("#" is a random digit).
     */
    private const array CITIES = [
        'UA' => [
            ['Kyiv', 'UA-30', '0####'],
            ['Kyiv', 'UA-30', '0####'],
            ['Kyiv', 'UA-30', '0####'],
            ['Lviv', 'UA-46', '79###'],
            ['Lviv', 'UA-46', '79###'],
            ['Odesa', 'UA-51', '65###'],
            ['Kharkiv', 'UA-63', '61###'],
            ['Dnipro', 'UA-12', '49###'],
            ['Vinnytsia', 'UA-05', '21###'],
            ['Ivano-Frankivsk', 'UA-26', '76###'],
            ['Ternopil', 'UA-61', '46###'],
            ['Poltava', 'UA-53', '36###'],
            ['Chernivtsi', 'UA-77', '58###'],
            ['Uzhhorod', 'UA-21', '88###'],
            ['Brovary', 'UA-32', '07###'],
        ],
        'PL' => [
            ['Warszawa', 'PL-14', '0#-###'],
            ['Krakow', 'PL-12', '3#-###'],
            ['Wroclaw', 'PL-02', '5#-###'],
            ['Gdansk', 'PL-22', '80-###'],
            ['Poznan', 'PL-30', '6#-###'],
            ['Lublin', 'PL-06', '20-###'],
        ],
        'DE' => [
            ['Berlin', 'BER', '1####'],
            ['Munchen', 'BAY', '8####'],
            ['Hamburg', 'HAM', '2####'],
            ['Koln', 'NRW', '5####'],
            ['Frankfurt am Main', 'HES', '6####'],
            ['Stuttgart', 'BAW', '7####'],
        ],
        'US' => [
            ['New York', 'NY', '100##'],
            ['Los Angeles', 'CA', '900##'],
            ['Chicago', 'IL', '606##'],
            ['Houston', 'TX', '770##'],
            ['Seattle', 'WA', '981##'],
            ['Miami', 'FL', '331##'],
            ['Boston', 'MA', '021##'],
            ['Denver', 'CO', '802##'],
        ],
    ];

    private const array STREETS = [
        'UA' => ['vul. Khreshchatyk', 'vul. Shevchenka', 'vul. Franka', 'prosp. Peremohy', 'vul. Sadova',
            'vul. Hrushevskoho', 'vul. Lesi Ukrainky', 'vul. Zelena', 'vul. Naukova', 'vul. Soborna'],
        'PL' => ['ul. Marszalkowska', 'ul. Dluga', 'ul. Polna', 'ul. Lesna', 'ul. Slowackiego'],
        'DE' => ['Hauptstrasse', 'Bahnhofstrasse', 'Gartenstrasse', 'Schulstrasse', 'Lindenweg'],
        'US' => ['Main St', 'Oak Ave', 'Maple St', 'Washington Blvd', 'Park Ave', 'Elm St'],
    ];

    private const array PHONE_PREFIXES = ['UA' => '+380', 'PL' => '+48', 'DE' => '+49', 'US' => '+1'];

    public function __construct(
        private readonly Random $random
    ) {
    }

    /**
     * Create a person; the email local part ends with the caller-supplied unique tag.
     *
     * @return Person
     */
    public function create(string $emailDomain, string $tag): array
    {
        $countryId = (string)$this->random->weighted(self::COUNTRY_WEIGHTS);
        $names = self::NAMES[$countryId];
        $gender = $this->random->chance(0.55) ? self::GENDER_FEMALE : self::GENDER_MALE;
        $firstname = $this->random->pick($names[$gender === self::GENDER_FEMALE ? 'female' : 'male']);
        $lastname = $this->random->pick($names['last']);
        $age = $this->random->int(18, 70);

        return [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'gender' => $gender,
            'dob' => sprintf(
                '%d-%02d-%02d',
                (int)date('Y') - $age,
                $this->random->int(1, 12),
                $this->random->int(1, 28)
            ),
            'email' => strtolower(sprintf('%s.%s.%s@%s', $firstname, $lastname, $tag, $emailDomain)),
            'address' => $this->createAddress($countryId),
        ];
    }

    /**
     * @return Address
     */
    private function createAddress(string $countryId): array
    {
        [$city, $regionCode, $postcodePattern] = $this->random->pick(self::CITIES[$countryId]);

        return [
            'country_id' => $countryId,
            'region_code' => $regionCode,
            'city' => $city,
            'postcode' => $this->fillDigits($postcodePattern),
            'street' => sprintf('%s, %d', $this->random->pick(self::STREETS[$countryId]), $this->random->int(1, 180)),
            'telephone' => self::PHONE_PREFIXES[$countryId] . $this->fillDigits('#########'),
        ];
    }

    private function fillDigits(string $pattern): string
    {
        return (string)preg_replace_callback('/#/', fn (): string => (string)$this->random->int(0, 9), $pattern);
    }
}

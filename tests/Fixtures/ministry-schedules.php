<?php

declare(strict_types=1);

/**
 * Ministry schedules to design and test against.
 *
 * These are fixtures, not church records. The names are invented and no
 * ministry here is created in any database — the supplied reference image is a
 * layout reference, and a schedule appearing in a document is not authority to
 * enter it as data.
 *
 * They exist because the density of a real Sunday is the thing the Classic
 * sheet is designed for, and the development database has nine assignments
 * across three ministries. A layout that looks right at that size tells you
 * nothing about how it looks at forty.
 *
 * @return array<string,array<string,mixed>> keyed by case name
 */

$people = static fn (string ...$names): array => array_map(
    static fn (string $n): array => ['name' => $n, 'personId' => null],
    $names,
);

$section = static function (string $title, array $assignments) use ($people): array {
    $out = [];
    $weight = 2;
    foreach ($assignments as $role => $names) {
        $list = is_array($names) ? $names : [$names];
        $out[] = [
            'role' => is_int($role) ? null : $role,
            'people' => $people(...$list),
            'note' => null,
        ];
        $weight += (is_int($role) ? 0 : 1) + max(1, count($list));
    }

    return [
        'title' => $title,
        'ministryId' => null,
        'source' => 'assignments',
        'assignments' => $out,
        'weight' => $weight,
    ];
};

return [
    // The primary visual target: roughly the reference's density and mix of
    // card shapes — some with named roles, some a plain list of names.
    'normal' => [
        'date' => '2026-08-23',
        'sections' => [
            $section('Emcee', [['Sis Dulvi']]),
            $section('Prayer Ablaze', [['Bro Rovv'], ['Sis Elvo'], ['Sis Dulvi'], ['Bro Kervoss']]),
            $section('Altar', [
                'Opening' => ['Bro Rivvel'],
                'T&O' => ['Bro Rovv'],
                'Pulpit' => ['Bro Crelvo'],
                'T&O Box' => ['Sis Elvo'],
            ]),
            $section('Proclaim', [
                ['Bro Mervo'],
                'Photo' => ['Bro Pumolo'],
                'FB Story' => ['Bro Kervoss'],
                'Reels' => ['Sis Kivva T.'],
                'Lights' => ['Bro Rivvel'],
            ]),
            $section('Inventory', [['Sis Avvalie M.']]),
            $section('Alpha', [['Sis Elvo', 'Bro Rovv']]),
            $section('VIP', [['Sis Rulva', 'Sis Czorvelle']]),
            $section('Gifts & Arrows', [['Sis Kuvly'], ['Bro Crelvo'], ['Sis Jevva'], ['Sis Kivve N.']]),
            $section('Facilities', [
                'Main' => ['Bro Ambo', 'Bro Tovv'],
                'Set-up' => ['Bro Rivvel', 'Bro Ruño'],
                'Runner' => ['Bro Crelvo'],
                'Washroom — Men' => ['Bro Pumolo'],
                'Washroom — Women' => ['Sis Kuvly'],
                'MTE' => ['Sis Muvvene'],
            ]),
            $section('Events', [['Sis Chervelle'], ['Sis Kivrel'], ['Sis Mirvanna'], ['Sis Gorvy']]),
            $section('Hosts & Keepers', [
                'Greeter' => ['Sis Divvy'],
                'Usher' => ['Sis Rovvenna'],
                'Sanctuary (Door)' => ['Sis Jusk'],
                'Sanctuary (Main)' => ['Bro Mivvern'],
            ]),
            $section('Victuals', [
                'Set-up' => ['Sis Rubbla', 'Sis Rumbona', 'Sis Mivvelyn'],
                'Server' => ['Sis Elvanquine', 'Sis Duvrene', 'Bro Rivv', 'Sis Rimble'],
                'Kitchen Porter' => ['Bro Nivvo', 'Sis Jevva'],
            ]),
            $section('More Than Enough', [
                'Pre-service' => ['Sis Rimble', 'Bro Ezzel'],
                'Post service' => ['Sis Elvanquine', 'Sis Divvy'],
            ]),
        ],
    ],

    // Few ministries. The sheet should still look composed rather than like a
    // page with three things stranded at the top.
    'small' => [
        'date' => '2026-08-30',
        'sections' => [
            $section('Emcee', [['Sis Dulvi']]),
            $section('Altar', ['Opening' => ['Bro Rivvel'], 'Pulpit' => ['Bro Crelvo']]),
            $section('Victuals', ['Server' => ['Sis Duvrene', 'Bro Rivv']]),
        ],
    ],

    // Names far longer than the reference, including one without spaces to
    // break at. Nothing may clip and nothing may shrink.
    'long-names' => [
        'date' => '2026-09-06',
        'sections' => [
            $section('Hospitality And Welcome Team', [
                'Front Door Greeting And Welcome' => [
                    'Sister Mirvel Corvallith de los Sarvik-Vellaquine',
                    'Brother Brovvelmund Chorvelbrin Farthingdalewhorl',
                ],
                'Overflow' => ['Sis Avva-Melbrin O’Shrevelwood-Fennimoral'],
            ]),
            $section('Audiovisual Production And Livestream Ministry', [
                'Camera' => ['Bro Novvalael'],
                'Supercalifragilisticexpialidociousness' => ['Sis Kuv'],
            ]),
            $section('Emcee', [['Sis Dulvi']]),
        ],
    ],

    // A single ministry with many duties — the card must not be split across
    // the page, and must not force the others off it.
    'large-ministry' => [
        'date' => '2026-09-13',
        'sections' => [
            $section('Facilities', array_combine(
                array_map(static fn (int $i): string => 'Station ' . $i, range(1, 14)),
                array_map(static fn (int $i): array => ['Bro Volunteer ' . $i, 'Sis Volunteer ' . $i], range(1, 14)),
            )),
            $section('Emcee', [['Sis Dulvi']]),
        ],
    ],

    // More than one Letter sheet can hold at a readable size.
    'oversized' => [
        'date' => '2026-09-20',
        'sections' => array_map(
            static fn (int $i): array => $section('Ministry ' . $i, [
                'Lead' => ['Bro Person ' . $i, 'Sis Person ' . $i],
                'Support' => ['Bro Helper ' . $i, 'Sis Helper ' . $i, 'Bro Extra ' . $i],
                'Reserve' => ['Sis Reserve ' . $i],
            ]),
            range(1, 24),
        ),
    ],

    // An assignment with nobody on it, and a ministry with nothing at all.
    'incomplete' => [
        'date' => '2026-09-27',
        'sections' => [
            $section('Altar', ['Opening' => ['Bro Rivvel'], 'Pulpit' => []]),
            [
                'title' => 'Victuals',
                'ministryId' => null,
                'source' => 'assignments',
                'assignments' => [],
                'weight' => 2,
            ],
            $section('Emcee', [['Sis Dulvi']]),
        ],
    ],
];

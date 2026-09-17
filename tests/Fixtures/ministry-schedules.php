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
            $section('Emcee', [['Sis Dara']]),
            $section('Prayer Ablaze', [['Bro Reyn'], ['Sis Ella'], ['Sis Dara'], ['Bro Kenton']]),
            $section('Altar', [
                'Opening' => ['Bro Ramil'],
                'T&O' => ['Bro Reyn'],
                'Pulpit' => ['Bro Cristo'],
                'T&O Box' => ['Sis Ella'],
            ]),
            $section('Proclaim', [
                ['Bro Marko'],
                'Photo' => ['Bro Paulo'],
                'FB Story' => ['Bro Kenton'],
                'Reels' => ['Sis Kaya T.'],
                'Lights' => ['Bro Ramil'],
            ]),
            $section('Inventory', [['Sis Annelie M.']]),
            $section('Alpha', [['Sis Ella', 'Bro Reyn']]),
            $section('VIP', [['Sis Rema', 'Sis Czarinah']]),
            $section('Gifts & Arrows', [['Sis Kimly'], ['Bro Cristo'], ['Sis Jenna'], ['Sis Kaye N.']]),
            $section('Facilities', [
                'Main' => ['Bro Aldo', 'Bro Teng'],
                'Set-up' => ['Bro Ramil', 'Bro Niño'],
                'Runner' => ['Bro Cristo'],
                'Washroom — Men' => ['Bro Paulo'],
                'Washroom — Women' => ['Sis Kimly'],
                'MTE' => ['Sis Maureen'],
            ]),
            $section('Events', [['Sis Christelle'], ['Sis Kiara'], ['Sis Michella'], ['Sis Gifty']]),
            $section('Hosts & Keepers', [
                'Greeter' => ['Sis Daisy'],
                'Usher' => ['Sis Rowena'],
                'Sanctuary (Door)' => ['Sis Jean'],
                'Sanctuary (Main)' => ['Bro Marvin'],
            ]),
            $section('Victuals', [
                'Set-up' => ['Sis Ruffa', 'Sis Ramona', 'Sis Maylyn'],
                'Server' => ['Sis Elizabeth', 'Sis Doreen', 'Bro Ryan', 'Sis Remzie'],
                'Kitchen Porter' => ['Bro Nico', 'Sis Jenna'],
            ]),
            $section('More Than Enough', [
                'Pre-service' => ['Sis Remzie', 'Bro Ervyn'],
                'Post service' => ['Sis Elizabeth', 'Sis Daisy'],
            ]),
        ],
    ],

    // Few ministries. The sheet should still look composed rather than like a
    // page with three things stranded at the top.
    'small' => [
        'date' => '2026-08-30',
        'sections' => [
            $section('Emcee', [['Sis Dara']]),
            $section('Altar', ['Opening' => ['Bro Ramil'], 'Pulpit' => ['Bro Cristo']]),
            $section('Victuals', ['Server' => ['Sis Doreen', 'Bro Ryan']]),
        ],
    ],

    // Names far longer than the reference, including one without spaces to
    // break at. Nothing may clip and nothing may shrink.
    'long-names' => [
        'date' => '2026-09-06',
        'sections' => [
            $section('Hospitality And Welcome Team', [
                'Front Door Greeting And Welcome' => [
                    'Sister Maria Consolacion de los Santos-Villanueva',
                    'Brother Bartholomew Christopher Featherstonehaugh',
                ],
                'Overflow' => ['Sis Anne-Marie O’Shaughnessy-Fitzgerald'],
            ]),
            $section('Audiovisual Production And Livestream Ministry', [
                'Camera' => ['Bro Nathanael'],
                'Supercalifragilisticexpialidociousness' => ['Sis Kim'],
            ]),
            $section('Emcee', [['Sis Dara']]),
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
            $section('Emcee', [['Sis Dara']]),
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
            $section('Altar', ['Opening' => ['Bro Ramil'], 'Pulpit' => []]),
            [
                'title' => 'Victuals',
                'ministryId' => null,
                'source' => 'assignments',
                'assignments' => [],
                'weight' => 2,
            ],
            $section('Emcee', [['Sis Dara']]),
        ],
    ],
];

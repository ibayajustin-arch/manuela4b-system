<?php
// config/facilities.php  -  facility rules (edit here when the final schedule is decided)
return [
    'open'         => '06:00',   // opening time
    'close'        => '22:00',   // closing time
    'hold_minutes' => 0,         // 0 = only PAID bookings block a slot (no hold for unpaid ones)

    'rate_periods' => [
        ['start' => '06:00', 'end' => '16:00', 'rate' => 200.00],
        ['start' => '16:00', 'end' => '22:00', 'rate' => 300.00],
    ],

    // 'venue' = facilities with the SAME venue share one physical place (can't overlap)
    'facilities' => [
        'Court'        => ['venue' => 'court'],
        'Tennis Court' => ['venue' => 'court'],
        'Clubhouse'    => ['venue' => 'clubhouse'],
    ],
];

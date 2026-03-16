<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_learningjourney\task\send_reminders',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];


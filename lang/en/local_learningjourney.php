<?php

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Learning Journey';
$string['task_sendreminders'] = 'Send Learning Journey reminders';

$string['remindersettings'] = 'Reminder settings';
$string['activity'] = 'Activity';
$string['timetosend'] = 'Time to send reminder';
$string['completionfilter'] = 'Target users';
$string['filter_completed'] = 'Only users who completed the activity';
$string['filter_notcompleted'] = 'Only users who have not completed the activity';
$string['filter_all'] = 'All enrolled users';
$string['subject'] = 'Email subject';
$string['message'] = 'Personal message';
$string['enabled'] = 'Enabled';

$string['remindersaved'] = 'Reminder saved successfully.';
$string['reminder_help'] = 'Create a reminder for a specific activity in this course. The reminder will be sent by email at the configured time, with a direct link to the activity and the course.';

$string['defaultsubject'] = 'Reminder: {$a->activity} in course {$a->course}';
$string['defaultmessage'] = 'You have a pending activity in your Learning Journey.';
$string['messagefooter'] = 'Activity: {$a->activity} ({$a->activityurl}) - Course page: {$a->courseurl}';


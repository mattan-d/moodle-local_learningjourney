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
$string['preview'] = 'Preview email';
$string['edit'] = 'Edit';

$string['remindersaved'] = 'Reminder saved successfully.';
$string['reminder_help'] = 'Create a reminder for a specific activity in this course. The reminder will be sent by email at the configured time, with a direct link to the activity and the course.';

$string['reminderlist'] = 'Existing reminders';
$string['status'] = 'Status';
$string['status_sent'] = 'Sent ({$a})';
$string['status_notsent'] = 'Not sent yet';
$string['actions'] = 'Actions';

$string['managerheader'] = 'Reminder type';
$string['targettype'] = 'Reminder type';
$string['target_student'] = 'Students';
$string['target_manager'] = 'Managers';
$string['managerstatusheading'] = 'Learner progress for activity {$a->activity}';
$string['managerstatus_complete'] = 'Completed';
$string['managerstatus_notcomplete'] = 'Not completed';
$string['managerprogress'] = 'Progress: {$a}%';

$string['previewheading'] = 'Reminder email preview';
$string['previewsubjectlabel'] = 'Subject:';
$string['previewbodylabel'] = 'Body:';

$string['defaultsubject'] = 'Reminder: {$a->activity} in course {$a->course}';
$string['defaultmessage'] = 'You have a pending activity in your Learning Journey.';
$string['messagefooter'] = 'Activity: {$a->activity} ({$a->activityurl}) - Course page: {$a->courseurl}';


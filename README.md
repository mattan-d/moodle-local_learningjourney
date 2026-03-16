# Learning Journey (local\_learningjourney)

Learning Journey is a local plugin for Moodle 4.x that lets course teachers schedule and manage reminder emails for learners and their managers at the **course level**.

## Features

- Course-level management page under **Course administration → Learning Journey**.
- Create reminders:
  - **Per activity** or **for all activities in the course**.
  - **Send to**:
    - Students (personal reminder).
    - Managers (summary per manager, based on a custom profile field).
  - **Filter by**:
    - Only users who completed the activity.
    - Only users who have not completed the activity.
    - All enrolled users.
    - From now on, when users complete the activity.
  - Custom subject and body (supports simple placeholders like `{{fullname}}`, `{{activityname}}`, `{{courseurl}}`).
- Email content:
  - Always includes activity name and direct links to the activity and course.
  - For **"all activities in the course"**:
    - Adds a per-user table with each activity and completion status.
    - Shows overall course progress (percentage).
- Manager summary emails:
  - Uses custom user profile field `manager` (shortname) which holds the **username** of the manager.
  - Each manager gets a single email with:
    - A table of all learners they manage in the course.
    - For each learner: completion status and course progress percentage.
- Existing reminders table in the course:
  - Activity (or "all activities").
  - Time to send.
  - Send to (students / managers).
  - Filter by.
  - Status (sent / not sent + timestamp).
  - Number of recipients.
  - Actions: preview, edit, delete (Moodle-style icons).

## Requirements

- Moodle **4.0 or later** (`$plugin->requires = 2022041900`).
- Cron must be configured and running regularly.
- Optional (for manager summaries):
  - A **custom user profile field** with shortname `manager` containing the manager's **username**.

## Installation

1. Place this plugin in:

   ```text
   local/learningjourney
   ```

   So the main file is:

   ```text
   local/learningjourney/version.php
   ```

2. Visit `Site administration → Notifications` to trigger the installation/upgrade.
3. Verify that the scheduled task **"Send Learning Journey reminders"** is present under:
   `Site administration → Server → Scheduled tasks`.

## Capabilities & Access

- Capability: `local/learningjourney:managereminders`
  - By default allowed for:
    - `manager`
    - `editingteacher`
- Only users with this capability in the course context can access the **Learning Journey** page for that course.

## Course-level Management Page

For a given course, navigate to:

```text
Course administration → Learning Journey
```

There you can:

- Create a new reminder:
  - Choose activity or "all activities in this course".
  - Choose **time to send**.
  - Choose **send to** (students / managers).
  - Choose **filter by** (completed / not completed / all / on complete).
  - Add custom subject and message.
  - Enable/disable the reminder.
- View existing reminders in a table, including:
  - Status and number of recipients.
  - Icon actions:
    - Preview email (using current user as example).
    - Edit reminder.
    - Delete reminder.

## Placeholders in Messages

You can use the following placeholders in reminder messages (both for students and managers):

- `{{fullname}}` – user full name.
- `{{firstname}}`
- `{{lastname}}`
- `{{activityname}}` – activity name, or "All activities" when applicable.
- `{{coursename}}`
- `{{activityurl}}` – direct link to activity or course (for "all activities").
- `{{courseurl}}` – link to course page.
- `{{sitename}}`

These are automatically replaced when the email is generated.

## Scheduled Task

- Task class: `\local_learningjourney\task\send_reminders`
- Default schedule: every 15 minutes.
- Behavior:
  - Finds all enabled reminders whose **time to send** has passed and have not yet been sent.
  - Applies the selected filter and target type.
  - Sends emails to matching users and (optionally) their managers.
  - Marks the reminder as sent and records:
    - `senttime`
    - `sentcount` (number of recipients, including managers if applicable).

## Copyright

All new source files in this plugin should start with:

```text
Copyright © CentricApp LTD. dev@centricapp.co.il
```


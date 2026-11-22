<?php

namespace App\Enums;

// SCRUM-40 (TT-5.5): the kinds of notification a person can switch off, by email or in the app (the user's decision:
// both channels, per category). Everything not in one of these -- safety, security, account standing, guardianship and
// anything about a ward, verification, urgent help, staff alerts -- is always sent (App\Support\NotificationCategories).
enum NotificationCategoryEnum: string
{
    case sessions = 'sessions';
    case therapies = 'therapies';
    case discussions = 'discussions';
    case organisations = 'organisations';
    case payments = 'payments';
    // TT-7.11d1/SCRUM-473: being recognised as a Counsellor of the month.
    case recognition = 'recognition';

    // Only a counsellor is sent these.
    public function forCounsellorsOnly(): bool
    {
        return in_array($this, [self::discussions, self::organisations, self::recognition], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::sessions => 'Sessions and reminders',
            self::therapies => 'Therapies and groups',
            self::discussions => 'Case discussions (counsellors)',
            self::organisations => 'Organisation pay changes (counsellors)',
            self::payments => 'Your refunds',
            self::recognition => 'Recognition (counsellors)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::sessions => 'New, changed and cancelled sessions, proposed times, and reminders before a session.',
            self::therapies => 'A counsellor accepting your request, a group request answered, a member leaving your group.',
            self::discussions => 'Being added to, and changes to, case discussions with other counsellors.',
            self::organisations => 'Proposed, accepted and expiring changes to what an organisation pays you.',
            self::payments => 'A refund going through, failing, or being turned down.',
            self::recognition => 'Being recognised as a Counsellor of the month, and a commission-free period given to you. A recognition\'s answer stays on your dashboard either way.',
        };
    }
}

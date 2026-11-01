<?php

namespace App\Notifications;

// SCRUM-356: identical to TherapyAssistanceRequestSentNotification -- it already words itself for
// a group from the request's `for`. Kept as its own class name so existing code and tests that
// refer to it keep working.
class GroupTherapyAssistanceRequestSentNotification extends TherapyAssistanceRequestSentNotification {}

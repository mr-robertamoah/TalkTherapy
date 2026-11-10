<?php

namespace App\Contracts;

// SCRUM-39: a notification sent only to administrators. The notification center hides it from anyone who
// isn't one now (NotificationCenter::ADMIN_ONLY, which a test keeps equal to the classes marked so).
interface SentOnlyToAdministrators {}

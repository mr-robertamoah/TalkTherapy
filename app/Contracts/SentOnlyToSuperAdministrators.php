<?php

namespace App\Contracts;

// SCRUM-39: a notification sent only to super administrators -- hidden from anyone who isn't one now
// (NotificationCenter::SUPER_ADMIN_ONLY, kept equal to the classes marked so by a test).
interface SentOnlyToSuperAdministrators extends SentOnlyToAdministrators {}

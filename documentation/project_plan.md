# TalkTherapy Project Plan

This document outlines the project plan structured into Epics and User Stories, covering new requirements (Organizations, Discussions), technical debt (Unification), and general improvements.

## Epic 1: Organizations & Membership (New Feature)
**Goal**: Enable organizations to manage members and either consume or provide therapy services.
- **Story 1.1 (Setup)**: As an Admin, I want to create Organization entities with basic profile info (name, logo, description), so they can exist in the system.
- **Story 1.2 (Membership)**: As an Organization Admin, I want to invite and manage members (users/counsellors), so I can control who belongs to my organization.
- **Story 1.3 (Client Mode)**: As an Organization (Client), I want to subscribe to Counsellors/Therapies, so my members can book them for free/subsidized rates.
- **Story 1.4 (Provider Mode)**: As an Organization (Provider), I want to onboard Counsellors, so they can offer services under the organization's banner.

## Epic 2: Real-time Discussions & Messaging (High Priority)
**Goal**: Complete and stabilize the discussion interaction features.
- **Story 2.1 (Live Chat Completion)**: As a User, I want the live chat in discussions to be fully functional (sending, receiving, typing indicators) without errors.
- **Story 2.2 (Reverb Fixes)**: As a Developer, I need to debug and fix Laravel Reverb sending duplicate broadcasts/notifications, to ensure a clean user experience.
- **Story 2.3 (Notes in Chat)**: As a Counsellor, I want to attach notes/comments to specific chat messages (with timestamps), so I can annotate important moments in a session.
- **Story 2.4 (Chat Limits)**: As an Admin, I want to limit the number of counsellors added to a single discussion, to prevent overcrowding.
- **Story 2.5 (Session Notes)**: As a Counsellor, I want to add notes directly to a running session, so I can record observations in real-time.

## Epic 3: Unified Therapy & UI Polish
**Goal**: Finalize the UnifiedTherapy refactor and improve general UI/UX.
- **Story 3.1 (Unification Completion)**: As a Developer, I want to ensure `UnifiedTherapy.vue` handles all edge cases for both Individual and Group therapies perfectly, removing any residual duplication.
- **Story 3.2 (Home Page Centering)**: As a User, I want the post section on the home page to be centered on all viewports, for a better visual experience.
- **Story 3.3 (HowTo Modal UX)**: As a User, I want a "Get Started" button in the HowTo modal that closes it and scrolls to the start, to streamline onboarding.

## Epic 4: Scheduling & Calendar
**Goal**: Improve how sessions are requested and tracked.
- **Story 4.1 (User Session Request)**: As a User, I want to request a session by proposing a day and time, so my Counsellor can accept or modify it.
- **Story 4.2 (Counsellor Calendar)**: As a Counsellor, I want a calendar view of my sessions, so I can easily track my schedule.
- **Story 4.3 (Time Validation Fix)**: As a User, I want to be able to update a start time to an earlier slot without getting a "prohibition error", provided the slot is free.

## Epic 5: Trust, Safety & Security
**Goal**: Enhance system security and user trust.
- **Story 5.1 (ID Verification)**: As a User, I want to see an ID Card/Verification badge on a Counsellor's profile (or a warning if unverified), so I can trust who I am talking to.
- **Story 5.2 (Institutionalisation)**: As a Counsellor, I want to be able to recommend "institutionalisation" for a user if deemed critical, triggering appropriate high-priority flows/alerts.
- **Story 5.3 (Anonymous Privacy)**: As a User, I want to ensure my PII (email/name) does not appear in emails for therapies where I am anonymous, to protect my identity.
- **Story 5.4 (2FA)**: As a User, I want to enable Two-Factor Authentication, to secure my account.
- **Story 5.5 (Password Security)**: As a System, I want to enforce a password policy and warn users with weak passwords on their profile.

## Epic 6: Notifications & User Tools
**Goal**: Improve user engagement and personal tools.
- **Story 6.1 (Journal)**: As a User, I want to create private journal entries, to track my mental state over time.
- **Story 6.2 (Notification Center)**: As a User, I want a dropdown in the app showing my database notifications, so I can see history without checking email.
- **Story 6.3 (Notification Settings)**: As a User, I want to configure which events trigger emails vs in-app notifications, to reduce noise.
- **Story 6.4 (Email Verification UX)**: As a System, I want to highlight the importance of email verification more prominently to the user.

## Epic 7: Admin & Ops
**Goal**: Tools for system administrators and operational stability.
- **Story 7.1 (Admin Dashboard)**: As an Admin, I want a dashboard showing key metrics (users, active sessions, system health), so I can monitor the platform.
- **Story 7.2 (Data Collection Transparency)**: As a User/Counsellor, I want to see exactly what data is collected about me, for transparency/GDPR compliance.

## Epic 8: Profile & configuration
- **Story 8.1 (Pricing)**: As a Counsellor, I want to set and display my preferred pricing on my profile.
- **Story 8.2 (Profile Links)**: As a User/Counsellor, I want shareable links to my profile to be visible and easy to copy.
- **Story 8.3 (Multilingual Support)**: (Future) as a User, I want to switch languages.
- **Story 8.4 (AI Emergency)**: (Future) AI integration for emergency support.

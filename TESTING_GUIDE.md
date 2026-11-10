# TalkTherapy Testing Guide

## User Accounts for Testing

### Super Admin
- **Username**: `mr_robertamoah`
- **Email**: `mr_robertamoah@yahoo.com`
- **Password**: `password` (from SUPER_PASSWORD env)
- **Role**: Super Administrator
- **Capabilities**: Full system access, user management, content moderation

---

## Regular Users (Non-Counsellors)

### User 1 - Sarah Johnson
- **Username**: `sarah_johnson`
- **Email**: `sarah.johnson@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Female
- **Has**: Individual therapies with counsellors

### User 2 - Michael Chen
- **Username**: `michael_chen`
- **Email**: `michael.chen@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Male
- **Has**: Individual therapies with counsellors

### User 3 - Emma Williams
- **Username**: `emma_williams`
- **Email**: `emma.williams@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Female
- **Has**: Individual therapies with counsellors

### User 4 - David Brown
- **Username**: `david_brown`
- **Email**: `david.brown@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Male
- **Has**: Individual therapies with counsellors

---

## Counsellor Users (First 6 users are also counsellors)

### Counsellor 1 - Dr. Sarah Johnson
- **Username**: `sarah_johnson`
- **Email**: `sarah.johnson@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Anxiety and depression (10 years experience)
- **Profession**: Clinical Psychologist
- **Cases**: Anxiety Disorders, Depression, Relationship Issues
- **Languages**: English + one random
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

### Counsellor 2 - Dr. Michael Chen
- **Username**: `michael_chen`
- **Email**: `michael.chen@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Trauma specialist (PTSD, childhood trauma)
- **Profession**: Trauma Specialist
- **Cases**: Trauma and PTSD, Depression, Relationship Issues
- **Languages**: English + one random (bilingual)
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

### Counsellor 3 - Dr. Emma Williams
- **Username**: `emma_williams`
- **Email**: `emma.williams@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Marriage and family therapy
- **Profession**: Marriage and Family Therapist
- **Cases**: Relationship Issues, Depression, Anxiety Disorders
- **Languages**: English + one random
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

### Counsellor 4 - Dr. David Brown
- **Username**: `david_brown`
- **Email**: `david.brown@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Addiction counselor
- **Profession**: Addiction Counselor
- **Cases**: Addiction Recovery, Depression, Anxiety Disorders
- **Languages**: English + one random
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

### Counsellor 5 - Dr. Lisa Anderson
- **Username**: `lisa_anderson`
- **Email**: `lisa.anderson@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Child psychology
- **Profession**: Child Psychologist
- **Cases**: Developmental issues, family dynamics
- **Languages**: English + one random
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

### Counsellor 6 - Dr. James Wilson
- **Username**: `james_wilson`
- **Email**: `james.wilson@example.com`
- **Password**: `password`
- **Role**: User + Verified Counsellor
- **Specialization**: Cognitive behavioral therapy
- **Profession**: Cognitive Behavioral Therapist
- **Cases**: Mood disorders, anxiety management
- **Languages**: English + one random
- **Status**: Verified and active
- **Has**: Individual therapy clients, group therapy participation

---

## Additional Regular Users

### User 7 - Maria Garcia
- **Username**: `maria_garcia`
- **Email**: `maria.garcia@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Female

### User 8 - John Davis
- **Username**: `john_davis`
- **Email**: `john.davis@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Male

### User 9 - Amy Taylor
- **Username**: `amy_taylor`
- **Email**: `amy.taylor@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Female

### User 10 - Robert Miller
- **Username**: `robert_miller`
- **Email**: `robert.miller@example.com`
- **Password**: `password`
- **Role**: Regular User
- **Gender**: Male

---

## Therapy Data Structure

### Individual Therapies
- **Created by**: Users 7-10 (non-counsellor users)
- **Assigned to**: Random counsellors from the 6 available
- **Count**: 1-2 therapies per user
- **Types**: Mix of FREE and PAID
- **Sessions**: 2-5 sessions per therapy
- **Session Types**: Online and In-person
- **Session Status**: pending, held, in_session, abandoned
- **Topics**: 2-4 topics per therapy
- **Visibility**: Mix of public and private
- **Cases**: Random therapy cases attached

### Group Therapies
- **Count**: 3-5 group therapies total
- **Created by**: Random users
- **Participants**: 3-8 users per group
- **Counsellors**: 1-3 counsellors per group
- **Max Users**: 5-15 per group
- **Types**: Mix of FREE and PAID
- **Visibility**: Mix of public and private
- **Allow Anyone**: Some groups allow anyone to join

### Discussions
- **Count**: 3-6 discussions
- **Created by**: Counsellors only
- **Participants**: 1-3 other counsellors
- **Related to**: Individual therapies
- **Status**: pending, in_session, held
- **Messages**: 10-25 messages per held discussion

### Sessions
- **Individual Therapy Sessions**: 2-5 per therapy
- **Time Range**: -30 days to +30 days from seeding
- **Duration**: 1 hour each
- **Messages**: 5-15 messages per held session
- **Participants**: User and assigned counsellor

---

## Posts and Social Features

### Posts
- **Counsellor Posts**: 2-5 posts per counsellor
- **Topics**: Professional mental health topics
- **User Posts**: 3 posts from regular users
- **Topics**: Personal mental health journeys
- **Engagement**: Likes and comments from other users

### Available Therapy Cases
1. Anxiety Disorders
2. Depression
3. Relationship Issues
4. Trauma and PTSD
5. Addiction Recovery
6. Grief and Loss
7. Stress Management
8. Self-Esteem Issues
9. Anger Management
10. Eating Disorders
11. Sleep Disorders
12. Academic/Career Counseling

### Available Languages
- English, French, Twi, Ewe, Ga, Spanish, German, Mandarin, Arabic, Portuguese

### Available Religions
- Christianity, Islam, Traditional, Atheist, Judaism, Buddhism, Hinduism, Agnostic

---

## Testing Scenarios

### As a Regular User (e.g., maria_garcia)
1. **Login** and view home page with posts
2. **Create individual therapy** - request counsellor assistance
3. **Join group therapy** if allowed
4. **View therapy sessions** and participate in messaging
5. **Comment and like posts** from counsellors

### As a Counsellor (e.g., sarah_johnson)
1. **Login** and view counsellor dashboard
2. **Manage individual therapy clients**
3. **Create and manage group therapies**
4. **Participate in discussions** with other counsellors
5. **Create posts** about mental health topics
6. **Conduct therapy sessions** with messaging

### As Super Admin (mr_robertamoah)
1. **Login** and access admin panel
2. **Manage all users** and counsellors
3. **Moderate content** and handle reports
4. **View system statistics**
5. **Manage reference data** (cases, languages, etc.)

---

## Quick Test Commands

```bash
# Reset and seed database
php artisan migrate:fresh --seed

# Check seeded data
php artisan tinker
>>> User::count()
>>> Counsellor::count()
>>> \App\Models\Therapy::count()
>>> \App\Models\GroupTherapy::count()
>>> \App\Models\Discussion::count()
>>> \App\Models\Session::count()
>>> \App\Models\Post::count()
```

---

## Browser Testing Tips

1. **Use multiple browsers/incognito tabs** to test different user roles simultaneously
2. **Test real-time features** by having counsellor and user logged in different tabs
3. **Check WebSocket connections** in browser dev tools
4. **Test responsive design** on different screen sizes
5. **Verify email notifications** (check logs if not configured)

This guide provides all the necessary account information and data structure to effectively test all features of the TalkTherapy application.
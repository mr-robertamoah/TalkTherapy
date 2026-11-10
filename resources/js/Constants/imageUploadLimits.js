// SCRUM-182/TT-10.8: mirrors app/Support/ImageUploadRules.php. Client-side validation here
// is only a UX convenience (instant feedback before a request is even sent) - the backend
// rule is the actual enforcement. Keep both in sync if either changes.
export const MAX_SIZE_KB = 2048;

export const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
];

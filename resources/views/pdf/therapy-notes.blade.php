{{-- SCRUM-438: a counsellor's own notes on one therapy. Every value escaped ({{ }} only, never {!! !!}); no images,
     links, scripts or remote fonts -- dompdf may fetch nothing. Never the client's words, never a co-counsellor's notes. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Notes</title>
    <style>
        @page { margin: 2cm 2cm 2.2cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        .meta { color: #4b5563; font-size: 9.5pt; margin: 0 0 16pt; }
        .session { border-top: 1px solid #d1d5db; padding-top: 10pt; margin-top: 14pt; }
        h2 { font-size: 12pt; margin: 0 0 2pt; }
        .when { color: #4b5563; font-size: 9pt; margin: 0 0 6pt; }
        .note { margin: 6pt 0 0 10pt; }
        .kind { color: #4b5563; font-size: 9pt; margin: 0; }
        .content { white-space: pre-wrap; word-wrap: break-word; margin: 2pt 0 0; }
        .footer { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8.5pt; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="footer">Confidential -- a counsellor's private notes, exported from TalkTherapy on {{ $exportedOn }}. Keep this file secure.</div>

    <h1>Notes: {{ $therapy }}</h1>
    <p class="meta">
        {{ $isGroup ? 'Group therapy' : 'Client: '.($client ?? 'a closed account') }} &middot; by {{ $counsellor }} &middot; exported {{ $exportedOn }}
    </p>

    @foreach ($sessions as $session)
        <div class="session">
            <h2>{{ $session['session'] }}</h2>
            @if ($session['at'])
                <p class="when">{{ $session['at'] }}</p>
            @endif
            @foreach ($session['notes'] as $note)
                <div class="note">
                    <p class="kind">{{ $note['kind'] }} &middot; written {{ $note['at'] }}</p>
                    <p class="content">{{ $note['content'] }}</p>
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>

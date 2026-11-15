{{-- SCRUM-38: the person's journal as a PDF. Every value is escaped ({{ }} only, never {!! !!}): an entry's text is
     shown as written, never read as HTML. No images, links, scripts or remote fonts -- dompdf may fetch nothing. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Journal</title>
    <style>
        @page { margin: 2cm 2cm 2.2cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        .meta { color: #4b5563; font-size: 9.5pt; margin: 0 0 18pt; }
        .entry { border-top: 1px solid #d1d5db; padding-top: 10pt; margin-top: 14pt; page-break-inside: auto; }
        .date { color: #4b5563; font-size: 9.5pt; margin: 0; }
        h2 { font-size: 13pt; margin: 2pt 0 6pt; }
        .content { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
        .footer { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8.5pt; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="footer">Private -- exported from TalkTherapy on {{ $exportedOn }}. Only you could read this in the app.</div>

    <h1>{{ $name }}'s journal</h1>
    <p class="meta">
        Exported on {{ $exportedOn }}
        @if ($from || $to)
            &middot; entries {{ $from ? 'from '.$from : '' }} {{ $to ? 'to '.$to : '' }}
        @endif
        &middot; {{ $entries->count() }} {{ $entries->count() === 1 ? 'entry' : 'entries' }}
    </p>

    @foreach ($entries as $entry)
        <div class="entry">
            <p class="date">{{ $entry['writtenOn'] }}</p>
            @if ($entry['title'])
                <h2>{{ $entry['title'] }}</h2>
            @endif
            <p class="content">{{ $entry['content'] }}</p>
        </div>
    @endforeach
</body>
</html>

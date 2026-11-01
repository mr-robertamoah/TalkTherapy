{{-- SECURITY (SCRUM-254/366): user text goes through App\Support\MailText and secured Markdown
     encoding. If this published template is ever re-published or replaced, keep MailText::inline
     on the greeting and every line -- tests/Unit/MailMarkdownInjectionTest.php will fail if not. --}}
<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ \App\Support\MailText::inline($greeting) }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ \App\Support\MailText::inline($line) }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ \App\Support\MailText::inline($line) }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ \App\Support\MailText::inline($salutation) }}
@else
@lang('Regards'),<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

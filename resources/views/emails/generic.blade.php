@extends('emails.layouts.base', [
    'title' => $subject ?? 'Trakli',
    'preheader' => $subject ?? '',
])

@section('content')
    @if (! empty($subject))
        <h1 class="h1 text-heading" style="margin:0 0 18px; font-size:22px; line-height:1.3; color:#0f3a23; font-weight:700;">
            {{ $subject }}
        </h1>
    @endif

    <div style="font-size:15px; line-height:1.65; color:#1f2937;">
        {!! nl2br(e($body)) !!}
    </div>
@endsection

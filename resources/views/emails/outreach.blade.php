@extends('emails.layouts.base', [
    'title' => $subject ?? 'Trakli',
    'preheader' => $subject ?? '',
])

@section('content')
    @if (! empty($imageUrl))
        <div style="margin:0 0 22px;">
            <img src="{{ $imageUrl }}" alt="" style="display:block; width:100%; max-width:560px; height:auto; border-radius:10px;" />
        </div>
    @endif

    <div class="outreach-body" style="font-size:15px; line-height:1.65; color:#1f2937;">
        {!! $bodyHtml !!}
    </div>

    @if (! empty($ctaLabel) && ! empty($ctaUrl))
        <div style="margin:28px 0 4px;">
            <a href="{{ $ctaUrl }}" target="_blank" rel="noopener"
               style="display:inline-block; background:#047844; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px; padding:12px 22px; border-radius:8px;">
                {{ $ctaLabel }}
            </a>
        </div>
    @endif
@endsection

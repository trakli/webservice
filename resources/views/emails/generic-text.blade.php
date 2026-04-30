@if (! empty($subject)){{ $subject }}

@endif
{{ $body }}

---
Manage email preferences: {{ config('app.frontend_url', config('app.url')) }}/settings
Contact support: support@trakli.app
(c) {{ date('Y') }} Trakli

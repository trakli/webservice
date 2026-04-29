@if (! empty($user?->first_name))
Hi {{ $user->first_name }},

@endif
{{ $tier['subject'] }}

It has been {{ $daysInactive }} days since your last transaction.

{{ $tier['message'] }}

CATCH UP FASTER WITH THE IMPORTER
{{ $tier['encouragement'] }}

Open the importer:
{{ config('app.frontend_url', config('app.url')) }}/imports

Prefer to enter them yourself? Log a transaction manually:
{{ config('app.frontend_url', config('app.url')) }}/transactions

---
Manage email preferences: {{ config('app.frontend_url', config('app.url')) }}/settings
Contact support: support@trakli.app
(c) {{ date('Y') }} Trakli

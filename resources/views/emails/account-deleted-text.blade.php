{{ strtoupper(__('Account deleted')) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('Your Trakli account and all associated data have been permanently deleted. This includes your wallets, transactions, categories, groups, files, and configuration.') }}

{{ __("If you didn't request this, please contact us right away at :email. After this point the data is no longer recoverable.", ['email' => 'support@trakli.app']) }}

{{ __("If you ever want to come back, you're always welcome to start fresh.") }}

---
(c) {{ date('Y') }} Trakli

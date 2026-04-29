<?php

namespace App\Http\Controllers;

use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use App\Mail\InactivityReminderMail;
use App\Mail\InsightsMail;
use App\Models\User;
use App\Services\InactivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

class MailPreviewController extends Controller
{
    public const MAIL_TYPES = [
        'inactivity-reminder' => 'Inactivity reminder',
        'insights' => 'Periodic insights',
        'account-deleted' => 'Account deleted',
        'generic' => 'Generic notification',
    ];

    public function index(): View
    {
        return view('mail-preview.index', [
            'types' => self::MAIL_TYPES,
        ]);
    }

    public function show(Request $request, string $type): Mailable
    {
        return match ($type) {
            'inactivity-reminder' => $this->buildInactivity($request),
            'insights' => $this->buildInsights($request),
            'account-deleted' => $this->buildAccountDeleted($request),
            'generic' => $this->buildGeneric($request),
            default => abort(404, "Unknown mail preview type: {$type}"),
        };
    }

    private function fakeUser(Request $request): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => $request->query('first_name', 'Alex'),
            'last_name' => $request->query('last_name', 'Doe'),
            'email' => $request->query('email', 'alex@example.com'),
        ]);

        return $user;
    }

    private function buildInactivity(Request $request): InactivityReminderMail
    {
        $days = max(0, (int) $request->query('days', 14));

        $applicable = null;
        $applicableDays = null;
        foreach (InactivityService::INACTIVITY_TIERS as $tierDays => $variants) {
            if ($days >= $tierDays) {
                $applicable = $variants;
                $applicableDays = $tierDays;
            }
        }

        if ($applicable === null) {
            $applicableDays = array_key_first(InactivityService::INACTIVITY_TIERS);
            $applicable = InactivityService::INACTIVITY_TIERS[$applicableDays];
        }

        $variantIndex = (int) $request->query('variant', 0);
        $variant = $applicable[$variantIndex] ?? $applicable[0];

        return new InactivityReminderMail($this->fakeUser($request), $variant, $days);
    }

    private function buildInsights(Request $request): InsightsMail
    {
        $frequency = $request->query('frequency', 'weekly') === 'monthly' ? 'monthly' : 'weekly';
        $periodLabel = $frequency === 'monthly' ? 'Monthly' : 'Weekly';

        $start = $frequency === 'monthly'
            ? Carbon::now()->subMonth()->startOfMonth()
            : Carbon::now()->subWeek()->startOfWeek();
        $end = $frequency === 'monthly'
            ? Carbon::now()->subMonth()->endOfMonth()
            : Carbon::now()->subWeek()->endOfWeek();
        $label = $frequency === 'monthly'
            ? $start->format('F Y')
            : $start->format('M j') . ' - ' . $end->format('M j, Y');

        $insights = [
            'period' => ['start' => $start, 'end' => $end, 'label' => $label],
            'frequency' => $frequency,
            'income' => 250000,
            'expenses' => 174320,
            'net' => 75680,
            'savings_rate' => 30.3,
            'transaction_count' => 47,
            'expenses_by_category' => [
                'Food & Dining' => 52400,
                'Transportation' => 31200,
                'Utilities' => 24800,
                'Entertainment' => 18600,
                'Shopping' => 14320,
            ],
            'top_expense' => [
                'description' => 'Annual insurance renewal',
                'amount' => 28400,
                'category' => 'Insurance',
            ],
            'expense_change_percent' => 8.4,
        ];

        return new InsightsMail($this->fakeUser($request), $insights, $periodLabel);
    }

    private function buildAccountDeleted(Request $request): AccountDeletedMail
    {
        $user = $this->fakeUser($request);

        return new AccountDeletedMail(trim($user->first_name . ' ' . $user->last_name));
    }

    private function buildGeneric(Request $request): GenericMail
    {
        $subject = $request->query('subject', 'A note from Trakli');
        $defaultBody = "Hi there,\n\n"
            . "This is what a generic notification from Trakli looks like. "
            . "The body supports plain newlines and is escaped before render.\n\n"
            . "Regards,\nThe Trakli team";
        $body = $request->query('body', $defaultBody);

        return new GenericMail($subject, $body);
    }
}

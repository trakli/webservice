<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Http\Controllers\API\ApiController;
use App\Mail\OutreachMail;
use App\Models\Outreach;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Admin", description="Admin operations")
 */
class OutreachController extends ApiController
{
    #[OA\Post(
        path: '/admin/outreach/preview',
        summary: 'Render the outreach email as it will appear to a recipient',
        tags: ['Admin'],
        responses: [
            new OA\Response(response: 200, description: 'Rendered HTML'),
            new OA\Response(response: 403, description: 'Not an admin'),
        ]
    )]
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:10000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $mail = new OutreachMail(
            $this->personalize($data['subject'] ?? '', $user) ?: __('Subject'),
            $this->personalize($data['body'] ?? '', $user),
            $data['cta_label'] ?? null,
            $data['cta_url'] ?? null,
            $data['image_url'] ?? null,
        );

        return $this->success(['html' => $mail->render()]);
    }

    #[OA\Post(
        path: '/admin/outreach/media',
        summary: 'Upload an image to embed in an outreach email',
        tags: ['Admin'],
        responses: [
            new OA\Response(response: 200, description: 'Public URL of the stored image'),
            new OA\Response(response: 403, description: 'Not an admin'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function media(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:' . FileService::MAX_KILOBYTES],
        ]);

        $path = $request->file('image')->store('outreach', 'public');

        // Build the URL from the host the request came in on rather than a
        // possibly misconfigured APP_URL, so the image is reachable by whoever
        // (a mail client, the live preview) loads the email.
        return $this->success(['url' => $request->getSchemeAndHttpHost() . '/storage/' . $path]);
    }

    #[OA\Post(
        path: '/admin/outreach/send',
        summary: 'Send a personalized outreach email to an audience',
        tags: ['Admin'],
        responses: [
            new OA\Response(response: 200, description: 'Sent'),
            new OA\Response(response: 403, description: 'Not an admin'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'url', 'max:2000', 'required_with:cta_label'],
            'image_url' => ['nullable', 'string', 'max:2000'],
            'audience' => ['required', 'string', 'in:all,active,inactive,test,specific'],
            'user_ids' => ['required_if:audience,specific', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'mimes:' . FileService::ALLOWED_EXTENSIONS, 'max:' . FileService::MAX_KILOBYTES],
        ]);

        $attachments = array_map(fn ($file) => [
            'path' => $file->getRealPath(),
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
        ], $request->file('files', []));

        $recipients = $this->resolveAudience($data['audience'], $request->user(), $data['user_ids'] ?? []);

        foreach ($recipients as $user) {
            Mail::to($user->email)->send(new OutreachMail(
                $this->personalize($data['subject'], $user),
                $this->personalize($data['body'], $user),
                $data['cta_label'] ?? null,
                $data['cta_url'] ?? null,
                $data['image_url'] ?? null,
                $attachments,
            ));
        }

        Outreach::create([
            'user_id' => $request->user()->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'audience' => $data['audience'],
            'recipients' => $recipients->count(),
            'sent' => $recipients->count(),
        ]);

        return $this->success(
            ['sent' => $recipients->count()],
            __('Outreach sent to :count recipients', ['count' => $recipients->count()])
        );
    }

    #[OA\Get(
        path: '/admin/outreach',
        summary: 'List past outreaches',
        tags: ['Admin'],
        responses: [new OA\Response(response: 200, description: 'Outreach history')]
    )]
    public function index(): JsonResponse
    {
        $outreaches = Outreach::query()->latest()->limit(50)->get([
            'id', 'subject', 'audience', 'recipients', 'sent', 'created_at',
        ]);

        return $this->success($outreaches);
    }

    /**
     * Resolve a named audience to its users. Segments are host-defined here;
     * a future package will let these be configured rather than hard-coded.
     *
     * @param  int[]  $userIds
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveAudience(string $audience, User $actor, array $userIds = []): \Illuminate\Support\Collection
    {
        return match ($audience) {
            'test' => collect([$actor]),
            'specific' => User::query()->whereIn('id', $userIds)->get(),
            'active' => User::query()->whereHas('transactions', function ($q) {
                $q->where('datetime', '>=', now()->subDays(30));
            })->get(),
            'inactive' => User::query()->whereDoesntHave('transactions', function ($q) {
                $q->where('datetime', '>=', now()->subDays(30));
            })->get(),
            default => User::query()->get(),
        };
    }

    private function personalize(string $text, User $user): string
    {
        $name = trim("{$user->first_name} {$user->last_name}");

        return strtr($text, [
            '{{first_name}}' => $user->first_name ?? '',
            '{{last_name}}' => $user->last_name ?? '',
            '{{name}}' => $name !== '' ? $name : ($user->first_name ?? ''),
            '{{email}}' => $user->email,
        ]);
    }
}

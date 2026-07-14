<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Http\Controllers\API\ApiController;
use App\Mail\OutreachMail;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Whilesmart\Outreach\Contracts\MessageRenderer;
use Whilesmart\Outreach\Models\Outreach;
use Whilesmart\Outreach\Services\OutreachDispatcher;

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

        $draft = new Outreach([
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'] ?? '',
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
        ]);

        $rendered = app(MessageRenderer::class)->render($draft, $request->user());

        $mail = new OutreachMail(
            $rendered->subject ?: __('Subject'),
            $rendered->body,
            $rendered->ctaLabel,
            $rendered->ctaUrl,
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

        $attachments = array_map(function ($file) {
            $stored = $file->store('outreach/attachments', 'local');

            return [
                'path' => Storage::disk('local')->path($stored),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ];
        }, $request->file('files', []));

        $outreach = $request->user()->outreaches()->create([
            'channel' => 'email',
            'subject' => $data['subject'],
            'body' => $data['body'],
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'audience' => [
                'mode' => $data['audience'],
                'user_ids' => $data['user_ids'] ?? [],
                'actor_id' => $request->user()->id,
            ],
            'metadata' => [
                'image_url' => $data['image_url'] ?? null,
                'attachments' => $attachments,
            ],
        ]);

        $outreach = app(OutreachDispatcher::class)->dispatch($outreach);
        $sent = $outreach->stats['sent'] ?? 0;

        return $this->success(
            ['sent' => $sent],
            __('Outreach sent to :count recipients', ['count' => $sent])
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
        $outreaches = Outreach::query()->latest()->limit(50)->get()->map(fn (Outreach $o) => [
            'id' => $o->id,
            'subject' => $o->subject,
            'audience' => $o->audience['mode'] ?? null,
            'recipients' => $o->stats['recipients'] ?? 0,
            'sent' => $o->stats['sent'] ?? 0,
            'image_url' => $o->metadata['image_url'] ?? null,
            'created_at' => $o->created_at,
        ]);

        return $this->success($outreaches);
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Transport;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Server\Contracts\Transport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseTransport implements Transport
{
    public function __construct(
        protected Request $request,
        protected string $sessionId,
        protected ?Closure $handler = null,
        protected ?Closure $stream = null,
        protected ?string $reply = null,
        protected ?string $replySessionId = null,
    ) {
    }

    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function send(string $message, ?string $sessionId = null): void
    {
        $session = $sessionId ?? $this->sessionId;

        // Check if there is an active SSE stream listener for this session
        if (Cache::has("mcp:active_session:{$session}")) {
            $key = "mcp:sse:{$session}";
            $existing = Cache::get($key, []);
            $existing[] = $message;
            Cache::put($key, $existing, 60);

            $this->reply = null;
            $this->replySessionId = $session;
        } else {
            // Fallback for synchronous stateless HTTP POST
            if ($this->stream instanceof Closure) {
                $this->sendStreamMessage($message);
            }

            $this->reply = $message;
            $this->replySessionId = $session;
        }
    }

    public function run(): Response|StreamedResponse
    {
        if ($this->request->isMethod('get')) {
            // Establish SSE event stream
            return response()->stream(function (): void {
                $sessionKey = "mcp:active_session:{$this->sessionId}";
                Cache::put($sessionKey, true, 60);

                // Send the endpoint event
                $endpointUrl = route('mcp.sse.post');
                $this->sendStreamMessage("{$endpointUrl}?sessionId={$this->sessionId}", 'endpoint');

                $lastHeartbeat = time();
                $msgKey = "mcp:sse:{$this->sessionId}";

                while (connection_aborted() === 0) {
                    // Keep TTL active
                    Cache::put($sessionKey, true, 60);

                    // Process pending messages
                    if (Cache::has($msgKey)) {
                        $messages = Cache::pull($msgKey);
                        if (is_array($messages)) {
                            foreach ($messages as $message) {
                                $this->sendStreamMessage((string) $message, 'message');
                            }
                        }
                    }

                    // Heartbeat to prevent gateway timeout
                    if (time() - $lastHeartbeat >= 15) {
                        echo ": ping\n\n";
                        if (ob_get_level() !== 0) {
                            ob_flush();
                        }
                        flush();
                        $lastHeartbeat = time();
                    }

                    usleep(100000); // 100ms
                }

                Cache::forget($sessionKey);
                Cache::forget($msgKey);
            }, 200, $this->getHeaders());
        }

        // Handle POST request
        if (is_callable($this->handler)) {
            ($this->handler)($this->request->getContent());
        }

        if ($this->stream instanceof Closure) {
            $stream = $this->stream;

            return response()->stream(function () use ($stream): void {
                $result = $stream();

                if (! is_iterable($result)) {
                    return;
                }

                foreach ($result as $message) {
                    if (connection_aborted() !== 0) {
                        return;
                    }

                    $this->sendStreamMessage((string) $message);
                }
            }, 200, $this->getHeaders());
        }

        // If reply is null (sent to cache event stream), return 202 Accepted
        $statusCode = $this->reply === null ? 202 : 200;
        $response = response($this->reply, $statusCode, $this->getHeaders());

        return $response;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function stream(Closure $stream): void
    {
        $this->stream = $stream;
    }

    protected function sendStreamMessage(string $message, ?string $event = null): void
    {
        if ($event !== null) {
            echo "event: {$event}\n";
        }
        echo "data: {$message}\n\n";

        if (ob_get_level() !== 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        $isSse = $this->request->isMethod('get');

        $headers = [
            'Content-Type' => $isSse ? 'text/event-stream' : 'application/json',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];

        if ($this->replySessionId !== null) {
            $headers['MCP-Session-Id'] = $this->replySessionId;
        }

        return $headers;
    }
}

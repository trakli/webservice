# Trakli MCP Integration Guide

Connect AI clients to your Trakli personal finance data via the Model Context Protocol (MCP).

---

## Quickstart

### 1. Generate an API Token

```bash
# Create a Sanctum token for MCP access
php artisan mcp:token --name=claude-desktop
```

Alternatively, create a token via the API:

```bash
curl -X POST https://your-trakli.app/api/tokens \
  -H "Authorization: Bearer $USER_TOKEN" \
  -d '{"name": "claude-desktop", "abilities": ["mcp:access"]}'
```

### 2. Configure Claude Desktop

Edit `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "trakli": {
      "type": "sse",
      "url": "https://your-trakli.app/mcp/sse",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN",
        "MCP-Protocol-Version": "2025-06-18"
      }
    }
  }
}
```

### 3. Verify Connection

```bash
# Check server info and capabilities
curl -X POST https://your-trakli.app/mcp/initialize \
  -H "Authorization: Bearer YOUR_MCP_TOKEN" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "method": "initialize", "params": {}, "id": 1}'
```

Expected response includes server name, version, protocol version, and capability manifest.

---

## Authentication

### Token-Based Auth (Current)

- Use Laravel Sanctum tokens
- Pass token in `Authorization: Bearer <token>` header
- Token must have `mcp:access` ability (configurable via `config/mcp.php` `auth.guard`)
- Tokens can be scoped to specific MCP permissions

### Token Management

```bash
# List tokens
php artisan mcp:tokens

# Revoke token
php artisan mcp:token:revoke --id=<token-id>
```

### Permission Scopes

Each MCP operation maps to a permission that is checked against Laravel Gates:

| Permission           | Gate                | Operations                     |
|----------------------|---------------------|--------------------------------|
| `transactions.read`  | `transactions.view` | Search, list, view transactions |
| `transactions.write` | `transactions.manage` | Create, update, delete transactions |
| `budgets.read`       | `budgets.view`      | List budgets, view progress     |
| `budgets.write`      | `budgets.manage`    | Create, update, delete budgets  |
| `wallets.read`       | `wallets.view`      | List wallets, check balances    |
| `wallets.write`      | `wallets.manage`    | Create, update wallets          |
| `categories.read`    | `categories.view`   | View categories                 |
| `reports.read`       | `reports.view`      | Access financial reports        |
| `insights.read`      | `insights.view`     | Access transaction insights     |

---

## SSE Connection Lifecycle

### Connection Flow

```
Client                    Server
  |                         |
  |--- GET /mcp/sse ------->|  (1) Establish SSE stream
  |<-- event: endpoint -----|  (2) Receive message endpoint
  |                         |
  |--- POST {endpoint} ---->|  (3) Send JSON-RPC messages
  |<-- event: message -----|  (4) Receive responses/events
```

### Message Format (JSON-RPC 2.0)

```json
// Request
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "transactions.search",
    "arguments": {
      "type": "expense",
      "limit": 10
    }
  },
  "id": 1
}

// Response
{
  "jsonrpc": "2.0",
  "result": {
    "transactions": [...],
    "total": 42,
    "page": 1
  },
  "id": 1
}
```

### Reconnection

1. Client detects SSE stream closure
2. Client waits with exponential backoff (1s, 2s, 4s, 8s, max 30s)
3. Client reconnects with `GET /mcp/sse`
4. Server returns a new `endpoint` event with new session ID
5. Client resumes sending JSON-RPC messages

**Important:** Session state (e.g., initialized context) may be lost on reconnection. Clients must re-initialize after reconnect unless the server supports session resumption.

---

## Transports

### SSE (Server-Sent Events) — Current

- Single connection per session
- Server pushes events to client
- Client sends JSON-RPC via POST to message endpoint
- Best for: persistent connections, streaming results

### HTTP POST — Supported

- Stateless request-response
- Send JSON-RPC via `POST /mcp/sse` (also accepts POST)
- Best for: single-shot queries, tools without streaming

---

---

## Capabilities

This server registers zero tools, resources, and prompts by default.  
Capabilities are added by registering tool/resource/prompt classes in `TrakliMcpServer` or via the plugin system when third-party plugins are installed.

See `docs/MCP_API_REFERENCE.md` (when available) for the full capability manifest.

---

## Error Handling

### Error Response Format

```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32603,
    "message": "Internal error",
    "data": {
      "permission": "transactions.write"
    }
  },
  "id": 1
}
```

### Common Error Codes

| Code | Meaning | Description |
|------|---------|-------------|
| -32700 | Parse Error | Invalid JSON was received |
| -32600 | Invalid Request | JSON is not a valid request object |
| -32601 | Method Not Found | Method does not exist |
| -32602 | Invalid Params | Invalid method parameter(s) |
| -32603 | Internal Error | Internal JSON-RPC error |
| -32001 | Permission Denied | Token lacks required permission |
| -32002 | Rate Limited | Too many requests |
| -32003 | Not Found | Requested resource not found |
| -32004 | Validation Error | Input validation failed |
| -32099 | Plugin Error | Error from a plugin |

---

## Rate Limiting

Default: **60 requests per minute** (configurable via `MCP_RATE_LIMIT_MAX`).

Headers on rate-limit violation:
```
Retry-After: 60
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
```

---

## Pagination

Tools that return lists support cursor-based pagination:

```json
{
  "transactions": [...],
  "pagination": {
    "next_cursor": "eyJpZCI6MTUwfQ==",
    "has_more": true,
    "total": 250
  }
}
```

Pass `cursor` parameter to get the next page:
```json
{
  "arguments": {
    "cursor": "eyJpZCI6MTUwfQ==",
    "limit": 10
  }
}
```

---

## Cursor IDE Configuration

```json
{
  "mcpServers": {
    "trakli": {
      "type": "sse",
      "url": "https://your-trakli.app/mcp/sse",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN"
      }
    }
  }
}
```

---

## Troubleshooting

### Connection Issues

| Symptom | Likely Cause | Solution |
|---------|-------------|----------|
| 401 Unauthorized | Invalid or expired token | Generate new Sanctum token |
| 400 Bad Request | Unsupported protocol version | Send `MCP-Protocol-Version: 2025-06-18` header |
| 429 Too Many Requests | Rate limit exceeded | Wait and retry, or increase `MCP_RATE_LIMIT_MAX` |
| 403 Forbidden | Missing permission | Check token abilities and user permissions |
| SSE disconnects | Network timeout | Implement reconnection with backoff |

### Debugging

1. **Check server health:**
   ```bash
   curl https://your-trakli.app/mcp/inspect \
     -H "Authorization: Bearer YOUR_MCP_TOKEN"
   ```

2. **Test initialize handshake:**
   ```bash
   curl -X POST https://your-trakli.app/mcp/initialize \
     -H "Authorization: Bearer YOUR_MCP_TOKEN" \
     -H "MCP-Protocol-Version: 2025-06-18"
   ```

3. **List registered plugins:**
   ```bash
   php artisan mcp:plugins --format=json
   ```

4. **Verify plugin system:**
   ```bash
   php artisan mcp:plugins --verify
   ```

---

## Environment Configuration

```env
MCP_ENABLED=true
MCP_ENDPOINT=/mcp/sse
MCP_AUTH_GUARD=sanctum
MCP_RATE_LIMIT_ENABLED=true
MCP_RATE_LIMIT_MAX=60
MCP_RATE_LIMIT_DECAY=1
MCP_STRICT_PERMISSIONS=false
```

---

## Security Considerations

1. **Token Rotation**: Rotate MCP tokens regularly. Implement token refresh endpoints for long-lived SSE connections.
2. **HTTPS Only**: Never expose the MCP endpoint over plain HTTP in production.
3. **Permission Scoping**: Grant tokens minimal required permissions using Gates/Policies.
4. **Rate Limiting**: Always enable rate limiting in production to prevent abuse.
5. **SSE Connection Limits**: Monitor concurrent SSE connections; nginx may need `proxy_buffering off;` for SSE to work.
6. **Audit Logging**: All MCP operations are logged via `Log::info()` at the plugin manager level.
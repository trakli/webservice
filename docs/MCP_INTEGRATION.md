# Trakli MCP Integration Guide

Connect an AI client (Claude Desktop, Cursor) to your Trakli finance data over
the Model Context Protocol (MCP).

---

## Quickstart

### 1. Get a token

In the Trakli app open **Settings → Connected AI clients**, generate a token,
and copy it. The token is shown only once. That screen also shows the MCP
endpoint URL for your instance (for example `https://your-trakli.app/mcp`).

### 2. Configure your client

Point the client at the endpoint and pass the token as a bearer token. For
Claude Desktop, edit `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "trakli": {
      "url": "https://your-trakli.app/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN"
      }
    }
  }
}
```

The client discovers the available tools automatically on connect.

---

## Transport and authentication

- One endpoint, `POST /mcp`, using the Streamable HTTP transport. `GET` and
  `DELETE` on the path return `405`.
- Authentication is a Laravel Sanctum bearer token, issued from the settings
  screen (or the `POST /api/v1/ai/mcp/tokens` endpoint). Every request acts as
  the token's owner and sees only that user's data.
- The server is opt-in per instance via `MCP_ENABLED`. Rate limiting is on by
  default (`MCP_RATE_LIMIT_*`).

---

## Tools

Read tools are available to any connected client; write tools additionally
require the user's write permission (see Permissions).

| Tool | Access | Purpose |
|------|--------|---------|
| `list-wallets` | read | Wallets with balances |
| `list-transactions` | read | Recent transactions, filterable by type |
| `list-categories` | read | The user's categories |
| `list-parties` | read | The user's parties |
| `get-stats` | read | Pre-computed analytics (balances, cash flow, net worth) |
| `create-wallet` | write | Create a wallet |
| `record-transaction` | write | Record an income or expense |

Tools reuse the same validation, ownership, and limit checks as the REST API,
so a client cannot exceed what the user could do in the app.

---

## Permissions

Write access maps to Laravel Gates defined in `config/mcp.php`. By default a
user may write their own data. To restrict or scope access, an operator defines
the mapped gate (for example `transactions.manage`) in a policy or service
provider; when present it takes over the decision.

---

## Plugins

Packages can contribute additional MCP tools, resources, and prompts by
implementing the plugin contract. Inspect what is registered with:

```bash
php artisan mcp:plugins           # list registered plugins
php artisan mcp:plugins --verify  # validate plugin contracts
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| 401 Unauthorized | Missing or revoked token | Generate a new token in settings |
| 404 Not Found | MCP disabled on the instance | Set `MCP_ENABLED=true` |
| 405 on GET | Using the wrong method | The endpoint is `POST /mcp` |
| 429 Too Many Requests | Rate limit hit | Wait, or raise `MCP_RATE_LIMIT_MAX` |
| A write tool returns "Permission denied" | A gate forbids the write | Review the mapped gate for that permission |

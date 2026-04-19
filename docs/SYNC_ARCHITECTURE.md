# Trakli Sync Architecture

## Overview

Trakli implements a **bidirectional, timestamp-based REST synchronization system** with offline-first architecture. The mobile app uses a custom `drift_sync_core` framework with local SQLite storage, while the webservice provides polymorphic sync state tracking via Laravel.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         MOBILE APP                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────┐  │
│  │   UI Layer   │◄───│  SyncCubit   │◄───│  SynchAppDatabase │  │
│  └──────────────┘    └──────────────┘    └────────┬─────────┘  │
│                                                    │             │
│  ┌──────────────┐    ┌──────────────┐             │             │
│  │ LocalChanges │◄───│ SyncHandlers │◄────────────┘             │
│  │    Queue     │    │ (per entity) │                           │
│  └──────────────┘    └──────┬───────┘                           │
│                             │                                    │
│  ┌──────────────────────────▼───────────────────────────────┐   │
│  │              Drift Database (SQLite)                      │   │
│  │  transactions | wallets | categories | parties | groups   │   │
│  └───────────────────────────────────────────────────────────┘   │
└─────────────────────────────────┬───────────────────────────────┘
                                  │ REST API
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│                        WEBSERVICE                                │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────┐  │
│  │  API Routes  │───►│ Controllers  │───►│    Services      │  │
│  └──────────────┘    └──────────────┘    └──────────────────┘  │
│                                                    │             │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              Models with Syncable Trait                   │   │
│  │  Transaction | Wallet | Category | Party | Group          │   │
│  └───────────────────────────────────────────────────────────┘   │
│                             │                                    │
│  ┌──────────────────────────▼───────────────────────────────┐   │
│  │              ModelSyncState (Polymorphic)                 │   │
│  │  syncable_type | syncable_id | client_id | last_synced_at │   │
│  └───────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

## Synchronized Entities

| Entity | Mobile Handler | Server Controller | Dependencies | Notes |
|--------|----------------|-------------------|--------------|-------|
| **Wallet** | `WalletSyncHandler` | `WalletController` | None | |
| **Category** | `CategorySyncHandler` | `CategoryController` | None | |
| **Party** | `PartySyncHandler` | `PartyController` | None | |
| **Group** | `GroupSyncHandler` | `GroupController` | None | |
| **Transaction** | `TransactionSyncHandler` | `TransactionController` | Wallet, Category, Party, Group | |
| **Config** | `ConfigSyncHandler` | - | None | |
| **Transfer** | - | `TransferController` | Wallet | |
| **Budget** | `BudgetSyncHandler` | `BudgetController` | Category, Group, Wallet | User-authored. Polymorphic owner (`owner_type` + `owner_id`), polymorphic targets via `budgetables`. |
| **Refund** | `RefundSyncHandler` | `TransactionRefundController` | Transaction | See [Refund pattern](#refund-decorator-pattern). |
| **BudgetPeriodState** | `BudgetPeriodStateSyncHandler` | `BudgetController::periodStates` | Budget | Read-only from the client. See [Server-authored entities](#server-authored-entities). |

## Special Sync Patterns

Not every Syncable entity follows the straight "POST → GET" REST shape.
These patterns apply to newer domain entities and are the reference for
future additions.

### Refund decorator pattern

A `Refund` is a row that marks an income `Transaction` as refunding money
back from a prior expense. It is Syncable because clients can create
one offline, but it has two peculiarities:

- **There is no top-level `POST /refunds`.** Refunds are always attached
  to an existing Transaction, so the create endpoint is imperative and
  transaction-scoped: `POST /transactions/{id}/refund`.
- **The payload accepts both server ids and client ids.** When a client
  marks a refund offline, the expense it's linked to may also still be
  offline. The body accepts either `original_transaction_id` (server id)
  or `original_client_id` (client id); the server resolves whichever is
  present.
- **List sync uses `GET /refunds`** with the standard
  `synced_since` / `limit` / `no_client_id` filters.
- **Idempotency:** `POST /transactions/{id}/refund` uses `updateOrCreate`
  keyed on `refund_transaction_id`, so replaying the same mark twice is
  safe.

### Server-authored entities

`BudgetPeriodState` is Syncable, but **the client never POSTs one**.
Period states are created exclusively by `CloseBudgetPeriodJob` — either
on the scheduler's daily tick or in response to a manual close (`POST
/budgets/{id}/close-period`). Three things happen atomically inside that
job: the closed row is written with server-computed `net_spent` and
`rollover_out`, the next period's `rollover_in` is seeded, and a
`BudgetPeriodClosed` event is dispatched.

The client's role is therefore read-only for this table:

- `GET /budget-period-states?synced_since=…` pulls new rows for the
  user's visible budgets.
- `client_generated_id` stays `null` because the server owns creation.
- `last_synced_at` is populated via `Syncable::bootSyncable()` when the
  server writes the row, so `synced_since` filtering works normally.
- To trigger a close offline, the client queues the imperative
  `POST /budgets/{id}/close-period` action (same shape as any other
  queued write). On replay the server does the three-step close atomically
  and the client picks up the authoritative row on the next download.

This pattern — Syncable table + server-only writes + a separate
imperative action endpoint — is the right fit for any entity whose
creation is an outcome of a coordinated server operation rather than a
user-submitted resource.

### Polymorphic owners (Budget)

`Budget.owner_id` + `owner_type` is a morphTo so a budget can belong to
a `User` today and a `Workspace` / `Couple` tomorrow. Sync-time
implications:

- **Writes** accept an optional `owner: {type, id}` block. It defaults
  to the authenticated user.
- **Reads** go through `Budget::visibleTo($user)` which consults the
  `OwnerResolver` contract to produce the set of owner records the user
  can see. Today that resolves to just `(User, $user->id)`; new owner
  types drop in by registering another resolver.
- **Mobile** mirrors this by storing `ownerType` + `ownerClientId` on
  the local Budgets table rather than a `userId` FK. Until a second
  owner type exists, `ownerType` is always `'user'` on the wire.

### Polymorphic targets (Budget)

A budget targets Categories, Groups, and Wallets through a single
`budgetables` pivot (`budgetable_type` + `budgetable_id`). The API
flattens this to a `targets: [{type, id, client_generated_id, name}]`
array on Budget payloads so the client doesn't need to traverse a pivot
table.

On create/update, `targets: [{type, id}]` in the body is accepted; mixed
types are fine. The server diffs per type and only syncs pivots that
changed.

## Sync Protocol

### Phase 1: Upload Local Changes (Mobile → Server)

```
Mobile                                    Server
  │                                         │
  │──── GET pending changes from queue ────►│
  │                                         │
  │ For each pending change:                │
  │──── PUT /api/v1/{entity}/{id} ────────►│
  │     { client_id, data... }              │
  │                                         │──── Validate client_id format
  │                                         │──── DB transaction
  │                                         │──── Create/Update entity
  │                                         │──── setClientGeneratedId()
  │                                         │──── markAsSynced()
  │◄─── { success, data, last_synced_at } ──│
  │                                         │
  │──── Mark change as concluded ──────────►│
  │                                         │
```

### Phase 2: Download Server Changes (Server → Mobile)

```
Mobile                                    Server
  │                                         │
  │──── GET /api/v1/{entity}?synced_since={timestamp} ──►│
  │                                         │──── Parse ISO 8601 timestamp
  │                                         │──── WHERE updated_at > timestamp
  │                                         │──── Include soft-deleted (withTrashed)
  │◄─── { data: [...], last_sync, pagination } ─────────│
  │                                         │
  │──── Upsert to local DB ────────────────►│
  │──── Update local sync metadata ────────►│
  │                                         │
```

## Client ID Generation

The system uses a dual-UUID format for client-generated IDs:

**Format:** `{device_uuid}:{entity_uuid}`

### Mobile Generation
```dart
Future<TransactionCompleteDto> assignClientId(entity) async {
  final deviceId = await _deviceService.getDeviceId();
  final entityId = const Uuid().v4();
  return entity.copyWith(clientId: '$deviceId:$entityId');
}
```

### Server Validation
```php
public function validate(string $attribute, mixed $value, Closure $fail): void {
    $parts = explode(':', $value);
    if (count($parts) !== 2) {
        $fail('Invalid client_id format');
    }
    if (!Str::isUuid($parts[0]) || !Str::isUuid($parts[1])) {
        $fail('Invalid UUID format');
    }
}
```

## Timestamp Handling

### Supported Formats
- `2025-04-30T15:17:54.120Z` (milliseconds)
- `2025-04-30T15:17:54Z` (no milliseconds)
- `2025-06-02T15:17:54+00:00` (ATOM format)

### Sync Metadata Storage

| Location | Table | Key Fields |
|----------|-------|------------|
| Mobile | `syncMetadata` | `entityType`, `lastSyncedAt` |
| Server | `model_sync_states` | `syncable_type`, `syncable_id`, `last_synced_at` |

## Conflict Resolution

The system uses **Last-Write-Wins (LWW)** with client-side responsibility:

1. Client saves change locally
2. Client uploads to server
3. Server returns updated entity with timestamps
4. Client compares returned data with local expectation
5. If HTTP 409 → `ConflictException` thrown
6. Server version wins (re-downloaded in Phase 2)

## Offline Support

### Local Change Queue Schema
```
┌─────────────────────────────────────────────────────────┐
│                    localChanges Table                    │
├──────────────┬───────────────────────────────────────────┤
│ entityType   │ "transaction"                             │
│ entityId     │ "{device_uuid}:{entity_uuid}"            │
│ entityRev    │ "1" (for optimistic concurrency)         │
│ deleted      │ false                                     │
│ data         │ { serialized entity JSON }               │
│ createMoment │ 2025-12-26T10:30:45.123Z                 │
│ concluded    │ false (pending) / true (processed)       │
│ error        │ null / "Error message"                   │
│ dismissed    │ false (user hasn't dismissed error)      │
└──────────────┴───────────────────────────────────────────┘
```

### Network State Monitoring
```dart
_internetChecker.onStatusChange.listen((status) {
  if (!wasConnected && _isConnected) {
    _syncTiming.resetTiming();
    await performSync(syncFunction);
  }
});
```

## Retry Logic

Exponential backoff with the following parameters:

| Parameter | Value |
|-----------|-------|
| Base Delay | 2 seconds |
| Max Delay | 30 minutes |
| Max Attempts | 10 |
| Exponential Factor | 1.5 |

```
┌─────────────────────────────────────────────────────────┐
│ Attempt │ Delay    │ Total Wait                        │
├─────────┼──────────┼───────────────────────────────────┤
│    1    │ 2s       │ 2s                                │
│    2    │ 3s       │ 5s                                │
│    3    │ 4.5s     │ 9.5s                              │
│    4    │ 6.75s    │ 16.25s                            │
│    5    │ 10.1s    │ 26.35s                            │
│   ...   │ ...      │ ...                               │
│   10    │ 30m (max)│ Max attempts reached              │
└─────────┴──────────┴───────────────────────────────────┘
```

## Dependency Management

Sync order is managed to prevent orphan records:

```dart
Map<String, Set<String>> get dependencies => {
  'transaction': {'category', 'wallet', 'party', 'group'},
  'category': {},
  'wallet': {},
  'party': {},
  'group': {},
};
```

**Execution Flow:**
1. Sync: wallet, category, party, group (parallel-safe)
2. Wait for completion
3. Sync: transaction (depends on all above)

## Sync Triggers

| Trigger | Location | Mechanism |
|---------|----------|-----------|
| **On Login** | `app_widget.dart` | `AuthCubit` listener |
| **Periodic** | `sync_database.dart` | 5-minute timer |
| **Pull-to-Refresh** | Various screens | `RefreshIndicator` |
| **Network Restore** | `network_sync_mixin.dart` | `InternetConnection` listener |
| **Manual** | Profile screen | Button tap |

## API Endpoints

| Endpoint | Method | Sync Query Params | Purpose |
|----------|--------|-------------------|---------|
| `/api/v1/wallets` | GET | `synced_since`, `limit`, `no_client_id` | Fetch wallet changes |
| `/api/v1/wallets` | POST/PUT | - | Create/update wallet |
| `/api/v1/transactions` | GET | `synced_since`, `limit`, `no_client_id` | Fetch transaction changes |
| `/api/v1/transactions` | POST/PUT | - | Create/update transaction |
| `/api/v1/categories` | GET | `synced_since`, `limit`, `no_client_id` | Fetch category changes |
| `/api/v1/parties` | GET | `synced_since`, `limit`, `no_client_id` | Fetch party changes |
| `/api/v1/groups` | GET | `synced_since`, `limit`, `no_client_id` | Fetch group changes |

### Response Format
```json
{
  "success": true,
  "data": [...],
  "last_sync": "2025-12-26T10:30:45.000000Z",
  "current_page": 1,
  "total": 100,
  "per_page": 20,
  "last_page": 5
}
```

## Error Handling

| Error Type | HTTP Code | Mobile Exception | Recovery |
|------------|-----------|------------------|----------|
| Network timeout | - | `UnavailableException` | Exponential backoff retry |
| No connection | - | `UnavailableException` | Wait for network restore |
| Conflict | 409 | `ConflictException` | Log, server wins on download |
| Not found | 404 | `NotFoundException` | Mark as error, skip |
| Validation | 422 | Generic | Store error, user can dismiss |
| Server error | 500 | Generic | Store error, retry later |
| Unauthorized | 401 | - | Stop sync, re-auth required |

## Key Implementation Files

### Mobile
| Component | Path |
|-----------|------|
| Core Sync Engine | `drift_sync_core/lib/src/drift_synchronizer.dart` |
| App Implementation | `lib/core/sync/sync_database.dart` |
| Network Handling | `lib/core/sync/network_sync_mixin.dart` |
| Retry Logic | `lib/core/sync/sync_timing.dart` |
| Local Database | `lib/data/database/app_database.dart` |
| Change Queue | `drift_sync_core/lib/src/local_change.dart` |
| REST Adapter | `drift_sync_core/lib/src/adapters/rest/rest_sync_type_handler.dart` |
| Entity Handlers | `lib/data/sync/*_sync_handler.dart` |

### Webservice
| Component | Path |
|-----------|------|
| Syncable Trait | `app/Traits/Syncable.php` |
| API Controllers | `app/Http/Controllers/API/v1/*Controller.php` |
| Query Helpers | `app/Http/Traits/ApiQueryable.php` |
| Client ID Validation | `app/Rules/ValidateClientId.php` |
| DateTime Validation | `app/Rules/Iso8601DateTime.php` |
| Sync State Model | `app/Models/ModelSyncState.php` |

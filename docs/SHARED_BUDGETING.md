# Shared budgeting data model

Shared budgeting lets a couple, household, family, or other group plan money together while keeping personal finances private. Every shared space is the same kind of workspace. Audience names belong in marketing and user-chosen names, rather than stored finance types.

## Ownership and access

A record has one owner. Sharing grants another workspace visibility without moving or copying the record.

- A personal wallet, category, or spending group belongs to its user.
- A workspace-owned wallet, category, or spending group has a workspace and no personal owner. Its creator is recorded separately.
- A transaction and its wallet have the same owner. A transfer and both wallets must also have the same owner. Ownership cannot change after creation.
- Wallets, transactions, categories, spending groups, parties, transfers, reminders, file imports and import sessions use the same polymorphic owner. Budgets and holdings already use polymorphic owners.
- Workspace membership controls access to the space. Roles control management actions. Entitlements control whether the space can accept financial changes.

The existing `groups` table contains spending labels. A group of people is a workspace, not a row in that table.

The existing finance tables store `owner_type` and `owner_id`. For example, a user-owned wallet stores `App\Models\User` and the user's ID; a shared wallet stores `App\Models\Workspace` and the workspace's ID. Every query must constrain both columns because a user and workspace can have the same numeric ID.

| Tables | Columns added | Columns removed | Purpose |
| --- | --- | --- | --- |
| `wallets`, `transactions`, `categories`, `groups`, `parties`, `transfers`, `reminders`, `file_imports`, `import_sessions` | `owner_type`, `owner_id` | `user_id` | One required owner, either a user or workspace |
| `wallets`, `transactions`, `categories`, `groups` | `created_by_user_id` (nullable) | None | Record the person creating a shared record independently of ownership |

Owner indexes replace user-only indexes. Wallet and label slugs are unique within an owner. Reminder and import-session status indexes include both owner columns.

The personal API retains a derived `user_id` for existing clients. Workspace-owned records have no personal user ID. IDs, transaction values, wallet balances, sync types and client-generated IDs stay unchanged. Assistant reads select public columns and pin both owner columns. Chat authors, login identity and notification recipients remain user-specific.

User deletion removes that user's finance records. Workspace-owned records survive the creator's departure or account deletion. Soft-deleting a workspace retains its financial history; permanently deleting a workspace with financial records is refused. Workspace reminders notify current members of an active space. The shared UI still exposes the existing wallet, transaction, label and budget flows; this ownership change does not add workspace import or transfer screens.

## Additional tables

| Table | What one row means | Uniqueness |
| --- | --- | --- |
| `workspace_wallet_shares` | A personal wallet is shared with a workspace, including its visible transactions | Workspace and wallet |
| `workspace_transaction_shares` | One personal transaction is shared with a workspace | Workspace and transaction |
| `workspace_transaction_categories` | A transaction has a category in a particular workspace | Workspace, transaction, and category |
| `workspace_transaction_groups` | A transaction has a spending-group label in a particular workspace | Workspace, transaction, and spending group |

The two sharing tables retain the sharing user's identity so leaving or removal can revoke that person's grants. Their foreign keys remove links when the referenced resources are physically deleted.

Wallet and transaction grants stay separate because they grant different access. Sharing one grocery purchase must not reveal the wallet name, balance, or other purchases. Sharing the wallet includes its transactions without copying them. If both grants expose a transaction, the workspace still counts that transaction once.

Classification links stay separate from personal classification. The same personal purchase can have a personal category and different workspace categories. The workspace must not mutate the person's labels or reveal its own labels through personal transaction responses. A classification link grants no access by itself: financial queries first select transactions visible to the workspace.

Each classification link references its workspace, transaction, and label. The API also checks that the label belongs to that workspace; ordinary foreign keys alone do not enforce that relationship.

## Revocation and history

Leaving or removing a member revokes that person's personal wallet and transaction grants. Workspace-owned records remain with the workspace, independently of their creator's membership.

Revoking a wallet also revokes individually shared transactions from that wallet in the same workspace. Current budget progress is recomputed from the remaining visible records. Closed budget periods retain their saved historical totals.

Unsharing and leaving remain available when a space cannot accept new financial changes. Revocation does not depend on an active subscription.

## Compatibility and rollout

Workspace creation no longer requires or stores a `family` versus `group` discriminator. The API and assistant omit that field, and the UI offers one shared-space form. Older callers may still send `kind`; it is ignored. Historical metadata with that key is inert and needs no data rewrite for this change.

The ownership migration copies every existing `user_id` into `owner_id`, sets `owner_type` to `App\Models\User`, and replaces the old owner column and indexes. Active and soft-deleted records retain their IDs and financial values. The shared-finance migration then adds creation tracking, sharing grants and workspace classification links.

Deployment requires a maintenance window because old and new API versions use different database columns:

1. Back up the database and uploaded files, and restore the backup into an isolated rehearsal environment.
2. Record row counts, financial totals by currency, wallet balances, sync identities and unresolved transaction/wallet ownership mismatches. Reconcile mismatches before deployment.
3. Rehearse the full migration with the target database engine, compare the recorded values, and test personal and shared API access. Confirm the backup can be restored.
4. Stop API writes, scheduled tasks and workers. Take a final backup. Deploy matching API and worker revisions and run the migrations while writes remain stopped.
5. Compare the same data and access checks before resuming workers and traffic. Keep the backup until the release is accepted.

Rollback restores user-only columns and constraints only when every finance record still belongs to a user. It refuses non-user owners before altering any table. Once shared records exist, restore the complete backup and matching application version instead of discarding shared ownership.

## Package boundaries

The feature already uses the shared workspace, roles, and entitlements packages. Finance-specific visibility, classification, and recomputation stay in Trakli for this release.

The owner relation and owner-scoped queries can move into a shared package without changing owner identities. Sharing and contextual classification remain separate contracts. A future personal-finance or accounting backend can reuse a package for ownership-aware sharing and visibility. Such a package should own grants, revocation, and scoped queries. Each application should provide its resource models, membership rules, and financial calculations. This is a potential extraction boundary, not a prerequisite for shipping shared budgeting.

Polymorphic transaction, expense, payment, and account packages require a separate compatibility assessment. Adoption must preserve decimal precision, transfers between currencies, refunds, synchronization identities, permissions, and existing balances. Shared budgeting introduces no dependency on that migration and makes no changes to the live ledger format.

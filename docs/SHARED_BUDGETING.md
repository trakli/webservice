# Shared budgeting data model

Shared budgeting lets a couple, household, family, or other group plan money together while keeping personal finances private. Every shared space is the same kind of workspace. Audience names belong in marketing and user-chosen names, rather than stored finance types.

## Ownership and access

A record has one owner. Sharing grants another workspace visibility without moving or copying the record.

- A personal wallet, category, or spending group belongs to its user.
- A workspace-owned wallet, category, or spending group has a workspace and no personal owner. Its creator is recorded separately.
- A transaction belongs to its wallet. A workspace-owned transaction therefore belongs to that wallet's workspace.
- Budgets already have a polymorphic owner: either a user or a workspace.
- Workspace membership controls access to the space. Roles control management actions. Entitlements control whether the space can accept financial changes.

The existing `groups` table contains spending labels. A group of people is a workspace, not a row in that table.

The database uses the existing finance tables. There is no separate wallet, transaction, or budget table for each workspace. The API creates native workspace records with `user_id` empty and records the actor in `created_by_user_id`. Existing ownership columns remain in place for compatibility with personal finance and synchronization.

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

The original finance migration remains unchanged. A subsequent migration renames `workspace_transaction_categorys` to `workspace_transaction_categories`. The rename preserves rows and foreign keys, and its rollback restores the old name. If both names already exist, migration stops rather than choosing one or discarding data.

Apply the forward migration before switching the API and worker to the new code. Back up the development or production database first and rehearse the rename against a restored copy. Do not fresh-migrate a populated environment. Roll back the table rename only together with the application version that uses the previous name.

## Package boundaries

The feature already uses the shared workspace, roles, and entitlements packages. Finance-specific visibility, classification, and recomputation stay in Trakli for this release.

A future personal-finance or accounting backend can reuse a package for ownership-aware sharing and visibility. Such a package should own grants, revocation, and scoped queries. Each application should provide its resource models, membership rules, and financial calculations. This is a potential extraction boundary, not a prerequisite for shipping shared budgeting.

Polymorphic transaction, expense, payment, and account packages require a separate compatibility assessment. Adoption must preserve decimal precision, transfers between currencies, refunds, synchronization identities, permissions, and existing balances. Shared budgeting introduces no dependency on that migration and makes no changes to the live ledger format.

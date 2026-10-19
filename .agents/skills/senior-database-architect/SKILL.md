---
name: senior-database-architect
description: "Apply this skill when acting as a Senior Database Architect, or when the user invokes `/skill Senior Database Architect`. Use for database schema design, normalization and denormalization strategies, indexing optimization, query performance tuning, zero-downtime migrations, transaction isolation, concurrency locking, and caching architecture across MySQL, PostgreSQL, and SQLite."
license: MIT
metadata:
  author: ecoloop
---

# Senior Database Architect

As a **Senior Database Architect**, you ensure the data storage layer is scalable, consistent, resilient, and performant. You prevent bottlenecks before they occur through intentional schema design, optimal indexing, atomic transactions, and query optimization.

---

## 1. Core Principles

1. **Schema Integrity by Design**: Rely on database-level constraints (`NOT NULL`, `FOREIGN KEY`, `UNIQUE`, `CHECK`) rather than application-only validation. Data outlives application code.
2. **Indexing is a Trade-Off**: Every index accelerates specific reads but adds overhead to writes and consumes memory. Index based on actual query patterns, not assumptions.
3. **No Unbounded Queries**: Never perform unpaginated or unrestricted `SELECT *` on growing tables. Always specify columns and boundaries.
4. **Concurrency Safety**: Guard against race conditions using database transactions, optimistic locking (version timestamps), or pessimistic locking (`lockForUpdate()`).
5. **Zero-Downtime Schema Evolution**: Apply the *Expand and Contract* pattern for destructive schema changes (dropping or renaming columns).

---

## 2. Schema Design & Data Modeling

### Primary Keys & Identifiers
- Use `id` (`bigIncrements` or ULID/UUID) depending on distribution requirements.
- For high-volume multi-tenant or distributed architectures, consider ULIDs (`ulid()`) for time-ordered uniqueness without lock contention.

### Relationship Modeling
- Always declare explicit foreign key constraints:
  ```php
  $table->foreignId('user_id')->constrained()->cascadeOnDelete();
  ```
- Use polymorphic relationships judiciously. If referential integrity is critical, prefer explicit junction tables or separate foreign keys.

### Strict Column Types
- Store financial values in cents/integers (`unsignedBigInteger('amount_cents')`) or exact decimals (`decimal('amount', 12, 4)`). Never use `float` or `double` for currency.
- Use explicit enums or string columns with database check constraints for finite status fields:
  ```php
  $table->string('status', 32)->default('pending')->index();
  ```

---

## 3. Indexing Mastery & Performance Tuning

### Composite Indexes & Leftmost Prefix Rule
- When queries filter on multiple columns (e.g., `WHERE organization_id = ? AND status = ? ORDER BY created_at DESC`):
  ```php
  $table->index(['organization_id', 'status', 'created_at']);
  ```
- Place high-selectivity equality columns first, followed by range/sorting columns.

### Eliminating N+1 Queries in Eloquent
- Use eager loading:
  ```php
  // Good: 2 queries
  $orders = Order::with(['user:id,name,email', 'items.product:id,title,price'])->paginate(20);
  ```
- Prevent N+1 in development by configuring strict mode in `AppServiceProvider`:
  ```php
  use Illuminate\Database\Eloquent\Model;

  public function boot(): void
  {
      Model::shouldBeStrict(! $this->app->isProduction());
  }
  ```

### Analyzing Slow Queries
- Inspect execution plans using `EXPLAIN` or `EXPLAIN ANALYZE`:
  - Look for `type: ALL` (Full Table Scan) or `Using temporary; Using filesort`.
  - Ensure the query optimizer leverages intended composite indexes (`type: ref` or `range`).

---

## 4. Concurrency & Transaction Management

### Race Condition Mitigation
When updating balances, inventory, or claim tokens, always wrap within transactions and lock rows:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($userId, $amountCents) {
    $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

    if ($wallet->balance_cents < $amountCents) {
        throw new InsufficientFundsException();
    }

    $wallet->decrement('balance_cents', $amountCents);

    TransactionRecord::create([
        'wallet_id' => $wallet->id,
        'amount_cents' => $amountCents,
        'type' => 'debit',
    ]);
});
```

---

## 5. Safe Migration Strategies (Expand & Contract)

To rename or modify columns in production without downtime:
1. **Phase 1 (Expand)**: Add the new column alongside the old one. Write code that writes to both columns, reading from the old one.
2. **Phase 2 (Backfill)**: Run a chunked background job or migration to backfill historical records.
3. **Phase 3 (Switch)**: Update application code to read from and write exclusively to the new column.
4. **Phase 4 (Contract)**: Drop the old column in a subsequent release.

---

## 6. Senior Database Architect Checklist

- [ ] Foreign keys have explicit cascade/restrict rules.
- [ ] High-frequency query patterns have matching composite indexes following leftmost-prefix logic.
- [ ] No `SELECT *` on high-throughput queries; columns are explicitly selected.
- [ ] Financial or inventory transactions are protected by `DB::transaction()` and row-level locks.
- [ ] Eloquent strict mode enabled in development to catch lazy-loading immediately.
- [ ] Large batch operations use `chunkById()` or `lazy()` to keep memory overhead minimal.

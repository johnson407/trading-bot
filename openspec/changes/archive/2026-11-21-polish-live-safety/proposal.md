# Polish live safety

## Why

Three residual gaps can still distort live trading:

1. Two ticks can race between the sell reservation check and order creation, because the check and the insert are not serialized per bot.
2. A fee reported as an explicit zero is treated as "unreported" and replaced by the bot rate, and negative rebates are clamped away.
3. A shared-account balance-sync skip records one activity per bot every scheduled minute, flooding the activity log.

## What Changes

- Serialize the risk decision and order creation for a bot inside one transaction that locks the bot row; the created pending order acts as the reservation before any exchange call.
- Represent an unreported fee as absent, keep explicit zero fees at zero, and credit negative rebates.
- Record the shared-account balance-sync skip at most once per bot per UTC day.

## Impact

- `app/Domain/Bot/BotTickPipeline.php`, `app/Domain/Exchange/FeeResolver.php`, `app/Domain/Bot/BalanceSynchronizer.php`
- Tests for reservation ordering, fee semantics, and skip deduplication
- No migrations, no new configuration keys

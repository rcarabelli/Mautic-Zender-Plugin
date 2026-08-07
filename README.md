# Mautic WhatsApp Channel — Zender Transport

**Technical bundle:** `MauticZenderBundle`  
**Current version:** `2.2.1`  
**License:** GNU GPLv3  
**Requirements:** Mautic `>= 6.0.5`, PHP `>= 8.2`

## Overview

Mautic WhatsApp Channel — Zender Transport adds a WhatsApp workflow to Mautic while using Zender as the provider transport.

The plugin is not only a provider adapter. It includes message management, queueing, controlled dispatch, account assignment, manual segment broadcasts, campaign sends, direct contact sends, multimedia, status synchronization, inbound chat import, operational controls and AI-assisted spintax generation.

The technical plugin name remains `MauticZenderBundle`.

## Core principles

### One contact, one assigned Zender account

Each Mautic contact is associated with its Zender WhatsApp account through the contact field:

`id_whatsapp_in_zender`

That association is authoritative.

The campaign or operator does not manually choose a sender. When a contact is queued, the plugin resolves the Zender account already associated with that contact and preserves that assignment through dispatch.

If a contact does not have the required WhatsApp identity or assigned Zender account, the send is not silently moved to another account.

### Zender "sent" status

The canonical provider success state is Zender `status=sent`.

In the Mautic UI this is presented as:

**Enviado por Zender / Sent by Zender**

This means Zender performed the send toward WhatsApp.

It does **not** mean that WhatsApp delivered, displayed or read the message. Delivery/read claims must only be made if separate provider evidence exists.

## Main user workflows

### 1. Reusable WhatsApp messages

The WhatsApp Messages manager allows reusable messages to be created and edited independently from a specific queue operation.

A saved message can be used for:

- Mautic campaigns
- manual segment broadcasts
- direct contact sends
- controlled demo sends

The saved delivery preference supports:

- `campaign`
- `segment`

The preference is persisted with the message.

Messages remain reusable after a manual segment queue operation finishes or is cancelled.

### 2. Campaign sends

WhatsApp can be used from the Mautic campaign workflow.

The campaign action resolves the contact, its WhatsApp recipient and its fixed Zender account association, then places the work into the shared dispatch queue.

Campaign sends use the same production dispatcher, attempt tracking, account controls and provider transport as the other queued workflows.

### 3. Manual segment and multisegment broadcasts

Published Mautic segments can be selected from the WhatsApp Messages interface.

The segment workflow includes:

- one or multiple published segments
- contact eligibility inspection
- DISTINCT contact preview
- five-contact preview pages
- selected-segment membership information
- deduplication across overlapping segments
- one queue operation identifier
- reusable saved message content
- explicit queue operation state

If the same contact belongs to multiple selected segments, the operation deduplicates that contact instead of intentionally creating duplicate sends for the same operation.

### Segment scheduling

A manual segment queue operation can have:

- a start time
- an optional end time

The start controls when queue rows become eligible through `available_at`.

The optional end controls expiry through `expires_at`.

If no end time is supplied, the operation has no scheduled expiry.

While a selected message already has an active segment queue operation, the scheduling controls are hidden to prevent accidentally starting another operation from the same interface state.

### Segment cancellation

An active manual segment operation can be cancelled safely.

Cancellation:

- affects only safe unsent rows
- does not cancel rows already being dispatched
- keeps queue history
- marks the affected rows as cancelled
- releases the message for another future scheduling operation

Cancellation is not a deletion of historical queue evidence.

### 4. Direct send from a contact profile

The plugin adds a WhatsApp action to the Mautic contact profile.

The contact-profile send supports:

- text
- personalization
- the contact's fixed Zender account association
- optional multimedia/document attachment
- queue-based dispatch

Direct contact sends do not bypass the dispatch controls.

### 5. Demo send

The WhatsApp Messages interface includes a controlled demo-send workflow.

Demo contact lookup uses the same canonical contact association field:

`id_whatsapp_in_zender`

The demo workflow is independent from the segment preview/selection state.

## Multimedia and documents

WhatsApp messages can carry an optional Mautic Asset.

The attachment path supports image, audio, video and document files.

Direct contact-profile uploads use the same native Mautic Asset lifecycle before the Asset is propagated into the queue and provider envelope.

The UI currently documents a maximum attachment size of 20 MB.

The provider-envelope layer resolves the queued Asset and chooses the corresponding media type before building the Zender request.

Text-only messages remain supported and do not require an Asset.

## Dispatch architecture

All production sends converge on the controlled dispatch system.

Important components include:

- `ChannelNeutralEnqueueService`
- `DispatchQueueRepository`
- `DispatchAttemptRepository`
- `DispatchAccountRepository`
- `DispatchConfigRepository`
- `DispatchQuotaResolver`
- `DispatchCapacityCalculator`
- `RoundRobinPlanner`
- `GuardedRetryScheduler`
- `RetrySafetyClassifier`
- `ZenderDispatchCommand`
- `ZenderTransport`
- `WhatsAppProviderEnvelopeBuilder`

The dispatcher provides controlled behavior such as:

- enabled/disabled state
- dispatch window
- dispatch interval
- enabled Zender accounts
- account ordering
- temporary account pauses
- per-account daily limit overrides
- account cooldown
- round-robin planning
- attempt history
- guarded retries
- advisory locking against concurrent dispatchers

The queue records source information so campaign, segment, contact and demo operations can still be distinguished even though they share the same dispatcher.

## Provider priority rule

Provider priority and internal queue priority are separate concepts.

Current provider-request rule:

- demo sends: Zender provider `priority=1`
- direct contact-profile sends: Zender provider `priority=1`
- campaign sends: provider priority omitted
- segment and multisegment sends: provider priority omitted
- other normal queued sends: provider priority omitted unless explicitly defined by a supported workflow

The internal dispatch queue continues to use its own numeric priority for queue planning.

## Zender Control

The plugin provides a Zender Control area for operational administration and visibility.

The control surface includes the dispatcher/account configuration needed by the queue, provider-status information, history and inbound information used by the plugin.

Operational settings include the dispatcher configuration and account administration required for:

- enabled accounts
- account order
- account labels
- temporary pause state
- account limits/overrides
- dispatch window
- dispatch interval

The UI also exposes the current plugin version.

## Provider status synchronization

Provider status synchronization is separate from dispatch.

The status synchronization process reads eligible provider outcomes and persists the observations used by Mautic's WhatsApp status views and timeline.

The human-facing success terminology must remain consistent with the provider semantics described above: `sent` means **Enviado por Zender**, not WhatsApp delivery/read confirmation.

## Received WhatsApp chats

Inbound/received chat import is a separate operational process.

The importer:

- retrieves received data from Zender
- paginates provider snapshots
- normalizes provider records
- associates records to Mautic contacts when possible
- prevents duplicate persistence
- stores the received-chat history used by Zender Control

Inbound import does not replace the outbound dispatcher.

## AI settings

The Zender Settings area supports three AI providers:

- OpenAI
- Anthropic / Claude
- Grok / xAI

The configuration stores:

- provider credentials
- active provider
- active model

API credentials are stored encrypted using Mautic's encryption helper.

Public configuration reads expose only whether credentials are configured; they do not expose plaintext or encrypted secrets.

Submitting a blank credential field preserves the existing stored credential.

Model lists can be loaded for the selected provider from the Settings interface.

## AI-assisted spintax

The WhatsApp Messages editor includes AI-assisted spintax generation.

The spintax subsystem contains:

- provider routing
- prompt construction
- Mautic-token protection
- result validation
- fact-preservation checks
- generated-result presentation
- explicit replacement of the message text

The generated result is presented separately before replacement so the operator can review it.

The AI feature does not require changing the contact-to-Zender account association and does not change the queue/dispatcher architecture.

## Operational CLI commands

Version 2.2.0 intentionally contains only six operational Zender commands.

### Activation

```bash
php bin/console 7cats:zender:activation
```

Activation/setup helper for the plugin's operational configuration.

### Configuration

```bash
php bin/console 7cats:zender:config
```

Reads or manages the plugin's dispatch configuration according to the command options exposed by the installed version.

### Dispatch

```bash
php bin/console 7cats:zender:dispatch
```

Processes eligible queued messages through the controlled dispatcher.

This is the production outbound worker.

### Status

```bash
php bin/console 7cats:zender:status
```

Provides operational status information without replacing the dispatcher.

### Provider status synchronization

```bash
php bin/console 7cats:zender:sync:provider-status
```

Synchronizes provider-side status observations used by Mautic.

### Received chat import

```bash
php bin/console 7cats:zender:import:received
```

Imports received WhatsApp chat data from Zender.

## Cron wrappers

Production automation is provided through the wrappers under:

`Resources/bin/`

Current wrappers:

- `cron-dispatch-zender.sh`
- `cron-sync-zender-status.sh`
- `cron-import-zender-replies.sh`

They correspond to:

- outbound queue dispatch
- provider-status synchronization
- received-chat import

Use the installation's scheduler to invoke the wrappers with the application user and correct Mautic working directory.

See:

`Resources/docs/CRON-SCHEDULING.md`

for the dedicated cron scheduling notes.

Do not run multiple competing dispatch workers in parallel. The dispatcher also uses an advisory lock, but the scheduler should still be configured intentionally.

## Runtime user

Application operations that boot Mautic should run as the Mautic/site account, not as an unrelated privileged user.

For the Paellas Franco installation used during development, the application runtime contract is:

- user: `paellas`
- HOME: `/home/paellas`
- application directory: `/home/paellas/public_html/marketautomation`
- PHP: `/opt/cpanel/ea-php82/root/usr/bin/php`

These paths are deployment-specific and are not a requirement for other installations.

## Database migrations

Version `2.2.1` includes a self-contained clean-install foundation in
`Version0001.php`.

On a fresh installation, `Version0001.php` creates the foundational tables:

- `zender_dispatch_config`
- `zender_dispatch_accounts`
- `zender_dispatch_queue`
- `zender_dispatch_attempts`
- `zender_received_chats`

The migration also creates the initial `zender_dispatch_config` row with
`id=1` in a disabled/conservative state so transport must still be configured
before normal production dispatch is enabled.


The plugin currently keeps these migration files:

- `Version0001.php`
- `Version0002.php`
- `Version0003.php`
- `Version0004.php`
- `Version0006.php`
- `Version0007.php`
- `Version0008.php`
- `Version0009.php`
- `Version0010.php`

The missing `Version0005.php` is a historical numbering gap and is not, by itself, an indication that a migration file is missing from the current plugin.

Important later migrations include:

- `Version0007`: queue Asset propagation support
- `Version0008`: encrypted AI settings storage
- `Version0009`: persisted WhatsApp message delivery destination
- `Version0010`: segment queue expiry/window support

Existing migration files should not be removed simply because the current database already contains their schema.

## Installation and update notes

1. Install the bundle as `plugins/MauticZenderBundle`.
2. Ensure file ownership and permissions are correct for the Mautic installation.
3. Run the normal Mautic plugin discovery/install/update process for the target installation.
4. Ensure Mautic executes the bundle migrations; on a fresh installation they build the plugin schema from `Version0001.php` forward.
5. Clear the Mautic production cache.
6. Configure the Zender integration credentials.
7. Configure dispatch accounts and dispatcher settings in Zender Control.
8. Configure the three operational cron workflows.
9. If AI assistance is required, configure an AI provider credential and select an active model.
10. Validate a controlled send before enabling normal production volume.

Do not assume that a generic Mautic plugin reload automatically applies every migration on every installation. Verify schema state as part of deployment.

## Main routes

The plugin exposes the main administrative surfaces under Mautic, including:

- Zender Control: `/s/zender-control`
- WhatsApp Messages: `/s/zender-whatsapp-messages`

Additional internal routes support AI model loading, spintax generation, segment preview/queue operations, queue cancellation, contact actions and campaign status views.

Those internal routes should be called through the plugin UI/contracts rather than treated as a public external API.

## Campaign and contact visibility

The plugin integrates WhatsApp information into Mautic beyond the message manager.

Current integration includes:

- campaign WhatsApp actions
- campaign WhatsApp status views
- contact-profile WhatsApp send action
- contact timeline WhatsApp information

These surfaces use the same queue/provider-state semantics as the core dispatcher.

## Security notes

- AI API credentials are encrypted at rest.
- Settings submissions use CSRF protection.
- Queue operations use dedicated CSRF protection.
- Provider credentials must never be written to diagnostic output.
- Contact-to-account association must not be overridden casually.
- Do not infer WhatsApp delivery/read state from Zender `sent`.
- Keep Mautic and provider credentials outside source control.
- Keep production cron and application runtime under the intended application account.

## Troubleshooting order

For outbound issues, check in this order:

1. contact WhatsApp identity and `id_whatsapp_in_zender`
2. message/Asset validity
3. queue row creation
4. `available_at` / `expires_at`
5. dispatcher enabled state
6. dispatch window and interval
7. assigned account enabled/pause state
8. per-account quota/cooldown
9. dispatch attempt history
10. Zender provider response
11. provider-status synchronization

For segment issues, additionally check:

- published segment availability
- selected segment IDs
- eligibility preview
- overlapping-contact deduplication
- active queue operation
- cancellation/expiry state

For AI spintax issues, check:

- active AI provider
- provider credential configured
- active model
- model loading
- protected Mautic tokens
- generated-result validation

## Version 2.2.1 clean-install portability

Version 2.2.1 makes the plugin source tree self-contained for deployment to a
fresh compatible Mautic 6 installation.

When Mautic executes the plugin installation lifecycle:

- the plugin install listener creates the Mautic lead field
  `id_whatsapp_in_zender` when it does not already exist;
- `Version0001.php` creates the dispatch foundation and received-chat tables;
- the existing later migrations continue the schema evolution for WhatsApp
  messages, queue source lineage, Asset support, AI settings, delivery
  destination persistence and segment queue expiry.

No production contacts, queue rows, Zender credentials or account assignments
are embedded in the plugin. Those remain installation-specific data and must be
configured on the target Mautic instance.

The portability change was installed in the source without executing migrations
against the existing production database.

## Version 2.2.0 cleanup

Version 2.2.0 is the cleaned production baseline.

The cleanup removed legacy development/diagnostic artifacts that were not part of the live runtime:

- canary CLI commands
- self-test/certification CLI commands
- historical SMS 58 test tooling
- historical SMS 58 recovery command/service
- legacy endpoint-audit/plan CLI commands
- unused `Assets/img/whatsapp.png`
- obsolete repository/development Markdown files

The production command surface was reduced from 19 commands to the six operational commands documented above.

The cleanup did not modify the production cron contract, dispatcher logic, Zender transport, database content or provider state.

## Files intentionally retained

Documentation:

- `README.md`
- `LICENSE`
- `Resources/docs/CRON-SCHEDULING.md`

Brand asset:

- `Assets/img/7cats-isotipo-red-200x200.png`

Operational runtime wrappers:

- `Resources/bin/cron-dispatch-zender.sh`
- `Resources/bin/cron-sync-zender-status.sh`
- `Resources/bin/cron-import-zender-replies.sh`

## Compatibility

The plugin metadata targets:

- Mautic `>= 6.0.5`
- PHP `>= 8.2`

The current production development baseline was validated on Mautic 6.0.x with PHP 8.2.

## Support and project identity

Project:

**Mautic WhatsApp Channel — Zender Transport**

Technical plugin:

**MauticZenderBundle**

Author/support identity is maintained in `Config/config.php`.

## License

GNU GPLv3. See `LICENSE`.

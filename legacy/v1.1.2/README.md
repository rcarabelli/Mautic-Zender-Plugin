# Mautic WhatsApp (via Zender) – Plugin v1.1.2

## Overview
This plugin replaces Mautic's SMS channel and sends WhatsApp messages using a Zender account (can be your own instance). It supports Mautic campaigns, segmentation, placeholders, and tracking. Zender acts as a bridge to WhatsApp (not Meta's official API). Designed for Mautic ≥ 6.0.5

Inspired by: “Weekend project – a Mautic WhatsApp plugin” by Joey Keller.

## What the Plugin Does
- Sends WhatsApp messages from Mautic’s “Text Messages” channel via Zender.
- Supports Mautic placeholders (e.g., `{contactfield=firstname}`).
- Detects if your content includes image or video URLs and sends the message as “media” to embed the first image.
- Automatically converts Mautic’s “/r/…” URLs to their real destination for media (so WhatsApp can display images/videos directly).
- Optionally shortens links if a URL shortener is configured (YOURLS recommended).
- Logs every send, HTTP response, and error in Mautic for debugging.

## Requirements
- Mautic 6.0.5 or higher.
- PHP 8.2 or higher.
- An operational Zender instance (with connected WhatsApp devices).
- In Zender: an API Key and the “Account ID” (token) identifying the sender’s WhatsApp number.
- In Mautic contacts:
  - “phone” field in E.164 format (with + and country code).
  - Custom field “ID WhatsApp in Zender” (alias: `id_whatsapp_in_zender`) with Zender’s Account ID.

## What the Plugin Creates on Installation
- Adds “Zender” to Mautic’s Plugins page.
- Creates the custom field “ID WhatsApp in Zender (`id_whatsapp_in_zender`)”.
- Adds the “Zender” integration with its configuration form.

## Installation
1. Copy the plugin folder to `plugins/` and name it exactly `MauticZenderBundle`.
2. Clear Mautic’s cache.
3. Run in console:
   - `php bin/console mautic:plugins:reload`
   - `php bin/console cache:warmup --no-debug`
4. In the UI: Settings (gear icon) → Plugins → “Install/Upgrade Plugins”.
5. In Plugins → Zender, configure and enable:
   - Zender API URL (e.g., `https://YOUR-ZENDER/api/send/whatsapp`)
   - Zender API Key
   - Shortener URL (optional, recommended: YOURLS endpoint)
6. In Settings → Text Message Settings, select “Zender WhatsApp through SMS” as the transport.
7. For each contact you want to send to:
   - Set “phone” in E.164 format with “+” (e.g., `+51911XXXXXXX`).
   - Set “ID WhatsApp in Zender” with the Zender Account ID for the sending number.

## Basic Usage
1. Create a Campaign and assign a Segment with contacts that have “phone” and “id_whatsapp_in_zender”.
2. Create a “Text Message” with text, emojis, line breaks, and links. You can use Mautic placeholders.
3. If you include a direct image (jpg, jpeg, png, gif) or video (mp4) URL, the plugin detects media:
   - Replaces “/r/…” links with their real URL for WhatsApp to download media.
   - Uses the first image found as visible media in WhatsApp; other links remain as text.
4. Launch the campaign (or run crons) and check Mautic’s log for send results.

## Useful Commands (Cron/Manual)
- Update campaign members:  
  `php bin/console mautic:campaigns:update`
- Trigger campaigns:  
  `php bin/console mautic:campaigns:trigger -vvv`
- Send SMS broadcasts (if applicable):  
  `php bin/console mautic:broadcasts:send`
- View logs in real-time:  
  `tail -f var/logs/mautic_prod-YYYY-MM-DD.php`

## Zender Configuration (Payload Reference)
The plugin sends a POST `application/x-www-form-urlencoded` to the Zender API URL with fields like:
- `secret` (your Zender API Key)
- `account` (Account ID of the sender’s number)
- `recipient` (destination number in E.164 with “+”)
- `type` (“text” or “media”, auto-adjusted if media is detected)
- `message` (final text)
- For media, adds `media_url` and `media_type`


### Quick Test with cURL (to Verify Zender Credentials)
```bash
curl -i -X POST 'https://YOUR-ZENDER/api/send/whatsapp' \
--data-urlencode 'secret=YOUR_API_KEY' \
--data-urlencode 'account=ACCOUNT_ID_IN_ZENDER' \
--data-urlencode 'recipient=+54911XXXXXXX' \
--data-urlencode 'type=text' \
--data-urlencode 'message=Hello 👋 direct test from curl'
```

## Best Practices and Limits (Very Important)
- Use real WhatsApp numbers (not official API) to avoid spam flags.
- In Zender, set random send intervals per number (e.g., 80–160 seconds). This allows ~30 messages/hour per number without raising suspicion.
- Conservative recommendation: ≤ 300 campaign messages per number/day. For 3,000 daily messages, use ~10 numbers.
- Mautic may “send” many messages, but Zender rate-limits them.
- WhatsApp replies go to Zender. Manage them there or route to other systems via API/webhook if integrated.

## Logging and Debugging
The plugin logs clear entries in `var/logs/mautic_prod-YYYY-MM-DD.php`, e.g.:
- `[ZENDER] Preparing POST …` (shows destination, type, URL, and payload with masked API Key).
- `[ZENDER] Response …` (status and body).
- Common logged errors:
  - Contact missing phone or `id_whatsapp_in_zender`.
  - Invalid number (E.164 parsing error).
  - Integration not configured (empty API Key/URL).
  - Non-2xx HTTP, invalid JSON, or API-reported error.

## Quick Troubleshooting
- **“Not sending”**: Ensure the default transport is “Zender WhatsApp through SMS” and the integration is enabled.
- **“Invalid number”**: Fix the “phone” field to E.164 with “+”.
- **“API/HTTP error”**: Check Zender’s exact endpoint, API Key, and Account ID. Validate with the cURL test.
- **“Images not showing”**: Ensure image URLs are public and direct (end in .jpg/.jpeg/.png/.gif).
- **“Too slow”**: Normal due to recommended rate limits; add more numbers in Zender for higher throughput.

## Security Notes
- The API Key is stored in Mautic and masked in logs by the plugin.
- Do not share your API Key or Account ID in public documentation.

## Uninstallation / Update
- **Update**: Replace the plugin folder, clear cache, run “Install/Upgrade Plugins”.
- **Uninstall**: Remove the plugin folder and clear cache. The custom field remains until manually deleted (if desired).

## Changes in v1.1.2
- Phone numbers normalized to E.164 without forcing a default region.
- Sending via Guzzle with timeouts and detailed logs (API key masked).
- Automatic media detection (image/video) and “media” payload adjustment.
- Replaced /r/... links with real URLs so WhatsApp can download media.
- Credential checks for API URL and API key, with clear error messages.
- Service wiring compatible with Mautic 6 (FieldsWithUniqueIdentifier).

## Contact and Support
Questions and support: Form at https://www.7catstudio.com or email requests@7catstudio.com.
# Mautic Whatsapp Plugin
This plugin replaces the SMS channel and allows you to send messages to Whatsapp
using a Zender account (https://codecanyon.net/item/zender-android-mobile-devices-as-sms-gateway-saas-platform/26594230).
Intended for >= Mautic 4.0

This plugin was created based on this previous plugin:
https://joeykeller.com/weekend-project-a-mautic-whatsapp-plugin

## Installation by console
1. Download the plugin, unzip in your plugins folder
2. Rename the folder to MauticZenderv2Bundle <--- this is version 2
3. `php bin/console mautic:plugins:reload`

## Usage
1. Go to your **Plugins** in Mautic
2. You should see new Whatsapp plugin in the list, click and publish it.
3. Go to your Zender installation and create an API key and copy the URL in API documentation (will make a video soon), ignore the "Webhookv2 processing URL", will be removed in the next version, is not currently needed
4. This plugin overrides your SMS transport. In your **Configuration > Text message settings** select Whatsapp as default transport

You can ask me questions filling the contact form at https://www.7catstudio.com

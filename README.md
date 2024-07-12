# Mautic Whatsapp Plugin
Mautic Whatsapp (via Zender) Plugin v.1.1.0
This plugin replaces the SMS channel and allows you to send messages to Whatsapp
using a Zender account (https://codecanyon.net/item/zender-android-mobile-devices-as-sms-gateway-saas-platform/26594230).
Intended for >= Mautic 5.1

This plugin was created based on this previous plugin:
https://joeykeller.com/weekend-project-a-mautic-whatsapp-plugin

## What does this Plugin do
This plugin lets you send WhatsApp messages using "Zender" as a brige between Mautic and WhatsApp. The main advantage is that Zender is like a WhatsApp web emulator, so you can use standard "WhatsApp" and "WhatsApp business" accounts with it and you won't get charged for sending each message like what happens with "WhatsApp Business API". Of course it has its limits and they are explained in "Things to consider" some lines below.

Once you have this installed and running, you will be able to use the "Text Messages" channel to send vía your own WhatsApp numbers with all the perks Mautic have including link tracking, follow up and so on, and with the extra advantage that all traffic is saved into your own Zender's database so you can the use that for any use you find useful.

Zender also have a nice API, so each WhatsApp answer you get, can also be processed.

## What happens on installation
1. Mautic Plugin is activated, appearing in your plugins page showing the red 7 Cats logo
2. Creates a new "custom field" called "ID WhatsApp in Zender/id_whatsapp_in_zender" where the Zender token of a WhatsApp number should be placed (each contact you want to send a message to must have this field filled)
3. Creates a Plugin option in "plugins" where you will have to fill 3 form fields to activate this (Zender API Key, Zender API URL, Shortener Link)

## Installation
1. Download the plugin, unzip in your plugins folder
2. Rename the folder to MauticZenderBundle
3. Clear Mautic's cache <-- very important
4. On terminal/console run php bin/console mautic:plugins:reload, in mautic visual administrator, go to "cog > Plugins" and clic on "Install/Upgrade Plugins"
5. Fill the required fields (all are mandatory) and activate the "Zender" plugin <--- this means you will need a Zender account (self hosted) and access to a shortener (The recommended shortner is https://yourls.org/)
6. Change the "Text message settings" to "Zender WhatsApp through SMS" at "configuration > Text Message Setting"
7. Add phone numbers in the "phone" field to the leads you want to send WhatsApps, INCLUDING the "+" symbol and the country code. The number will look like this: "+01333999999999" where "+" is mandatory, "01" is the country code and the rest is the phone number
8. Get the "ID" of those WhatsApp numbers and place them in the the new "custom field" called "ID WhatsApp in Zender/id_whatsapp_in_zender" of the users you want to send them WhatsApp messages <--- I recommend like 200-300 users per WhatsApp number, if you want to contact them in the same day (Check "Things to consider" section)
9. Send personalized WhatsApp messages with visible media and Mautic traceable URLs and enjoy

## Usage / how does it work
1. Create a new campaign
2. Choose a segment (be sure there are leads with "phone" numbers)
3. Create or choose a Text Message, include in it text, images URLs (jpg, jpeg, gif and png) or video URLs (mp4 only), emojis and linebreaks. It accepts any character WhatsApp accept, but only text. It also accepts Mautic Placeholders ({contactfield=firstname} and all others)
4. Wait or force for the campaign update and trigger
5. Then the plugin will do the following
   - Check if you have any media files in the links you added
   - If it doesn't find any media file, send your WhatsApp message as you wrote it
   - If it find any media file, will use the "non tracked versions" from mautic (will replace them with the "page_redirects" table contents)
   - Then will choose the first image (only images). If you typed 1, 2 or infinite image URLs (gif, jpg, jpeg or png), the plugin will take the first one and rebuild all the JSON payload of the message, this new JSON payload uses an "image" format on
     WhatsApp. In other words, the first found image will be visible in the WhatsApp sent message and the other images and links will be sent as links
   - Then the plugin will send the JSON via API to Zender, one message at a time, with all the placeholders you want (like {contactfield=firstname} and so on)
6. Zender will download the first image and send it to WhatsApp with the text message
7. Zender will resend the message from your configured WhatsApp numbers in Zender


## Things to consider
1. WhatsApp limits and watches closely the usage for spam of regular NON API accounts, so you can't send too many messages per hour
2. The recommended configuration in Zender per number (you can manage infinite numbers) is randomly send from 80 to 160 seconds each message (THIS IS CRITICAL). That means that each WhatsApp number would be able to send around 30 messages per hour
3. Zender will manage the send speed. Mautic will send all in a single batch
4. The recommendation to prevent numbers blocked is not sending more than 300 messages daily form the same WhatsApp number (campaign messages, you can reply or autoreplay them because they will be different messages) per WhatsApp number per day.
   If you have 3,000 persons you want to contact per day, you should use 10 WhatsApp numbers. Zender let you manage unlimited numbers (if is your own installation). I bet is safe to send up to 600, but since my DBs are rather small, I prefer 300.
5. Zender can recieve answers. Currently I am working in a solution to get those messages on Mautic or in a console. Zender console is too basic. In any case, you can auto resend all messages from Zender to another centralized number or use Zender
   API to manage answers. Zender have a solid API management
6. In any scenario, you can send safely (without considering answers) at least 9,000 WhatsApp campaign messages per month. Using WhatsApp Business API (including the answer) will cost you at best some US$ 225.00
   (see rates here: https://developers.facebook.com/docs/whatsapp/pricing)
7. This is not to replace WhatsApp Business API, but it can help small budget/team projects with rather small DBs (less than 10K WhatsApps). Is a one time shot small cost and a dependable solution
8. Is an AWESOME solution for for some alerts and specially to recover shopping carts and store communications or even to keep your company team updated of news and important messages that need personalization or segmentation (for this we have a
   platform called "JANUS" https://www.janus.plus and it helps medium teams in an awesome way, medium teams are temas with less than 5k members)

You can ask me questions filling the contact form at https://www.7catstudio.com or writing a ticket to requests@7catstudio.com
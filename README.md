## Mautic Whatsapp (via Zender) Plugin v.1.1.14
This plugin replaces the SMS channel and allows you to send messages to Whatsapp
using a Zender account (https://codecanyon.net/item/zender-android-mobile-devices-as-sms-gateway-saas-platform/26594230).
Intended for >= Mautic 5.1

This plugin was created based on this previous plugin:
https://joeykeller.com/weekend-project-a-mautic-whatsapp-plugin

## What does this Plugin do
This plugin lets you send WhatsApp messages using "Zender" as a bridge between Mautic and WhatsApp. The main advantage is that Zender is like a WhatsApp web emulator, so you can use standard "WhatsApp" and "WhatsApp business" accounts with it and you won't get charged for sending each message like what happens with "WhatsApp Business API". Of course, it has its limits, and they are explained in "Things to consider" some lines below.

Once you have this installed and running, you will be able to use the "Text Messages" channel to send via your own WhatsApp numbers with all the perks Mautic has, including link tracking, follow up, and so on, and with the extra advantage that all traffic is saved into your own Zender's database so you can use that for any purpose you find useful.

Zender also has a nice API, so each WhatsApp answer you get can also be processed.

## New Features
- Fetch and process WhatsApp messages from Zender, including status updates.
- New fields added to the leads table to track the last sent and received message dates, statuses, and contents.

## What happens on installation
1. Mautic Plugin is activated, appearing in your plugins page showing the red 7 Cats logo.
2. Creates a new "custom field" called "ID WhatsApp in Zender/id_whatsapp_in_zender" where the Zender token of a WhatsApp number should be placed (each contact you want to send a message to must have this field filled).
3. Creates a Plugin option in "plugins" where you will have to fill 3 form fields to activate this (Zender API Key, Zender API URL, Shortener Link).
4. Six new fields are added to the leads table to save the last sent and received messages:
    - `last_sent_message_date` (varchar(64))
    - `last_sent_message_status` (varchar(64))
    - `last_sent_message_content` (varchar(255))
    - `last_received_message_date` (varchar(64))
    - `last_received_message_content` (varchar(255))
    - `last_received_message_status` (varchar(64))
5. A table named `maugittest_zender_api_request_log` is created to save the JSON responses from the Zender API.

## Installation
1. **Download the Plugin:**
   - Download the plugin from the provided source.

2. **Unzip the Plugin:**
   - Unzip the downloaded file in your Mautic plugins folder.

3. **Rename the Folder:**
   - Rename the unzipped folder to `MauticZenderBundle`.

4. **Clear Mautic's Cache:**
   - This is a crucial step. Open your terminal or command prompt and navigate to your Mautic root directory.
   - Run the command: `php bin/console cache:clear`.

5. **Reload Mautic Plugins:**
   - Run the command: `php bin/console mautic:plugins:reload`.
   - In the Mautic admin interface, go to "Settings" > "Plugins".
   - Click on "Install/Upgrade Plugins".

6. **Configure the Plugin:**
   - In the "Enabled/Auth" tab of the plugin settings, fill in the required fields:
     - **Zender API Key**: Your Zender API key.
     - **Zender API URL**: The base URL for the Zender API (up to the `/api` part).
     - **Shortener Link**: The URL for the shortener service, including the token or key.
   - In the "Features" tab of the plugin settings, fill in the required fields:
     - **Fetch Quantity**: The number of units of time to look back when fetching messages for the first time.
     - **Fetch Unit**: The unit of time to use when fetching messages for the first time.
     - **Batch Size**: The number of messages to fetch in each batch during synchronization.

7. **Change Text Message Settings:**
   - Go to "Settings" > "Configuration" > "Text Message Settings".
   - Set the "Text Message Transport" to "Zender WhatsApp through SMS".

8. **Add Phone Numbers:**
   - Ensure that the contacts you want to message have phone numbers in the "phone" field in the format: `+01333999999999` (including the "+" symbol and the country code).

9. **Set Zender Token for Contacts:**
   - Each contact must have a Zender token placed in the custom field "ID WhatsApp in Zender/id_whatsapp_in_zender".
   - It is recommended to send messages to around 200-300 users per WhatsApp number to avoid issues.

## Usage

### How to Use the Plugin

1. **Create a New Campaign:**
   - In Mautic, create a new campaign.

2. **Choose a Segment:**
   - Select a segment that contains contacts with phone numbers.

3. **Create or Select a Text Message:**
   - Include text, image URLs (jpg, jpeg, gif, png), or video URLs (mp4 only), emojis, and line breaks in the message.
   - The message accepts Mautic placeholders (e.g., `{contactfield=firstname}`).

4. **Update and Trigger the Campaign:**
   - Wait for the campaign to update and trigger automatically or force an update.

5. **Message Processing:**
   - The plugin will check for media files in the links you provided.
   - If no media files are found, the message will be sent as text.
   - If media files are found, the first image will be visible in the WhatsApp message, and other media will be sent as links.

6. **Send the Message:**
   - The plugin will send the message via the Zender API, using placeholders for personalization.
   - Zender will download the image (if any) and send the message to WhatsApp.

7. **Track and Process Responses:**
   - Zender will resend the message from your configured WhatsApp numbers.
   - The plugin will fetch and process message statuses and responses from Zender, updating the lead's profile accordingly.

## Fetching and Processing Messages

### Fetch and Process Messages

The plugin fetches messages from Zender and updates the lead profiles with the status, date, and content of the sent and received messages. Here’s how it works:

1. **Fetch Messages:**
   - The plugin fetches messages from Zender using the API.
   - It retrieves messages of types: `wa.pending`, `wa.received`, and `wa.sent`.

2. **Process Messages:**
   - For each fetched message, the plugin logs the API request and processes the message data.
   - The message data is saved in a table (`maugittest_zender_api_request_log`), and the lead's profile is updated with the message information.

3. **Update Lead Profiles:**
   - The plugin updates the lead's profile with the last sent and received message dates, statuses, and contents.

## Setting Up Cron Job

### Setting Up the Cron Job

To automate the fetching and processing of messages, you need to set up a cron job:

1. **Open your crontab file for editing:**
   - Run the command: `crontab -e`.

2. **Add the following line to schedule the command to run every 15 minutes:**
   */15 * * * * /usr/bin/php /path/to/your/mautic/bin/console mautic:zender:sync-messages

3. Save and exit the crontab editor.

## Running the Command Manually

If you need to run the command manually, use the following command in your terminal:

php /path/to/your/mautic/bin/console mautic:zender:sync-messages

Replace /path/to/your/mautic with the actual path to your Mautic installation.

## Things to Consider

### WhatsApp Limitations:
- WhatsApp closely monitors non-API accounts for spam, so you cannot send too many messages per hour.

### Sending Speed:
- Configure Zender to send messages randomly between 80 to 160 seconds per message to avoid detection.

### Daily Limits:
- It is recommended not to send more than 300 messages daily per WhatsApp number.
- For larger campaigns, use multiple WhatsApp numbers (e.g., 10 numbers for 3,000 contacts).

### Receiving Messages:
- Zender can receive messages, and you can process these responses using Zender's API.

### Monthly Message Limits:
- You can safely send at least 9,000 WhatsApp campaign messages per month.
- Using the WhatsApp Business API for the same volume could cost around $225.00.

### Use Cases:
- The plugin is ideal for small budget/team projects with databases of less than 10,000 contacts.
- It can also be used for alerts, shopping cart recovery, store communications, and team updates.

## License
Copyright (C) 2024 7 Cats Studio Corp

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

## Contact / More Information
You can ask questions by filling the contact form at https://www.7catstudio.com or writing a ticket to requests@7catstudio.com

This updated README includes detailed instructions for installation, configuration, usage, fetching and processing messages, setting up cron jobs, and manually running the command, along with all the new features and things to consider.

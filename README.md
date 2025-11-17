# Google-Shopping-availability-date-fixer

A lightweight, self-hosted **PHP tool** to generate a Google Shopping **supplemental feed** with dynamic `availability_date` values.

Many merchants use `preorder` or `backorder` but **don't know the exact delivery date**.  
Google Merchant Center, however, **requires** a valid `availability_date` for these products – otherwise items may not be listed.

This tool solves that by generating a small supplemental feed like:

```xml
<g:id>12345</g:id>
<g:availability>preorder</g:availability>
<g:availability_date>2025-12-08T12:33:18Z</g:availability_date>

availability_date = today + N days (UTC)

Features

✅ Supports XML (Google Shopping feed) and CSV
✅ CSV autodetection (; or ,)
✅ Flexible column names (id, g:id, product_id, … / availability, g:availability, …)
✅ Filters out all products with in stock
✅ Only non-stock items get a dynamic availability_date
✅ Generates a valid RSS supplemental feed with g:id, g:availability, g:availability_date
✅ PHP 5.6+ compatible (no frameworks, no Composer)
✅ Single file: feedlingo_availability_date_fixer.php
✅ Cron-ready endpoint: ?run=1&secret=...
✅ Modern dark UI with:

configuration form

"Test run now" button

generated cron URL

How it works

You point the tool to your main feed (XML or CSV).

You configure:

target supplemental file path

daysOffset (how many days in the future)

base shop URL

On each run (cronjob or Test Run button), the tool:

reads the source feed (XML → CSV fallback)

extracts id and availability

skips any product with availability = in stock

sets availability to existing value
(or falls back to preorder if empty)

writes a supplemental RSS feed with:

g:id

g:availability

g:availability_date = today + N days (UTC)

You then upload/assign this file as supplemental feed in Google Merchant Center, linked by ID to your main feed.

Installation

Copy feedlingo_availability_date_fixer.php to your web server (e.g. into /var/www/html/tools/).

Make sure PHP (5.6 or higher) is available.

Visit the script in your browser, e.g.:

https://yourserver.com/tools/feedlingo_availability_date_fixer.php


Fill out:

Source feed (URL or local path, XML or CSV)

Target file (local path, e.g. /var/www/html/feeds/availability_supplement.xml)

Availability date offset (days)

Shop base URL

Click Save configuration.

Use Test run now to generate the supplemental feed once and see the result message.

Configure a cronjob (see below).

Cron usage

The tool shows your cron URL in the UI, e.g.:

https://yourserver.com/tools/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET


Example cronjob (Linux):

0 5 * * * /usr/bin/wget -qO- "https://yourserver.com/tools/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET" \
  > /var/log/feedlingo_availability.log 2>&1


This runs every day at 05:00, regenerates the supplemental feed and writes a short log.

Requirements

PHP 5.6+

allow_url_fopen enabled if you use remote URLs as source feed

XML/CSV file accessible by the script

No database, no external APIs, no Composer.

Usage (DE)

Feedlingo Availability-Date Fixer ist ein kleines PHP-Tool, das dir automatisch einen Zusatzfeed für Google Shopping erzeugt.

Problem:
Für Produkte mit preorder / backorder verlangt Google ein konkretes availability_date.
Viele Händler kennen das genaue Datum nicht – oder wollen es nicht manuell pflegen.

Lösung:
Dieses Tool liest deinen bestehenden Produktfeed (XML oder CSV) und erzeugt einen Zusatzfeed, in dem für alle nicht lagernden Produkte ein availability_date auf „heute + X Tage“ gesetzt wird.

Diesen Zusatzfeed hinterlegst du im Merchant Center als zusätzlichen Feed, verknüpft über die Produkt-ID.

License

MIT License

Copyright (c) 2025 …

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the “Software”), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

(…you can paste the full MIT text here or use GitHub’s license template…)


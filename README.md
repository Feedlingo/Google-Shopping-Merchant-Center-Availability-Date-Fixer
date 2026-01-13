# Google-Shopping / Merchant-Center availability_date fixer


**Feedlingo Availability-Date Fixer** is a lightweight, self-hosted PHP tool that automatically generates a **Google Shopping supplemental feed** for products that are not in stock yet.

Many shops don’t know the exact delivery date for **preorder** or **backorder** products – but Google Merchant Center **requires** an `availability_date` for these items. This tool solves that problem by automatically creating a supplemental feed where

```text
availability_date = today + N days (UTC)
```

You keep full control over the offset (e.g. 5, 10, 30 days) while still providing a valid date to Google.
---

## Screenshot

The screenshot below shows the web-based configuration UI, including source feed selection, supplemental feed target, availability date offset and cronjob setup.

![Feedlingo Availability-Date Fixer – Configuration UI](docs/availability-date-fixer-backend.png)

---

## What it does

- Reads your **main product feed** (XML or CSV)
- Filters out all products with `availability = in stock`
- Keeps only products with any other availability (e.g. `preorder`, `backorder`, `out of stock`, …)
- Writes a separate **Google RSS supplemental feed** containing:
  - `g:id`
  - `g:availability`
  - `g:availability_date` (auto-calculated)
- Designed to be called via **cronjob** (daily or more often)
- Also offers a **Test run** button in the UI to manually trigger a generation

---

## Supported input formats

### 1. XML (Google Shopping style)

Example (main feed):

```xml
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <item>
      <g:id>SKU-123</g:id>
      <g:availability>preorder</g:availability>
      ...
    </item>
  </channel>
</rss>
```

The tool tries to parse this format first (`rss/channel/item` or `feed/entry`).

### 2. CSV

If XML parsing fails, the tool automatically falls back to CSV parsing.

Requirements:

- A header row
- One column for the product ID
- One column for the availability

Accepted column names (case-insensitive):

- ID: `id`, `g:id`, `item_id`, `item id`, `product_id`, `product id`
- Availability: `availability`, `g:availability`, `availability_status`, `stock_status`, …

Both `,` and `;` are supported as delimiters (auto-detected).

Example CSV:

```csv
id,availability,title
SKU-123,preorder,Some product
SKU-456,in stock,Another product
```

`SKU-456` will be ignored in the supplemental feed because it’s `in stock`.

---

## Output format (supplemental feed)

The tool generates a **clean Google RSS feed** like this:

```xml
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <title>Feedlingo Availability Supplemental Feed</title>
    <link>https://www.your-shop.com/</link>
    <description>Automatically generated supplemental feed for availability_date</description>

    <item>
      <g:id>SKU-123</g:id>
      <g:availability>preorder</g:availability>
      <g:availability_date>2025-12-31T00:00:00Z</g:availability_date>
    </item>
  </channel>
</rss>
```

You then add this file as a **supplemental feed** in Google Merchant Center and link it via the **ID** field to your main feed.

---

## Requirements

- PHP **5.6 or higher**  
- `DOM`, `SimpleXML` and `json` extensions (standard in most PHP installations)
- Webserver (Apache, Nginx, …) or CLI PHP with `php -S`

No external API, no database, no Composer, no framework.

---

## Installation

1. Copy `feedlingo_availability_date_fixer.php` to a directory on your webserver, e.g.:

   ```text
   /var/www/html/feedlingo_availability_date_fixer.php
   ```

2. Make sure the webserver user can **write** in that directory  
   (the script will create `feedlingo_availability_config.json` and the supplemental XML file).

3. Open the script in your browser, for example:

   ```text
   https://yourserver.com/feedlingo_availability_date_fixer.php
   ```

4. Configure:

   - **Source feed**: URL or path of your main Google Shopping feed (XML or CSV)
   - **Target file**: path where the supplemental feed should be written, e.g.

     ```text
     /var/www/html/feeds/availability_supplement.xml
     ```

   - **Availability date offset** (days)
   - **Shop base URL** (used as `<link>` in the RSS `<channel>`)

5. Click **Save configuration**.

---

## Test run (manual)

The UI includes a **“Run test now”** button.

- It uses the current configuration
- Immediately generates the supplemental feed
- Shows a result message like:

  ```text
  Test run finished: OK (xml): 42 products → /var/www/html/feeds/availability_supplement.xml (availability_date = 2025-12-31T00:00:00Z)
  ```

Use this before setting up the cronjob to verify that:

- The source feed is readable
- The CSV columns map correctly
- The target file is writable

---

## Cronjob usage

Once the configuration is correct, you can set up a cronjob that calls:

```text
https://yourserver.com/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET_TOKEN
```

The UI shows the exact URL in the **Cronjob URL** box.

### Linux cron example

```cron
0 5 * * * /usr/bin/wget -qO- "https://yourserver.com/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET_TOKEN"   > /var/log/feedlingo_availability.log 2>&1
```

This runs the generator every day at 05:00, updates the supplemental feed and logs the output.

---

## Security

- A random **secret token** is generated on first run.
- The cron endpoint (`?run=1&secret=...`) will return `403` if the secret is missing or wrong.
- You can click **Regenerate secret** in the UI to invalidate old cron URLs.

For extra safety, you can also:
- Restrict access to the script using HTTP auth or IP allow-lists.
- Place it in a protected backend area of your server.

---

## Use case summary

This tool is ideal if:

- Your shop or ERP can only export a **basic feed** (XML/CSV) without `availability_date`.
- You have many **preorder / backorder** products where manual date maintenance is impossible.
- You want a **simple, local, transparent** solution without external SaaS dependencies.
- You need **Google Shopping compliance** for availability rules with minimum effort.

---

## License

MIT License

Copyright (c) 2025 Feedlingo

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.


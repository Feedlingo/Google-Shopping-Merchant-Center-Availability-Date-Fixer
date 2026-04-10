# Availability Date Fixer

**Availability Date Fixer** is a lightweight, self-hosted PHP tool that generates a **Google Shopping supplemental feed** for products that need an `availability_date`.

Many shops do not know the exact delivery date for **preorder** or **backorder** products, but Google Merchant Center requires an `availability_date` for these cases. This tool solves that by generating a supplemental feed where:

```text
availability_date = today + N days (UTC)
```

It is intentionally built as a **single-file solution** with a small auto-generated JSON config file in the same directory.

---

## What it does

- Reads your **main product feed** (XML or CSV)
- Skips products with `availability = in stock`
- Skips products with `availability = out of stock`
- Keeps products such as `preorder` or `backorder`
- Writes a separate **Google RSS supplemental feed** containing:
  - `g:id`
  - `g:availability`
  - `g:availability_date`
- Can be triggered via **cronjob**
- Also provides a **manual test run** in the browser UI
- Shows the configured **target file path**
- Tries to detect a **public feed URL** that can be copied into Merchant Center

---

## Files in this release

- `feedlingo_availability_date_fixer.php` ← main application file
- `README.md`
- `LICENSE`

At runtime, the script also creates:

- `feedlingo_availability_config.json` ← generated automatically after first save

---

## Update compatibility

This release keeps the existing main filename unchanged:

```text
feedlingo_availability_date_fixer.php
```

That means existing:

- script URLs
- cronjob URLs
- server paths
- bookmarks / documentation

can remain unchanged as long as you replace the file in the same location.

---

## Supported input formats

### XML

Google Shopping style RSS / Atom feeds are supported.

Example:

```xml
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <item>
      <g:id>SKU-123</g:id>
      <g:availability>preorder</g:availability>
    </item>
  </channel>
</rss>
```

### CSV

If XML parsing fails, the tool automatically falls back to CSV.

Requirements:

- header row required
- one ID column
- one availability column

Accepted ID headers include:

- `id`
- `g:id`
- `item_id`
- `item id`
- `product_id`
- `product id`

Accepted availability headers include:

- `availability`
- `g:availability`
- `availability_status`
- `availability status`
- `stock_status`
- `stock status`

Both `,` and `;` delimiters are supported.

---

## Output feed

The generated supplemental feed contains only relevant items and looks like this:

```xml
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <title>Feedlingo Availability Supplemental Feed</title>
    <link>https://www.your-shop.com/</link>
    <description>Automatically generated supplemental feed for availability_date</description>

    <item>
      <g:id>SKU-123</g:id>
      <g:availability>preorder</g:availability>
      <g:availability_date>2026-04-15T00:00:00Z</g:availability_date>
    </item>
  </channel>
</rss>
```

---

## Requirements

- PHP **5.6+**
- `DOM`, `SimpleXML`, `json`
- writable directory for config + target feed output

No database, no Composer, no framework.

---

## Installation

1. Upload `feedlingo_availability_date_fixer.php` to your server.
2. Open it in your browser.
3. Configure:
   - **Source feed**
   - **Target file**
   - **Availability date offset**
   - **Shop base URL**
4. Save the configuration.
5. Optionally run a **test run**.

The script stores its settings in:

```text
feedlingo_availability_config.json
```

in the same directory as the PHP file.

---

## Cronjob

After configuration, call:

```text
https://yourserver.com/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET_TOKEN
```

Example:

```cron
0 5 * * * /usr/bin/wget -qO- "https://yourserver.com/feedlingo_availability_date_fixer.php?run=1&secret=YOUR_SECRET_TOKEN" > /var/log/feedlingo_availability.log 2>&1
```

---

## Public feed URL detection

The UI shows the configured target file path and also tries to detect a **public URL** for the generated supplemental feed.

Detection works best when the target file is:

- inside the server `DOCUMENT_ROOT`, or
- inside the same web-accessible directory tree as the PHP script itself

If the file is outside the public web tree, the script cannot always determine a correct public URL automatically. In that case, the target path is still valid for writing the file, but you must choose or provide the public URL yourself.

---

## License

MIT License

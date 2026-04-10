<?php
/**
 * Feedlingo Availability-Date Fixer – Single-File-Version (XML + CSV)
 *
 * - Browser: configuration UI (also supports manual "Test run")
 * - Cron:    ?run=1&secret=XYZ  → generates supplemental feed
 *
 * Output feed contains:
 *   g:id
 *   g:availability
 *   g:availability_date = today + N days (UTC)
 *
 * Products with availability = in stock are skipped.
 *
 * Supports:
 * - XML source feeds (Google Shopping style RSS / Atom)
 * - CSV source feeds (id + availability columns)
 *
 * Compatible with PHP 5.6+
 */

$configFile = __DIR__ . '/feedlingo_availability_config.json';
$nsG        = 'http://base.google.com/ns/1.0';

/* -------------------------------------------------
   Random bytes autodetection (for cron secret)
--------------------------------------------------*/

function feedlingo_random_bytes($length = 12)
{
    // 1. PHP 7+: random_bytes
    if (function_exists('random_bytes')) {
        try {
            return random_bytes($length);
        } catch (Exception $e) {
            // fallback below
        }
    }

    // 2. PHP 5.3+: openssl_random_pseudo_bytes
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes  = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes !== false && $strong === true) {
            return $bytes;
        }
    }

    // 3. Last fallback: mt_rand-based (not cryptographically secure,
    //    but good enough for a cron secret)
    $bytes = '';
    for ($i = 0; $i < $length; $i++) {
        $bytes .= chr(mt_rand(0, 255));
    }
    return $bytes;
}

function feedlingo_generate_secret($length = 12)
{
    return bin2hex(feedlingo_random_bytes($length));
}

/* -------------------------------------------------
   Configuration handling
--------------------------------------------------*/

function feedlingo_load_config($configFile)
{
    if (file_exists($configFile)) {
        $data = json_decode(@file_get_contents($configFile), true);
        if (is_array($data)) {
            return $data;
        }
    }

    // Default config
    return array(
        'sourceFeed'  => 'https://www.your-shop.com/google-shopping.xml',
        'targetFeed'  => __DIR__ . '/google_availability_supplement.xml',
        'daysOffset'  => 5,
        'shopBaseUrl' => 'https://www.your-shop.com/',
        'cronSecret'  => feedlingo_generate_secret(12),
    );
}

function feedlingo_save_config($configFile, $config)
{
    @file_put_contents(
        $configFile,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

/* -------------------------------------------------
   CSV parser (id + availability)
--------------------------------------------------*/

/**
 * Parse CSV file and extract id + availability columns.
 *
 * @param string $sourceFeed  File path or URL
 * @param string &$error      Error message (if any)
 * @return array|null         Array of ['id'=>..., 'availability'=>...] or null on error
 */
function feedlingo_parse_csv($sourceFeed, &$error)
{
    $error = '';
    $items = array();

    $fh = @fopen($sourceFeed, 'r');
    if (!$fh) {
        $error = "Error: Could not open CSV source: " . $sourceFeed;
        return null;
    }

    // Detect delimiter from first line
    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        $error = "Error: CSV seems to be empty: " . $sourceFeed;
        return null;
    }

    $semiCount  = substr_count($firstLine, ';');
    $commaCount = substr_count($firstLine, ',');

    $delimiter = ($semiCount > $commaCount) ? ';' : ',';

    // Rewind and parse with chosen delimiter
    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter);
    if ($header === false || count($header) === 0) {
        fclose($fh);
        $error = "Error: CSV header row could not be read.";
        return null;
    }

    // Normalize header names
    $headerLower = array();
    for ($i = 0; $i < count($header); $i++) {
        $headerLower[$i] = strtolower(trim($header[$i]));
    }

    // Try to find id and availability columns
    $idCandidates = array('id', 'g:id', 'item_id', 'item id', 'product_id', 'product id');
    $avCandidates = array('availability', 'g:availability', 'availability_status', 'availability status', 'stock_status', 'stock status');

    $idIndex = -1;
    $avIndex = -1;

    foreach ($headerLower as $idx => $name) {
        if ($idIndex === -1 && in_array($name, $idCandidates, true)) {
            $idIndex = $idx;
        }
        if ($avIndex === -1 && in_array($name, $avCandidates, true)) {
            $avIndex = $idx;
        }
    }

    if ($idIndex === -1) {
        fclose($fh);
        $error = "Error: CSV header does not contain an ID column (expected e.g. id, g:id, item_id).";
        return null;
    }

    if ($avIndex === -1) {
        fclose($fh);
        $error = "Error: CSV header does not contain an availability column (expected e.g. availability, g:availability).";
        return null;
    }

    // Parse rows
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if (!isset($row[$idIndex])) {
            continue;
        }
        $id = trim($row[$idIndex]);
        if ($id === '') {
            continue;
        }
        $availabilityRaw = isset($row[$avIndex]) ? trim($row[$avIndex]) : '';
        $items[] = array(
            'id'           => $id,
            'availability' => $availabilityRaw,
        );
    }

    fclose($fh);
    return $items;
}

/* -------------------------------------------------
   Generate supplemental feed (XML or CSV source)
--------------------------------------------------*/

function feedlingo_generate_availability_feed($config, $nsG)
{
    $sourceFeed  = $config['sourceFeed'];
    $targetFeed  = $config['targetFeed'];
    $daysOffset  = (int)$config['daysOffset'];
    $shopBaseUrl = $config['shopBaseUrl'];

    $parsedItems = array();
    $mode        = '';
    $errors      = array();

    // Try XML first
    libxml_use_internal_errors(true);
    $mainXml = @simplexml_load_file($sourceFeed);
    if ($mainXml !== false) {
        $mainXml->registerXPathNamespace('g', $nsG);
        $rootName = $mainXml->getName();

        if ($rootName === 'rss') {
            $items = $mainXml->channel->item;
        } elseif ($rootName === 'feed') {
            $items = $mainXml->entry;
        } else {
            $items = $mainXml->xpath('//item | //entry');
        }

        if (!empty($items)) {
            foreach ($items as $item) {
                $sx = simplexml_import_dom(dom_import_simplexml($item));
                $g  = $sx->children($nsG);

                $id = isset($g->id) ? trim((string)$g->id) : '';
                if ($id === '') {
                    continue;
                }

                $availabilityRaw = isset($g->availability) ? trim((string)$g->availability) : '';

                $parsedItems[] = array(
                    'id'           => $id,
                    'availability' => $availabilityRaw,
                );
            }

            if (!empty($parsedItems)) {
                $mode = 'xml';
            }
        }
    } else {
        $xmlErrs = libxml_get_errors();
        foreach ($xmlErrs as $err) {
            $errors[] = trim($err->message);
        }
        libxml_clear_errors();
    }

    // If XML parsing did not succeed, try CSV
    if ($mode === '') {
        $csvError = '';
        $csvItems = feedlingo_parse_csv($sourceFeed, $csvError);
        if ($csvItems === null) {
            // both XML and CSV failed
            $msg  = "Error: Could not parse source feed as XML or CSV.\nSource: " . $sourceFeed . "\n";
            if (!empty($errors)) {
                $msg .= "XML errors:\n - " . implode("\n - ", $errors) . "\n";
            }
            if ($csvError !== '') {
                $msg .= "CSV error:\n - " . $csvError . "\n";
            }
            return $msg;
        }
        $parsedItems = $csvItems;
        $mode        = 'csv';
    }

    // Prepare target DOM
    $dom               = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttributeNS(
        'http://www.w3.org/2000/xmlns/',
        'xmlns:g',
        $nsG
    );
    $dom->appendChild($rss);

    $channel = $dom->createElement('channel');
    $rss->appendChild($channel);

    $channel->appendChild($dom->createElement('title', 'Feedlingo Availability Supplemental Feed'));
    $channel->appendChild($dom->createElement('link', $shopBaseUrl));
    $channel->appendChild($dom->createElement(
        'description',
        'Automatically generated supplemental feed for availability_date'
    ));

    // Calculate availability_date (UTC)
    $utc     = new DateTimeZone('UTC');
    $now     = new DateTime('now', $utc);
    $dt      = (clone $now)->modify('+' . $daysOffset . ' days');
    $dateStr = $dt->format('Y-m-d\TH:i:s\Z');

    $count = 0;

    foreach ($parsedItems as $row) {
        $id           = isset($row['id']) ? trim($row['id']) : '';
        $avRaw        = isset($row['availability']) ? trim($row['availability']) : '';
        $availability = strtolower($avRaw);

        if ($id === '') {
            continue;
        }

        // Skip products that should not be present in the supplemental feed
        if (
            $availability === 'in stock' ||
            $availability === 'instock' ||
            $availability === 'out of stock' ||
            $availability === 'outofstock'
        ) {
            continue;
        }

        // Default to "preorder" if empty
        if ($availability === '') {
            $availability = 'preorder';
        }

        $itemNode = $dom->createElement('item');
        $itemNode->appendChild($dom->createElement('g:id', $id));
        $itemNode->appendChild($dom->createElement('g:availability', $availability));
        $itemNode->appendChild($dom->createElement('g:availability_date', $dateStr));
        $channel->appendChild($itemNode);

        $count++;
    }

    $dom->save($targetFeed);

    return "OK (" . $mode . "): " . $count . " products → " . $targetFeed . " (availability_date = " . $dateStr . ")";
}


/* -------------------------------------------------
   Public URL detection (best effort)
--------------------------------------------------*/

function feedlingo_normalize_path_for_compare($path)
{
    $path = str_replace('\\', '/', (string)$path);
    return rtrim($path, '/');
}

function feedlingo_detect_public_file_url($absoluteTargetPath)
{
    if ($absoluteTargetPath === '') {
        return '';
    }

    $targetPath = feedlingo_normalize_path_for_compare($absoluteTargetPath);
    $scriptPath = isset($_SERVER['SCRIPT_FILENAME'])
        ? feedlingo_normalize_path_for_compare($_SERVER['SCRIPT_FILENAME'])
        : feedlingo_normalize_path_for_compare(__FILE__);

    $scriptDir = feedlingo_normalize_path_for_compare(dirname($scriptPath));
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host      = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $uriPath   = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
    $baseUrl   = $host !== '' ? $scheme . $host : '';

    if ($baseUrl === '') {
        return '';
    }

    // Case 1: target file is inside DOCUMENT_ROOT
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = feedlingo_normalize_path_for_compare($_SERVER['DOCUMENT_ROOT']);
        if ($docRoot !== '' && strpos($targetPath, $docRoot . '/') === 0) {
            $relative = substr($targetPath, strlen($docRoot));
            return $baseUrl . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $relative)));
        }
    }

    // Case 2: target file sits inside the same public directory tree as this script
    if ($scriptDir !== '' && strpos($targetPath, $scriptDir . '/') === 0 && $uriPath !== '') {
        $scriptWebDir = rtrim(str_replace('\\', '/', dirname($uriPath)), '/');
        $relative     = substr($targetPath, strlen($scriptDir));
        return $baseUrl . ($scriptWebDir === '' ? '' : $scriptWebDir) . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $relative)));
    }

    return '';
}

/* -------------------------------------------------
   Controller: cron vs. UI (with Test run)
--------------------------------------------------*/

$config = feedlingo_load_config($configFile);

// Cronjob mode: ?run=1&secret=XYZ
if (isset($_GET['run']) && $_GET['run'] == '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $sec = isset($_GET['secret']) ? $_GET['secret'] : '';

    if ($sec !== $config['cronSecret']) {
        http_response_code(403);
        echo "Error: Invalid secret token.\n";
        exit;
    }

    echo feedlingo_generate_availability_feed($config, $nsG) . "\n";
    exit;
}

$message = '';

// Browser UI: handle POST (save config + optional test run)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Merge posted values into config
    if (isset($_POST['sourceFeed'])) {
        $config['sourceFeed'] = trim($_POST['sourceFeed']);
    }
    if (isset($_POST['targetFeed'])) {
        $config['targetFeed'] = trim($_POST['targetFeed']);
    }
    if (isset($_POST['daysOffset'])) {
        $config['daysOffset'] = (int)$_POST['daysOffset'];
    }
    if (isset($_POST['shopBaseUrl'])) {
        $config['shopBaseUrl'] = trim($_POST['shopBaseUrl']);
    }
    if (isset($_POST['regenSecret'])) {
        $config['cronSecret'] = feedlingo_generate_secret(12);
    }

    feedlingo_save_config($configFile, $config);

    if (isset($_POST['test_run'])) {
        // Perform a manual test run
        $result  = feedlingo_generate_availability_feed($config, $nsG);
        $message = 'Test run finished: ' . $result;
    } elseif (isset($_POST['save_config'])) {
        $message = 'Configuration saved.';
    } else {
        $message = 'Configuration updated.';
    }
}

// Build cron URL for display
$scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$toolUrl = $scheme . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
$cronUrl       = $toolUrl . '?run=1&secret=' . urlencode($config['cronSecret']);
$publicFeedUrl = feedlingo_detect_public_file_url($config['targetFeed']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Feedlingo – Availability-Date Fixer</title>
    <style>
        :root {
            --bg-main: #05070b;
            --bg-panel: #131822;
            --border-panel: #262d3a;
            --accent: #00c2ff;
            --accent-soft: rgba(0,194,255,0.18);
            --text-main: #e5ecff;
            --text-muted: #9aa2b5;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #111827 0, #05070b 55%);
            color: var(--text-main);
        }
        .app {
            max-width: 960px;
            margin: 32px auto;
            padding: 0 16px 32px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(90deg, #0b1019, #151b26);
            border-radius: 16px;
            padding: 10px 18px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.04);
            margin-bottom: 20px;
        }
        .brand-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .brand-name {
            font-size: 1.9rem;
            font-weight: 700;
            background: linear-gradient(135deg, #00b4ff, #00e0ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .brand-subline {
            font-size: 0.9rem;
            color: var(--text-muted);
            padding-left: 10px;
            border-left: 1px solid rgba(255,255,255,0.12);
        }
        .brand-subline strong {
            color: #ffffff;
            font-weight: 600;
        }
        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px;
        }
        .panel {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 16px;
            border: 1px solid var(--border-panel);
            box-shadow: 0 12px 30px rgba(0,0,0,0.65);
        }
        .panel-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .panel-title {
            font-size: 0.95rem;
            font-weight: 600;
        }
        .panel-subtitle {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: var(--accent-soft);
            border: 1px solid rgba(0,194,255,0.5);
            font-size: 0.7rem;
            color: var(--accent);
        }
        label {
            display: block;
            margin-top: 12px;
            font-size: 0.8rem;
        }
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 7px 10px;
            border-radius: 9px;
            border: 1px solid #31384a;
            background: #0a0f18;
            color: var(--text-main);
            font-size: 0.8rem;
            outline: none;
        }
        input[type="text"]:focus,
        input[type="number"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px rgba(0,194,255,0.3);
        }
        .help {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .message {
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(0,194,255,0.12);
            border: 1px solid rgba(0,194,255,0.5);
            font-size: 0.8rem;
            color: #cceeff;
        }
        .btn-row {
            margin-top: 16px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        button {
            border-radius: 999px;
            border: none;
            padding: 7px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #00b4ff, #00e0ff);
            color: #021017;
        }
        .btn-secondary {
            background: #1f2933;
            color: var(--text-muted);
        }
        .btn-primary:hover {
            filter: brightness(1.07);
        }
        .btn-secondary:hover {
            background: #2a3542;
        }
        .cron-box {
            background: #050811;
            border-radius: 10px;
            padding: 8px 10px;
            margin-top: 6px;
            font-family: monospace;
            font-size: 0.7rem;
            color: #d0d8f5;
            border: 1px solid #252b3a;
            word-break: break-all;
        }
        .muted-block {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 8px;
            line-height: 1.4;
        }
        @media (max-width: 900px) {
            .grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="brand-block">
            <div class="brand-name">Feedlingo</div>
            <div class="brand-subline">
                Google Shopping <strong>availability_date</strong> fixer
            </div>
        </div>
    </header>

    <?php if (!empty($message)) : ?>
        <div class="message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="grid">
        <!-- Left panel: Source feed info -->
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">Source feed</div>
                    <div class="panel-subtitle">Google Shopping main feed (XML or CSV)</div>
                </div>
                <span class="badge">XML · CSV</span>
            </div>

            <label>Current source feed</label>
            <input type="text"
                   value="<?php echo htmlspecialchars($config['sourceFeed'], ENT_QUOTES, 'UTF-8'); ?>"
                   readonly>
            <div class="help">
                This URL or path is used by the cronjob. You can change it in the right panel.
            </div>

            <p class="muted-block">
                The tool reads your source feed as XML first. If that fails, it tries CSV.<br>
                Products with <code>in stock</code> or <code>out of stock</code> availability are skipped completely.<br>
                Only products such as <code>preorder</code> or <code>backorder</code> (or any other non-empty value except in-stock / out-of-stock)
                will appear in the supplemental feed – which is where <code>availability_date</code> is mandatory.
            </p>
        </section>

        <!-- Right panel: Settings & Cronjob -->
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">Settings &amp; cronjob</div>
                    <div class="panel-subtitle">Configure source, target file &amp; date offset</div>
                </div>
            </div>

            <form method="post">
                <label for="sourceFeed">Source feed (XML or CSV)</label>
                <input type="text" id="sourceFeed" name="sourceFeed"
                       value="<?php echo htmlspecialchars($config['sourceFeed'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="help">
                    URL or local path of your Google Shopping main feed.<br>
                    XML: standard Google Shopping feed<br>
                    CSV: must contain <code>id</code> and <code>availability</code> columns.
                </div>

                <label for="targetFeed">Target file (supplemental feed)</label>
                <input type="text" id="targetFeed" name="targetFeed"
                       value="<?php echo htmlspecialchars($config['targetFeed'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="help">
                    This file will be created/overwritten and used as a supplemental feed in Google Merchant Center.
                    The absolute path is fine for server-side writing; below you may also see a detected public URL for Merchant Center.
                </div>

                <label style="margin-top:12px;">Detected public feed URL</label>
                <?php if (!empty($publicFeedUrl)) : ?>
                    <div class="cron-box"><?php echo htmlspecialchars($publicFeedUrl, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="help">
                        You can usually enter this URL directly as a scheduled fetch URL in Google Merchant Center.
                    </div>
                <?php else : ?>
                    <div class="cron-box">Could not determine a public URL automatically for the current target path.</div>
                    <div class="help">
                        This can happen if the target file is outside the web-accessible directory tree. In that case, choose a web-accessible target path or provide the URL manually.
                    </div>
                <?php endif; ?>

                <label for="daysOffset">Availability date offset (days)</label>
                <input type="number" id="daysOffset" name="daysOffset" min="0" max="365"
                       value="<?php echo (int)$config['daysOffset']; ?>">
                <div class="help">
                    Example: <code>5</code> → <code>availability_date = today + 5 days (UTC)</code>.
                </div>

                <label for="shopBaseUrl">Shop base URL</label>
                <input type="text" id="shopBaseUrl" name="shopBaseUrl"
                       value="<?php echo htmlspecialchars($config['shopBaseUrl'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="help">
                    Used as &lt;link&gt; inside the &lt;channel&gt; of the supplemental feed.
                </div>

                <label>Cron secret token</label>
                <input type="text" readonly
                       value="<?php echo htmlspecialchars($config['cronSecret'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="help">
                    Protects the cronjob URL from unauthorized access.
                </div>

                <div class="btn-row">
                    <button type="submit" name="save_config" value="1" class="btn-primary">
                        Save configuration
                    </button>
                    <button type="submit" name="test_run" value="1" class="btn-secondary">
                        Run test now
                    </button>
                    <button type="submit" name="regenSecret" value="1" class="btn-secondary">
                        Regenerate secret
                    </button>
                </div>
            </form>

            <label style="margin-top:16px;">Cronjob URL</label>
            <div class="cron-box"><?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?></div>

            <label style="margin-top:12px;">Linux cron example</label>
            <div class="cron-box">
                0 5 * * * /usr/bin/wget -qO- "<?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?>"
                &gt; /var/log/feedlingo_availability.log 2&gt;&amp;1
            </div>

            <p class="muted-block">
                In Google Merchant Center, create a new <strong>supplemental feed</strong>, point it to the target file
                above and link it to your main feed via the <strong>ID</strong> field.
            </p>
        </section>
    </div>
</div>
</body>
</html>

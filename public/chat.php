<?php
// MZL Solution - website AI chat assistant (backend).
// Receives the visitor's conversation, asks Claude for a reply and returns it as JSON.
// The API key lives OUTSIDE public_html, in /home1/janmar94/mzl-chat-config.php:
//   <?php return ['anthropic_api_key' => 'sk-ant-...'];

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const HOME_DIR       = '/home1/janmar94';
const MAX_TURNS      = 20;    // messages per conversation
const MAX_CHARS      = 1200;  // per visitor message
const HOURLY_LIMIT   = 40;    // requests per IP per hour
const DAILY_LIMIT    = 400;   // requests per day for the whole site (cost cap)

function reply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

const FRIENDLY_ERROR = "Sorry, I'm having trouble connecting right now. Please reach our team at mzl@mzlsolution.com or call (645) 999-1556, we'll be glad to help.";

// Health check (no secrets): /chat.php?status=1
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['status'])) {
    $sdk = is_file(HOME_DIR . '/repositories/mzlsolution-site/server/vendor/autoload.php') || is_file(__DIR__ . '/../server/vendor/autoload.php');
    $cfg = is_file(HOME_DIR . '/mzl-chat-config.php');
    $key = false;
    if ($cfg) { $c = @include HOME_DIR . '/mzl-chat-config.php'; $k = is_array($c) ? (string)($c['anthropic_api_key'] ?? '') : ''; $key = strncmp($k, 'sk-ant-', 7) === 0; }
    reply(200, [
        'php_version' => PHP_VERSION,
        'php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'curl' => function_exists('curl_init'),
        'sdk_found' => $sdk,
        'config_file_found' => $cfg,
        'api_key_looks_valid' => $key || (bool)getenv('ANTHROPIC_API_KEY'),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'reply' => 'Method not allowed.']);
}

// ---------- Load SDK and config ----------
$autoloadCandidates = [
    HOME_DIR . '/repositories/mzlsolution-site/server/vendor/autoload.php',
    __DIR__ . '/../server/vendor/autoload.php',
];
$autoload = null;
foreach ($autoloadCandidates as $c) { if (is_file($c)) { $autoload = $c; break; } }
if ($autoload === null) { reply(500, ['ok' => false, 'reply' => FRIENDLY_ERROR]); }
require $autoload;

$apiKey = getenv('ANTHROPIC_API_KEY') ?: null;
$configFile = HOME_DIR . '/mzl-chat-config.php';
if (!$apiKey && is_file($configFile)) {
    $cfg = include $configFile;
    $apiKey = is_array($cfg) ? ($cfg['anthropic_api_key'] ?? null) : null;
}
if (!$apiKey) { reply(500, ['ok' => false, 'reply' => FRIENDLY_ERROR]); }

// ---------- Rate limiting (simple file-based counters, outside public_html) ----------
$dataDir = is_dir(HOME_DIR) ? HOME_DIR . '/mzl-chat-data' : sys_get_temp_dir() . '/mzl-chat-data';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0700, true); }

function bump(string $file, int $windowSeconds): int {
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) { return 0; }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $d = json_decode($raw ?: '', true) ?: ['start' => $now, 'count' => 0];
    if ($now - (int)$d['start'] >= $windowSeconds) { $d = ['start' => $now, 'count' => 0]; }
    $d['count']++;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN); fclose($fh);
    return (int)$d['count'];
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (bump($dataDir . '/ip_' . hash('sha256', $ip) . '.json', 3600) > HOURLY_LIMIT
    || bump($dataDir . '/site_daily.json', 86400) > DAILY_LIMIT) {
    reply(429, ['ok' => false, 'reply' => "We've had a lot of conversations today. Please send us your request through the form below or email mzl@mzlsolution.com, and a team member will help you personally."]);
}

// ---------- Validate the conversation ----------
$input = json_decode(file_get_contents('php://input') ?: '', true);
$history = is_array($input['messages'] ?? null) ? $input['messages'] : [];
$history = array_slice($history, -MAX_TURNS);

$messages = [];
foreach ($history as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = trim(mb_substr((string)($m['content'] ?? ''), 0, MAX_CHARS));
    if ($text === '') { continue; }
    // Merge consecutive messages from the same role.
    if ($messages && end($messages)['role'] === $role) {
        $messages[count($messages) - 1]['content'] .= "\n" . $text;
    } else {
        $messages[] = ['role' => $role, 'content' => $text];
    }
}
while ($messages && $messages[0]['role'] !== 'user') { array_shift($messages); }
if (!$messages || end($messages)['role'] !== 'user') {
    reply(400, ['ok' => false, 'reply' => 'Please type a message.']);
}

// ---------- System prompt ----------
$system = <<<PROMPT
You are the virtual assistant on the website of MZL Solution LLC, an IT field services and infrastructure company based in Orlando, Florida, serving businesses statewide across Florida. You are an AI assistant, and you say so honestly if asked; never claim to be a human.

Your role:
- Welcome visitors warmly, listen to their technology problem, and make them feel heard and reassured. Many people arrive stressed because something stopped working; acknowledge that first in a natural, human way ("That sounds frustrating, let's figure it out together.").
- Help them understand what might be going on and offer simple, safe first steps they can try themselves (restart the device, check cables and lights, confirm power, check if others are affected, note error messages).
- When the problem needs hands-on work, or the safe steps don't solve it, guide them to request a technician through the contact form on this page, by email at mzl@mzlsolution.com, or by phone at (645) 999-1556. Mention it naturally, not in every message.

What MZL Solution does: on-site field services and smart hands, structured cabling (Cat5e, Cat6, fiber), networking and Wi-Fi, servers and infrastructure (rack and stack, virtualization, backups), business VoIP and phone lines, CCTV/IP camera systems, commercial paging systems, and Tier 1-2 help desk support. Clients include MSPs and IT providers, field service platforms (Field Nation, WorkMarket), telecom/ISPs and integrators, and businesses such as medical offices, HOAs, retail and corporate offices. The company has more than 25 years of hands-on experience and carries General Liability insurance ($1M per occurrence / $2M aggregate).

Boundaries:
- Stay on topic: technology, IT, networking, phones, cameras, cabling and MZL Solution's services. If asked about something unrelated, kindly say you can only help with technology questions and MZL's services.
- Never quote prices, availability, arrival times or guarantees. Say the team will confirm a quote and schedule after reviewing the request.
- Never ask for passwords, payment details or other sensitive information, and tell visitors not to share them in the chat.
- Safety first: never suggest opening electrical panels, working with high voltage, climbing to dangerous heights, or opening power supplies. For anything involving smoke, burning smells, sparks or overheating equipment, tell them to power it off safely if they can do so without risk and to contact a professional; in an emergency, call 911.
- Do not make promises on behalf of the company or invent facts about it. If you don't know, say so and offer to connect them with the team.

Style:
- Warm, calm, friendly and professional, like an experienced technician who genuinely cares.
- Keep replies short: usually 2 to 5 sentences, or a few brief steps. Ask one simple question at a time when you need more details.
- Plain language, no jargon unless the visitor is clearly technical. Plain text only, no markdown headings or tables; short numbered steps are fine.
- Reply in the same language the visitor writes in.
PROMPT;

// ---------- Call Claude ----------
try {
    $client = new \Anthropic\Client(apiKey: $apiKey);
    $message = $client->beta->messages->create(
        model: 'claude-opus-5-5',
        maxTokens: 1024,
        system: [
            ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
        ],
        messages: $messages,
        outputConfig: ['effort' => 'low'],
        betas: ['server-side-fallback-2026-07-01'],
        fallbacks: 'default',
    );

    if ($message->stopReason === 'refusal') {
        reply(200, ['ok' => true, 'reply' => "I'm not able to help with that here, but our team is happy to talk with you directly at mzl@mzlsolution.com or (645) 999-1556."]);
    }

    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') { $text .= $block->text; }
    }
    $text = trim($text);
    if ($text === '') { reply(200, ['ok' => true, 'reply' => FRIENDLY_ERROR]); }

    reply(200, ['ok' => true, 'reply' => $text]);
} catch (\Anthropic\Core\Exceptions\RateLimitException $e) {
    reply(429, ['ok' => false, 'reply' => "I'm a bit busy at the moment. Please try again in a minute, or reach our team at mzl@mzlsolution.com or (645) 999-1556."]);
} catch (\Anthropic\Core\Exceptions\APIConnectionException $e) {
    reply(502, ['ok' => false, 'reply' => FRIENDLY_ERROR]);
} catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
    error_log('MZL chat API error: ' . $e->getMessage());
    reply(502, ['ok' => false, 'reply' => FRIENDLY_ERROR]);
} catch (\Throwable $e) {
    error_log('MZL chat error: ' . $e->getMessage());
    reply(500, ['ok' => false, 'reply' => FRIENDLY_ERROR]);
}

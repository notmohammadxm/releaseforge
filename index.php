<?php
declare(strict_types=1);
session_start();

/* ==========================================================================
   CONFIG
   ========================================================================== */
$AI_API_URL = getenv('AI_API_URL') ?: 'https://YOUR_API_BASE_URL/v1/chat/completions';
$AI_API_KEY = getenv('AI_API_KEY') ?: 'YOUR_API_KEY';
$AI_MODEL   = getenv('AI_MODEL')   ?: 'YOUR_MODEL';

/* ==========================================================================
   Jalali (Shamsi) conversion
   ========================================================================== */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
    $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
           + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy    = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy   += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/* ==========================================================================
   Clean any stray markdown / XML tags the model may still emit
   ========================================================================== */
function clean_output(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/<\/?(?:markdown|md|code|pre|output|result)[^>]*>/i', '', $s);
    $s = preg_replace('/^[ \t]*```[a-zA-Z0-9_\-\+]*[ \t]*$/m', '', $s);
    $s = str_replace('```', '', $s);
    $s = preg_replace('/^\s*===\s*(ENGLISH|PERSIAN|GIT)\s*===\s*$/mi', '', $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s);
}

/* ==========================================================================
   Split the raw AI reply into the 3 sections (tolerant parser)
   ========================================================================== */
function split_sections(string $raw): array
{
    $out = ['english' => '', 'persian' => '', 'git' => ''];

    if (preg_match('/===\s*ENGLISH\s*===(.*?)===\s*PERSIAN\s*===(.*?)===\s*GIT\s*===(.*)/si', $raw, $m)) {
        $out['english'] = clean_output($m[1]);
        $out['persian'] = clean_output($m[2]);
        $out['git']     = clean_output($m[3]);
        return $out;
    }

    $parts = preg_split('/===\s*(ENGLISH|PERSIAN|GIT)\s*===/i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($parts) >= 7) {
        $out['english'] = clean_output($parts[2] ?? '');
        $out['persian'] = clean_output($parts[4] ?? '');
        $out['git']     = clean_output($parts[6] ?? '');
        if ($out['english'] !== '' || $out['persian'] !== '' || $out['git'] !== '') {
            return $out;
        }
    }

    $out['english'] = clean_output($raw);
    return $out;
}

/* ==========================================================================
   cURL call to the AI endpoint
   ========================================================================== */
function call_ai(string $url, string $key, string $model, array $messages): array
{
    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.35,
        'max_tokens'  => 4000,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'خطای شبکه: ' . $err];
    }

    $json = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code . ' — ' . substr((string)$raw, 0, 240));
        return ['ok' => false, 'error' => 'خطای API: ' . $msg];
    }
    $content = $json['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        return ['ok' => false, 'error' => 'پاسخ خالی از مدل دریافت شد.'];
    }
    return ['ok' => true, 'content' => $content];
}

/* ==========================================================================
   AJAX API endpoint (POST with action=generate)
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $version = trim((string)($_POST['version'] ?? ''));
    $tasks   = trim((string)($_POST['tasks']   ?? ''));
    $jy      = (int)($_POST['jy'] ?? 0);
    $jm      = (int)($_POST['jm'] ?? 0);
    $jd      = (int)($_POST['jd'] ?? 0);

    if ($tasks === '') {
        echo json_encode(['ok' => false, 'error' => 'لطفاً تسک‌های انجام‌شده را وارد کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($version === '') {
        echo json_encode(['ok' => false, 'error' => 'لطفاً شماره نسخه را وارد کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        echo json_encode(['ok' => false, 'error' => 'تاریخ شمسی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dateStr = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

    $system = <<<'SYS'
You are "Release Forge", an expert release-notes writer and technical localizer.

You will receive a raw list of completed tasks/changes for a software release, plus a version string and a Jalali (Shamsi) date.

Reply with EXACTLY three sections, separated by these markers. Each marker must be on its own line, in this exact order, with nothing before the first marker and nothing after the last section:

===ENGLISH===
<content>

===PERSIAN===
<content>

===GIT===
<content>

ABSOLUTE RULES — VIOLATING THESE WILL BREAK THE PARSER:
1. DO NOT wrap your output in triple-backtick code fences (```).
2. DO NOT use <markdown>, </markdown>, <md>, <code>, <pre> or ANY XML/HTML tags.
3. Output ONLY the three sections and their markers. No intro, no outro, no apologies, no commentary.
4. In the English and Persian headers you MUST use the literal tokens {VERSION} and {DATE} exactly as written. Never replace them with real values.
5. Each bullet must be on its own line, starting with "* " (English/Git) or "- " (Persian).

--- ENGLISH SECTION FORMAT ---
**{VERSION} — {DATE}**

### Changes

* clear professional past-tense English, one per line
* ...

--- PERSIAN SECTION FORMAT ---
نسخه: *{VERSION}*
تاریخ: *{DATE}*

تغییرات:
 - concise natural Persian, one per line
 - ...

Then end the Persian section with exactly these two lines:
#release
#stable

--- GIT SECTION FORMAT ---
<type>: <short summary in imperative mood>
(type must be one of: feat, fix, chore, refactor, perf, docs, style)

- one bullet per change, imperative mood
- ...

If you can confidently infer file/area names from the tasks, append:

Files changed:
- path/to/file.ext

If you cannot infer any files, omit the whole "Files changed:" block entirely.

Merge duplicates, group related items, and keep wording concise.
SYS;

    $userMsg = "Version: {$version}\nDate: {$dateStr}\n\nCompleted tasks:\n" . $tasks;

    $res = call_ai($AI_API_URL, $AI_API_KEY, $AI_MODEL, [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $userMsg],
    ]);

    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sections = split_sections($res['content']);

    foreach ($sections as $k => $v) {
        $sections[$k] = str_replace(
            ['{VERSION}', '{DATE}'],
            [$version, $dateStr],
            $v
        );
    }

    echo json_encode([
        'ok'       => true,
        'version'  => $version,
        'date'     => $dateStr,
        'sections' => $sections,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ==========================================================================
   Page render — GET
   ========================================================================== */
[$todayJY, $todayJM, $todayJD] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));

$JALALI_MONTHS = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',   4 => 'تیر',
    5 => 'مرداد',    6 => 'شهریور',   7 => 'مهر',      8 => 'آبان',
    9 => 'آذر',     10 => 'دی',        11 => 'بهمن',   12 => 'اسفند',
];

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#090909">
<title>Release Forge — تولیدکننده هوشمند Release Notes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════════
   AURORA DESIGN SYSTEM · TOKENS
   ═══════════════════════════════════════════════════════════════ */
:root {
  --ink:#090909;
  --surface:#0c0c0c;
  --surface-2:#111111;
  --card:#141414;
  --card-hi:#1a1a1a;
  --g: rgba(255,255,255,.045);
  --gb: rgba(255,255,255,.09);
  --tx:#f2f2f2;
  --mu:#8a8a8a;
  --mu2:#b4b4b4;
  --line: rgba(255,255,255,.08);
  --line-2: rgba(255,255,255,.16);
  --line-3: rgba(255,255,255,.28);
  --glass: linear-gradient(180deg, rgba(255,255,255,.065), rgba(255,255,255,.018));
  --danger:#e5484d;
  --danger-soft: rgba(248,113,113,.18);
  --danger-text:#fca5a5;
  --success:#4ade80;
  --success-soft: rgba(74,222,128,.18);
  --success-text:#86efac;

  --e: cubic-bezier(.22,1,.36,1);
  --sp: cubic-bezier(.34,1.56,.64,1);
  --e2: cubic-bezier(.16,1,.3,1);
  --e3: cubic-bezier(.16,1,.3,1);
  --sm: cubic-bezier(.25,.8,.25,1);

  --shadow-sm: 0 2px 14px rgba(0,0,0,.3);
  --shadow-md: 0 8px 28px rgba(0,0,0,.45);
  --shadow-lg: 0 30px 80px -24px #000, inset 0 1px 0 rgba(255,255,255,.05);
  --shadow-glow: 0 24px 60px -30px rgba(255,255,255,.25);
  --ring: 0 0 0 1px rgba(255,255,255,.55), 0 0 0 5px rgba(255,255,255,.08);

  --serif: 'Source Serif 4', Georgia, serif;
  --sans: 'Vazirmatn', system-ui, -apple-system, sans-serif;
  --mono: 'JetBrains Mono', ui-monospace, Consolas, monospace;

  --r-xs: 8px;
  --r-sm: 10px;
  --r-md: 12px;
  --r-lg: 16px;
  --r-xl: 20px;
  --r-2xl: 24px;
  --r-3xl: 28px;

  --sb-w: 360px;
  --content-max: 760px;
}

/* ═══════════════════════════════════════════════════════════════
   RESET & BASE
   ═══════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; color-scheme: dark; -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  background: var(--ink);
  color: var(--tx);
  font: 400 15px/1.75 var(--sans);
  letter-spacing: .005em;
  text-rendering: optimizeLegibility;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
  background-image: radial-gradient(1200px 700px at 50% -10%, #161616 0%, var(--ink) 60%);
  background-attachment: fixed;
}
::selection { background: rgba(255,255,255,.92); color: #000; }
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,.14);
  border-radius: 10px;
  border: 3px solid transparent;
  background-clip: content-box;
}
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.3); background-clip: content-box; border: 3px solid transparent; }
a { color: inherit; text-decoration: none; }
button { font: inherit; color: inherit; }
input, textarea, select { font: inherit; color: inherit; }
code, pre { font-family: var(--mono); direction: ltr; unicode-bidi: isolate; }

/* ═══════════════════════════════════════════════════════════════
   LAYOUT · APP SHELL (sidebar on the right because dir=rtl)
   ═══════════════════════════════════════════════════════════════ */
.app {
  display: grid;
  grid-template-columns: 1fr var(--sb-w);
  min-height: 100vh;
}

/* ─── SIDEBAR ─── */
.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  background: linear-gradient(180deg, #0e0e0e, #0a0a0a);
  border-inline-start: 1px solid var(--line);
  display: flex;
  flex-direction: column;
  padding: 22px 20px 20px;
  z-index: 20;
  animation: sbIn .55s var(--e2) both;
}
@keyframes sbIn { from{opacity:0;transform:translateX(24px)} to{opacity:1;transform:none} }
[dir="rtl"] .sidebar { animation-name: sbInRtl; }
@keyframes sbInRtl { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:none} }
.brand {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 2px 2px 22px;
  font: 500 19px/1.2 var(--serif);
  letter-spacing: .01em;
}
.brand-mark {
  width: 32px; height: 32px;
  display: grid; place-items: center;
  border-radius: 50%;
  background: radial-gradient(circle at 32% 28%, #fff, rgba(255,255,255,.55) 18%, rgba(160,160,160,.75) 45%, rgba(80,80,80,.55) 72%, rgba(10,10,10,.9) 100%);
  box-shadow: 0 0 24px rgba(255,255,255,.25), inset -3px -4px 8px rgba(0,0,0,.45);
  flex: none;
  animation: brandFloat 4.5s ease-in-out infinite;
  transition: transform .4s var(--sp), box-shadow .4s var(--e);
}
.brand:hover .brand-mark {
  transform: scale(1.12) rotate(-12deg);
  box-shadow: 0 0 32px rgba(255,255,255,.4), inset -3px -4px 8px rgba(0,0,0,.45);
}
.brand-mark svg { width: 17px; height: 17px; color: #000; stroke-width: 2; }
@keyframes brandFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-3px)} }
.brand small { display:block; font: 500 11px var(--sans); color: var(--mu); letter-spacing: .04em; margin-top: 2px; }

.sb-section {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 2px 8px; font-size: 11.5px; font-weight: 600; color: var(--mu);
  letter-spacing: .06em;
}

/* Form fields inside sidebar */
.sidebar .field { max-width: none; }
.sidebar form { display: flex; flex-direction: column; gap: 16px; flex: 1; }

.date-grid { display: grid; grid-template-columns: 1fr 1.3fr .8fr; gap: 8px; }

.hint-row {
  display: flex; justify-content: space-between; align-items: center;
  gap: 8px; font-size: 11.5px; color: var(--mu); flex-wrap: wrap;
}
.hint-row b { color: var(--mu2); font-weight: 600; }
.char-count { font: 500 11.5px var(--mono); color: var(--mu); direction: ltr; transition: color .3s var(--e); display: inline-block; }
.char-count.warn { color: #f5c451; animation: ccPulse .9s ease-in-out infinite; }
.char-count.over { color: var(--danger-text); animation: ccPulse .6s ease-in-out infinite; }
@keyframes ccPulse { 0%,100%{opacity:1} 50%{opacity:.55} }

.sidebar-foot {
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--line);
}
.sidebar-foot .meta {
  display: flex; align-items: center; gap: 10px;
  font-size: 11.5px; color: var(--mu);
}
.sidebar-foot .meta b { color: var(--mu2); font-weight: 600; }
.kbd-hint {
  display: flex; justify-content: center; align-items: center; gap: 6px;
  margin-top: 12px; font-size: 11.5px; color: var(--mu);
}
kbd {
  display: inline-block; font: 600 10.5px var(--mono);
  padding: 2px 7px; border-radius: 6px;
  background: rgba(255,255,255,.07); border: 1px solid var(--line-2);
  color: var(--mu2);
}

/* ─── MAIN ─── */
.main { min-width: 0; }
.topbar {
  position: sticky; top: 0; z-index: 10;
  display: flex; align-items: center; gap: 12px;
  padding: 18px 36px;
  background: rgba(9,9,9,.75);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--line);
  animation: fadeIn .5s var(--e2) both;
}
.topbar h1 { font: 500 17px/1.3 var(--serif); margin: 0; flex: 1; }
.topbar .crumbs { color: var(--mu); font-size: 12px; }

.content { padding: 36px 36px 120px; max-width: var(--content-max); margin: 0 auto; }

/* ═══════════════════════════════════════════════════════════════
   ICONS
   ═══════════════════════════════════════════════════════════════ */
.ic { width: 20px; height: 20px; flex: none; transition: transform .3s var(--sm); pointer-events: none; }
.ic .ig { transform-box: fill-box; transform-origin: center; }
.ic .ig path, .ic .ig circle, .ic .ig rect, .ic .ig line, .ic .ig polyline, .ic .ig polygon {
  transition: transform .5s var(--sp), opacity .3s var(--e);
}
button:hover .ic-send .ig { transform: translateX(-2px); }
button:hover .ic-copy .ig { transform: translate(-1.2px,-1.2px) scale(1.07); }
button:hover .ic-dl .ig { animation: icDown .55s var(--e); }
@keyframes icDown { 0%,100%{transform:translateY(0)} 45%{transform:translateY(3px)} }
@keyframes icSpin { to { transform: rotate(360deg); } }

/* ═══════════════════════════════════════════════════════════════
   BUTTONS
   ═══════════════════════════════════════════════════════════════ */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  height: 38px; padding: 0 16px;
  border-radius: var(--r-sm);
  border: 1px solid var(--line);
  background: var(--glass);
  color: var(--tx);
  font-size: 13.5px; font-weight: 500; line-height: 1.4;
  white-space: nowrap; cursor: pointer;
  position: relative; overflow: hidden; isolation: isolate;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
  transition: background .25s var(--e), border-color .25s var(--e), color .2s, box-shadow .3s var(--e), transform .2s var(--sm), opacity .2s;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
}
.btn::after {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,.35) 0%, transparent 60%);
  opacity: 0; transform: scale(0);
  transition: opacity .5s var(--e), transform .5s var(--e);
  pointer-events: none; z-index: -1;
}
.btn:active::after { opacity: 1; transform: scale(2.2); transition: opacity .05s, transform .05s; }
.btn:hover { background: linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05)); border-color: var(--line-2); transform: translateY(-2px); }
.btn:active { transform: translateY(0) scale(.96); transition-duration: .1s; }
.btn:focus-visible { outline: 0; box-shadow: var(--ring); }
.btn:disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }
.btn .ic { width: 16px; height: 16px; }

.btn.pri {
  background: linear-gradient(180deg, #fff, #d8d8d8);
  color: #000; border-color: #fff; font-weight: 600;
  box-shadow: 0 8px 20px -10px rgba(255,255,255,.55), inset 0 -2px 0 rgba(0,0,0,.1);
  width: 100%; height: 46px; font-size: 14.5px; border-radius: var(--r-md);
}
.btn.pri:hover {
  background: linear-gradient(180deg, #fff, #e8e8e8);
  box-shadow: 0 16px 34px -10px rgba(255,255,255,.75), inset 0 -2px 0 rgba(0,0,0,.1);
  transform: translateY(-2px) scale(1.012);
}
.btn.pri:active { transform: translateY(0) scale(.98); }
.btn.sm { height: 30px; padding: 0 12px; font-size: 12px; gap: 5px; }
.btn.full { width: 100%; }

.btn.pri:not(:disabled) { animation: priBreathe 3.4s ease-in-out infinite; }
.btn.pri:hover, .btn.pri:disabled { animation: none; }
@keyframes priBreathe {
  0%,100% { box-shadow: 0 8px 20px -10px rgba(255,255,255,.55), inset 0 -2px 0 rgba(0,0,0,.1); }
  50% { box-shadow: 0 10px 28px -8px rgba(255,255,255,.8), inset 0 -2px 0 rgba(0,0,0,.1); }
}

/* ═══════════════════════════════════════════════════════════════
   INPUTS
   ═══════════════════════════════════════════════════════════════ */
.field { display: flex; flex-direction: column; gap: 7px; position: relative; width: 100%; }
.field > span { font-size: 12px; color: var(--mu2); font-weight: 500; }
.input {
  width: 100%; height: 44px; padding: 0 14px;
  border-radius: var(--r-md);
  background: rgba(255,255,255,.035);
  border: 1px solid var(--line-2);
  color: var(--tx); font-size: 14px; outline: 0;
  box-shadow: inset 0 1px 2px rgba(0,0,0,.4);
  transition: border-color .3s var(--e3), box-shadow .35s var(--e3), background .3s, transform .4s var(--e3);
}
.input::placeholder { color: #6d6d6d; transition: color .3s var(--e); }
.input:hover { border-color: rgba(255,255,255,.26); }
.input:hover::placeholder { color: #8a8a8a; }
.input:focus {
  background: rgba(255,255,255,.055);
  border-color: rgba(255,255,255,.7);
  box-shadow: 0 0 0 4px rgba(255,255,255,.09), inset 0 1px 2px rgba(0,0,0,.4);
  transform: translateY(-2px);
}
textarea.input {
  height: auto; min-height: 220px; padding: 12px 14px;
  line-height: 1.85; resize: vertical; font-family: inherit;
}
select.input {
  cursor: pointer; appearance: none;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
  background-repeat: no-repeat;
  background-position: left 12px center;
  padding-left: 32px;
}
select.input option { background: #141414; color: var(--tx); }
.field small { color: var(--mu); font-size: 11.5px; line-height: 1.6; }

/* ═══════════════════════════════════════════════════════════════
   BADGES
   ═══════════════════════════════════════════════════════════════ */
.badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: var(--r-sm);
  font: 700 11px/1 var(--mono); flex: none;
  background: #fff; color: #000;
  transition: transform .35s var(--sp);
}
.result-head:hover .badge { transform: rotate(-8deg) scale(1.08); }
.badge.en { background: #fff; color: #000; }
.badge.fa { background: #e8e8e8; color: #000; }
.badge.git { background: #cfcfcf; color: #000; }

/* ═══════════════════════════════════════════════════════════════
   ALERT
   ═══════════════════════════════════════════════════════════════ */
.alert {
  border-radius: var(--r-md); padding: 14px 16px; font-size: 13px;
  margin-bottom: 18px; display: flex; gap: 11px; align-items: flex-start;
  animation: alertIn .4s var(--sp) both;
}
.alert.err { background: var(--danger-soft); border: 1px solid rgba(248,113,113,.35); color: var(--danger-text); }
.alert .ic { width: 17px; height: 17px; flex-shrink: 0; margin-top: 1px; animation: alertShake .5s var(--e) .1s both; }
@keyframes alertIn { from{opacity:0;transform:translateY(-8px) scale(.97)} to{opacity:1;transform:none} }
@keyframes alertShake { 0%,100%{transform:rotate(0)} 25%{transform:rotate(-10deg)} 75%{transform:rotate(10deg)} }

/* ═══════════════════════════════════════════════════════════════
   RESULT CARDS
   ═══════════════════════════════════════════════════════════════ */
.results { display: flex; flex-direction: column; gap: 20px; }
.results-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  gap: 12px; flex-wrap: wrap; padding: 2px 2px 4px;
}
.results-toolbar .eyebrow {
  font: 600 12px/1.6 var(--sans); color: var(--mu);
  letter-spacing: .02em;
}
.toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.card.result {
  padding: 0; overflow: hidden;
  background: var(--glass);
  border: 1px solid var(--line);
  border-radius: var(--r-lg);
  animation: cardIn .55s var(--e2) both;
  transition: border-color .35s var(--e), transform .35s var(--e), box-shadow .4s var(--e);
}
.card.result:hover {
  border-color: var(--line-2);
  transform: translateY(-3px);
  box-shadow: 0 20px 44px -22px rgba(0,0,0,.8);
}
.card.result:nth-child(1){animation-delay:.04s}
.card.result:nth-child(2){animation-delay:.14s}
.card.result:nth-child(3){animation-delay:.24s}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(18px) scale(.985); }
  to   { opacity: 1; transform: none; }
}

.result-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; padding: 16px 20px; border-bottom: 1px solid var(--line); flex-wrap: wrap;
}
.result-title { display: flex; align-items: center; gap: 11px; font: 600 13.5px/1.6 var(--sans); color: #fff; }
.head-actions { display: flex; gap: 8px; align-items: center; }

pre.result-body {
  margin: 0; padding: 20px;
  font-family: var(--sans);
  font-size: 13.5px; line-height: 2;
  white-space: pre-wrap; word-wrap: break-word;
  color: var(--mu2);
  max-height: 520px; overflow: auto;
}
pre.result-body.ltr { direction: ltr; text-align: left; font-family: var(--mono); font-size: 12.8px; line-height: 1.85; }
pre.result-body.rtl { direction: rtl; text-align: right; }

/* ═══════════════════════════════════════════════════════════════
   SKELETON
   ═══════════════════════════════════════════════════════════════ */
.skeleton {
  height: 12px; border-radius: 6px;
  background: linear-gradient(90deg, rgba(255,255,255,.05) 0%, rgba(255,255,255,.18) 50%, rgba(255,255,255,.05) 100%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease-in-out infinite;
}
.skeleton + .skeleton { margin-top: 10px; }
.skeleton.w-30{width:30%}.skeleton.w-40{width:40%}.skeleton.w-50{width:50%}
.skeleton.w-60{width:60%}.skeleton.w-70{width:70%}.skeleton.w-80{width:80%}
.skeleton.w-90{width:90%}.skeleton.w-100{width:100%}
.skeleton-badge { width: 30px; height: 30px; border-radius: var(--r-sm); background: rgba(255,255,255,.08); animation: shimmer 1.4s ease-in-out infinite, skPop .5s var(--sp) both; background-size: 200% 100%; }
.skeleton-btn { height: 30px; width: 70px; border-radius: var(--r-sm); background: rgba(255,255,255,.06); animation: shimmer 1.4s ease-in-out infinite; background-size: 200% 100%; }
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
@keyframes skPop { from{opacity:0;transform:scale(.7)} to{opacity:1;transform:scale(1)} }

/* ═══════════════════════════════════════════════════════════════
   EMPTY STATE  (now uses a live point-cloud canvas instead of the
   static glow orb, matching the auth-page visualization)
   ═══════════════════════════════════════════════════════════════ */
.card.result.empty,
.empty {
  text-align: center;
  padding: 40px 24px 44px;
  color: var(--mu);
  animation: emptyIn .6s var(--e2) both;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 260px;
}
.empty-canvas {
  width: 130px;
  height: 130px;
  display: block;
  margin: 0 auto 8px;
  flex: none;
}
.empty h3 {
  color: #fff; font: 500 16px/1.6 var(--sans); margin: 8px 0 10px;
  animation: emptyIn .6s var(--e2) .08s both;
}
.empty p {
  font: 400 13px/1.9 var(--sans); color: var(--mu);
  max-width: 320px; margin: 0 auto;
  animation: emptyIn .6s var(--e2) .15s both;
}
.empty p strong { color: #fff; font-weight: 600; }
@keyframes emptyIn { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:none} }

/* ═══════════════════════════════════════════════════════════════
   TOAST — monochrome, consistent with the Aurora system
   ═══════════════════════════════════════════════════════════════ */
.toast-stack {
  position: fixed; top: 22px; left: 22px; z-index: 9999;
  display: flex; flex-direction: column; gap: 10px;
  pointer-events: none;
}
.toast {
  position: relative; overflow: hidden; isolation: isolate;
  background: linear-gradient(180deg, rgba(26,26,26,.95), rgba(14,14,14,.95));
  border: 1px solid var(--line-2); color: var(--tx);
  padding: 13px 16px 13px 14px; border-radius: var(--r-md);
  font: 500 13px/1.5 var(--sans);
  box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,.06);
  backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  display: flex; align-items: center; gap: 11px;
  min-width: 220px; max-width: 320px;
  transform: translateX(-130%) scale(.92); opacity: 0;
  transition: transform .45s var(--sp), opacity .3s ease;
}
.toast.show { transform: translateX(0) scale(1); opacity: 1; }
.toast.out {
  opacity: 0; transform: translateX(-30%) scale(.94);
  transition: opacity .3s var(--e), transform .3s var(--e);
}
.toast-dot {
  width: 30px; height: 30px; border-radius: 50%; flex: none;
  display: grid; place-items: center;
  background: radial-gradient(circle at 32% 28%, #fff, rgba(220,220,220,.7) 45%, rgba(60,60,60,.7) 100%);
  box-shadow: 0 0 14px rgba(255,255,255,.3), inset -2px -2px 4px rgba(0,0,0,.35);
  animation: toastDotPop .45s var(--sp) both;
}
.toast.bad .toast-dot {
  background: radial-gradient(circle at 32% 28%, #3a3a3a, rgba(20,20,20,.9) 70%);
  box-shadow: 0 0 10px rgba(0,0,0,.4), inset 0 0 0 1px rgba(255,255,255,.14);
}
.toast-dot .ic { width: 15px; height: 15px; color: #000; }
.toast.bad .toast-dot .ic { color: #fff; }
.toast-msg { flex: 1; min-width: 0; }
.toast-bar {
  position: absolute; inset-inline-start: 0; bottom: 0; height: 2px;
  background: linear-gradient(90deg, rgba(255,255,255,.75), rgba(255,255,255,.15));
  animation: toastDrain 2.6s linear forwards;
  transform-origin: left;
}
[dir="rtl"] .toast-bar { transform-origin: right; }
@keyframes toastDotPop { from{opacity:0;transform:scale(.4) rotate(-20deg)} to{opacity:1;transform:scale(1) rotate(0)} }
@keyframes toastDrain { from{transform:scaleX(1)} to{transform:scaleX(0)} }

/* ═══════════════════════════════════════════════════════════════
   SPINNER / MISC
   ═══════════════════════════════════════════════════════════════ */
.spinner { width: 16px; height: 16px; border: 2px solid rgba(0,0,0,.28); border-top-color: #000; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }

footer.page-footer { text-align: center; margin-top: 50px; font-size: 11.5px; color: #595959; }
footer.page-footer span { color: var(--mu2); font-weight: 600; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════════════════════════════ */
@media (max-width: 980px) {
  .app { grid-template-columns: 1fr; }
  .sidebar {
    position: static; height: auto; border-inline-start: none;
    border-bottom: 1px solid var(--line);
  }
  .content { padding: 28px 18px 90px; }
  .topbar { padding: 14px 18px; }
}
@media (max-width: 560px) {
  .date-grid { grid-template-columns: 1fr; }
  pre.result-body { padding: 14px; font-size: 12.8px; }
  .card.result.empty, .empty { padding: 32px 18px 36px; }
  .empty-canvas { width: 110px; height: 110px; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
}
</style>
</head>
<body>

<div class="toast-stack" id="toastStack"></div>

<div class="app">

  <!-- ═══════════ MAIN CONTENT ═══════════ -->
  <div class="main">
    <div class="topbar">
      <h1>Release Forge</h1>
      <span class="crumbs">تولیدکننده خروجی ریلیز</span>
    </div>

    <div class="content">
      <div id="alerts"></div>
      <div id="results" class="results" aria-live="polite"></div>

      <footer class="page-footer">
        Release Forge · طراحی‌شده با <span>Aurora Design System</span>
      </footer>
    </div>
  </div>

  <!-- ═══════════ SIDEBAR (right, form) ═══════════ -->
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 L15 9 L22 12 L15 15 L12 22 L9 15 L2 12 L9 9 Z"/></svg>
      </span>
      <div>
        Release Forge
        <small>AI Release Notes</small>
      </div>
    </div>

    <div class="sb-section">ورودی‌ها</div>

    <form id="forgeForm" autocomplete="off">
      <div class="field">
        <span>شماره نسخه</span>
        <input type="text" id="version" name="version" class="input" value="1.0.0" placeholder="1.4.0" spellcheck="false">
      </div>

      <div class="field">
        <span>تاریخ انتشار (شمسی)</span>
        <div class="date-grid">
          <select name="jy" id="sel-jy" class="input" aria-label="سال">
            <?php for ($y = $todayJY - 2; $y <= $todayJY + 3; $y++): ?>
              <option value="<?= $y ?>" <?= $y === $todayJY ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
          <select name="jm" id="sel-jm" class="input" aria-label="ماه">
            <?php foreach ($JALALI_MONTHS as $num => $name): ?>
              <option value="<?= $num ?>" <?= $num === $todayJM ? 'selected' : '' ?>><?= $h($name) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="jd" id="sel-jd" class="input" aria-label="روز">
            <?php for ($d = 1; $d <= 31; $d++): ?>
              <option value="<?= $d ?>" <?= $d === $todayJD ? 'selected' : '' ?>><?= sprintf('%02d', $d) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="hint-row">
          <span>امروز: <?= sprintf('%04d/%02d/%02d', $todayJY, $todayJM, $todayJD) ?></span>
          <b id="liveDate"></b>
        </div>
      </div>

      <div class="field">
        <span>تسک‌های انجام‌شده</span>
        <textarea id="tasks" name="tasks" class="input" spellcheck="false" maxlength="8000"
placeholder="هر خط یک تسک. مثال:

افزودن پشتیبانی کامل PWA با حالت آفلاین
افزودن تقویم رویدادهای مدرسه با هدف‌گذاری پایه
افزودن گزارش پیشرفت فردی دانش‌آموزان
بهبود امنیت احراز هویت توسعه‌دهندگان
به‌روزرسانی رابط کاربری با تم رنگی ملایم"></textarea>
        <div class="hint-row">
          <span>به هر شکلی بنویس — ایجنت بازنویسی و فرمت می‌کنه.</span>
          <span class="char-count" id="charCount">۰ / ۸۰۰۰</span>
        </div>
      </div>

      <button class="btn pri" type="submit" id="submitBtn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="btnIcon" style="width:18px;height:18px">
          <path d="M12 2 L15 9 L22 12 L15 15 L12 22 L9 15 L2 12 L9 9 Z"/>
        </svg>
        <span id="btnText">تولید Release Notes</span>
      </button>
    </form>

    <div class="sidebar-foot">
      <div class="kbd-hint">
        <kbd>Ctrl</kbd><span>+</span><kbd>Enter</kbd><span>برای تولید سریع</span>
      </div>
    </div>
  </aside>

</div>

<script>
(function () {
    'use strict';

    /* ---------------- Elements ---------------- */
    const form         = document.getElementById('forgeForm');
    const tasksEl       = document.getElementById('tasks');
    const versionEl     = document.getElementById('version');
    const charCount     = document.getElementById('charCount');
    const submitBtn     = document.getElementById('submitBtn');
    const btnText       = document.getElementById('btnText');
    const resultsEl      = document.getElementById('results');
    const alertsEl       = document.getElementById('alerts');
    const toastStack     = document.getElementById('toastStack');
    const selJY          = document.getElementById('sel-jy');
    const selJM          = document.getElementById('sel-jm');
    const selJD          = document.getElementById('sel-jd');
    const liveDate       = document.getElementById('liveDate');

    const MAX_CHARS = 8000;
    const FA_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    function toFaDigits(n) {
        return String(n).replace(/\d/g, d => FA_DIGITS[d]);
    }

    /* ---------------- Toast ---------------- */
    function showToast(message, type) {
        type = type || 'ok';
        const icons = {
            ok:  '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            bad: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
        };
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        el.innerHTML =
            '<span class="toast-dot">' + (icons[type] || icons.ok) + '</span>' +
            '<span class="toast-msg">' + escapeHtml(message) + '</span>' +
            '<span class="toast-bar"></span>';
        toastStack.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        setTimeout(function(){
            el.classList.add('out');
            el.classList.remove('show');
            setTimeout(function(){ el.remove(); }, 320);
        }, 2600);
    }

    /* ---------------- Alert banner ---------------- */
    function showAlert(msg) {
        alertsEl.innerHTML =
            '<div class="alert err">' +
            '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
            '<div>' + escapeHtml(msg) + '</div></div>';
    }
    function clearAlert(){ alertsEl.innerHTML = ''; }

    /* ---------------- Utils ---------------- */
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    /* ---------------- Character count ---------------- */
    function updateCharCount() {
        const n = tasksEl.value.length;
        charCount.textContent = toFaDigits(n) + ' / ' + toFaDigits(MAX_CHARS);
        charCount.classList.toggle('warn', n > MAX_CHARS * 0.85 && n <= MAX_CHARS);
        charCount.classList.toggle('over', n > MAX_CHARS);
    }
    tasksEl.addEventListener('input', updateCharCount);
    updateCharCount();

    /* ---------------- Live Jalali date label ---------------- */
    function updateLiveDate() {
        liveDate.textContent = selJY.value + '/' +
            String(selJM.value).padStart(2,'0') + '/' +
            String(selJD.value).padStart(2,'0');
    }
    [selJY, selJM, selJD].forEach(function(s){ s.addEventListener('change', updateLiveDate); });
    updateLiveDate();

    /* ---------------- Skeleton rendering ---------------- */
    function skeletonHTML() {
        const labels = [
            { badge:'EN', title:'فرمت انگلیسی' },
            { badge:'FA', title:'فرمت فارسی' },
            { badge:'GIT', title:'فرمت Git Commit' }
        ];
        return labels.map(function(l){
            return '' +
            '<div class="card result">' +
              '<div class="result-head">' +
                '<div style="display:flex;align-items:center;gap:11px">' +
                  '<div class="skeleton-badge"></div>' +
                  '<div class="skeleton w-40" style="height:13px"></div>' +
                '</div>' +
                '<div class="skeleton-btn"></div>' +
              '</div>' +
              '<div style="padding:18px">' +
                '<div class="skeleton w-40"></div>' +
                '<div class="skeleton w-60"></div>' +
                '<div class="skeleton w-100"></div>' +
                '<div class="skeleton w-90"></div>' +
                '<div class="skeleton w-80"></div>' +
                '<div class="skeleton w-70"></div>' +
                '<div class="skeleton w-100"></div>' +
                '<div class="skeleton w-50"></div>' +
              '</div>' +
            '</div>';
        }).join('');
    }

    /* ---------------- Result card rendering ---------------- */
    function copyIconSVG() {
        return '<svg class="ic ic-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><g class="ig"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></g></svg>';
    }
    function dlIconSVG() {
        return '<svg class="ic ic-dl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><g class="ig"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></g></svg>';
    }

    function resultCardHTML(cfg) {
        return '' +
        '<div class="card result">' +
          '<div class="result-head">' +
            '<div class="result-title">' +
              '<div class="badge ' + cfg.key + '">' + cfg.badge + '</div>' +
              cfg.title +
            '</div>' +
            '<div class="head-actions">' +
              '<button type="button" class="btn sm copy-btn" data-target="out-' + cfg.key + '">' +
                copyIconSVG() + '<span>کپی</span>' +
              '</button>' +
              '<button type="button" class="btn sm dl-btn" data-target="out-' + cfg.key + '" data-name="' + cfg.filename + '">' +
                dlIconSVG() + '<span>ذخیره</span>' +
              '</button>' +
            '</div>' +
          '</div>' +
          '<pre id="out-' + cfg.key + '" class="result-body ' + (cfg.rtl ? 'rtl' : 'ltr') + '"></pre>' +
        '</div>';
    }

    function renderResults(sections, version, dateStr) {
        const toolbar =
            '<div class="results-toolbar">' +
              '<div class="eyebrow">خروجی تولیدشده</div>' +
              '<div class="toolbar-actions">' +
                '<button type="button" class="btn sm" id="copyAllBtn">' + copyIconSVG() + '<span>کپی همه</span></button>' +
                '<button type="button" class="btn sm" id="downloadAllBtn">' + dlIconSVG() + '<span>ذخیره همه</span></button>' +
              '</div>' +
            '</div>';

        const slug = String(version).replace(/[^a-z0-9._-]/gi, '_');

        const html = toolbar +
            resultCardHTML({ key:'en',  badge:'EN',  title:'فرمت انگلیسی', rtl:false, filename: 'release-' + slug + '-en.md' }) +
            resultCardHTML({ key:'fa',  badge:'FA',  title:'فرمت فارسی',  rtl:true,  filename: 'release-' + slug + '-fa.md' }) +
            resultCardHTML({ key:'git', badge:'git', title:'فرمت Git Commit', rtl:false, filename: 'release-' + slug + '-commit.txt' });

        resultsEl.innerHTML = html;

        document.getElementById('out-en').textContent  = sections.english || '(خالی)';
        document.getElementById('out-fa').textContent  = sections.persian || '(خالی)';
        document.getElementById('out-git').textContent = sections.git     || '(خالی)';

        resultsEl.querySelectorAll('.copy-btn[data-target]').forEach(function(btn){
            btn.addEventListener('click', function(){ copyFromBtn(btn); });
        });
        resultsEl.querySelectorAll('.dl-btn[data-target]').forEach(function(btn){
            btn.addEventListener('click', function(){ downloadFromBtn(btn); });
        });
        document.getElementById('copyAllBtn').addEventListener('click', function(){
            const all = '=== ENGLISH ===\n' + (sections.english||'') +
                        '\n\n=== PERSIAN ===\n' + (sections.persian||'') +
                        '\n\n=== GIT ===\n' + (sections.git||'');
            copyText(all).then(function(){ showToast('هر سه فرمت کپی شد', 'ok'); });
        });
        document.getElementById('downloadAllBtn').addEventListener('click', function(){
            const all = '# Release ' + version + ' — ' + dateStr + '\n\n' +
                        '## English\n\n' + (sections.english||'') + '\n\n---\n\n' +
                        '## Persian\n\n' + (sections.persian||'') + '\n\n---\n\n' +
                        '## Git\n\n' + (sections.git||'') + '\n';
            downloadFile('release-' + slug + '-all.md', all);
            showToast('فایل ترکیبی ذخیره شد', 'ok');
        });
    }

    /* ---------------- Copy ---------------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject){
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                ta.style.pointerEvents = 'none';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                resolve();
            } catch (e) { reject(e); }
        });
    }

    function copyFromBtn(btn) {
        const target = document.getElementById(btn.dataset.target);
        if (!target) return;
        const text = target.textContent;
        copyText(text).then(function(){
            const label = btn.querySelector('span');
            const original = label.textContent;
            label.textContent = 'کپی شد!';
            showToast('کپی شد', 'ok');
            setTimeout(function(){ label.textContent = original; }, 1600);
        }).catch(function(){
            showToast('کپی ناموفق بود', 'bad');
        });
    }

    /* ---------------- Download ---------------- */
    function downloadFile(filename, content) {
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function(){ URL.revokeObjectURL(url); }, 500);
    }

    function downloadFromBtn(btn) {
        const target = document.getElementById(btn.dataset.target);
        if (!target) return;
        downloadFile(btn.dataset.name || 'release.md', target.textContent);
        showToast('ذخیره شد', 'ok');
    }

    /* ════════════════════════════════════════════════════════════
       EMPTY-STATE POINT-CLOUD CANVAS
       (same 3D particle sphere as the auth page, just smaller)
       ════════════════════════════════════════════════════════════ */
    function initEmptyCanvas(canvas) {
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const NP = 420;
        const pts = [];
        const GA = Math.PI * (3 - Math.sqrt(5));
        for (let i = 0; i < NP; i++) {
            const y = 1 - (i + .5) / NP * 2;
            const r = Math.sqrt(1 - y * y);
            const th = i * GA;
            pts.push({
                bx: Math.cos(th) * r,
                by: y,
                bz: Math.sin(th) * r,
                sz: Math.random()
            });
        }

        let W = 1, H = 1, dpr = 1;
        const fit = () => {
            const rect = canvas.getBoundingClientRect();
            W = Math.max(1, rect.width);
            H = Math.max(1, rect.height);
            dpr = Math.min(devicePixelRatio || 1, 2);
            canvas.width  = Math.round(W * dpr);
            canvas.height = Math.round(H * dpr);
        };
        fit();
        if (window.ResizeObserver) {
            try { new ResizeObserver(fit).observe(canvas); } catch (_) {}
        }

        let yaw = 0.6;
        let last = performance.now();
        const draw = (t) => {
            if (!canvas.isConnected) return; // stop loop if removed from DOM
            const dt = Math.min(.05, (t - last) / 1000 || .016);
            last = t;
            yaw += 0.32 * dt;

            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, W, H);
            ctx.fillStyle = '#fff';

            const sc = Math.min(W, H) * .40;
            const ox = W / 2, oy = H / 2;
            const cy = Math.cos(yaw), sy = Math.sin(yaw);
            const cp = Math.cos(.3),  sp = Math.sin(.3);

            for (let i = 0; i < pts.length; i++) {
                const p = pts[i];
                const x1 = p.bx * cy + p.bz * sy;
                const z1 = -p.bx * sy + p.bz * cy;
                const y1 = p.by * cp - z1 * sp;
                const z2 = p.by * sp + z1 * cp;
                const f = 3 / (3 + z2);
                const d = (1 - z2) / 2;
                const alpha = .08 + .60 * d * d;
                const s = (.6 + p.sz * .9) * f;
                ctx.globalAlpha = alpha;
                ctx.fillRect(ox + x1 * sc * f - s / 2, oy + y1 * sc * f - s / 2, s, s);
            }
            ctx.globalAlpha = 1;
            requestAnimationFrame(draw);
        };
        requestAnimationFrame(draw);
    }

    /* ---------------- Empty state ---------------- */
    function emptyStateHTML() {
        return '' +
        '<div class="card result empty">' +
          '<canvas class="empty-canvas" aria-hidden="true"></canvas>' +
          '<h3>هنوز چیزی تولید نشده</h3>' +
          '<p>فرم سمت راست را پر کن و روی <strong>تولید Release Notes</strong> بزن تا سه خروجی فرمت‌شده اینجا ظاهر بشه.</p>' +
        '</div>';
    }

    function setEmptyState() {
        resultsEl.innerHTML = emptyStateHTML();
        const cv = resultsEl.querySelector('.empty-canvas');
        if (cv) initEmptyCanvas(cv);
    }

    /* ---------------- Submit (AJAX) ---------------- */
    let inFlight = false;

    function setLoading(loading) {
        inFlight = loading;
        submitBtn.disabled = loading;
        if (loading) {
            const icon = document.getElementById('btnIcon');
            icon.outerHTML = '<div class="spinner" id="btnIcon"></div>';
            btnText.textContent = 'در حال تولید…';
        } else {
            const icon = document.getElementById('btnIcon');
            if (icon && icon.tagName.toLowerCase() !== 'svg') {
                icon.outerHTML =
                    '<svg id="btnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">' +
                    '<path d="M12 2 L15 9 L22 12 L15 15 L12 22 L9 15 L2 12 L9 9 Z"/></svg>';
            }
            btnText.textContent = 'تولید Release Notes';
        }
    }

    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (inFlight) return;

        const version = versionEl.value.trim();
        const tasks   = tasksEl.value.trim();

        clearAlert();

        if (!tasks) {
            showAlert('لطفاً تسک‌های انجام‌شده را وارد کنید.');
            tasksEl.focus();
            return;
        }
        if (!version) {
            showAlert('لطفاً شماره نسخه را وارد کنید.');
            versionEl.focus();
            return;
        }

        try { localStorage.setItem('releaseForge.lastVersion', version); } catch(_){}

        resultsEl.innerHTML = skeletonHTML();
        setLoading(true);

        const fd = new FormData();
        fd.append('action',  'generate');
        fd.append('version', version);
        fd.append('tasks',   tasks);
        fd.append('jy',      selJY.value);
        fd.append('jm',      selJM.value);
        fd.append('jd',      selJD.value);

        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.json().catch(function(){ throw new Error('پاسخ نامعتبر از سرور'); }); })
        .then(function(data){
            if (!data.ok) {
                setEmptyState();
                showAlert(data.error || 'خطای نامشخص');
                showToast('تولید ناموفق بود', 'bad');
                return;
            }
            renderResults(data.sections, data.version, data.date);
            showToast('Release notes تولید شد', 'ok');
        })
        .catch(function(err){
            setEmptyState();
            showAlert(err.message || 'خطای شبکه');
            showToast('خطای شبکه', 'bad');
        })
        .finally(function(){ setLoading(false); });
    });

    /* ---------------- Keyboard shortcut ---------------- */
    document.addEventListener('keydown', function(e){
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            if (document.activeElement === tasksEl || document.activeElement === versionEl) {
                e.preventDefault();
                form.requestSubmit();
            }
        }
    });

    /* ---------------- Init ---------------- */
    try {
        const lastV = localStorage.getItem('releaseForge.lastVersion');
        if (lastV) versionEl.value = lastV;
    } catch(_){}

    function autoGrow() {
        tasksEl.style.height = 'auto';
        tasksEl.style.height = Math.max(220, tasksEl.scrollHeight) + 'px';
    }
    tasksEl.addEventListener('input', autoGrow);
    autoGrow();

    setEmptyState();

})();
</script>

</body>
</html>

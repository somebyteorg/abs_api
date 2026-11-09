<?php

return [
    'max_file_size' => 1024 * 1024 * 1024,
    'max_cover_size' => 20 * 1024 * 1024,
    'chunk_size' => 16 * 1024 * 1024,
    'max_chunk_size' => 90 * 1024 * 1024,
    'audio_extensions' => ['mp3', 'm4a', 'm4b', 'aac', 'flac', 'ogg', 'opus', 'wav', 'wma', 'aiff'],
    'cover_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    'cover_compression' => [
        'max_size' => 1024 * 1024,
        'max_dimension' => 1800,
        'initial_quality' => 0.86,
        'mime_type' => 'image/webp',
    ],
    'notice' => [
        'version' => '2026-11-08',
        'text' => <<<'TEXT'
这是一个由社区共同维护的有声书与播客资源库。

欢迎上传音频、封面，共同完善资源。

请在上传时核对作品、季数和集数。每个章节默认对应一份音频；如存在多个版本，请指定唯一主资源，收听端只展示主资源。
TEXT,
    ],
];

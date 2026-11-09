<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AudioMetadata
{
    public function probe(string $path): array
    {
        $url = rtrim((string) config('services.metadata.global'), '/');
        abort_if($url === '', 503, '音频元数据服务还没有配置，文件分片已保留，可以稍后重试。');
        $absolutePath = Storage::disk('abs')->path($path);
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(60)->post($url.'/ffprobe', ['url' => $absolutePath]);
        } catch (ConnectionException $exception) {
            abort(502, '音频元数据服务暂时没连上，分片已保留，可以稍后重试。');
        }
        abort_unless($response->successful(), 502, '音频元数据读取失败，请核查服务能否访问上传路径后重试。');
        $metadata = $response->json();
        abort_unless(is_array($metadata), 422, '元数据响应格式不正确。');
        $audio = collect($metadata['streams'] ?? [])->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'audio');
        $duration = (float) ($metadata['format']['duration'] ?? $audio['duration'] ?? 0);
        abort_unless($audio && is_finite($duration) && $duration > 0, 422, '这个文件没有可读取的音轨或时长。');

        return ['duration' => $duration, 'probe' => $metadata];
    }
}

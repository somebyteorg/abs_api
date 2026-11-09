<?php

namespace App\Services;

use App\Models;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadChunks
{
    public function received(Models\UploadSession $upload): array
    {
        if ($upload->status === 'completed') {
            return [];
        }
        $disk = Storage::disk('abs');
        $received = [];
        for ($index = 0; $index < $upload->chunk_count; $index++) {
            if ($disk->exists($this->directory($upload->id).'/'.$index)) {
                $received[] = $index;
            }
        }

        return $received;
    }

    public function store(Models\UploadSession $upload, int $index, UploadedFile $chunk): void
    {
        abort_unless($upload->status === 'uploading', 409, '文件已经上传完成。');
        abort_unless($index >= 0 && $index < $upload->chunk_count, 422, '分片编号不正确。');
        abort_unless($chunk->getSize() === $this->length($upload, $index), 422, '分片大小和约定不一致，请重新选择原文件。');
        $stored = $chunk->storeAs($this->directory($upload->id), (string) $index, 'abs');
        abort_unless($stored, 500, '分片暂时没能保存，请重试。');
    }

    public function assemble(Models\UploadSession $upload): string
    {
        $disk = Storage::disk('abs');
        $directory = $this->directory($upload->id);
        $disk->makeDirectory($directory);
        $path = $disk->path($directory.'/assembled.'.strtolower(pathinfo($upload->file_name, PATHINFO_EXTENSION)));
        $temporary = $path.'.assembling';
        $output = fopen($temporary, 'wb');
        abort_unless($output, 500, '暂时无法写入文件。');

        try {
            for ($index = 0; $index < $upload->chunk_count; $index++) {
                $chunk = $directory.'/'.$index;
                abort_unless($disk->exists($chunk), 422, '还有分片没传完，继续上传后再合并。');
                $input = $disk->readStream($chunk);
                abort_unless(is_resource($input), 500, '分片暂时无法读取。');
                try {
                    abort_unless(stream_copy_to_stream($input, $output) === $this->length($upload, $index), 422, '分片内容不完整，请重传。');
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($output);
        }
        abort_unless(filesize($temporary) === $upload->file_size, 422, '合并后的文件大小不正确。');
        abort_unless(rename($temporary, $path), 500, '合并文件保存失败。');

        return $path;
    }

    public function discard(string $id): void
    {
        // Published files are stored outside this temporary upload directory.
        Storage::disk('abs')->deleteDirectory($this->directory($id));
    }

    private function directory(string $id): string
    {
        return 'uploads/'.$id;
    }

    private function length(Models\UploadSession $upload, int $index): int
    {
        return min($upload->chunk_size, $upload->file_size - $index * $upload->chunk_size);
    }
}

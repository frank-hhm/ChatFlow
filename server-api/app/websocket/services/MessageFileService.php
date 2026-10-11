<?php
/**
 * 消息文件服务
 * 处理语音/视频/图片文件的上传、存储、元数据提取
 */
declare(strict_types=1);

namespace app\websocket\services;

use think\facade\Db;
use think\facade\Config;

class MessageFileService
{
    /**
     * 允许的文件扩展名
     */
    private const ALLOWED_VOICE  = ['mp3', 'wav', 'ogg', 'amr', 'm4a'];
    private const ALLOWED_VIDEO  = ['mp4', 'webm', 'mov', 'avi'];
    private const ALLOWED_IMAGE  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * 最大文件大小(字节)
     */
    private const MAX_VOICE_SIZE  = 10 * 1024 * 1024; // 10MB
    private const MAX_VIDEO_SIZE  = 50 * 1024 * 1024; // 50MB
    private const MAX_IMAGE_SIZE  = 5 * 1024 * 1024;  // 5MB

    /**
     * 上传语音文件
     * 返回: ['success'=>true, 'file_url'=>'...', 'duration'=>秒, 'file_size'=>字节]
     *       ['success'=>false, 'error'=>'错误信息']
     */
    public function uploadVoice($file): array
    {
        if (!$file->isValid()) {
            return ['success' => false, 'error' => '文件无效'];
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, self::ALLOWED_VOICE)) {
            return ['success' => false, 'error' => '不支持的语音格式: ' . $ext];
        }

        $size = $file->getSize();
        if ($size > self::MAX_VOICE_SIZE) {
            return ['success' => false, 'error' => '语音文件过大(最大10MB)'];
        }

        // 生成存储路径
        $day = date('Ymd');
        $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = config('websocket.upload_path.voice', 'uploads/voice') . '/' . $day;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $fileName;
        $file->move($dir, $fileName);

        if (!$file->move($dir, $fileName)) {
            return ['success' => false, 'error' => '文件保存失败'];
        }

        // 提取音频时长(需要使用 ffmpeg 或 php-mp3info)
        $duration = $this->getAudioDuration($path);

        // 生成URL
        $baseUrl = config('app.url', 'http://localhost');
        $fileUrl = $baseUrl . '/uploads/voice/' . $day . '/' . $fileName;

        return [
            'success'  => true,
            'file_url' => $fileUrl,
            'file_path'=> $path,
            'file_size'=> $size,
            'file_format'=> $ext,
            'duration' => $duration,
        ];
    }

    /**
     * 上传视频文件
     * 返回: ['success'=>true, 'file_url'=>'...', 'duration'=>秒, 'width'=>px, 'height'=>px, 'thumbnail'=>'...']
     */
    public function uploadVideo($file): array
    {
        if (!$file->isValid()) {
            return ['success' => false, 'error' => '文件无效'];
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, self::ALLOWED_VIDEO)) {
            return ['success' => false, 'error' => '不支持的视频格式: ' . $ext];
        }

        $size = $file->getSize();
        if ($size > self::MAX_VIDEO_SIZE) {
            return ['success' => false, 'error' => '视频文件过大(最大50MB)'];
        }

        // 生成存储路径
        $day = date('Ymd');
        $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = config('websocket.upload_path.video', 'uploads/video') . '/' . $day;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $fileName;
        $file->move($dir, $fileName);

        // 提取视频信息
        $info = $this->getVideoInfo($path);

        // 生成缩略图
        $thumbnailUrl = null;
        if ($info['width'] > 0 && $info['height'] > 0) {
            $thumbnailPath = $path . '.jpg';
            $this->generateThumbnail($path, $thumbnailPath);
            $thumbnailUrl = config('app.url', 'http://localhost') . '/uploads/video/' . $day . '/' . $fileName . '.jpg';
        }

        $baseUrl = config('app.url', 'http://localhost');
        $fileUrl = $baseUrl . '/uploads/video/' . $day . '/' . $fileName;

        return [
            'success'      => true,
            'file_url'     => $fileUrl,
            'file_path'    => $path,
            'file_size'    => $size,
            'file_format'  => $ext,
            'duration'     => $info['duration'],
            'width'        => $info['width'],
            'height'       => $info['height'],
            'thumbnail_url'=> $thumbnailUrl,
        ];
    }

    /**
     * 上传封面图(视频缩略图/语音波形图)
     */
    public function uploadThumbnail($file): array
    {
        if (!$file->isValid()) {
            return ['success' => false, 'error' => '文件无效'];
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, self::ALLOWED_IMAGE)) {
            return ['success' => false, 'error' => '不支持的图片格式'];
        }

        $size = $file->getSize();
        if ($size > self::MAX_IMAGE_SIZE) {
            return ['success' => false, 'error' => '图片文件过大(最大5MB)'];
        }

        $day = date('Ymd');
        $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = config('websocket.upload_path.thumbnail', 'uploads/thumbnail') . '/' . $day;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $fileName);

        $baseUrl = config('app.url', 'http://localhost');
        $fileUrl = $baseUrl . '/uploads/thumbnail/' . $day . '/' . $fileName;

        return [
            'success'  => true,
            'file_url' => $fileUrl,
            'file_path'=> $dir . '/' . $fileName,
            'file_size'=> $size,
        ];
    }

    /**
     * 保存媒体文件元数据到数据库
     */
    public function saveFileMeta(
        int $messageId,
        string $conversationId,
        int $tenantId,
        int $senderId,
        string $senderType,
        string $fileType,
        string $fileUrl,
        string $filePath,
        int $fileSize,
        string $fileFormat,
        int $duration = 0,
        int $width = 0,
        int $height = 0,
        ?string $thumbnailUrl = null
    ): ?int {
        try {
            $id = Db::name('im_message_file')->insertGetId([
                'message_id'      => $messageId,
                'conversation_id' => $conversationId,
                'tenant_id'       => $tenantId,
                'sender_id'       => $senderId,
                'sender_type'     => $senderType,
                'file_type'       => $fileType,
                'file_url'        => $fileUrl,
                'file_path'       => $filePath,
                'file_size'       => $fileSize,
                'file_format'     => $fileFormat,
                'duration'        => $duration,
                'width'           => $width,
                'height'          => $height,
                'thumbnail_url'   => $thumbnailUrl ?? '',
                'created_at'      => time(),
                'updated_at'      => time(),
            ]);

            return $id;
        } catch (\Throwable $e) {
            error_log('[MessageFileService] saveFileMeta error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取语音时长(需要 ffmpeg)
     */
    private function getAudioDuration(string $path): int
    {
        // 方法1: 使用 ffmpeg
        if (function_exists('shell_exec')) {
            $cmd = sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
                escapeshellarg($path)
            );
            $output = shell_exec($cmd);
            if ($output && is_numeric(trim($output))) {
                return (int) round((float) trim($output));
            }
        }

        // 方法2: 使用 php-mp3info (mp3)
        if (function_exists('mp3info')) {
            try {
                $info = mp3info($path);
                if (isset($info['length'])) {
                    return (int) $info['length'];
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // 方法3: 使用 getid3 库
        // $getid3 = new GetID3();
        // $info = $getid3->analyze($path);
        // return isset($info['playtime_seconds']) ? (int) $info['playtime_seconds'] : 0;

        return 0;
    }

    /**
     * 获取视频信息(需要 ffmpeg)
     */
    private function getVideoInfo(string $path): array
    {
        $default = ['duration' => 0, 'width' => 0, 'height' => 0];

        if (!function_exists('shell_exec')) {
            return $default;
        }

        // 获取时长
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
            escapeshellarg($path)
        );
        $duration = (float) (shell_exec($cmd) ?: 0);

        // 获取分辨率
        $cmd2 = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 "%s" 2>&1',
            escapeshellarg($path)
        );
        $resolution = shell_exec($cmd2);
        $resolution = explode('x', trim($resolution ?: ''));
        $width = isset($resolution[0]) && is_numeric($resolution[0]) ? (int) $resolution[0] : 0;
        $height = isset($resolution[1]) && is_numeric($resolution[1]) ? (int) $resolution[1] : 0;

        return [
            'duration' => (int) round($duration),
            'width'    => $width,
            'height'   => $height,
        ];
    }

    /**
     * 生成视频缩略图(需要 ffmpeg)
     */
    private function generateThumbnail(string $videoPath, string $thumbnailPath): void
    {
        if (!function_exists('shell_exec')) {
            return;
        }

        // 取视频第1秒的帧作为缩略图
        $cmd = sprintf(
            'ffmpeg -y -i "%s" -ss 00:00:01 -vframes 1 -q:v 2 "%s" 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($thumbnailPath)
        );
        shell_exec($cmd);
    }

    /**
     * 删除文件(物理+数据库)
     */
    public function deleteFile(int $fileId): bool
    {
        try {
            $meta = Db::name('im_message_file')->where('id', $fileId)->find();
            if ($meta) {
                // 删除物理文件
                if ($meta['file_path'] && file_exists($meta['file_path'])) {
                    unlink($meta['file_path']);
                }
                // 删除缩略图
                if (!empty($meta['thumbnail_url'])) {
                    $thumbPath = str_replace(config('app.url', ''), '', $meta['thumbnail_url']);
                    if (file_exists($thumbPath)) {
                        unlink($thumbPath);
                    }
                }
                // 标记删除
                Db::name('im_message_file')->where('id', $fileId)->update(['status' => 1]);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[MessageFileService] deleteFile error: ' . $e->getMessage());
            return false;
        }
    }
}
<?php

declare(strict_types=1);

namespace Lychee\captcha\driver;

use Lychee\captcha\CaptchaInterface;
use Lychee\captcha\exception\CaptchaException;

/**
 * 图形验证码驱动。
 *
 * 使用 PHP GD 扩展，配合 assets 目录下的背景图与 TTF 字体生成验证码。
 * 字符集默认去除易混淆的 0/O/1/I/L。
 */
class ImageDriver implements CaptchaInterface
{
    /** @var string 资源目录 */
    private string $assetsDir;

    /** @var array<string, mixed> 配置 */
    private array $config;

    /**
     * @param array<string, mixed> $config 配置项：chars / length / width / height / font_size
     */
    public function __construct(array $config = [])
    {
        $this->assetsDir = dirname(__DIR__) . '/assets';

        $this->config = array_merge([
            'chars'     => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'length'    => 4,
            'width'     => 120,
            'height'    => 40,
            'font_size' => 20,
        ], $config);
    }

    public function generateCode(): string
    {
        $chars  = (string) $this->config['chars'];
        $length = (int) $this->config['length'];
        $max    = strlen($chars) - 1;
        $code   = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    public function render(string $code, string $key): string
    {
        if (!extension_loaded('gd')) {
            throw new CaptchaException('GD 扩展未安装，无法生成图形验证码');
        }

        $width  = (int) $this->config['width'];
        $height = (int) $this->config['height'];

        $img = $this->createBackground($width, $height);

        // 干扰线
        for ($i = 0; $i < 3; $i++) {
            $color = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $color);
        }

        // 噪点
        for ($i = 0; $i < 30; $i++) {
            $color = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $color);
        }

        // 字符（使用 TTF 字体，支持倾斜）
        $font     = $this->pickFont();
        $fontSize = (int) $this->config['font_size'];
        $len      = strlen($code);
        $step     = (int) floor($width / ($len + 1));

        for ($i = 0; $i < $len; $i++) {
            $color = imagecolorallocate($img, random_int(0, 80), random_int(0, 80), random_int(0, 80));
            $angle = random_int(-20, 20);
            $x     = $step * ($i + 1) - (int) ($fontSize / 2);
            $y     = (int) ($height / 2) + random_int(-3, 3) + (int) ($fontSize / 3);
            imagettftext($img, $fontSize, $angle, $x, $y, $color, $font, $code[$i]);
        }

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    /**
     * 创建背景：优先使用 assets/bgs 下的随机图片，失败则回退纯色背景。
     */
    private function createBackground(int $width, int $height)
    {
        $bgDir = $this->assetsDir . '/bgs';
        $files = glob($bgDir . '/*.jpg');

        if ($files !== false && $files !== []) {
            $bgFile = $files[array_rand($files)];
            $source = @imagecreatefromjpeg($bgFile);

            if ($source !== false) {
                $srcW = imagesx($source);
                $srcH = imagesy($source);

                $img = imagecreatetruecolor($width, $height);
                imagecopyresampled($img, $source, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
                imagedestroy($source);

                return $img;
            }
        }

        // 回退：纯色背景
        $img = imagecreatetruecolor($width, $height);
        $bg  = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);

        return $img;
    }

    /**
     * 随机选取一个 TTF 字体文件。
     */
    private function pickFont(): string
    {
        $fontDir = $this->assetsDir . '/ttfs';
        $files   = glob($fontDir . '/*.ttf');

        if ($files !== false && $files !== []) {
            return $files[array_rand($files)];
        }

        // 回退：使用 GD 内置字体无法被 imagettftext 使用，抛出异常
        throw new CaptchaException('未找到 TTF 字体文件：' . $fontDir);
    }
}

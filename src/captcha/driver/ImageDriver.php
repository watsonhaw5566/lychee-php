<?php

declare(strict_types=1);

namespace Lychee\captcha\driver;

use Lychee\captcha\CaptchaInterface;
use Lychee\captcha\exception\CaptchaException;

/**
 * 图形验证码驱动。
 *
 * 使用 PHP GD 扩展生成带干扰线的 PNG 图片，无第三方依赖。
 * 字符集默认去除易混淆的 0/O/1/I/L。
 */
class ImageDriver implements CaptchaInterface
{
    /** @var array<string, mixed> 配置 */
    private array $config;

    /**
     * @param array<string, mixed> $config 配置项：chars / length / width / height
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'chars'  => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'length' => 4,
            'width'  => 120,
            'height' => 40,
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

        $img = imagecreatetruecolor($width, $height);
        $bg  = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);

        // 干扰线
        for ($i = 0; $i < 5; $i++) {
            $color = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $color);
        }

        // 噪点
        for ($i = 0; $i < 50; $i++) {
            $color = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $color);
        }

        // 字符
        $len  = strlen($code);
        $step = (int) floor($width / ($len + 1));
        $font = 5;
        for ($i = 0; $i < $len; $i++) {
            $color = imagecolorallocate($img, random_int(0, 100), random_int(0, 100), random_int(0, 100));
            $x     = $step * ($i + 1) - (int) (imagefontwidth($font) / 2);
            $y     = random_int(2, $height - imagefontheight($font) - 2);
            imagestring($img, $font, $x, $y, $code[$i], $color);
        }

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }
}

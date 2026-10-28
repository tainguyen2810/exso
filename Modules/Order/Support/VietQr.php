<?php

namespace Modules\Order\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * VietQR (NAPAS 247) transfer codes, built locally: the EMVCo payload with
 * the receiving account, amount and transfer note, drawn as an SVG.
 * Every banking app in Vietnam can scan it.
 */
class VietQr
{
    public static function payload(string $bankBin, string $accountNumber, int $amount = 0, ?string $note = null): string
    {
        $account = self::field('00', 'A000000727')
            .self::field('01', self::field('00', $bankBin).self::field('01', $accountNumber))
            .self::field('02', 'QRIBFTTA');

        $payload = self::field('00', '01')
            .self::field('01', $amount > 0 ? '12' : '11')
            .self::field('38', $account)
            .self::field('53', '704')
            .($amount > 0 ? self::field('54', (string) $amount) : '')
            .self::field('58', 'VN');

        $note = self::clean($note);

        if ($note !== '') {
            $payload .= self::field('62', self::field('08', $note));
        }

        $payload .= '6304';

        return $payload.self::crc($payload);
    }

    public static function svg(string $payload, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($payload);
    }

    protected static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * Transfer notes may only hold plain letters, digits and spaces (max 25).
     */
    protected static function clean(?string $note): string
    {
        $ascii = Str::ascii((string) $note);

        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $ascii)), 0, 25);
    }

    /**
     * CRC-16/CCITT-FALSE, as required by EMVCo.
     */
    protected static function crc(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}

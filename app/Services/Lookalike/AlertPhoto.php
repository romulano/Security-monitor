<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeCapture;

/**
 * Em que aparelho o golpe aparece, e qual print vai no aviso.
 *
 * Regra do aviso: vai uma foto só, a do navegador (Chrome no Windows). A do
 * celular só vai quando o golpe aparece no celular e não no computador, que
 * é o jeito comum de esconder phishing de quem vigia. Do aparelho escolhido,
 * o print de 10 s depois quando a tela mudou; senão, o de quando abriu.
 */
final class AlertPhoto
{
    /** Quantos sinais de golpe a página mostrou neste aparelho (formulário, marca, arquivos do cliente, semelhança visual). */
    public static function deviceScore(LookalikeCapture $capture, BrandContext $brand, string $device): int
    {
        $seen = $capture->device($device);

        if (! ($seen['ok'] ?? false)) {
            return 0;
        }

        $forms = (array) ($seen['forms'] ?? []);
        $similar = $capture->shots[$device]['similar']['score'] ?? null;

        return (int) (($forms['password'] ?? 0) > 0)
            + (int) (($forms['card'] ?? 0) + ($forms['cpf'] ?? 0) + ($forms['pix'] ?? 0) > 0)
            + (int) $brand->mentions(($seen['title'] ?? '').' '.($seen['text'] ?? ''))
            + (int) (($seen['own_requests'] ?? 0) > 0)
            + (int) ($similar !== null && $similar >= (int) config('secmon.lookalikes.looks_like_threshold', 88));
    }

    /** O aparelho em que o golpe mais aparece, com o computador na frente em empate; nulo se nenhum mostra nada. */
    public static function scamDevice(LookalikeCapture $capture, BrandContext $brand): ?string
    {
        $best = null;
        $bestScore = 0;

        foreach (array_keys(LookalikeCapture::DEVICES) as $device) {
            $score = self::deviceScore($capture, $brand, $device);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$device, $score];
            }
        }

        return $best;
    }

    /**
     * A foto do aviso. `$aiDevice` é onde a IA viu o golpe: desempata a favor
     * de um celular só quando o computador não mostrou nada.
     *
     * @return array{device: string, moment: string, path: string}|null
     */
    public static function pick(LookalikeCapture $capture, BrandContext $brand, ?string $aiDevice = null): ?array
    {
        $device = 'desktop';

        if (self::deviceScore($capture, $brand, 'desktop') === 0 || ! $capture->hasShot('desktop')) {
            $mobile = self::scamDevice($capture, $brand);
            $mobile ??= in_array($aiDevice, ['android', 'iphone'], true) ? $aiDevice : null;

            if ($mobile && $capture->hasShot($mobile)) {
                $device = $mobile;
            }
        }

        if (! $capture->hasShot($device)) {
            $device = $capture->devicesWithShots()[0] ?? null;
        }

        if (! $device) {
            return null;
        }

        $moment = $capture->latestMoment($device);

        return ['device' => $device, 'moment' => $moment, 'path' => $capture->shot($device, $moment)];
    }

    /** A página do cliente mais parecida com o achado, em qualquer aparelho. @return array{device: string, page: int, score: int}|null */
    public static function mostSimilar(LookalikeCapture $capture): ?array
    {
        $best = null;
        $threshold = (int) config('secmon.lookalikes.looks_like_threshold', 88);

        foreach (array_keys(LookalikeCapture::DEVICES) as $device) {
            $similar = $capture->shots[$device]['similar'] ?? null;

            if (is_array($similar) && ($similar['score'] ?? 0) >= $threshold && (! $best || $similar['score'] > $best['score'])) {
                $best = ['device' => $device, 'page' => (int) ($similar['page'] ?? 0), 'score' => (int) $similar['score']];
            }
        }

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Robôs e pré-visualizadores conhecidos (não contam como aberturas): pré-visualização
 * de links no WhatsApp, Facebook, Telegram, Slack, Twitter, LinkedIn, Discord e Skype;
 * motores de pesquisa; verificadores de links do email (Outlook Safe Links, Proofpoint,
 * Mimecast, Barracuda, Symantec); clientes HTTP e browsers sem interface. A primeira
 * barreira é o sinal enviado pela página depois de carregar (estes raramente correm
 * JavaScript); esta lista apanha os que correm.
 */
final class BotUserAgent
{
    // Nomes exatos dos robôs de pré-visualização: os browsers dentro das apps (Instagram,
    // Facebook, LinkedIn, Telegram, X) usam outro user agent e são pessoas reais.
    private const PATTERN = '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp\/|telegrambot|slackbot|slack-imgproxy|twitterbot|linkedinbot|discordbot|'
        . 'skypeuripreview|embedly|vkshare|pinterest|redditbot|applebot|bingpreview|googleother|google-safety|googleimageproxy|'
        . 'apis-google|feedfetcher|mediapartners|adsbot|google-read-aloud|headless|phantomjs|puppeteer|playwright|selenium|'
        . 'lighthouse|python|curl|wget|go-http-client|okhttp|java\/|libwww|httpclient|axios|node-fetch|undici|preview|'
        . 'proofpoint|mimecast|barracuda|symantec|safelinks|microsoft office existence|ms-office|outlook-|scanner|monitor|'
        . 'checker|validator|iframely|bitly|ahrefs|semrush|petalbot|bytespider|gptbot|claudebot|amazonbot|yandex|baidu/i';

    public static function isBot(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);

        return $ua === '' || preg_match(self::PATTERN, $ua) === 1;
    }

    /** Telemóvel ou computador (os tablets contam como telemóvel). */
    public static function device(?string $userAgent): string
    {
        return preg_match('/mobi|android|iphone|ipad|ipod|tablet|silk|kindle|opera mini|iemobile/i', (string) $userAgent) === 1
            ? 'mobile'
            : 'desktop';
    }
}

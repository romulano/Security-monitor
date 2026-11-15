<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Idioma em que o leitor quer o relatório (título, resumo e "o que há de novo").
 * Só o conteúdo analisado muda; a interface é a mesma. A API pública não passa
 * por aqui e segue sempre em inglês.
 */
class ReportLanguage
{
    public const COOKIE = 'report_lang';

    public const EN = 'en';

    public const PT = 'pt';

    /** Código => rótulo do seletor. */
    public const LABELS = [self::EN => 'EN', self::PT => 'PT-BR'];

    /** Escolha salva > idioma do navegador > inglês. */
    public static function fromRequest(Request $request): string
    {
        $chosen = (string) $request->cookie(self::COOKIE);

        if (isset(self::LABELS[$chosen])) {
            return $chosen;
        }

        $browser = (string) $request->getPreferredLanguage(['en', 'pt_BR', 'pt']);

        return str_starts_with(strtolower($browser), 'pt') ? self::PT : self::EN;
    }

    public static function htmlLang(string $lang): string
    {
        return $lang === self::PT ? 'pt-BR' : 'en';
    }
}

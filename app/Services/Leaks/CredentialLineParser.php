<?php

namespace App\Services\Leaks;

/**
 * Lê uma linha de combo de stealer e decide quem é o endereço, quem é o login e quem é a senha.
 *
 * O formato não é fixo. No mesmo arquivo aparecem:
 *
 *   PE https://login.live.com/oauth20_authorize.srf:arm@x.com:Senha@000111   (país + url:login:senha)
 *   https://cassino.exemplo.com/m/login:jogador123:abc123                      (url:login:senha)
 *   buddhadevsau@gmail.com:Fulano123:https://app.exemplo.com/                      (login:senha:url)
 *   Amostra2026:beltrano21@gmail.com:https://streaming.exemplo.com/fi-en/login    (senha:login:url)
 *
 * Por isso o parser não conta posições: ele **acha** o endereço (pelo esquema `xxx://`, por um
 * caminho, ou por um TLD conhecido) e trabalha em cima de onde ele caiu. Partir a linha no `:` é
 * ingênuo: `https://a.com` tem dois-pontos, `smtp://host:465` tem porta, e senha pode ter `:`.
 *
 * Quando a ordem não dá para provar, a linha NÃO é adivinhada: volta com `ok = false` e um motivo,
 * para um humano classificar. Errar aqui grava senha no lugar de login, e é pior que não gravar.
 */
final class CredentialLineParser
{
    /** Esquemas vistos nos dumps. `android://` é o do gerenciador de senhas do Android. */
    private const SCHEMES = [
        'http', 'https', 'ftp', 'ftps', 'sftp', 'smtp', 'smtps', 'imap', 'imaps', 'pop3', 'pop3s',
        'android', 'ios', 'ssh', 'rdp', 'vnc', 'ldap', 'ldaps', 'mysql', 'postgres', 'postgresql',
        'mongodb', 'redis', 'ws', 'wss', 'chrome-extension', 'moz-extension',
    ];

    /** Os limites das colunas de `leaked_credentials`. */
    private const MAX_USERNAME = 320;

    private const MAX_PASSWORD = 255;

    private const MAX_URL = 2048;

    /**
     * A lista de webmail grátis vem do banco, mantida pelo admin — não do código. Ela é lida uma
     * vez e fica memorizada na instância, que vive o processo inteiro da carga.
     */
    public function __construct(private FreeMailList $free) {}

    /**
     * @return array{ok:bool,reason:?string,layout:?string,country:?string,url:?string,site_host:?string,
     *               username:?string,email:?string,domain:?string,password:?string,free_mail:bool,
     *               corporate:bool,company_email:?string,fields:array<int,string>}
     */
    public function parse(string $raw): array
    {
        $line = trim(str_replace(["\0", "\r", "\u{FEFF}"], '', $raw));

        if ($line === '' || str_starts_with($line, '#')) {
            return $this->fail('vazia', null, []);
        }

        // Prefixo de país: duas letras soltas antes do resto ("PE https://...").
        $country = null;
        if (preg_match('/^([A-Za-z]{2})[\s\x{00A0}]+(\S.*)$/u', $line, $m) === 1) {
            $country = strtoupper($m[1]);
            $line = $m[2];
        }

        $fields = $this->fields($line);

        if (count($fields) < 3) {
            return $this->fail('campos_de_menos', $country, $fields);
        }

        $found = array_keys(array_filter($fields, fn (string $f): bool => $this->isUrl($f)));

        if ($found === []) {
            return $this->fail('sem_endereco', $country, $fields);
        }

        if (count($found) > 1) {
            return $this->fail('dois_enderecos', $country, $fields);
        }

        $at = $found[0];
        $last = count($fields) - 1;

        if ($at === 0) {
            // url:login:senha — a ordem dominante. A senha é o rabo da linha, então um `:` dentro
            // dela não estraga nada.
            [$layout, $user, $pass] = ['url:login:senha', $fields[1], implode(':', array_slice($fields, 2))];
        } elseif ($at === 1) {
            // login:url:senha, a segunda ordem confirmada. O e-mail manda se contradisser.
            $user = $fields[0];
            $pass = implode(':', array_slice($fields, 2));
            $layout = 'login:url:senha';

            if (! $this->isEmail($user) && $this->isEmail($pass)) {
                [$layout, $user, $pass] = ['senha:url:login', $pass, $fields[0]];
            }
        } elseif ($at === $last && $at === 2) {
            // Endereço no fim: pode ser login:senha:url ou senha:login:url, e nada na posição
            // resolve. Só o e-mail resolve; sem ele, vai para o humano.
            [$a, $b] = [$fields[0], $fields[1]];

            if ($this->isEmail($a) && ! $this->isEmail($b)) {
                [$layout, $user, $pass] = ['login:senha:url', $a, $b];
            } elseif ($this->isEmail($b) && ! $this->isEmail($a)) {
                [$layout, $user, $pass] = ['senha:login:url', $b, $a];
            } else {
                return $this->fail('ordem_ambigua', $country, $fields);
            }
        } else {
            return $this->fail('campos_demais', $country, $fields);
        }

        $url = $fields[$at];

        if ($user === '' || $pass === '') {
            return $this->fail('campo_vazio', $country, $fields);
        }

        if (mb_strlen($user) > self::MAX_USERNAME || mb_strlen($pass) > self::MAX_PASSWORD || mb_strlen($url) > self::MAX_URL) {
            return $this->fail('campo_longo', $country, $fields);
        }

        $email = $this->isEmail($user) ? mb_strtolower($user) : null;
        $domain = $email === null ? null : substr($email, strrpos($email, '@') + 1);
        $free = $domain !== null && $this->free->has($domain);

        return [
            'ok' => true,
            'reason' => null,
            'layout' => $layout,
            'country' => $country,
            'url' => $url,
            'site_host' => $this->host($url),
            'username' => $user,
            'email' => $email,
            'domain' => $domain,
            'password' => $pass,
            'free_mail' => $free,
            'company_email' => $free ? null : $email,
            // O que vai para o banco: só credencial de empresa. Webmail grátis e login que nem
            // e-mail é (`usuario01`, `Usuaria999`) ficam de fora — a carga conta cada um.
            'corporate' => $domain !== null && ! $free,
            'fields' => $fields,
        ];
    }

    /**
     * Parte a linha no `:` e remonta o que o `:` não devia ter partido: `esquema://...` e a porta
     * logo depois dele. A porta só é colada se ainda sobrarem dois campos — senão uma senha de
     * seis dígitos (`:123456`) seria confundida com porta.
     *
     * @return array<int,string>
     */
    private function fields(string $line): array
    {
        $parts = explode(':', $line);
        $out = [];

        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            $scheme = strtolower($parts[$i]);

            if (isset($parts[$i + 1]) && str_starts_with($parts[$i + 1], '//') && in_array($scheme, self::SCHEMES, true)) {
                $url = $parts[$i].':'.$parts[$i + 1];
                $i++;

                if (isset($parts[$i + 1]) && preg_match('/^\d{1,5}$/', $parts[$i + 1]) === 1 && ($n - $i - 2) >= 2) {
                    $url .= ':'.$parts[$i + 1];
                    $i++;
                }

                $out[] = $url;

                continue;
            }

            $out[] = $parts[$i];
        }

        return $out;
    }

    private function isUrl(string $field): bool
    {
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $field, $m) === 1) {
            return in_array(strtolower($m[1]), self::SCHEMES, true);
        }

        if ($field === '' || str_contains($field, '@') || preg_match('/\s/u', $field) === 1) {
            return false;
        }

        $host = explode('/', $field, 2)[0];

        if (! str_contains($host, '.') || preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/i', $host) !== 1) {
            return false;
        }

        // Tem caminho (`site.com/login`): é endereço. Sem caminho, só com TLD conhecido.
        return str_contains($field, '/')
            || Tlds::isSuffix(substr($host, strrpos($host, '.') + 1));
    }

    private function isEmail(string $field): bool
    {
        return preg_match('/^[^@\s]+@[a-z0-9]([a-z0-9.\-]*[a-z0-9])?\.[a-z]{2,}$/iu', $field) === 1;
    }

    /** O host do endereço, para cruzar com o site do cliente. `android://` tem base64 com `@`. */
    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            if (preg_match('#^[a-z0-9+.\-]+://(?:[^/@]*@)?([a-z0-9.\-]+)#i', $url, $m) !== 1) {
                return null;
            }
            $host = $m[1];
        }

        $host = mb_strtolower(rtrim($host, '.'));

        return preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host) === 1 && mb_strlen($host) <= 253 ? $host : null;
    }

    /**
     * Não deu para ler a linha — mas isso ainda não quer dizer que um humano resolva.
     *
     * Só vale chamar alguém se houver, entre os campos, um e-mail DE EMPRESA. A tabela exige e-mail
     * (é o domínio que identifica a empresa a avisar) e webmail genérico é descartado por regra:
     * sem um e-mail de empresa na linha, nenhuma decisão do humano geraria credencial. Mandar assim
     * para a fila é pedir trabalho sem saída — ele abre, pensa e só pode descartar.
     *
     * `company_email` é esse campo quando existe. `free_mail` diz que havia e-mail, mas só genérico:
     * serve para a carga contar essa linha como descartada por genérico, e não por falta de e-mail.
     *
     * @param  array<int,string>  $fields
     */
    private function fail(string $reason, ?string $country, array $fields): array
    {
        $company = null;
        $viuEmail = false;

        foreach ($fields as $field) {
            if (! $this->isEmail($field)) {
                continue;
            }

            $viuEmail = true;
            $email = mb_strtolower($field);

            if (! $this->free->has(substr($email, strrpos($email, '@') + 1))) {
                $company = $email;
                break;
            }
        }

        return [
            'ok' => false, 'reason' => $reason, 'layout' => null, 'country' => $country,
            'url' => null, 'site_host' => null, 'username' => null, 'email' => null,
            'domain' => null, 'password' => null,
            'free_mail' => $company === null && $viuEmail, 'corporate' => false,
            'company_email' => $company,
            'fields' => $fields,
        ];
    }
}

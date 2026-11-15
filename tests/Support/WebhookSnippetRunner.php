<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Roda os exemplos de conferência da tela do webhook (WebhookExample::verification)
 * como o servidor do cliente rodaria, e devolve o código HTTP que eles respondem:
 * o de PHP no servidor embutido do PHP; o de Python e o de Node com um Flask e um
 * Express de mentira (só o que o exemplo usa). Sem o interpretador na máquina, nulo.
 */
class WebhookSnippetRunner
{
    public static function php(string $code, string $secret, string $body, array $headers): int
    {
        return self::inDir(function (string $dir) use ($code, $secret, $body, $headers) {
            file_put_contents("{$dir}/index.php", $code);
            $port = random_int(20000, 40000);
            $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, ['SECMON_WEBHOOK_SECRET' => $secret]);

            try {
                $socket = null;

                for ($i = 0; $i < 50 && ! $socket; $i++) {
                    usleep(100_000);
                    $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1);
                }

                $request = "POST /secmon HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n";

                foreach ($headers as $name => $value) {
                    $request .= "{$name}: {$value}\r\n";
                }

                fwrite($socket, $request."\r\n".$body);
                $status = (int) (explode(' ', (string) fgets($socket))[1] ?? 0);
                fclose($socket);

                return $status;
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
        });
    }

    public static function python(string $code, string $secret, string $body, array $headers): ?int
    {
        return self::run('python3', ['-I'], 'snippet.py', self::PYTHON, $code, $secret, $body, $headers);
    }

    public static function node(string $code, string $secret, string $body, array $headers): ?int
    {
        return self::run('node', [], 'snippet.js', self::NODE, $code, $secret, $body, $headers);
    }

    /** O interpretador lê a entrega (corpo em base64 e cabeçalhos) na entrada e escreve o código HTTP na saída. */
    private static function run(string $binary, array $flags, string $file, string $harness, string $code, string $secret, string $body, array $headers): ?int
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        if ($path === '') {
            return null;
        }

        return self::inDir(function (string $dir) use ($path, $flags, $file, $harness, $code, $secret, $body, $headers) {
            file_put_contents("{$dir}/{$file}", $code);
            file_put_contents("{$dir}/harness", $harness);
            $process = proc_open([$path, ...$flags, "{$dir}/harness", "{$dir}/{$file}"], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['SECMON_WEBHOOK_SECRET' => $secret]);
            fwrite($pipes[0], json_encode(['body' => base64_encode($body), 'headers' => $headers]));
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            proc_close($process);

            return ctype_digit(trim((string) $out)) ? (int) trim($out) : throw new \RuntimeException("{$binary}: {$out} {$error}");
        });
    }

    private static function inDir(callable $work): mixed
    {
        $dir = sys_get_temp_dir().'/secmon-webhook-'.Str::random(10);
        File::ensureDirectoryExists($dir);

        try {
            return $work($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private const PYTHON = <<<'PY'
        import base64, json, sys, types

        data = json.load(sys.stdin)


        class Aborted(Exception):
            pass


        def abort(code):
            raise Aborted(code)


        class Headers:
            def __init__(self, items):
                self.items = {name.lower(): value for name, value in items.items()}

            def get(self, name, default=None):
                return self.items.get(name.lower(), default)


        class Request:
            headers = Headers(data["headers"])

            def get_data(self):
                return base64.b64decode(data["body"])

            def get_json(self):
                return json.loads(self.get_data())


        routes = {}


        class Flask:
            def __init__(self, name):
                pass

            def post(self, path):
                def register(view):
                    routes[path] = view
                    return view
                return register


        flask = types.ModuleType("flask")
        flask.Flask, flask.abort, flask.request = Flask, abort, Request()
        sys.modules["flask"] = flask
        exec(compile(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1], "exec"), {"__name__": "snippet"})

        try:
            print(routes["/secmon"]()[1])
        except Aborted as stop:
            print(stop.args[0])
        PY;

    private const NODE = <<<'JS'
        const Module = require('module');
        const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));
        let handler;
        const express = () => ({ post: (path, ...stack) => { handler = stack[stack.length - 1]; } });
        express.raw = () => (req, res, next) => next();
        const load = Module._load;
        Module._load = function (request, ...rest) { return request === 'express' ? express : load.call(this, request, ...rest); };
        require(process.argv[2]);
        const headers = Object.fromEntries(Object.entries(input.headers).map(([name, value]) => [name.toLowerCase(), value]));
        const res = { sendStatus: (code) => { console.log(code); return res; } };
        handler({ body: Buffer.from(input.body, 'base64'), get: (name) => headers[name.toLowerCase()] }, res);
        JS;
}

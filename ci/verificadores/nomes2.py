#!/usr/bin/env python3
r"""Acha `use` removido com o uso mantido.

Lista os nomes de classe usados num arquivo PHP que não estão importados, não
são do mesmo namespace e não são classes globais do PHP: o erro clássico de
apagar um `use` que ainda era usado mais abaixo no arquivo.

O `php -l` não vê nada disso (a sintaxe continua válida); o erro só aparece
quando a linha roda. Este verificador já pegou dois bugs reais no projeto.

Uso: python3 -I ci/verificadores/nomes2.py [raiz]

Sai com 1 se encontrar qualquer coisa, para o CI ficar vermelho.
"""
import glob
import os
import re
import sys


def detectar_raiz():
    """Sobe da pasta do próprio script até a raiz do repositório.

    A raiz é a pasta que tem composer.json e artisan (um projeto Laravel), e
    não depende de onde o script foi chamado nem de caminho fixo de máquina.
    """
    atual = os.path.dirname(os.path.abspath(__file__))
    while True:
        if all(os.path.exists(os.path.join(atual, a)) for a in ('composer.json', 'artisan')):
            return atual
        pai = os.path.dirname(atual)
        if pai == atual:
            sys.exit(
                'nomes2: não achei a raiz do repositório (pasta com composer.json e artisan) '
                f'acima de {os.path.abspath(__file__)}'
            )
        atual = pai


raiz = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else detectar_raiz()

GLOBAIS = {
 'Exception', 'LogicException', 'RuntimeException', 'InvalidArgumentException', 'ArgumentCountError', 'TypeError', 'Error',
 'Throwable', 'Closure', 'Generator', 'ArrayObject', 'ArrayAccess', 'Countable', 'IteratorAggregate', 'Traversable', 'Iterator',
 'JsonSerializable', 'Stringable', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod', 'DateTimeInterface',
 'SplFileInfo', 'SplObjectStorage', 'SplQueue', 'SplStack', 'SplFixedArray', 'JsonException', 'ValueError', 'DomainException',
 'OutOfRangeException', 'OutOfBoundsException', 'LengthException', 'RangeException', 'UnexpectedValueException', 'UnderflowException',
 'OverflowException', 'BadFunctionCallException', 'BadMethodCallException', 'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty',
 'ReflectionNamedType', 'ReflectionFunction', 'ReflectionEnum', 'WeakMap', 'Fiber', 'CurlHandle', 'PDO', 'PDOException', 'Random',
 'NumberFormatter', 'IntlDateFormatter', 'Collator', 'Transliterator', 'SimpleXMLElement', 'DOMDocument', 'ZipArchive', 'SodiumException',
 'self', 'static', 'parent', 'array', 'string', 'int', 'float', 'bool', 'void', 'mixed', 'null', 'true', 'false', 'callable', 'iterable', 'object', 'never',
}

# Falsos positivos conhecidos: caminho relativo -> função que diz se o nome é
# esperado naquele arquivo. Sem isso o CI ficaria vermelho para sempre, sem
# bug nenhum para corrigir.
#
# - database/seeders/DatabaseSeeder.php: chama os outros seeders pelo nome
#   curto, e todos são do mesmo namespace (Database\Seeders), então de fato não
#   precisam de `use`. O verificador não percebe porque classes_do_namespace()
#   só sabe traduzir os namespaces App\ e Tests\ em pasta, não Database\.
EXCECOES = {
    'database/seeders/DatabaseSeeder.php': lambda nome: nome.endswith('Seeder'),
}


def classes_do_namespace(ns):
    pasta = ns.replace('App\\', 'app/', 1).replace('Tests\\', 'tests/', 1).replace('\\', '/')
    d = os.path.join(raiz, pasta)
    return {f[:-4] for f in os.listdir(d)} if os.path.isdir(d) else set()


problemas = {}
alvos = (
    glob.glob(f'{raiz}/app/**/*.php', recursive=True)
    + glob.glob(f'{raiz}/tests/**/*.php', recursive=True)
    + glob.glob(f'{raiz}/database/**/*.php', recursive=True)
)
for arq in alvos:
    rel = os.path.relpath(arq, raiz)
    bruto = open(arq, encoding='utf-8', errors='replace').read()
    txt = re.sub(r"'(?:[^'\\]|\\.)*'", "''", re.sub(r'"(?:[^"\\]|\\.)*"', '""',
          re.sub(r'//[^\n]*', '', re.sub(r'/\*(?:[^*]|\*(?!/))*\*/', '', bruto))))
    ns = (re.search(r'^namespace ([^;]+);', bruto, re.M) or [None, ''])[1]
    importados = {f.split('\\')[-1] for f in re.findall(r'^use (?:function )?([^;]+);', bruto, re.M)}
    importados |= set(re.findall(r'^use [^;]+ as (\w+);', bruto, re.M))
    vizinhos = classes_do_namespace(ns) if ns else set()
    usados = set(re.findall(r'(?<![\w\\$>:])([A-Z][A-Za-z0-9_]*)::', txt))
    usados |= set(re.findall(r'new ([A-Z][A-Za-z0-9_]*)\s*\(', txt))
    usados |= set(re.findall(r'(?:instanceof|extends|implements) ([A-Z][A-Za-z0-9_]*)', txt))
    aceito = EXCECOES.get(rel, lambda nome: False)
    faltando = {u for u in usados
                if u not in importados and u not in vizinhos and u not in GLOBAIS
                and f'\\{u}::' not in txt and f'new \\{u}' not in txt
                and not aceito(u)}
    if faltando:
        problemas[rel] = sorted(faltando)

for arq, classes in sorted(problemas.items()):
    print(f'{arq}: {", ".join(classes)}')
print(f'--- {len(problemas)} arquivos com nome de classe sem import (raiz: {raiz})')
sys.exit(1 if problemas else 0)

#!/usr/bin/env python3
r"""Acha referência a classe que não existe no repositório.

Dois casos:

- `use App\...` (ou `use Tests\...`) apontando para um arquivo que não existe;
- símbolo `App\...::` citado no corpo do arquivo sem o arquivo correspondente.

É o resto que sobra quando uma classe é apagada e alguém esquece de tirar quem
a citava: o `php -l` passa, porque a sintaxe está correta, e o erro só aparece
em tempo de execução.

Uso: python3 -I ci/verificadores/orfaos.py [raiz]

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
                'orfaos: não achei a raiz do repositório (pasta com composer.json e artisan) '
                f'acima de {os.path.abspath(__file__)}'
            )
        atual = pai


raiz = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else detectar_raiz()


def existe(fqcn):
    p = fqcn.replace('App\\', 'app/', 1).replace('\\', '/') + '.php'
    q = fqcn.replace('Tests\\', 'tests/', 1).replace('\\', '/') + '.php'
    return os.path.exists(os.path.join(raiz, p)) or os.path.exists(os.path.join(raiz, q))


problemas = {}
for arq in glob.glob(f'{raiz}/**/*.php', recursive=True) + glob.glob(f'{raiz}/**/*.blade.php', recursive=True):
    if '/vendor/' in arq or '/node_modules/' in arq:
        continue
    txt = open(arq, encoding='utf-8', errors='replace').read()
    faltando = set()
    for fqcn in re.findall(r'^use ((?:App|Tests)\\[A-Za-z0-9_\\]+);', txt, re.M):
        if not existe(fqcn):
            faltando.add(fqcn)
    for fqcn in re.findall(r'\\?((?:App|Tests)\\[A-Za-z0-9_]+(?:\\[A-Za-z0-9_]+)+)::', txt):
        if not existe(fqcn):
            faltando.add(fqcn)
    if faltando:
        problemas[os.path.relpath(arq, raiz)] = sorted(faltando)

for arq, classes in sorted(problemas.items()):
    print(f'{arq}: {", ".join(classes)}')
print(f'--- {len(problemas)} arquivos com referência a classe inexistente (raiz: {raiz})')
sys.exit(1 if problemas else 0)

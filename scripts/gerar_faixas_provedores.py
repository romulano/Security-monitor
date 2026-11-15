#!/usr/bin/env python3
"""Gera resources/pentest/provider-ranges.json (formato secmon.pentest.providers/1).

O arquivo diz, por provedor, o tipo (cdn, lb, plataforma ou compartilhado), as faixas IPv4 e
IPv6, os sufixos de CNAME e de PTR e as orgs do RDAP. O PHP da P9b lê o arquivo para
classificar os IPs de um teste autorizado; o README-provider-ranges.md, ao lado do arquivo,
explica as fontes e as regras.

Script de DESENVOLVIMENTO, nunca de produção: quem atualiza roda na própria máquina, com
internet, e o resultado vira PR. Ele só fala com fontes públicas:
- as listas de faixas que os próprios provedores publicam;
- o RIPEstat, para os prefixos anunciados pelo ASN de quem não publica lista;
- as páginas de documentação que provam os IPs e os sufixos escritos à mão aqui;
- o RDAP dos registros (bootstrap da IANA), que confere cada org.
Tudo o que vem de fora é dado: o JSON passa pelo json, o texto por ipaddress e por comparação
de texto fixo, sempre com teto de tamanho. Nada é executado.

Uso, da raiz do repositório (só a biblioteca padrão; Python 3.9+):
  python3 -I scripts/gerar_faixas_provedores.py                 baixa, monta, confere e grava
  python3 -I scripts/gerar_faixas_provedores.py --sem-rdap      idem, sem reconferir as orgs
  python3 -I scripts/gerar_faixas_provedores.py --validar ARQ   só confere um arquivo pronto
  python3 -I scripts/gerar_faixas_provedores.py --validar ARQ --mentira
                                                                o arquivo de mentira dos testes
Saída 0 quando está tudo certo; 1 com o motivo no stderr (nada é gravado pela metade).
"""
import argparse
import bisect
import concurrent.futures
import datetime
import hashlib
import http.client
import ipaddress
import json
import os
import re
import sys
import tempfile
import threading
import time
import unicodedata
import urllib.error
import urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
SAIDA = RAIZ / "resources" / "pentest" / "provider-ranges.json"
ESQUEMA = "secmon.pentest.providers/1"
TIPOS = ("cdn", "lb", "plataforma", "compartilhado")
CHAVES_PROVEDOR = ("kind", "ipv4", "ipv6", "cname_suffixes", "ptr_suffixes", "orgs")
MENOR_PREFIXO = {4: 8, 6: 19}          # regra do leitor: IPv4 /8 a /32, IPv6 /19 a /128
UA = "Mozilla/5.0 (X11; Linux x86_64) SecurityMonitorFaixas/1.0 (+https://example.com)"
TETO_BYTES = 32 * 1024 * 1024
DATA = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z")

# Espelho do App\Support\RegistrableDomain::TWO_LEVEL_SUFFIXES: o sufixo precisa de pelo menos
# um rótulo além do sufixo público, pela mesma régua que o app usa ("nada de .com.br ou .net").
DOIS_NIVEIS = {
    "com.br", "net.br", "org.br", "gov.br", "edu.br", "art.br", "blog.br", "eco.br", "ind.br", "inf.br", "srv.br",
    "tv.br", "app.br", "dev.br", "log.br",
    "co.uk", "org.uk", "ac.uk", "gov.uk", "me.uk",
    "com.au", "net.au", "org.au", "co.nz", "co.jp", "ne.jp", "or.jp", "co.kr", "co.in", "co.za",
    "com.mx", "com.ar", "com.co", "com.pe", "com.uy", "com.py", "com.bo", "com.ec", "com.ve", "com.cl",
    "com.cn", "com.hk", "com.tw", "com.sg", "com.tr", "com.pt", "com.es", "com.pl", "co.il", "co.id",
}
ROTULO = re.compile(r"[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?")

# Faixas de uso especial (RFC 6890 e afins): nunca no arquivo real. As de documentação
# (RFC 5737 e 3849) são as únicas do arquivo de mentira.
ESPECIAIS = [ipaddress.ip_network(r) for r in (
    "0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12",
    "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "192.168.0.0/16", "198.18.0.0/15", "198.51.100.0/24",
    "203.0.113.0/24", "224.0.0.0/3",
    "::/8", "100::/64", "2001::/23", "2001:db8::/32", "2002::/16", "fc00::/7", "fe80::/10", "ff00::/8")]
DOCUMENTACAO = [ipaddress.ip_network(r) for r in ("192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24", "2001:db8::/32")]

# ---------------------------------------------------------------------------------------------
# Fontes
# ---------------------------------------------------------------------------------------------

# Listas oficiais de faixas: o provedor publica, o gerador lê.
LISTAS = {
    "cloudflare-ips-v4": "https://www.cloudflare.com/ips-v4",
    "cloudflare-ips-v6": "https://www.cloudflare.com/ips-v6",
    "aws-ip-ranges": "https://ip-ranges.amazonaws.com/ip-ranges.json",
    "fastly-public-ip-list": "https://api.fastly.com/public-ip-list",
    "google-goog": "https://www.gstatic.com/ipranges/goog.json",
    "google-cloud": "https://www.gstatic.com/ipranges/cloud.json",
    "github-meta": "https://api.github.com/meta",
    "bunny-edge-v4": "https://bunnycdn.com/api/system/edgeserverlist",
    "bunny-edge-v6": "https://bunnycdn.com/api/system/edgeserverlist/ipv6",
    "gcore-cdn": "https://api.gcore.com/cdn/public-ip-list",
    "imperva-ips": "https://my.imperva.com/api/integration/v1/ips?resp_format=json",
    # O JSON da Azure troca de nome toda semana (ServiceTags_Public_AAAAMMDD.json): a URL do dia
    # sai desta página de download, e as duas entram nas fontes.
    "azure-service-tags-pagina": "https://www.microsoft.com/en-us/download/details.aspx?id=56519",
}
POST = {"imperva-ips"}                 # a API da Imperva só responde por POST (sem corpo)
AZURE_JSON = re.compile(r"https://download\.microsoft\.com/download/[0-9a-z/_.-]+/ServiceTags_Public_(\d{8})\.json", re.I)
RIPESTAT = "https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS{asn}&sourceapp=secmon-faixas"
IANA_RDAP = "https://data.iana.org/rdap/{familia}.json"

# Páginas de documentação que provam o que foi escrito à mão (IP, faixa ou sufixo): o gerador
# baixa cada uma e confere que a prova está lá. Página que deixar de dizer, para a geração.
DOCS = {
    "doc-cloudflare-parcial": "https://developers.cloudflare.com/dns/zone-setups/partial-setup/setup/",
    "doc-cloudflare-pages": "https://developers.cloudflare.com/pages/configuration/custom-domains/",
    "doc-cloudfront-cname": "https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/CNAMEs.html",
    "doc-fastly-cname": "https://www.fastly.com/documentation/guides/getting-started/domains/working-with-domains/working-with-cname-records-and-your-dns-provider/",
    "doc-akamai-edge-hostnames": "https://techdocs.akamai.com/property-mgr/reference/create-edge-hostnames",
    "doc-azure-front-door": "https://learn.microsoft.com/en-us/azure/frontdoor/front-door-custom-domain",
    "doc-azure-cdn": "https://learn.microsoft.com/en-us/azure/cdn/cdn-map-content-to-custom-domain",
    "doc-azure-app-service": "https://learn.microsoft.com/en-us/azure/app-service/manage-custom-dns-migrate-domain",
    "doc-bunny-hostname": "https://docs.bunny.net/cdn/custom-hostname",
    "doc-gcore-cname": "https://docs.gcore.com/cdn/getting-started/create-a-cdn-resource/create-a-cdn-resource",
    "doc-sucuri-firewall": "https://docs.sucuri.net/website-firewall/sucuri-firewall-troubleshooting-guide/",
    "doc-google-1e100": "https://support.google.com/faqs/answer/174717?hl=en",
    "doc-blogger-dominio": "https://support.google.com/blogger/answer/1233387?hl=en",
    "doc-vercel-dominio": "https://vercel.com/docs/domains/working-with-domains/add-a-domain",
    "doc-vercel-registro-a": "https://vercel.com/kb/guide/a-record-and-caa-with-vercel",
    "doc-netlify-dns-externo": "https://docs.netlify.com/domains-https/custom-domains/configure-external-dns/",
    "doc-github-pages-dominio": "https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/managing-a-custom-domain-for-your-github-pages-site",
    "doc-aws-elb-dominio": "https://docs.aws.amazon.com/elasticloadbalancing/latest/classic/using-domain-names-with-elb.html",
    "doc-aws-global-accelerator": "https://docs.aws.amazon.com/global-accelerator/latest/dg/dns-addressing-custom-domains.dns-addressing.html",
    "doc-azure-ip-publico": "https://learn.microsoft.com/en-us/azure/virtual-network/ip-services/public-ip-addresses",
    "doc-azure-cloud-services": "https://learn.microsoft.com/en-us/previous-versions/azure/cloud-services/cloud-services-custom-domain-name-portal",
    "doc-azure-traffic-manager": "https://learn.microsoft.com/en-us/azure/traffic-manager/traffic-manager-overview",
    "doc-shopify-dominio": "https://help.shopify.com/en/manual/domains/add-a-domain/connecting-domains/connect-domain-manual",
    "doc-squarespace-dominio": "https://support.squarespace.com/hc/en-us/articles/205812378",
    "doc-wix-apontamento": "https://support.wix.com/en/article/connecting-a-domain-to-wix-using-the-pointing-method",
    "doc-webflow-registros": "https://webflow.com/integrations/google-domains",
    "doc-nuvemshop-dominio": "https://atendimento.nuvemshop.com.br/pt_BR/12322-adicionar-dominio-proprio/como-vincular-meu-dominio-em-outras-hospedagens",
}


class Falha(Exception):
    """Fonte que mudou, prova que sumiu ou arquivo inválido: para tudo, nada é gravado."""


def agora():
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Baixador:
    """Baixa só por HTTPS, com teto de tamanho, e anota cada fonte (id, url, quando, sha256)."""

    def __init__(self):
        self.fontes = {}
        self._trava = threading.Lock()

    def baixar(self, fid, url, aceitar="*/*"):
        if not url.startswith("https://"):
            raise Falha(f"{fid}: só HTTPS ({url})")
        ultimo = None
        for tentativa in range(3):
            if tentativa:
                time.sleep(3 * tentativa)
            req = urllib.request.Request(url, data=b"" if fid in POST else None, method="POST" if fid in POST else "GET",
                                         headers={"User-Agent": UA, "Accept": aceitar})
            try:
                with urllib.request.urlopen(req, timeout=60) as r:
                    if not r.geturl().startswith("https://"):
                        raise Falha(f"{fid}: redirecionou para fora do HTTPS ({r.geturl()})")
                    corpo = r.read(TETO_BYTES + 1)
                    quando = agora()
            except urllib.error.HTTPError as e:
                ultimo = f"HTTP {e.code}"
                if e.code < 500 and e.code != 429:
                    break
                continue
            except (OSError, http.client.HTTPException) as e:   # URLError, timeout, TLS, conexão caída
                ultimo = str(e)
                continue
            if len(corpo) > TETO_BYTES:
                raise Falha(f"{fid}: resposta maior que {TETO_BYTES} bytes")
            with self._trava:
                self.fontes[fid] = {"id": fid, "url": url, "fetched_at": quando,
                                    "sha256": hashlib.sha256(corpo).hexdigest()}
            return corpo
        raise Falha(f"{fid}: não baixou ({ultimo}) {url}")


def _json(corpos, fid):
    try:
        return json.loads(corpos[fid].decode("utf-8-sig"))
    except (UnicodeDecodeError, json.JSONDecodeError) as e:
        raise Falha(f"{fid}: JSON inválido ({e})") from None


def _texto(corpos, fid):
    try:
        return corpos[fid].decode("ascii").split()
    except UnicodeDecodeError:
        raise Falha(f"{fid}: a lista não é texto ASCII") from None


def _seguro(onde, funcao, *args):
    """Formato inesperado numa fonte (chave que sumiu, tipo trocado) vira Falha com o nome dela."""
    try:
        return funcao(*args)
    except (KeyError, TypeError, AttributeError, IndexError, ValueError) as e:
        raise Falha(f"{onde}: o formato da fonte mudou ({e!r:.80})") from None


# ---------------------------------------------------------------------------------------------
# Leitura de cada lista (recebe os corpos baixados; devolve CIDRs ou IPs em texto)
# ---------------------------------------------------------------------------------------------

def _cloudflare(c):
    return _texto(c, "cloudflare-ips-v4") + _texto(c, "cloudflare-ips-v6")


def _aws(servico):
    # Só o serviço pedido. O CLOUDFRONT não leva o CLOUDFRONT_ORIGIN_FACING (é a saída da AWS para
    # a origem do cliente, não a borda), e o "AMAZON", que repete tudo, nunca entra.
    def ler(c):
        d = _json(c, "aws-ip-ranges")
        return ([p["ip_prefix"] for p in d["prefixes"] if p.get("service") == servico]
                + [p["ipv6_prefix"] for p in d["ipv6_prefixes"] if p.get("service") == servico])
    return ler


def _fastly(c):
    d = _json(c, "fastly-public-ip-list")
    return d["addresses"] + d["ipv6_addresses"]


def _google(c):
    # O método da própria Google: goog.json (tudo da Google) menos cloud.json (o que os clientes
    # do Google Cloud usam). O que sobra é a borda dos serviços da Google.
    def prefixos(fid):
        return [p.get("ipv4Prefix") or p.get("ipv6Prefix") for p in _json(c, fid)["prefixes"]]
    return [str(n) for n in subtrair(redes(prefixos("google-goog"), "google-goog"), redes(prefixos("google-cloud"), "google-cloud"))]


def _github_pages(c):
    return _json(c, "github-meta")["pages"]


def _bunny(c):
    return _json(c, "bunny-edge-v4") + _json(c, "bunny-edge-v6")


def _gcore(c):
    d = _json(c, "gcore-cdn")
    return d["addresses"] + d["addresses_v6"]


def _imperva(c):
    d = _json(c, "imperva-ips")
    if d.get("res") != 0:
        raise Falha(f"imperva-ips: res={d.get('res')!r}")
    return d["ipRanges"] + d["ipv6Ranges"]


def _azure(tag):
    def ler(c):
        for v in _json(c, "azure-service-tags")["values"]:
            if v.get("name") == tag:
                return v["properties"]["addressPrefixes"]
        raise Falha(f"azure-service-tags: a tag {tag} sumiu")
    return ler


def _asn(c, asn):
    fid = f"ripestat-as{asn}"
    d = _json(c, fid)
    if d.get("status") != "ok" or str(d.get("data", {}).get("resource", "")).upper().lstrip("AS") != str(asn):
        raise Falha(f"{fid}: resposta inesperada do RIPEstat")
    return [p["prefix"] for p in d["data"]["prefixes"]]


# ---------------------------------------------------------------------------------------------
# Os provedores
#
#   lista  função que lê a lista oficial;      asns  ASNs cujos prefixos anunciados entram;
#   ips    {cidr: (doc, prova)};               cname / ptr  {sufixo: (doc, prova)};
#   orgs   {org (o fn do RDAP, em minúsculas): IP de amostra que o RDAP confere}.
# A prova é o texto que a página precisa conter (IP ou faixa: com fronteira; o resto: trecho).
# ---------------------------------------------------------------------------------------------

def _doc(doc, *provas):
    return {p: (doc, p) for p in provas}


PROVEDORES = {
    # --- cdn: borda de CDN, WAF ou PaaS que serve o que o cliente publicou ---------------------
    "cloudflare": dict(kind="cdn", lista=_cloudflare,
                       cname={".cdn.cloudflare.net": ("doc-cloudflare-parcial", "cdn.cloudflare.net"),
                              ".pages.dev": ("doc-cloudflare-pages", "pages.dev")},
                       orgs={"cloudflare, inc.": "104.16.0.1", "mnt-cloudflare": "2a06:98c0::1"}),
    "cloudfront": dict(kind="cdn", lista=_aws("CLOUDFRONT"),
                       cname={".cloudfront.net": ("doc-cloudfront-cname", "cloudfront.net")}),
    "fastly": dict(kind="cdn", lista=_fastly,
                   cname={".fastly.net": ("doc-fastly-cname", "global.fastly.net")},
                   orgs={"fastly, inc.": "151.101.0.1"}),
    "akamai": dict(kind="cdn",            # sem lista pública: só CNAME e org
                   cname={f".{s}": ("doc-akamai-edge-hostnames", s)
                          for s in ("edgekey.net", "edgesuite.net", "akamaized.net", "akamaihd.net")},
                   orgs={"akamai technologies, inc.": "23.32.0.1", "akam1-ripe-mnt": "2.16.0.1"}),
    "azure-front-door": dict(kind="cdn", lista=_azure("AzureFrontDoor.Frontend"),
                             cname={".azurefd.net": ("doc-azure-front-door", "azurefd.net")}),
    "azure-cdn": dict(kind="cdn", cname={".azureedge.net": ("doc-azure-cdn", "azureedge.net")}),
    "azure-app-service": dict(kind="cdn", lista=_azure("AppService"),
                              cname={".azurewebsites.net": ("doc-azure-app-service", "azurewebsites.net")}),
    "bunny": dict(kind="cdn", lista=_bunny,   # sem org: a borda roda em datacenter de terceiro
                  cname={".b-cdn.net": ("doc-bunny-hostname", "b-cdn.net")}),
    "gcore": dict(kind="cdn", lista=_gcore,   # sem org: a mesma org cobre a nuvem da Gcore
                  cname={".gcdn.co": ("doc-gcore-cname", "gcdn.co")}),
    "imperva": dict(kind="cdn", lista=_imperva, orgs={"incapsula inc": "199.83.128.1"}),
    "sucuri": dict(kind="cdn",
                   ips=_doc("doc-sucuri-firewall", "192.88.134.0/23", "185.93.228.0/22", "66.248.200.0/22",
                            "208.109.0.0/22", "2a02:fe80::/29"),
                   orgs={"sucuri": "192.88.134.1", "sucurisoc": "185.93.228.1"}),
    "google": dict(kind="cdn", lista=_google,  # sem org: "Google LLC" cobre o Google Cloud
                   cname={".google.com": ("doc-blogger-dominio", "ghs.google.com")},
                   ptr={".1e100.net": ("doc-google-1e100", "1e100.net")}),
    "vercel": dict(kind="cdn",
                   ips={"76.76.21.21/32": ("doc-vercel-registro-a", "76.76.21.21")},
                   cname={f".{s}": ("doc-vercel-dominio", s) for s in ("vercel-dns.com", "vercel-dns-0.com", "vercel-dns-017.com", "vercel.app")},
                   orgs={"vercel, inc": "76.76.21.21"}),
    "netlify": dict(kind="cdn",                # sem org: a Netlify roda em nuvem de terceiro
                    ips={"75.2.60.5/32": ("doc-netlify-dns-externo", "75.2.60.5")},
                    cname={".netlify.com": ("doc-netlify-dns-externo", "apex-loadbalancer.netlify.com"),
                           ".netlify.app": ("doc-netlify-dns-externo", "netlify.app")}),
    "github-pages": dict(kind="cdn", lista=_github_pages,
                         cname={".github.io": ("doc-github-pages-dominio", "github.io")},
                         orgs={"github, inc.": "185.199.108.153"}),
    # --- lb: balanceador de nuvem (o IP é da nuvem, não do cliente) ----------------------------
    "aws-elb": dict(kind="lb", cname={".elb.amazonaws.com": ("doc-aws-elb-dominio", "elb.amazonaws.com")}),
    "aws-global-accelerator": dict(kind="lb", lista=_aws("GLOBALACCELERATOR"),
                                   cname={".awsglobalaccelerator.com": ("doc-aws-global-accelerator", "awsglobalaccelerator.com")}),
    "azure-cloudapp": dict(kind="lb", cname={".cloudapp.azure.com": ("doc-azure-ip-publico", "cloudapp.azure.com"),
                                             ".cloudapp.net": ("doc-azure-cloud-services", "cloudapp.net")}),
    "azure-traffic-manager": dict(kind="lb", cname={".trafficmanager.net": ("doc-azure-traffic-manager", "trafficmanager.net")}),
    # --- plataforma: loja ou construtor de site (o cliente não controla servidor nem código) ---
    "shopify": dict(kind="plataforma", asns=[62679],
                    ips={"23.227.38.65/32": ("doc-shopify-dominio", "23.227.38.65")},
                    cname={".myshopify.com": ("doc-shopify-dominio", "shops.myshopify.com")},
                    orgs={"shopify, inc.": "23.227.38.65"}),
    "squarespace": dict(kind="plataforma", asns=[53831],
                        ips={f"{ip}/32": ("doc-squarespace-dominio", ip)
                             for ip in ("198.185.159.144", "198.185.159.145", "198.49.23.144", "198.49.23.145")},
                        cname={".squarespace.com": ("doc-squarespace-dominio", "ext-cust.squarespace.com")},
                        orgs={"squarespace, inc.": "198.185.159.144"}),
    "wix": dict(kind="plataforma", asns=[58182],
                ips={"185.230.63.107/32": ("doc-wix-apontamento", "185.230.63.107")},
                cname={".wixdns.net": ("doc-wix-apontamento", "wixdns.net")},
                orgs={"il-wixcom-1-mnt": "185.230.63.107"}),
    "webflow": dict(kind="plataforma",
                    ips={f"{ip}/32": ("doc-webflow-registros", ip) for ip in ("198.202.211.1", "75.2.70.75", "99.83.190.102")},
                    cname={".webflow.com": ("doc-webflow-registros", "proxy-ssl.webflow.com")},
                    orgs={"webflow, inc": "198.202.211.1"}),
    "nuvemshop": dict(kind="plataforma",
                      ips={f"{ip}/32": ("doc-nuvemshop-dominio", ip) for ip in ("185.133.35.21", "185.133.35.22")},
                      cname={".lojavirtualnuvem.com.br": ("doc-nuvemshop-dominio", "lojavirtualnuvem.com.br")},
                      orgs={"linked store brasil criacao e desenvol de software": "185.133.35.21"}),
    # --- compartilhado: hospedagem compartilhada (sem lista: os prefixos anunciados pelo ASN) ---
    "locaweb": dict(kind="compartilhado", asns=[27715], orgs={"locaweb serviços de internet s/a": "177.11.82.1"}),
    "kinghost": dict(kind="compartilhado", asns=[28299], orgs={"lwsa s/a": "177.12.160.1"}),
    "redehost-umbler": dict(kind="compartilhado", asns=[53057], orgs={"redehost internet ltda.": "170.233.84.1"}),
    "hostinger": dict(kind="compartilhado", asns=[47583], orgs={"mnt-hostinger": "145.14.144.1"}),
    "hostgator-bluehost": dict(kind="compartilhado", asns=[46606],
                               orgs={"unified layer": "162.241.0.1", "hostgator.com llc": "192.185.0.1"}),
    "godaddy": dict(kind="compartilhado", asns=[26496],
                    orgs={"godaddy.com, llc": "160.153.0.1", "godaddy.com": "43.255.152.1"}),
    "ionos": dict(kind="compartilhado", asns=[8560], orgs={"ionos inc.": "74.208.0.1", "as8560-mnt": "217.160.0.1"}),
    "uol-host": dict(kind="compartilhado", asns=[7162], orgs={"universo online s.a.": "200.98.0.1"}),
}


# ---------------------------------------------------------------------------------------------
# Faixas
# ---------------------------------------------------------------------------------------------

def redes(textos, fonte):
    """Texto → rede, estrita (sem bit de host) e nunca mais larga que a regra do leitor."""
    saida = []
    for t in textos:
        if not isinstance(t, str):
            raise Falha(f"{fonte}: item que não é texto ({t!r:.60})")
        try:
            n = ipaddress.ip_network(t.strip(), strict=True)
        except ValueError:
            raise Falha(f"{fonte}: faixa inválida {t!r:.60}") from None
        if n.prefixlen < MENOR_PREFIXO[n.version]:
            raise Falha(f"{fonte}: {n} é mais larga que /{MENOR_PREFIXO[n.version]} (erro na fonte?)")
        saida.append(n)
    return saida


def juntar(nets):
    """Junta as faixas de um provedor (v4 e v6 separados), sem passar do menor prefixo."""
    saida = {4: [], 6: []}
    for v in (4, 6):
        for n in ipaddress.collapse_addresses([x for x in nets if x.version == v]):
            if n.prefixlen < MENOR_PREFIXO[v]:
                saida[v].extend(n.subnets(new_prefix=MENOR_PREFIXO[v]))
            else:
                saida[v].append(n)
    return saida


def subtrair(nets, tirar):
    """nets − tirar, faixa a faixa (o que sobra continua em CIDR)."""
    saida = []
    for v in (4, 6):
        corte = list(ipaddress.collapse_addresses([t for t in tirar if t.version == v]))
        inicios = [int(t.network_address) for t in corte]
        for r in (n for n in nets if n.version == v):
            fim = bisect.bisect_right(inicios, int(r.broadcast_address))
            ini = fim
            while ini > 0 and int(corte[ini - 1].broadcast_address) >= int(r.network_address):
                ini -= 1
            pedacos = [r]
            for t in corte[ini:fim]:
                novos = []
                for p in pedacos:
                    if not p.overlaps(t):
                        novos.append(p)
                    elif not p.subnet_of(t):
                        novos.extend(p.address_exclude(t))
                pedacos = novos
            saida.extend(pedacos)
    return saida


def sobreposicoes(por_provedor):
    """Pares (larga, dono_larga, estreita, dono_estreita) de provedores diferentes que se cruzam."""
    pares = []
    for v in (4, 6):
        itens = sorted(((n, p) for p, nets in por_provedor.items() for n in nets if n.version == v),
                       key=lambda x: (int(x[0].network_address), x[0].prefixlen))
        pilha = []
        for n, p in itens:
            while pilha and not n.subnet_of(pilha[-1][0]):
                pilha.pop()
            pares.extend((m, q, n, p) for m, q in pilha if q != p)
            pilha.append((n, p))
    return pares


# ---------------------------------------------------------------------------------------------
# Validação (a mesma régua do leitor, mais o que só o gerador consegue conferir)
# ---------------------------------------------------------------------------------------------

def registravel(host):
    """Espelho do RegistrableDomain::of(): o domínio registrável, ou None."""
    rotulos = host.split(".")
    if len(rotulos) < 2 or re.fullmatch(r"[0-9.]+", host):
        return None
    pegar = 3 if ".".join(rotulos[-2:]) in DOIS_NIVEIS else 2
    return ".".join(rotulos[-pegar:]) if len(rotulos) >= pegar else None


def erro_sufixo(s):
    if not isinstance(s, str) or not s.startswith(".") or s != s.lower() or len(s) > 254:
        return "precisa ser texto minúsculo começando com ponto"
    host = s[1:]
    if not host or host.endswith(".") or not all(ROTULO.fullmatch(r) for r in host.split(".")):
        return "rótulo inválido"
    if registravel(host) is None:
        return "precisa de um rótulo além do sufixo público (RegistrableDomain::of)"
    return None


def normalizar_org(s):
    """A normalização sugerida ao leitor: minúsculas, sem acento, pontuação e espaços viram um espaço."""
    s = unicodedata.normalize("NFKD", s.lower())
    s = "".join(ch for ch in s if not unicodedata.combining(ch))
    return re.sub(r"[^a-z0-9]+", " ", s).strip()


def _sem_repetir(pares):
    vistos = {}
    for k, v in pares:
        if k in vistos:
            raise Falha(f"chave repetida no JSON: {k!r}")
        vistos[k] = v
    return vistos


def validar(doc, mentira=False):
    """Lista de erros (vazia = válido)."""
    erros = []
    if not isinstance(doc, dict) or set(doc) != {"schema", "generated_at", "sources", "providers"}:
        return ["o topo precisa ter exatamente schema, generated_at, sources e providers"]
    if doc["schema"] != ESQUEMA:
        erros.append(f"schema diferente de {ESQUEMA}")
    if not isinstance(doc["generated_at"], str) or not DATA.fullmatch(doc["generated_at"]):
        erros.append("generated_at precisa ser AAAA-MM-DDTHH:MM:SSZ (UTC)")
    else:
        try:
            datetime.datetime.strptime(doc["generated_at"], "%Y-%m-%dT%H:%M:%SZ")
        except ValueError:
            erros.append("generated_at não é uma data válida")

    fontes = doc["sources"]
    if not isinstance(fontes, list) or not fontes:
        erros.append("sources precisa ser uma lista não vazia")
        fontes = []
    ids = set()
    for f in fontes:
        if not isinstance(f, dict) or set(f) != {"id", "url", "fetched_at", "sha256"}:
            erros.append(f"fonte malformada: {f!r:.80}")
            continue
        if not isinstance(f["id"], str) or not re.fullmatch(r"[a-z0-9][a-z0-9.-]*", f["id"]) or f["id"] in ids:
            erros.append(f"id de fonte inválido ou repetido: {f['id']!r}")
        ids.add(f["id"])
        if not isinstance(f["url"], str) or not f["url"].startswith("https://"):
            erros.append(f"{f['id']}: url precisa ser https")
        if not isinstance(f["fetched_at"], str) or not DATA.fullmatch(f["fetched_at"]):
            erros.append(f"{f['id']}: fetched_at fora do formato")
        if not isinstance(f["sha256"], str) or not re.fullmatch(r"[0-9a-f]{64}", f["sha256"]):
            erros.append(f"{f['id']}: sha256 precisa de 64 hex minúsculos")

    provs = doc["providers"]
    if not isinstance(provs, dict) or not provs:
        return erros + ["providers precisa ser um objeto não vazio"]
    donos_sufixo, donos_org, nets_por_provedor = {}, {}, {}
    for pid, p in provs.items():
        if not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,62})", pid):
            erros.append(f"id de provedor inválido: {pid!r}")
        if not isinstance(p, dict) or set(p) != set(CHAVES_PROVEDOR):
            erros.append(f"{pid}: precisa ter exatamente {', '.join(CHAVES_PROVEDOR)}")
            continue
        if p["kind"] not in TIPOS:
            erros.append(f"{pid}: kind {p['kind']!r} fora de {TIPOS}")
        if not all(isinstance(p[k], list) and all(isinstance(x, str) for x in p[k]) for k in CHAVES_PROVEDOR[1:]):
            erros.append(f"{pid}: as listas precisam ser de texto")
            continue
        if not any(p[k] for k in CHAVES_PROVEDOR[1:]):
            erros.append(f"{pid}: provedor sem nenhum sinal")
        nets = []
        for chave, v in (("ipv4", 4), ("ipv6", 6)):
            vistos = []
            for t in p[chave]:
                try:
                    n = ipaddress.ip_network(t, strict=True)
                except ValueError:
                    erros.append(f"{pid}: {chave} inválido {t!r}")
                    continue
                if n.version != v or str(n) != t:
                    erros.append(f"{pid}: {t!r} fora de {chave} ou fora da forma canônica")
                    continue
                if not MENOR_PREFIXO[v] <= n.prefixlen <= (32 if v == 4 else 128):
                    erros.append(f"{pid}: {t} fora de /{MENOR_PREFIXO[v]}..")
                if mentira and not any(n.subnet_of(d) for d in DOCUMENTACAO if d.version == v):
                    erros.append(f"{pid}: {t} fora das faixas de documentação (RFC 5737/3849)")
                if not mentira and any(n.overlaps(e) for e in ESPECIAIS if e.version == v):
                    erros.append(f"{pid}: {t} cruza faixa de uso especial")
                vistos.append(n)
            if vistos != sorted(vistos, key=lambda x: (int(x.network_address), x.prefixlen)):
                erros.append(f"{pid}: {chave} fora de ordem")
            if any(a.overlaps(b) for a, b in zip(vistos, vistos[1:])):
                erros.append(f"{pid}: {chave} com faixas que se cruzam (junte-as)")
            nets.extend(vistos)
        nets_por_provedor[pid] = nets
        for chave in ("cname_suffixes", "ptr_suffixes"):
            if p[chave] != sorted(set(p[chave])):
                erros.append(f"{pid}: {chave} fora de ordem ou repetido")
            for s in p[chave]:
                motivo = erro_sufixo(s)
                if motivo:
                    erros.append(f"{pid}: sufixo {s!r}: {motivo}")
                if mentira and not s.endswith(".example"):
                    erros.append(f"{pid}: no arquivo de mentira o sufixo termina em .example ({s})")
                if (chave, s) in donos_sufixo:
                    erros.append(f"{s} em dois provedores: {donos_sufixo[(chave, s)]} e {pid}")
                donos_sufixo[(chave, s)] = pid
        if p["orgs"] != sorted(set(p["orgs"])):
            erros.append(f"{pid}: orgs fora de ordem ou repetida")
        for o in p["orgs"]:
            if o != o.lower() or o != o.strip() or not 1 <= len(o) <= 200 or not normalizar_org(o):
                erros.append(f"{pid}: org {o!r} precisa ser minúscula, aparada e com letra ou número")
                continue
            chave = normalizar_org(o)
            if chave in donos_org and donos_org[chave] != pid:
                erros.append(f"org {o!r} em dois provedores (normalizada): {donos_org[chave]} e {pid}")
            donos_org[chave] = pid
    for (chave, s), dono in donos_sufixo.items():
        for (chave2, s2), dono2 in donos_sufixo.items():
            if chave == chave2 and dono != dono2 and s != s2 and s.endswith(s2):
                erros.append(f"{s} ({dono}) fica dentro de {s2} ({dono2}): o sufixo não pode desempatar")
    for m, q, n, p in sobreposicoes(nets_por_provedor):
        if m == n:
            erros.append(f"{n} em dois provedores ({q} e {p}): a mais específica não desempata")
    return erros


# ---------------------------------------------------------------------------------------------
# Provas nas páginas e orgs no RDAP
# ---------------------------------------------------------------------------------------------

def consta(texto, prova):
    """A página diz a prova? IP/faixa com fronteira (23.227.38.65 não casa 123.227.38.650)."""
    if re.fullmatch(r"[0-9a-f:.]+(?:/\d+)?", prova) and re.search(r"\d", prova) and ("." in prova or ":" in prova):
        return re.search(r"(?<![0-9a-z.:])" + re.escape(prova) + r"(?![0-9a-z:]|\.\d)", texto) is not None
    return prova in texto


def conferir_provas(corpos):
    faltam = []
    for pid, p in PROVEDORES.items():
        for campo in ("ips", "cname", "ptr"):
            for item, (doc, prova) in p.get(campo, {}).items():
                texto = corpos[doc].decode("utf-8", "replace").lower()
                if not consta(texto, prova.lower()):
                    faltam.append(f"{pid}: {item} — a página {doc} não diz mais {prova!r} ({DOCS[doc]})")
    if faltam:
        raise Falha("prova que sumiu da documentação (revise o provedor):\n  " + "\n  ".join(faltam))


def _fn_rdap(dados):
    """O mesmo que o RdapClient::parseIp() lê: o fn do registrant, senão o do administrative."""
    for papel in ("registrant", "administrative"):
        for ent in dados.get("entities") or []:
            if papel in (ent.get("roles") or []):
                for item in ((ent.get("vcardArray") or [None, []])[1] or []):
                    if isinstance(item, list) and len(item) > 3 and item[0] == "fn" and isinstance(item[3], str) and item[3].strip():
                        return item[3].strip()
    return None


def conferir_orgs(baixador):
    boot = {}
    for familia in ("ipv4", "ipv6"):
        corpo = baixador.baixar(f"iana-rdap-{familia}", IANA_RDAP.format(familia=familia), "application/json")
        boot[familia] = _seguro(f"iana-rdap-{familia}", lambda b: [(list(map(ipaddress.ip_network, k)), u)
                                                                    for k, u in json.loads(b)["services"]], corpo)
    erros = []
    for pid, p in PROVEDORES.items():
        for i, (org, ip) in enumerate(sorted(p.get("orgs", {}).items()), 1):
            endereco = ipaddress.ip_address(ip)
            familia = "ipv6" if endereco.version == 6 else "ipv4"
            base = next((next((u for u in urls if u.startswith("https://")), None)
                         for chaves, urls in boot[familia] if any(endereco in k for k in chaves)), None)
            if not base:
                erros.append(f"{pid}: {ip} sem servidor RDAP no bootstrap")
                continue
            corpo = baixador.baixar(f"rdap-{pid}-{i}", base.rstrip("/") + "/ip/" + ip, "application/rdap+json, application/json;q=0.9")
            fn = _seguro(f"rdap-{pid}-{i}", lambda b: _fn_rdap(json.loads(b)), corpo)
            if (fn or "").lower() != org:
                erros.append(f"{pid}: o RDAP de {ip} diz {fn!r}, o gerador espera {org!r}")
            time.sleep(1)
    if erros:
        raise Falha("org que não confere no RDAP:\n  " + "\n  ".join(erros))


# ---------------------------------------------------------------------------------------------
# Montagem
# ---------------------------------------------------------------------------------------------

def baixar_tudo(baixador):
    pedidos = dict(LISTAS)
    pedidos.update(DOCS)
    for p in PROVEDORES.values():
        for asn in p.get("asns", []):
            pedidos[f"ripestat-as{asn}"] = RIPESTAT.format(asn=asn)
    with concurrent.futures.ThreadPoolExecutor(6) as ex:
        futuros = {fid: ex.submit(baixador.baixar, fid, url) for fid, url in pedidos.items()}
        corpos = {fid: f.result() for fid, f in futuros.items()}
    datas = sorted(set(AZURE_JSON.findall(corpos["azure-service-tags-pagina"].decode("utf-8", "replace"))))
    urls = {m.group(0) for m in AZURE_JSON.finditer(corpos["azure-service-tags-pagina"].decode("utf-8", "replace"))
            if m.group(1) == (datas[-1] if datas else None)}
    if len(urls) != 1:
        raise Falha(f"azure-service-tags-pagina: esperava um link do JSON do dia, achei {len(urls)}")
    corpos["azure-service-tags"] = baixador.baixar("azure-service-tags", urls.pop(), "application/json")
    return corpos


def montar(corpos):
    conferir_provas(corpos)
    oficiais, anunciadas = {}, {}
    for pid, p in PROVEDORES.items():
        nets = redes(_seguro(pid, p["lista"], corpos), pid) if "lista" in p else []
        nets += redes(list(p.get("ips", {})), pid)
        oficiais[pid] = nets
        anunciadas[pid] = [n for asn in p.get("asns", [])
                           for n in redes(_seguro(f"ripestat-as{asn}", _asn, corpos, asn), f"ripestat-as{asn}")]
    # O anúncio BGP é o sinal mais grosso: perde para a lista e para a documentação de qualquer
    # outro provedor (ex.: um prefixo da Sucuri anunciado pela GoDaddy fica só na Sucuri).
    todas_oficiais = [(n, pid) for pid, nets in oficiais.items() for n in nets]
    for pid in anunciadas:
        if anunciadas[pid]:
            anunciadas[pid] = subtrair(anunciadas[pid], [n for n, q in todas_oficiais if q != pid])
    provedores = {}
    for pid in sorted(PROVEDORES):
        p = PROVEDORES[pid]
        faixas = juntar(oficiais[pid] + anunciadas[pid])
        provedores[pid] = {
            "kind": p["kind"],
            "ipv4": [str(n) for n in sorted(faixas[4], key=lambda x: int(x.network_address))],
            "ipv6": [str(n) for n in sorted(faixas[6], key=lambda x: int(x.network_address))],
            "cname_suffixes": sorted(p.get("cname", {})),
            "ptr_suffixes": sorted(p.get("ptr", {})),
            "orgs": sorted(p.get("orgs", {})),
        }
    return provedores


def resumo(doc):
    provs = doc["providers"]
    print(f"{'provedor':<24}{'tipo':<15}{'v4':>6}{'v6':>6}{'cname':>7}{'ptr':>5}{'orgs':>6}")
    for pid, p in provs.items():
        print(f"{pid:<24}{p['kind']:<15}{len(p['ipv4']):>6}{len(p['ipv6']):>6}{len(p['cname_suffixes']):>7}"
              f"{len(p['ptr_suffixes']):>5}{len(p['orgs']):>6}")
    tot = {k: sum(len(p[k]) for p in provs.values()) for k in CHAVES_PROVEDOR[1:]}
    print(f"total: {len(provs)} provedores, {tot['ipv4']} faixas IPv4, {tot['ipv6']} IPv6, "
          f"{tot['cname_suffixes']} sufixos de CNAME, {tot['ptr_suffixes']} de PTR, {tot['orgs']} orgs; "
          f"{len(doc['sources'])} fontes")
    nets = {pid: [ipaddress.ip_network(t) for t in p["ipv4"] + p["ipv6"]] for pid, p in provs.items()}
    contagem = {}
    for m, q, n, p in sobreposicoes(nets):
        contagem.setdefault((q, p), []).append(f"{m} ⊃ {n}")
    if contagem:
        print("faixas dentro de outra de outro provedor (o leitor usa a mais específica):")
        for (q, p), exemplos in sorted(contagem.items()):
            print(f"  {q} ⊃ {p}: {len(exemplos)} (ex.: {exemplos[0]})")


def gravar(doc, destino):
    texto = json.dumps(doc, indent=2, ensure_ascii=False) + "\n"
    destino.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=destino.parent, prefix=".provider-ranges.", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(texto)
        os.chmod(tmp, 0o644)               # o mkstemp cria 0600; o arquivo é público no repositório
        os.replace(tmp, destino)
    except BaseException:
        os.unlink(tmp)
        raise
    return hashlib.sha256(texto.encode("utf-8")).hexdigest()


def main():
    ap = argparse.ArgumentParser(description="Gera ou confere o provider-ranges.json da P9b.")
    ap.add_argument("--saida", type=Path, default=SAIDA, help=f"onde gravar (padrão: {SAIDA.relative_to(RAIZ)})")
    ap.add_argument("--sem-rdap", action="store_true", help="não reconfere as orgs no RDAP (só para iterar; não abra PR assim)")
    ap.add_argument("--validar", type=Path, metavar="ARQ", help="só confere um arquivo pronto")
    ap.add_argument("--mentira", action="store_true", help="com --validar: o arquivo de mentira dos testes")
    args = ap.parse_args()
    try:
        if args.validar:
            doc = json.loads(args.validar.read_text(encoding="utf-8"), object_pairs_hook=_sem_repetir)
            erros = validar(doc, mentira=args.mentira)
            if erros:
                raise Falha(f"{args.validar}: inválido\n  " + "\n  ".join(erros))
            idade = datetime.datetime.now(datetime.timezone.utc) - datetime.datetime.strptime(
                doc["generated_at"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc)
            print(f"{args.validar}: válido ({len(doc['providers'])} provedores; gerado há {idade.days} dias)")
            if idade.days > 30 and not args.mentira:
                print("aviso: mais velho que 30 dias; o leitor desliga a regra 10 (faixas_desatualizadas)")
            return 0
        baixador = Baixador()
        corpos = baixar_tudo(baixador)
        provedores = montar(corpos)
        if args.sem_rdap:
            print("aviso: orgs sem reconferência no RDAP (--sem-rdap)", file=sys.stderr)
        else:
            conferir_orgs(baixador)
        doc = {"schema": ESQUEMA, "generated_at": agora(),
               "sources": [baixador.fontes[k] for k in sorted(baixador.fontes)], "providers": provedores}
        erros = validar(doc)
        if erros:
            raise Falha("o arquivo montado não passa na validação:\n  " + "\n  ".join(erros))
        resumo(doc)
        sha = gravar(doc, args.saida)
        print(f"gravado: {args.saida} (sha256 {sha})")
        return 0
    except Falha as e:
        print(f"erro: {e}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

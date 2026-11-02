#!/usr/bin/env python3
"""Verificação local de login e leitura do painel antes do corte de DNS."""
import http.client
import re
import ssl
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import urlencode

host = 'pay.360bh.com.br'
password = Path('/opt/360bh/secrets/pay360bh_prod_admin_password').read_text().strip()
context = ssl._create_unverified_context()
connection = http.client.HTTPSConnection('127.0.0.1', 443, context=context, timeout=10)
connection.request('GET', '/login', headers={'Host': host})
response = connection.getresponse()
body = response.read().decode('utf-8')
if response.status != 200:
    raise RuntimeError('A página de login não respondeu com sucesso.')
cookie = SimpleCookie()
cookie.load(response.getheader('Set-Cookie', ''))
session = cookie.get('pay360bh_session')
csrf = re.search(r'name="_csrf" value="([^"]+)"', body)
if not session or not csrf:
    raise RuntimeError('Sessão ou CSRF ausente na página de login.')

payload = urlencode({'_csrf': csrf.group(1), 'email': 'instalabh@gmail.com', 'password': password})
connection.request('POST', '/login', body=payload, headers={
    'Host': host, 'Content-Type': 'application/x-www-form-urlencoded',
    'Cookie': 'pay360bh_session=' + session.value,
})
response = connection.getresponse()
response.read()
if response.status != 303 or response.getheader('Location') != '/painel':
    raise RuntimeError('A autenticação não retornou o redirecionamento esperado.')
cookie.load(response.getheader('Set-Cookie', ''))
session = cookie.get('pay360bh_session')
if not session:
    raise RuntimeError('Sessão autenticada ausente.')
connection.request('GET', '/painel', headers={'Host': host, 'Cookie': 'pay360bh_session=' + session.value})
response = connection.getresponse()
body = response.read().decode('utf-8')
if response.status != 200 or 'AMBIENTE DE PRODUÇÃO' not in body:
    raise RuntimeError('Painel de produção não abriu após a autenticação.')
print('Login, sessão e painel de produção: OK')

# Prova Técnica Nasajon

Solução implementada em **PHP (CLI)** por Mauricelio Pereira Silvestre.

## Pré-requisitos

- PHP 7.4+ instalado e acessível via terminal
- Acesso à internet (para a API do IBGE e Supabase)

## Como rodar

```bash
cd C:\xampp\htdocs\my-projects\prova-nasajon
php solution.php
```

O script irá:
1. Ler o `input.csv`
2. Buscar todos os municípios na API do IBGE
3. Fazer o matching de cada município
4. Gerar o `resultado.csv`
5. Calcular as estatísticas e enviar para a API de correção

## Arquivos

| Arquivo | Descrição |
|---|---|
| `input.csv` | Arquivo de entrada com municípios e populações |
| `solution.php` | Script principal da solução |
| `resultado.csv` | Gerado automaticamente após a execução |
# Sistema de Vendas de Vouchers

Sistema completo para gerenciamento de vendas de vouchers/ingressos com controle de comissões para consultores.

## 🚀 Versão 2.0 - Atualização Maior

### ✨ Novidades desta versão

- **✅ Banco de Dados MySQL** - Migração completa de JSON para MySQL/MariaDB
- **🔐 Segurança Aprimorada**
  - Hash de senhas com bcrypt (PASSWORD_BCRYPT)
  - Proteção contra SQL Injection (PDO com Prepared Statements)
  - Proteção CSRF (Cross-Site Request Forgery)
  - Timeout automático de sessão (configurável)
  - Sanitização de inputs
- **📊 Sistema de Auditoria** - Logs de todas as ações importantes
- **⚙️ Instalador Automático** - Criação automática de banco e tabelas
- **👥 Gestão de Usuários Aprimorada** - CRUD completo com validações

---

## 📋 Requisitos

- PHP 7.4 ou superior
- MySQL 5.7+ ou MariaDB 10.2+
- Extensões PHP:
  - PDO
  - pdo_mysql
  - mbstring
  - json

---

## 🔧 Instalação

### Passo 1: Configure o Banco de Dados

1. Edite o arquivo `db_config.php` com as credenciais do seu banco:

```php
return [
    'host' => 'localhost',
    'database' => 'vouchersales',
    'username' => 'seu_usuario',
    'password' => 'sua_senha',
    'charset' => 'utf8mb4',
    'port' => 3306,
];
```

**Importante:** Este arquivo já existe no projeto com valores padrão. Use `db_config.example.php` como referência.

### Passo 2: Execute o Instalador

Acesse no navegador:
```
http://seu-dominio.com/admin/install.php
```

O instalador irá:
- ✅ Criar o banco de dados (se não existir)
- ✅ Criar todas as tabelas necessárias
- ✅ Inserir configurações padrão
- ✅ Criar usuário admin padrão
- ✅ Migrar dados dos arquivos JSON (se existirem)

### Passo 3: Login Inicial

**Credenciais Padrão:**
- **Usuário:** `admin`
- **Senha:** `godmode@2025`

**⚠️ IMPORTANTE:** Altere a senha padrão imediatamente após o primeiro login!

---

## 📊 Estrutura do Banco de Dados

### Tabelas Criadas

1. **users** - Usuários do sistema
   - Senhas armazenadas com hash bcrypt
   - Controle de ativo/inativo
   - Rastreamento de último login

2. **payments** - Pagamentos de comissões
   - Vinculado a promotor e mês
   - Rastreamento de quem marcou/desmarcou
   - Valores e quantidades

3. **config** - Configurações do sistema
   - Chave-valor flexível
   - Configurações dinâmicas

4. **sessions** - Controle de sessões
   - Timeout automático
   - Vinculado a usuários

5. **audit_logs** - Logs de auditoria
   - Registro de todas as ações importantes
   - IP e User Agent
   - Dados antigos e novos (JSON)

---

## 🔐 Segurança

### Recursos Implementados

✅ **Hash de Senhas** - Bcrypt com salt automático
✅ **SQL Injection Protection** - PDO com prepared statements
✅ **CSRF Protection** - Token em todas as requisições AJAX
✅ **Session Timeout** - Expira sessões inativas (1 hora padrão)
✅ **Input Sanitization** - Limpeza de todos os dados de entrada
✅ **Audit Logging** - Registro de ações sensíveis
✅ **XSS Protection** - htmlspecialchars em todas as saídas

### Configuração de Timeout

Para alterar o timeout da sessão (padrão: 3600 segundos = 1 hora):

```sql
UPDATE config SET value = '7200' WHERE key = 'session_timeout';
```

---

## 🎯 Funcionalidades

### Modo Consultor
- Visualização de vendas individuais por mês
- Cálculo automático de comissão (25%)
- Detalhamento de cada venda
- Exportação para Excel
- Saldo acumulado de meses anteriores

### Modo GODMODE (Administrador)
- Visão geral de TODOS os consultores
- Detalhamento por consultor e por mês
- Marcação de comissões pagas/não pagas
- Sistema de gestão de usuários
- Logs de auditoria
- Exportação consolidada

### Modo Admin
- Upload de arquivos CSV
- Gerenciamento de períodos
- Exclusão de arquivos
- Estatísticas por arquivo

---

## 📁 Estrutura de Arquivos

```
vouchersales/
├── index.php              # Arquivo principal
├── functions.php          # Funções do sistema
├── Database.php           # Classe de conexão PDO
├── ajax_handler.php       # Handler para requisições AJAX
├── godmode.js            # JavaScript modo GODMODE
├── db_config.php         # Configuração do banco (NÃO COMMITAR)
├── db_config.example.php # Exemplo de configuração
├── .gitignore            # Arquivos ignorados pelo git
├── README.md             # Este arquivo
├── admin/
│   └── install.php       # Instalador automático
└── data/
    ├── ingressos_*.csv   # Arquivos CSV de vendas
    └── *.json            # Arquivos JSON (legado)
```

---

## 🔄 Migração de Dados

Se você já tem dados nos arquivos JSON antigos (`users.json`, `comissoes.json`), o instalador irá:

1. Detectar automaticamente os arquivos
2. Migrar os dados para o banco
3. Fazer hash das senhas automaticamente
4. Preservar todos os dados

**Nota:** Os arquivos JSON originais não serão deletados, mas o sistema passará a usar apenas o banco de dados.

---

## 🛠️ Manutenção

### Alterar Senha de Usuário

Via interface web (recomendado):
1. Login como admin
2. Acesse "Gerenciar Usuários"
3. Edite o usuário desejado

Via SQL (emergência):
```sql
-- Gerar hash da senha 'nova_senha'
-- Use um script PHP para gerar o hash:
-- echo password_hash('nova_senha', PASSWORD_BCRYPT);

UPDATE users
SET password = '$2y$10$...'
WHERE username = 'admin';
```

### Limpar Logs de Auditoria

```sql
-- Deletar logs mais antigos que 90 dias
DELETE FROM audit_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

### Backup do Banco

```bash
mysqldump -u usuario -p vouchersales > backup_$(date +%Y%m%d).sql
```

### Restaurar Backup

```bash
mysql -u usuario -p vouchersales < backup_20260101.sql
```

---

## 🐛 Troubleshooting

### Erro: "Arquivo de configuração db_config.php não encontrado"

**Solução:** Certifique-se de ter criado o arquivo `db_config.php` com as credenciais corretas.

### Erro: "Não foi possível conectar ao banco de dados"

**Possíveis causas:**
- Credenciais incorretas no `db_config.php`
- MySQL/MariaDB não está rodando
- Firewall bloqueando a conexão
- Usuário do banco não tem permissões

**Solução:** Verifique o log de erros do PHP e as credenciais.

### Sessão Expirando Muito Rápido

**Solução:** Aumente o valor de `session_timeout` na tabela `config`:
```sql
UPDATE config SET value = '7200' WHERE key = 'session_timeout';
```

### Erro CSRF ao Marcar Pagamentos

**Solução:**
1. Limpe o cache do navegador
2. Faça logout e login novamente
3. Verifique se a meta tag csrf-token está presente no HTML

---

## 📝 Changelog

### Versão 2.0 (Janeiro 2026)
- ✨ Migração completa para banco de dados MySQL
- 🔐 Implementação de segurança completa (hash, SQL injection, CSRF, timeout)
- 📊 Sistema de auditoria e logs
- ⚙️ Instalador automático
- 👥 Gestão aprimorada de usuários
- 🐛 Correções diversas de bugs

### Versão 1.0
- Sistema básico com arquivos JSON
- Funcionalidades principais implementadas

---

## 🔒 Segurança - Boas Práticas

1. **NUNCA** compartilhe o arquivo `db_config.php`
2. **SEMPRE** altere a senha padrão após instalação
3. **MANTENHA** o PHP e MySQL atualizados
4. **CONFIGURE** backups automáticos
5. **MONITORE** os logs de auditoria regularmente
6. **USE** HTTPS em produção
7. **LIMITE** acesso ao `/admin/install.php` após instalação

---

## 👨‍💻 Suporte

Para questões e suporte, consulte a documentação ou entre em contato com o desenvolvedor.

---

## 📄 Licença

Todos os direitos reservados.

---

**Desenvolvido com ❤️ para gerenciamento eficiente de vendas e comissões**

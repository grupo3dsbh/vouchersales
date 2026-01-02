<?php
/**
 * Classe Database - Gerencia conexões com o banco de dados
 *
 * Esta classe implementa o padrão Singleton para garantir uma única
 * conexão PDO durante toda a execução do script.
 */

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Construtor privado para prevenir instanciação direta
     */
    private function __construct() {}

    /**
     * Previne clonagem do objeto
     */
    private function __clone() {}

    /**
     * Obtém a instância única da conexão PDO
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            self::loadConfig();
            self::connect();
        }

        return self::$instance;
    }

    /**
     * Carrega configurações do banco de dados
     *
     * @throws RuntimeException
     */
    private static function loadConfig(): void
    {
        $configFile = __DIR__ . '/db_config.php';

        if (!file_exists($configFile)) {
            throw new RuntimeException(
                'Arquivo de configuração db_config.php não encontrado. ' .
                'Por favor, copie db_config.example.php para db_config.php e configure.'
            );
        }

        self::$config = require $configFile;
    }

    /**
     * Estabelece conexão com o banco de dados
     *
     * @throws PDOException
     */
    private static function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'],
            self::$config['database'],
            self::$config['charset']
        );

        try {
            self::$instance = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                self::$config['options']
            );
        } catch (PDOException $e) {
            // Log do erro sem expor detalhes sensíveis
            error_log('Erro de conexão com banco de dados: ' . $e->getMessage());
            throw new PDOException('Não foi possível conectar ao banco de dados.');
        }
    }

    /**
     * Executa uma query preparada
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Busca um único registro
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public static function fetchOne(string $sql, array $params = [])
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Busca todos os registros
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insere um registro e retorna o ID
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::getConnection()->lastInsertId();
    }

    /**
     * Executa UPDATE/DELETE e retorna o número de linhas afetadas
     *
     * @param string $sql
     * @param array $params
     * @return int
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Inicia uma transação
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Confirma uma transação
     *
     * @return bool
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Reverte uma transação
     *
     * @return bool
     */
    public static function rollBack(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Testa a conexão com o banco de dados
     *
     * @return bool
     */
    public static function testConnection(): bool
    {
        try {
            $conn = self::getConnection();
            $conn->query('SELECT 1');
            return true;
        } catch (Exception $e) {
            error_log('Teste de conexão falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fecha a conexão (útil para testes)
     */
    public static function closeConnection(): void
    {
        self::$instance = null;
    }
}

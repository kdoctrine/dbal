<?php

namespace Doctrine\DBAL\Driver\SQLAnywhere;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

use function array_key_exists;
use function assert;
use function is_int;
use function is_resource;

/**
 * SAP SQL Anywhere implementation of the Statement interface.
 */
class SQLAnywhereStatement implements Statement
{
    /** @var resource The connection resource. */
    private $conn;

    /** @var resource The prepared SQL statement to execute. */
    private $stmt;

    /** @var mixed[] The references to bound parameter values. */
    private $boundValues = [];

    /**
     * Prepares given statement for given connection.
     *
     * @param resource $conn The connection resource to use.
     * @param string   $sql  The SQL statement to prepare.
     *
     * @throws SQLAnywhereException
     */
    public function __construct($conn, $sql)
    {
        if (! is_resource($conn)) {
            throw new SQLAnywhereException('Invalid SQL Anywhere connection resource: ' . $conn);
        }

        $this->conn = $conn;
        $this->stmt = sasql_prepare($conn, $sql);

        if (! is_resource($this->stmt)) {
            throw SQLAnywhereException::fromSQLAnywhereError($conn);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws SQLAnywhereException
     */
    public function bindParam($column, &$variable, $type = ParameterType::STRING, $length = null)
    {
        assert(is_int($column));

        switch ($type) {
            case ParameterType::INTEGER:
            case ParameterType::BOOLEAN:
                $type = 'i';
                break;

            case ParameterType::LARGE_OBJECT:
                $type = 'b';
                break;

            case ParameterType::NULL:
            case ParameterType::STRING:
            case ParameterType::BINARY:
                $type = 's';
                break;

            default:
                throw new SQLAnywhereException('Unknown type: ' . $type);
        }

        $this->boundValues[$column] =& $variable;

        if (! sasql_stmt_bind_param_ex($this->stmt, $column - 1, $variable, $type, $variable === null)) {
            throw SQLAnywhereException::fromSQLAnywhereError($this->conn, $this->stmt);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        return $this->bindParam($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     *
     * @throws SQLAnywhereException
     */
    public function execute($params = null): ResultInterface
    {
        if ($params !== null) {
            $hasZeroIndex = array_key_exists(0, $params);

            foreach ($params as $key => $val) {
                if ($hasZeroIndex && is_int($key)) {
                    $this->bindValue($key + 1, $val);
                } else {
                    $this->bindValue($key, $val);
                }
            }
        }

        if (! sasql_stmt_execute($this->stmt)) {
            throw SQLAnywhereException::fromSQLAnywhereError($this->conn, $this->stmt);
        }

        return new Result($this->stmt);
    }

    public function free(): void
    {
        sasql_stmt_reset($this->stmt);
    }
}

<?php

declare(strict_types=1);

namespace Braesident\JPdo;

use Braesident\Blowfish\Blowfish;
use PDO;

/**
 * @method JPdoStatement prepare(string $command, array $options = [])
 */
final class JPdo extends PDO
{
  public string $schema;

  private ?Blowfish $blowfish;

  /**
   * @deprecated Use the static named constructors
   */
  public function __construct(
    string $hostOrDsn,
    string $schema,
    string $user,
    string $password,
    ?Blowfish $blowfish = null,
    array $options = [],
    bool $isDsn = false
  ) {
    $this->blowfish = $blowfish;
    $this->schema   = $schema;

    parent::__construct(
      $isDsn
        ? $hostOrDsn
        : "mysql:dbname={$schema};host={$hostOrDsn};charset=utf8mb4",
      $user,
      $password,
      self::buildOptions($options)
    );
  }

  public static function mysql(string $host, string $schema, string $user, string $password, ?Blowfish $blowfish = null, array $options = []): self
  {
    return new self($host, $schema, $user, $password, $blowfish, $options);
  }

  public static function sqlsrv(
    string $host,
    string $database,
    string $user,
    string $password,
    ?Blowfish $blowfish = null,
    array $dsnOptions = [],
    array $options = []
  ): self {
    $dsn = "sqlsrv:server=tcp:{$host};Database={$database}".self::buildSqlsrvDsnOptions($dsnOptions);

    return new self(
      $dsn,
      $database,
      $user,
      $password,
      $blowfish,
      $options,
      true
    );
  }

  public function prepare(string $query, array $options = []): JPdoStatement|false
  {
    /** @var JPdoStatement $stmt */
    $stmt = parent::prepare($query, $options);

    return $stmt->setBlowfish($this->blowfish);
  }

  private static function buildOptions(array $options): array
  {
    return $options + [
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_STATEMENT_CLASS    => ['Braesident\JPdo\JPdoStatement']
    ];
  }

  private static function buildSqlsrvDsnOptions(array $options): string
  {
    $defaults = [
      'Encrypt'                  => 'yes',
      'TrustServerCertificate'   => 'yes',
      'MultipleActiveResultSets' => null
    ];

    $merged = array_replace($defaults, $options);

    $parts = [];
    foreach ($merged as $key => $value) {
      if (null === $value || '' === $value) {
        continue;
      }
      $parts[] = "{$key}={$value}";
    }

    return $parts ? ';'.implode(';', $parts) : '';
  }

  public function isMysql(): bool
  {
    return $this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  public function isSqlsrv(): bool
  {
    return $this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlsrv';
  }
}

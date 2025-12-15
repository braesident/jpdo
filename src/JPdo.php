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
  private ?Blowfish $blowfish;

  public function __construct(string $host, public string $schema, string $user, string $password, ?Blowfish $blowfish = null)
  {
    $this->blowfish = $blowfish;

    parent::__construct(
      "mysql:dbname={$schema};host={$host};charset=utf8mb4",
      $user,
      $password,
      [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_STATEMENT_CLASS    => ['Braesident\JPdo\JPdoStatement']
      ]
    );
  }

  public function prepare(string $query, array $options = []): JPdoStatement|false
  {
    /** @var JPdoStatement $stmt */
    $stmt = parent::prepare($query, $options);

    return $stmt->setBlowfish($this->blowfish);
  }
}

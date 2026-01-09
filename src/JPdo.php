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
    [$query, $duplicateParams] = self::replaceDuplicatePlaceholders($query);

    /** @var JPdoStatement $stmt */
    $stmt = parent::prepare($query, $options);
    if (false === $stmt) {
      return false;
    }

    $stmt->setBlowfish($this->blowfish);
    if ($duplicateParams) {
      $stmt->setDuplicateParams($duplicateParams);
    }

    return $stmt;
  }

  private static function replaceDuplicatePlaceholders(string $query): array
  {
    [$placeholders, $counts] = self::findNamedPlaceholders($query);

    if (empty($placeholders)) {
      return [$query, []];
    }

    $hasDuplicates = false;
    foreach ($counts as $count) {
      if ($count > 1) {
        $hasDuplicates = true;
        break;
      }
    }

    if ( ! $hasDuplicates) {
      return [$query, []];
    }

    $usedNames = [];
    foreach (array_keys($counts) as $name) {
      $usedNames[$name] = true;
    }

    $duplicateParams = [];
    $occurrenceIndex = [];
    $rebuilt = '';
    $last = 0;

    foreach ($placeholders as $placeholder) {
      [$pos, $len, $name] = $placeholder;

      $rebuilt .= substr($query, $last, $pos - $last);

      if ($counts[$name] > 1) {
        $index = $occurrenceIndex[$name] ?? 0;
        while (true) {
          $candidate = $name.'_'.$index;
          if ( ! isset($usedNames[$candidate])) {
            break;
          }
          $index++;
        }

        $occurrenceIndex[$name] = $index + 1;
        $usedNames[$candidate] = true;
        $duplicateParams[$name][] = $candidate;

        $rebuilt .= ':'.$candidate;
      } else {
        $rebuilt .= substr($query, $pos, $len);
      }

      $last = $pos + $len;
    }

    $rebuilt .= substr($query, $last);

    return [$rebuilt, $duplicateParams];
  }

  private static function findNamedPlaceholders(string $query): array
  {
    $placeholders = [];
    $counts = [];

    $length = strlen($query);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
      $ch = $query[$i];
      $next = $i + 1 < $length ? $query[$i + 1] : '';

      if ($inLineComment) {
        if ($ch === "\n") {
          $inLineComment = false;
        }
        continue;
      }

      if ($inBlockComment) {
        if ($ch === '*' && $next === '/') {
          $inBlockComment = false;
          $i++;
        }
        continue;
      }

      if ($inSingle) {
        if ($ch === "'") {
          if ($next === "'") {
            $i++;
            continue;
          }
          $inSingle = false;
        }
        continue;
      }

      if ($inDouble) {
        if ($ch === '"') {
          if ($next === '"') {
            $i++;
            continue;
          }
          $inDouble = false;
        }
        continue;
      }

      if ($inBacktick) {
        if ($ch === '`') {
          if ($next === '`') {
            $i++;
            continue;
          }
          $inBacktick = false;
        }
        continue;
      }

      if ($ch === "'") {
        $inSingle = true;
        continue;
      }

      if ($ch === '"') {
        $inDouble = true;
        continue;
      }

      if ($ch === '`') {
        $inBacktick = true;
        continue;
      }

      if ($ch === '-' && $next === '-') {
        $inLineComment = true;
        $i++;
        continue;
      }

      if ($ch === '/' && $next === '*') {
        $inBlockComment = true;
        $i++;
        continue;
      }

      if ($ch === '#') {
        $inLineComment = true;
        continue;
      }

      if ($ch === ':' && ($i === 0 || $query[$i - 1] !== ':') && $next !== '' && self::isPlaceholderStart($next)) {
        $j = $i + 1;
        while ($j < $length && self::isPlaceholderChar($query[$j])) {
          $j++;
        }

        $name = substr($query, $i + 1, $j - ($i + 1));
        $placeholders[] = [$i, $j - $i, $name];
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $i = $j - 1;
      }
    }

    return [$placeholders, $counts];
  }

  private static function isPlaceholderStart(string $char): bool
  {
    return ($char >= 'a' && $char <= 'z')
        || ($char >= 'A' && $char <= 'Z')
        || $char === '_';
  }

  private static function isPlaceholderChar(string $char): bool
  {
    return self::isPlaceholderStart($char)
        || ($char >= '0' && $char <= '9');
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
